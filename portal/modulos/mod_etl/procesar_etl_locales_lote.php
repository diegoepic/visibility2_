<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require __DIR__ . '/etl_locales_helpers.php';

if (!isset($usuario_id)) {
    jsonResponse(['success' => false, 'message' => 'Usuario no autenticado.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

$sessionToken = trim($_SESSION['csrf_token'] ?? '');
$postToken = trim($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    jsonResponse(['success' => false, 'message' => 'Token CSRF inválido.'], 400);
}

$jobId = (int)($_POST['job_id'] ?? 0);
$limit = (int)($_POST['limit'] ?? 100);
$limit = ($limit > 0 && $limit <= 250) ? $limit : 100;
if ($jobId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Job inválido.'], 400);
}

$conn->set_charset('utf8mb4');
$stmt = $conn->prepare('SELECT * FROM etl_locales_jobs WHERE id = ? AND user_id = ? LIMIT 1');
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'Error preparando consulta job: ' . $conn->error], 500);
}
$stmt->bind_param('ii', $jobId, $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
$job = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$job) {
    jsonResponse(['success' => false, 'message' => 'Job no encontrado.'], 404);
}

$previewUrl = !empty($job['preview_path'])
    ? publicUrlFromPath($job['preview_path'], $_SERVER['DOCUMENT_ROOT'])
    : '';
$reportUrl = !empty($job['report_path'])
    ? publicUrlFromPath($job['report_path'], $_SERVER['DOCUMENT_ROOT'])
    : '';

if (in_array($job['status'], ['preview_completed', 'applying', 'completed'], true)) {
    jsonResponse([
        'success' => true,
        'done' => true,
        'phase' => 'preview',
        'job_id' => (int)$job['id'],
        'total_rows' => (int)$job['total_rows'],
        'processed_rows' => (int)$job['processed_rows'],
        'accepted_rows' => (int)$job['accepted_rows'],
        'review_rows' => (int)$job['review_rows'],
        'rejected_rows' => (int)$job['rejected_rows'],
        'progress' => 100,
        'previewReportUrl' => $previewUrl,
        'reportUrl' => (int)$job['rejected_rows'] > 0 ? $reportUrl : ''
    ]);
}

if (!in_array($job['status'], ['pending_preview', 'processing_preview'], true)) {
    jsonResponse(['success' => false, 'message' => 'El job no está disponible para validación.'], 409);
}

$divisionId = (int)($job['id_division'] ?? 0);
if ($divisionId <= 0) {
    jsonResponse(['success' => false, 'message' => 'El job no tiene una división válida.'], 409);
}

$filePath = (string)$job['file_path'];
$previewPath = (string)($job['preview_path'] ?? '');
$validationPath = (string)($job['validation_path'] ?? '');
$reportPath = (string)($job['report_path'] ?? '');
if (!is_file($filePath) || $previewPath === '' || $validationPath === '') {
    jsonResponse(['success' => false, 'message' => 'Faltan archivos requeridos para validar el job.'], 500);
}

$stmtDivision = $conn->prepare('SELECT nombre FROM division_empresa WHERE id=? LIMIT 1');
if (!$stmtDivision) {
    jsonResponse(['success' => false, 'message' => 'No se pudo consultar la división del job.'], 500);
}
$stmtDivision->bind_param('i', $divisionId);
$stmtDivision->execute();
$stmtDivision->bind_result($divisionName);
$divisionFound = $stmtDivision->fetch();
$stmtDivision->close();
if (!$divisionFound) {
    jsonResponse(['success' => false, 'message' => 'La división del job ya no existe.'], 409);
}
$divisionName = etlUpper((string)$divisionName);
$apiKey = etlLoadGoogleMapsApiKey();

$stmt = $conn->prepare("UPDATE etl_locales_jobs SET status='processing_preview', updated_at=NOW() WHERE id=? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $stmt->close();
}

$delimiter = $job['delimiter'] ?: ';';
$handle = fopen($filePath, 'r');
if (!$handle) {
    jsonResponse(['success' => false, 'message' => 'No se pudo abrir el archivo del job.'], 500);
}

$headers = fgetcsv($handle, 200000, $delimiter);
if ($headers === false) {
    fclose($handle);
    jsonResponse(['success' => false, 'message' => 'El CSV del job está vacío.'], 400);
}
$headers[0] = removeBOM($headers[0] ?? '');
$indexes = resolveColumnIndexes($headers);
if ($indexes === false) {
    fclose($handle);
    jsonResponse(['success' => false, 'message' => 'Los encabezados del archivo no son válidos.'], 400);
}

$startOffset = (int)$job['processed_rows'];
$physicalLineNumber = 1;
$skippedRows = 0;
while ($skippedRows < $startOffset && ($row = fgetcsv($handle, 200000, $delimiter)) !== false) {
    $physicalLineNumber++;
    if (isNonEmptyCsvRow($row)) {
        $skippedRows++;
    }
}

$batchProcessed = 0;
$batchAccepted = 0;
$batchReview = 0;
$batchRejected = 0;
$errors = [];

while ($batchProcessed < $limit && ($dataLine = fgetcsv($handle, 200000, $delimiter)) !== false) {
    $physicalLineNumber++;
    if (!isNonEmptyCsvRow($dataLine)) {
        continue;
    }
    $batchProcessed++;

    $valueFor = static function (string $field) use ($indexes, $dataLine): string {
        $index = $indexes[$field] ?? null;
        return $index === null ? '' : normalizeCsvText($dataLine[$index] ?? '');
    };
    $codigo = etlUpper($valueFor('codigo'));
    $divisionInput = etlUpper($valueFor('division'));
    $nombreOriginal = $valueFor('nombre_local');
    $direccionOriginal = $valueFor('direccion');
    $comunaOriginal = $valueFor('comuna');
    $cuentaName = etlUpper($valueFor('cuenta'));
    $cadenaName = etlUpper($valueFor('cadena'));
    $zonaInput = etlUpper($valueFor('zona'));
    $distritoInput = etlUpper($valueFor('distrito'));
    $vendedorRaw = $valueFor('nombre_vendedor');
    $jefeVentaRaw = $valueFor('jefe_venta');
    $vendedorName = $vendedorRaw === '' ? '' : etlNormalizeOptionalPerson($vendedorRaw);
    $jefeVentaName = $jefeVentaRaw === '' ? '' : etlNormalizeOptionalPerson($jefeVentaRaw);
    $hasAssignmentPayload = $vendedorName !== '' || $jefeVentaName !== '';
    $hasAddressPayload = $direccionOriginal !== '' || $comunaOriginal !== '';
    $hasPartialPayload = $nombreOriginal !== '' || $cuentaName !== '' || $cadenaName !== ''
        || $zonaInput !== '' || $distritoInput !== '' || $hasAssignmentPayload;

    $record = [
        'line' => $physicalLineNumber,
        'codigo' => $codigo,
        'division_id' => $divisionId,
        'division_archivo' => $divisionInput,
        'tipo_actualizacion' => $hasAddressPayload ? 'DIRECCION Y CAMPOS INFORMADOS' : 'CAMPOS PARCIALES SIN GOOGLE',
        'apply_address' => $hasAddressPayload,
        'update_nombre' => $nombreOriginal !== '',
        'update_cuenta' => $cuentaName !== '',
        'update_cadena' => $cadenaName !== '',
        'nombre' => $nombreOriginal,
        'direccion_actual' => '',
        'direccion_original' => $direccionOriginal,
        'direccion_limpia' => '',
        'direccion_nueva' => '',
        'direccion_google' => '',
        'comuna' => $comunaOriginal,
        'distrito' => '',
        'zona' => '',
        'region' => '',
        'cuenta' => $cuentaName,
        'cadena' => $cadenaName,
        'cuenta_archivo' => $cuentaName,
        'cadena_archivo' => $cadenaName,
        'zona_actual_id' => null,
        'zona_actual' => '',
        'zona_archivo' => $zonaInput,
        'estado_zona' => $zonaInput === '' ? 'SIN_CAMBIO' : 'PENDIENTE',
        'distrito_actual_id' => null,
        'distrito_actual' => '',
        'distrito_archivo' => $distritoInput,
        'distrito_zona_sugerida_id' => null,
        'estado_distrito' => $distritoInput === '' ? 'SIN_CAMBIO' : 'PENDIENTE',
        'vendedor_actual_id' => null,
        'vendedor_actual' => '',
        'vendedor_archivo' => $vendedorName,
        'estado_vendedor' => $vendedorName === '' ? 'SIN_CAMBIO' : 'PENDIENTE',
        'jefe_venta_actual_id' => null,
        'jefe_venta_actual' => '',
        'jefe_venta_archivo' => $jefeVentaName,
        'estado_jefe_venta' => $jefeVentaName === '' ? 'SIN_CAMBIO' : 'PENDIENTE',
        'estado_validacion' => 'ERROR_ENTRADA',
        'reason' => '',
        'lat' => null,
        'lng' => null,
        'response_id' => ''
    ];

    $divisionMatches = $divisionInput === ''
        || (ctype_digit($divisionInput) && (int)$divisionInput === $divisionId)
        || etlLookupKey($divisionInput) === etlLookupKey($divisionName);

    if ($codigo === '') {
        $record['reason'] = 'Código Local vacío.';
    } elseif (!$divisionMatches) {
        $record['estado_validacion'] = 'DIVISION_NO_COINCIDE';
        $record['reason'] = "La división '$divisionInput' del archivo no coincide con '$divisionName' (ID $divisionId).";
    } elseif (!$hasAddressPayload && !$hasPartialPayload) {
        $record['reason'] = 'No se indicó ningún dato para actualizar.';
    } else {
        $localActual = getExistingLocalByCode($conn, $codigo, $divisionId, $errors, $physicalLineNumber);
        if ($localActual === false) {
            $record['estado_validacion'] = 'LOCAL_NO_ENCONTRADO';
            $record['reason'] = array_pop($errors) ?: 'No se encontró el local.';
        } else {
            $record['direccion_actual'] = normalizeCsvText($localActual['direccion'] ?? '');
            $record['vendedor_actual_id'] = isset($localActual['id_vendedor']) ? (int)$localActual['id_vendedor'] : null;
            $record['vendedor_actual'] = etlUpper($localActual['nombre_vendedor'] ?? '');
            $record['jefe_venta_actual_id'] = isset($localActual['id_jefe_venta']) ? (int)$localActual['id_jefe_venta'] : null;
            $record['jefe_venta_actual'] = etlUpper($localActual['jefe_venta'] ?? '');
            $record['zona_actual_id'] = isset($localActual['id_zona']) ? (int)$localActual['id_zona'] : null;
            $record['zona_actual'] = etlUpper($localActual['zona'] ?? '');
            $record['distrito_actual_id'] = isset($localActual['id_distrito']) ? (int)$localActual['id_distrito'] : null;
            $record['distrito_actual'] = etlUpper($localActual['distrito'] ?? '');

            $vendedorInspection = etlInspectAssignmentPerson($conn, 'vendedor', $vendedorName);
            $jefeInspection = etlInspectAssignmentPerson($conn, 'jefe_venta', $jefeVentaName);
            $record['estado_vendedor'] = $vendedorInspection['status'];
            $record['estado_jefe_venta'] = $jefeInspection['status'];

            $zonaInspection = etlInspectTerritoryAssignment($conn, 'zona', $zonaInput);
            $record['estado_zona'] = $zonaInspection['status'];
            $targetZoneId = $zonaInput !== ''
                ? ($zonaInspection['id'] !== null ? (int)$zonaInspection['id'] : null)
                : ($record['zona_actual_id'] ?: null);
            if ($distritoInput !== '' && $zonaInput !== '' && $zonaInspection['status'] === 'NUEVO') {
                $distritoInspection = ['id' => null, 'zone_id' => null, 'matches' => 0, 'status' => 'NUEVO', 'name' => $distritoInput];
            } else {
                $distritoInspection = etlInspectTerritoryAssignment($conn, 'distrito', $distritoInput, $targetZoneId);
            }
            if ($distritoInput !== '' && $zonaInput === '' && !$targetZoneId
                && $distritoInspection['status'] === 'NUEVO') {
                $distritoInspection['status'] = 'ZONA_REQUERIDA';
            }
            $record['estado_distrito'] = $distritoInspection['status'];
            $record['distrito_zona_sugerida_id'] = $distritoInspection['zone_id'] ?? $targetZoneId;

            $catalogStatuses = [
                $record['estado_vendedor'], $record['estado_jefe_venta'],
                $record['estado_zona'], $record['estado_distrito'],
            ];
            if (in_array('ERROR_CONSULTA', $catalogStatuses, true)) {
                $record['estado_validacion'] = 'ERROR_CATALOGO';
                $record['reason'] = 'No se pudo consultar alguno de los catálogos informados.';
            } elseif (in_array('ZONA_REQUERIDA', $catalogStatuses, true)) {
                $record['estado_validacion'] = 'ZONA_REQUERIDA';
                $record['reason'] = 'El distrito no existe y el local no tiene una zona actual; debes informar también la Zona.';
            } elseif (in_array('DUPLICADO_EN_BASE', $catalogStatuses, true)) {
                $record['estado_validacion'] = 'DUPLICADO_EN_BASE';
                $record['reason'] = 'Uno de los valores informados aparece duplicado en su catálogo; debe regularizarse antes de asignarlo.';
            } elseif (!$hasAddressPayload) {
                $record['nombre'] = $nombreOriginal !== '' ? etlUpper($nombreOriginal) : etlUpper($localActual['nombre'] ?? '');
                $record['direccion_original'] = normalizeCsvText($localActual['direccion_original'] ?? $localActual['direccion'] ?? '');
                $record['direccion_limpia'] = normalizeCsvText($localActual['direccion'] ?? '');
                $record['direccion_nueva'] = normalizeCsvText($localActual['direccion'] ?? '');
                $record['direccion_google'] = normalizeCsvText($localActual['direccion_google'] ?? '');
                $record['comuna'] = etlUpper($localActual['comuna'] ?? '');
                $record['distrito'] = etlUpper($localActual['distrito'] ?? '');
                $record['zona'] = etlUpper($localActual['zona'] ?? '');
                $record['region'] = etlUpper($localActual['region'] ?? '');
                $record['cuenta'] = etlUpper($localActual['cuenta'] ?? '');
                $record['cadena'] = etlUpper($localActual['cadena'] ?? '');
                $record['lat'] = isset($localActual['lat']) && $localActual['lat'] !== '' ? (float)$localActual['lat'] : null;
                $record['lng'] = isset($localActual['lng']) && $localActual['lng'] !== '' ? (float)$localActual['lng'] : null;
                $record['response_id'] = normalizeCsvText($localActual['google_response_id'] ?? '');
                $record['estado_validacion'] = 'ACEPTADA';
                $record['reason'] = 'Se actualizarán únicamente las columnas informadas; la dirección y coordenadas se conservarán.';
            } elseif ($apiKey === '') {
                $record['estado_validacion'] = 'ERROR_CONFIGURACION';
                $record['reason'] = 'Falta configurar GOOGLE_MAPS_API_KEY para validar la dirección.';
            } else {
                $effectiveComuna = $comunaOriginal !== '' ? $comunaOriginal : normalizeCsvText($localActual['comuna'] ?? '');
                $effectiveAddress = $direccionOriginal !== ''
                    ? $direccionOriginal
                    : normalizeCsvText($localActual['direccion_original'] ?? $localActual['direccion'] ?? '');
                $territory = etlLookupTerritoryByComuna($effectiveComuna);
                if ($territory === null) {
                    $record['estado_validacion'] = 'COMUNA_SIN_MAPEO';
                    $record['reason'] = "La comuna '$effectiveComuna' no existe en el catálogo territorial.";
                } elseif ($effectiveAddress === '') {
                    $record['estado_validacion'] = 'DIRECCION_VACIA';
                    $record['reason'] = 'No existe una dirección actual y tampoco se informó una nueva.';
                } else {
                    $record['comuna'] = $territory['comuna'];
                    $record['distrito'] = $territory['distrito'];
                    $record['zona'] = $territory['zona'];
                    $record['region'] = $territory['region'];
                    $record['nombre'] = $nombreOriginal !== ''
                        ? etlBuildLocalName($codigo, $nombreOriginal, $record['comuna'])
                        : etlUpper($localActual['nombre'] ?? '');
                    $record['direccion_original'] = $effectiveAddress;
                    $record['direccion_limpia'] = etlCleanAddress($effectiveAddress, $record['comuna']);
                    $validation = etlValidateAddress(
                        $record['direccion_limpia'],
                        $record['comuna'],
                        $apiKey,
                        $physicalLineNumber
                    );
                    $record['estado_validacion'] = $validation['status'];
                    $record['reason'] = $validation['reason'];
                    $record['direccion_nueva'] = etlPreferredAddress($validation, $record['direccion_limpia']);
                    $record['direccion_google'] = $validation['formatted_address'];
                    $record['lat'] = $validation['lat'];
                    $record['lng'] = $validation['lng'];
                    $record['response_id'] = $validation['response_id'];
                }
            }
        }
    }

    if ($record['estado_validacion'] === 'ACEPTADA') {
        $batchAccepted++;
    } elseif ($record['estado_validacion'] === 'REVISION') {
        $batchReview++;
    } else {
        $batchRejected++;
        appendFailureToReport($reportPath, $record);
    }

    if (!appendPreviewRecord($previewPath, $record) || !appendValidationRecord($validationPath, $record)) {
        fclose($handle);
        jsonResponse(['success' => false, 'message' => 'No se pudo guardar el resultado de la validación.'], 500);
    }
}
fclose($handle);

$newProcessed = $startOffset + $batchProcessed;
$newAccepted = (int)$job['accepted_rows'] + $batchAccepted;
$newReview = (int)$job['review_rows'] + $batchReview;
$newRejected = (int)$job['rejected_rows'] + $batchRejected;
$totalRows = (int)$job['total_rows'];
$done = $newProcessed >= $totalRows;
$newStatus = $done ? 'preview_completed' : 'processing_preview';
$progress = $totalRows > 0 ? round(($newProcessed / $totalRows) * 100, 2) : 100;

$stmt = $conn->prepare("UPDATE etl_locales_jobs SET processed_rows=?, accepted_rows=?, review_rows=?, rejected_rows=?, failed_rows=?, status=?, updated_at=NOW() WHERE id=? LIMIT 1");
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'No se pudo actualizar el avance del job: ' . $conn->error], 500);
}
$stmt->bind_param('iiiiisi', $newProcessed, $newAccepted, $newReview, $newRejected, $newRejected, $newStatus, $jobId);
$stmt->execute();
$stmt->close();

jsonResponse([
    'success' => true,
    'done' => $done,
    'phase' => 'preview',
    'job_id' => $jobId,
    'total_rows' => $totalRows,
    'processed_rows' => $newProcessed,
    'accepted_rows' => $newAccepted,
    'review_rows' => $newReview,
    'rejected_rows' => $newRejected,
    'progress' => $progress,
    'previewReportUrl' => $done ? publicUrlFromPath($previewPath, $_SERVER['DOCUMENT_ROOT']) : '',
    'reportUrl' => $done && $newRejected > 0 ? publicUrlFromPath($reportPath, $_SERVER['DOCUMENT_ROOT']) : ''
]);
