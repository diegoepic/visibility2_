<?php
declare(strict_types=1);

session_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('memory_limit', '512M');
set_time_limit(180);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('America/Santiago');

if (!function_exists('normalizeCsvText')) {
    function normalizeCsvText($value): string
    {
        $value = (string)$value;
        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252, ISO-8859-1');
            if ($converted !== false) {
                $value = $converted;
            }
        }
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string)$value);
    }
}

require_once __DIR__ . '/../mod_local/etl_address_validation.php';

function json_fail(string $message, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_text(string $value): string
{
    $value = trim($value);

    $map = [
        'Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A','á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
        'É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'Ñ'=>'N','ñ'=>'n'
    ];

    $value = strtr($value, $map);
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/\s+/', ' ', $value);

    return trim($value);
}

function find_header_index(array $headers, array $aliases): int
{
    $normalizedHeaders = [];

    foreach ($headers as $idx => $header) {
        $normalizedHeaders[$idx] = normalize_text((string)$header);
    }

    foreach ($aliases as $alias) {
        $aliasNorm = normalize_text($alias);

        foreach ($normalizedHeaders as $idx => $headerNorm) {
            if ($headerNorm === $aliasNorm) {
                return $idx;
            }
        }
    }

    return -1;
}

function has_valid_coords(array $local): bool
{
    if (!(isset($local['lat'], $local['lng'])
        && $local['lat'] !== null
        && $local['lng'] !== null
        && $local['lat'] !== ''
        && $local['lng'] !== ''
        && is_numeric((string)$local['lat'])
        && is_numeric((string)$local['lng']))) {
        return false;
    }

    $lat = (float)$local['lat'];
    $lng = (float)$local['lng'];
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
        && (abs($lat) > 0.0001 || abs($lng) > 0.0001);
}

function normalize_excel_visit_date($value): array
{
    if ($value === null || trim((string)$value) === '') {
        return ['', ''];
    }

    try {
        if (is_numeric($value)) {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
            return [$date->format('d-m-Y'), $date->format('Y-m-d')];
        }

        $raw = trim((string)$value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'm/d/Y', 'm/d/y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            $dateErrors = DateTimeImmutable::getLastErrors();
            $dateIsValid = $dateErrors === false
                || ((int)($dateErrors['warning_count'] ?? 0) === 0 && (int)($dateErrors['error_count'] ?? 0) === 0);
            if ($date instanceof DateTimeImmutable && $dateIsValid) {
                return [$date->format('d-m-Y'), $date->format('Y-m-d')];
            }
        }
    } catch (Throwable $e) {
        // La validación de fila informará la fecha inválida.
    }

    return [trim((string)$value), ''];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Método no permitido.', 405);
}

$idDivisionRuta = (int)($_POST['id_division'] ?? 0);

if ($idDivisionRuta <= 0) {
    json_fail('Debes seleccionar una división para cargar los locales.');
}

// Validar que la división exista y esté activa
$stmtDivision = $conn->prepare("
    SELECT id, nombre
    FROM division_empresa
    WHERE id = ?
      AND estado = 1
    LIMIT 1
");

if (!$stmtDivision) {
    json_fail('Error al validar la división: ' . $conn->error, 500);
}

$stmtDivision->bind_param('i', $idDivisionRuta);
$stmtDivision->execute();
$resDivision = $stmtDivision->get_result();
$divisionData = $resDivision ? $resDivision->fetch_assoc() : null;
$stmtDivision->close();

if (!$divisionData) {
    json_fail('La división seleccionada no existe o no está activa.');
}

$divisionNombreRuta = (string)($divisionData['nombre'] ?? '');

// Un usuario normal sólo puede consultar locales de su división. MC conserva
// la selección global que ya ofrece la interfaz.
$divisionSesionId = (int)($_SESSION['division_id'] ?? 0);
$divisionSesionNombre = '';
if ($divisionSesionId > 0) {
    $stmtSesion = $conn->prepare('SELECT nombre FROM division_empresa WHERE id=? LIMIT 1');
    if ($stmtSesion) {
        $stmtSesion->bind_param('i', $divisionSesionId);
        $stmtSesion->execute();
        $stmtSesion->bind_result($divisionSesionNombre);
        $stmtSesion->fetch();
        $stmtSesion->close();
    }
}
$esMc = strtoupper(trim($divisionSesionNombre)) === 'MC';
if (!$esMc && $divisionSesionId > 0 && $idDivisionRuta !== $divisionSesionId) {
    json_fail('No tienes permiso para cargar locales de otra división.', 403);
}

$modoCarga = trim((string)($_POST['modo'] ?? 'locales'));
$esOrdenCliente = $modoCarga === 'orden_cliente';
$fileField = $esOrdenCliente ? 'ordenFile' : 'csvFile';

if (!isset($_FILES[$fileField]) || !is_array($_FILES[$fileField])) {
    json_fail($esOrdenCliente ? 'No se recibió el archivo Excel con el orden del cliente.' : 'No se recibió el archivo CSV.');
}

$file = $_FILES[$fileField];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_fail('Error al subir el archivo.');
}

$tmpPath = $file['tmp_name'] ?? '';

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    json_fail('Archivo temporal inválido.');
}

$rows = [];

if ($esOrdenCliente) {
    $originalName = (string)($file['name'] ?? 'orden_cliente.xlsx');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls'], true)) {
        json_fail('El orden del cliente debe cargarse en formato Excel .xlsx o .xls.');
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
        $sheet = $spreadsheet->sheetNameExists('Rutas')
            ? $spreadsheet->getSheetByName('Rutas')
            : $spreadsheet->getActiveSheet();
        $excelRows = $sheet ? $sheet->toArray(null, true, false, false) : [];
    } catch (Throwable $e) {
        json_fail('No se pudo leer el Excel: ' . $e->getMessage());
    }

    if (count($excelRows) < 2) {
        json_fail('La hoja Rutas no contiene registros para procesar.');
    }
    $headers = array_map('strval', array_shift($excelRows));
    $idxCodigo = find_header_index($headers, ['codigo local', 'codigo', 'codigo_local', 'local', 'cod_local']);
    $idxUsuario = find_header_index($headers, ['usuario', 'user', 'ejecutor', 'merchan']);
    $idxFecha = find_header_index($headers, ['fecha visita', 'fecha_visita', 'fecha ruta', 'fecha planificada', 'fecha']);
    $idxOrden = find_header_index($headers, ['orden visita', 'orden_visita', 'orden']);
    if ($idxCodigo < 0 || $idxUsuario < 0 || $idxFecha < 0 || $idxOrden < 0) {
        json_fail('El Excel debe contener las columnas CODIGO LOCAL, USUARIO, FECHA VISITA y ORDEN VISITA.');
    }

    foreach ($excelRows as $index => $data) {
        $codigo = trim((string)($data[$idxCodigo] ?? ''));
        $usuarioInput = trim((string)($data[$idxUsuario] ?? ''));
        $fechaValue = $data[$idxFecha] ?? '';
        $ordenVisita = trim((string)($data[$idxOrden] ?? ''));
        if ($codigo === '' && $usuarioInput === '' && trim((string)$fechaValue) === '' && $ordenVisita === '') {
            continue;
        }
        [$fechaVisita, $fechaVisitaSql] = normalize_excel_visit_date($fechaValue);
        $rows[] = [
            'fila_csv' => $index + 2,
            'codigo' => $codigo,
            'usuario_input' => $usuarioInput,
            'fecha_visita' => $fechaVisita,
            'fecha_visita_sql' => $fechaVisitaSql,
            'orden_visita' => is_numeric($ordenVisita) ? (int)$ordenVisita : 0,
        ];
    }
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
} else {
    $handle = fopen($tmpPath, 'r');
    if (!$handle) {
        json_fail('No se pudo abrir el archivo CSV.');
    }
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        json_fail('El archivo CSV está vacío.');
    }
    $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
    rewind($handle);
    $delimiters = [',', ';', "\t"];
    $bestDelimiter = ',';
    $bestCount = -1;
    foreach ($delimiters as $delimiter) {
        $count = count(str_getcsv($firstLine, $delimiter));
        if ($count > $bestCount) {
            $bestCount = $count;
            $bestDelimiter = $delimiter;
        }
    }
    $headers = fgetcsv($handle, 0, $bestDelimiter);
    if ($headers === false || empty($headers)) {
        fclose($handle);
        json_fail('No se pudo leer la cabecera del CSV.');
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $idxCodigo = find_header_index($headers, ['codigo', 'codigo_local', 'local', 'cod_local']);
    $idxUsuario = find_header_index($headers, ['usuario', 'user', 'ejecutor', 'merchan']);
    if ($idxCodigo < 0 || $idxUsuario < 0) {
        fclose($handle);
        json_fail('El CSV debe contener las columnas "codigo" y "usuario".');
    }
    $rowNumber = 1;
    while (($data = fgetcsv($handle, 0, $bestDelimiter)) !== false) {
        $rowNumber++;
        if ($data === [null] || $data === false) {
            continue;
        }
        $codigo = trim((string)($data[$idxCodigo] ?? ''));
        $usuarioInput = trim((string)($data[$idxUsuario] ?? ''));
        if ($codigo === '' && $usuarioInput === '') {
            continue;
        }
        $rows[] = [
            'fila_csv' => $rowNumber,
            'codigo' => $codigo,
            'usuario_input' => $usuarioInput,
        ];
    }
    fclose($handle);
}

if (empty($rows)) {
    json_fail($esOrdenCliente ? 'El Excel no contiene filas válidas.' : 'El CSV no contiene filas válidas.');
}

// Obtener códigos únicos y usuarios únicos
$codigos = [];
$usuariosInput = [];

foreach ($rows as $row) {
    if ($row['codigo'] !== '') {
        $codigos[$row['codigo']] = true;
    }

    if ($row['usuario_input'] !== '') {
        $usuariosInput[$row['usuario_input']] = true;
    }
}

$codigos = array_keys($codigos);
$usuariosInput = array_keys($usuariosInput);

// Buscar locales por código + división
$localesMap = [];

if (!empty($codigos)) {
    foreach (array_chunk($codigos, 1000) as $codigoChunk) {
    $placeholders = implode(',', array_fill(0, count($codigoChunk), '?'));

    $types = str_repeat('s', count($codigoChunk)) . 'i';
    $params = $codigoChunk;
    $params[] = $idDivisionRuta;

    $sqlLocales = "
        SELECT
            l.id AS id_local,
            l.codigo,
            l.nombre,
            l.direccion,
            l.direccion_original,
            l.direccion_google,
            l.google_response_id,
            l.direccion_validada_at,
            c.comuna,
            l.lat,
            l.lng,
            l.id_division,
            d.nombre AS division_nombre
        FROM local l
        LEFT JOIN comuna c
            ON c.id = l.id_comuna
        LEFT JOIN division_empresa d
            ON d.id = l.id_division
        WHERE l.codigo IN ($placeholders)
          AND l.id_division = ?
          AND l.deleted_at IS NULL
    ";

    $stmtLocales = $conn->prepare($sqlLocales);

    if (!$stmtLocales) {
        json_fail('Error al preparar consulta de locales: ' . $conn->error, 500);
    }

    $stmtLocales->bind_param($types, ...$params);

    if (!$stmtLocales->execute()) {
        $msg = $stmtLocales->error;
        $stmtLocales->close();
        json_fail('Error al ejecutar consulta de locales: ' . $msg, 500);
    }

    $resLocales = $stmtLocales->get_result();

    while ($row = $resLocales->fetch_assoc()) {
        $codigo = trim((string)$row['codigo']);

        $localesMap[normalize_text($codigo)] = [
            'id_local'        => (int)$row['id_local'],
            'codigo'          => $codigo,
            'nombre'          => $row['nombre'] ?? '',
            'direccion'       => $row['direccion'] ?? '',
            'direccion_original' => $row['direccion_original'] ?? '',
            'direccion_google' => $row['direccion_google'] ?? '',
            'estado_address_validation' => '',
            'google_response_id' => $row['google_response_id'] ?? '',
            'direccion_validada_at' => $row['direccion_validada_at'] ?? null,
            'comuna'          => $row['comuna'] ?? '',
            'lat'             => $row['lat'],
            'lng'             => $row['lng'],
            'id_division'     => (int)$row['id_division'],
            'division_nombre' => $row['division_nombre'] ?? '',
        ];
    }

    $stmtLocales->close();
    }
}

// Las coordenadas existentes siempre tienen prioridad. Address Validation sólo
// se usa como respaldo para locales sin un punto geográfico utilizable.
$apiKey = etlLoadGoogleMapsApiKey();
$validationDeadline = microtime(true) + 130;
$maxGoogleValidations = 40;
$googleAttempts = 0;

foreach ($localesMap as &$local) {
    $local['coordenadas_origen'] = has_valid_coords($local) ? 'SQL' : 'SIN_COORDENADAS';
    $local['motivo_validacion'] = has_valid_coords($local)
        ? 'Se utilizaron las coordenadas guardadas en SQL.'
        : '';

    if (has_valid_coords($local)) {
        continue;
    }

    if ($apiKey === '') {
        $local['motivo_validacion'] = 'No hay una API key configurada para Address Validation.';
        continue;
    }

    $lastValidation = trim((string)($local['direccion_validada_at'] ?? ''));
    if ($lastValidation !== '' && strtotime($lastValidation) >= strtotime('-30 days')) {
        $local['motivo_validacion'] = 'La dirección ya fue revisada recientemente y Google no entregó coordenadas utilizables.';
        continue;
    }

    if ($googleAttempts >= $maxGoogleValidations || microtime(true) >= $validationDeadline) {
        $local['motivo_validacion'] = 'Validación pendiente por límite de proceso; vuelve a cargar el archivo para continuar.';
        continue;
    }

    $cleanAddress = etlCleanAddress($local['direccion'], $local['comuna']);
    if ($cleanAddress === '') {
        $local['motivo_validacion'] = 'El local no tiene una dirección que se pueda validar.';
        continue;
    }

    $googleAttempts++;
    $validation = etlValidateAddress(
        $cleanAddress,
        (string)$local['comuna'],
        $apiKey,
        (int)$local['id_local']
    );

    $local['estado_address_validation'] = (string)($validation['status'] ?? 'RECHAZADA');
    $local['motivo_validacion'] = (string)($validation['reason'] ?? 'Sin detalle de validación.');
    $formattedAddress = etlUpper($validation['formatted_address'] ?? '');
    $responseId = trim((string)($validation['response_id'] ?? ''));
    $latGoogle = $validation['lat'] ?? null;
    $lngGoogle = $validation['lng'] ?? null;
    $googleHasCoords = is_numeric($latGoogle) && is_numeric($lngGoogle)
        && has_valid_coords(['lat' => $latGoogle, 'lng' => $lngGoogle]);

    if ($googleHasCoords && in_array($local['estado_address_validation'], ['ACEPTADA', 'REVISION'], true)) {
        $latGoogle = (float)$latGoogle;
        $lngGoogle = (float)$lngGoogle;
        $stmtUpdate = $conn->prepare("
            UPDATE local
            SET direccion_original = CASE
                    WHEN direccion_original IS NULL OR direccion_original = '' THEN direccion
                    ELSE direccion_original
                END,
                direccion_google = ?,
                google_response_id = ?,
                direccion_validada_at = NOW(),
                lat = ?,
                lng = ?,
                updated_at = NOW()
            WHERE id = ? AND id_division = ?
            LIMIT 1
        ");
        if ($stmtUpdate) {
            $idLocalUpdate = (int)$local['id_local'];
            $stmtUpdate->bind_param('ssddii', $formattedAddress, $responseId, $latGoogle, $lngGoogle, $idLocalUpdate, $idDivisionRuta);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        }

        $local['direccion_google'] = $formattedAddress;
        $local['google_response_id'] = $responseId;
        $local['lat'] = $latGoogle;
        $local['lng'] = $lngGoogle;
        $local['coordenadas_origen'] = 'ADDRESS_VALIDATION';
    } elseif ($responseId !== '') {
        // Registrar la revisión evita cobrar repetidamente por la misma dirección rechazada.
        $stmtRejected = $conn->prepare("
            UPDATE local
            SET direccion_original = CASE
                    WHEN direccion_original IS NULL OR direccion_original = '' THEN direccion
                    ELSE direccion_original
                END,
                direccion_google = ?,
                google_response_id = ?,
                direccion_validada_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND id_division = ?
            LIMIT 1
        ");
        if ($stmtRejected) {
            $idLocalRejected = (int)$local['id_local'];
            $stmtRejected->bind_param('ssii', $formattedAddress, $responseId, $idLocalRejected, $idDivisionRuta);
            $stmtRejected->execute();
            $stmtRejected->close();
        }
        $local['direccion_google'] = $formattedAddress;
    }
}
unset($local);

// Resolver usuarios activos sólo como enriquecimiento opcional. Un responsable
// escrito en el archivo no necesita existir todavía en la tabla usuario.
$usuariosMap = [];

if (!empty($usuariosInput)) {
    $sqlUsuarios = "
        SELECT
            u.id,
            u.usuario,
            u.nombre,
            u.apellido,
            u.activo
        FROM usuario u
        WHERE u.activo = 1
    ";

    $resUsuarios = $conn->query($sqlUsuarios);

    if (!$resUsuarios) {
        json_fail('Error al consultar usuarios: ' . $conn->error, 500);
    }

    while ($u = $resUsuarios->fetch_assoc()) {
        $id = (int)($u['id'] ?? 0);
        $login = trim((string)($u['usuario'] ?? ''));
        $nombre = trim((string)($u['nombre'] ?? ''));
        $apellido = trim((string)($u['apellido'] ?? ''));
        $nombreCompleto = trim($nombre . ' ' . $apellido);

        $payload = [
            'usuario_id' => $id,
            'usuario_login' => $login,
            'usuario_nombre' => $nombreCompleto,
        ];

        if ($login !== '') {
            $usuariosMap[normalize_text($login)] = $payload;
        }

        if ($id > 0) {
            $usuariosMap[(string)$id] = $payload;
        }

        if ($nombreCompleto !== '') {
            $usuariosMap[normalize_text($nombreCompleto)] = $payload;
        }
    }
}

$encontradosValidos = [];
$localesNoEncontrados = [];
$responsablesSinRegistro = [];
$filasInvalidas = [];
$coordenadasSql = 0;
$validadosGoogle = 0;
$sinCoordenadas = 0;

// La planificacion mensual necesita conocer la ultima fecha propuesta de cada
// local para no volver a programarlo antes de cumplir la frecuencia definida.
$ultimasFechasPropuesta = [];
$idsLocalesConsulta = [];
foreach ($localesMap as $localItem) {
    $idLocalConsulta = (int)($localItem['id_local'] ?? 0);
    if ($idLocalConsulta > 0) {
        $idsLocalesConsulta[$idLocalConsulta] = true;
    }
}

foreach (array_chunk(array_keys($idsLocalesConsulta), 1000) as $idsChunk) {
    if (empty($idsChunk)) {
        continue;
    }

    $placeholdersFechas = implode(',', array_fill(0, count($idsChunk), '?'));
    $typesFechas = str_repeat('i', count($idsChunk));
    $sqlFechas = "
        SELECT fq.id_local, MAX(fq.fechaPropuesta) AS ultima_fecha_propuesta
        FROM formularioQuestion fq
        WHERE fq.id_local IN ($placeholdersFechas)
          AND fq.fechaPropuesta IS NOT NULL
        GROUP BY fq.id_local
    ";
    $stmtFechas = $conn->prepare($sqlFechas);
    if (!$stmtFechas) {
        json_fail('Error al preparar la consulta de ultimas planificaciones: ' . $conn->error, 500);
    }
    $stmtFechas->bind_param($typesFechas, ...$idsChunk);
    if (!$stmtFechas->execute()) {
        $msg = $stmtFechas->error;
        $stmtFechas->close();
        json_fail('Error al consultar las ultimas planificaciones: ' . $msg, 500);
    }
    $resFechas = $stmtFechas->get_result();
    while ($fechaRow = $resFechas->fetch_assoc()) {
        $fechaSql = substr((string)($fechaRow['ultima_fecha_propuesta'] ?? ''), 0, 10);
        if ($fechaSql !== '') {
            $ultimasFechasPropuesta[(int)$fechaRow['id_local']] = $fechaSql;
        }
    }
    $stmtFechas->close();
}

$ordenesRutaVistos = [];
$localesRutaVistos = [];
foreach ($rows as $row) {
    $codigo = $row['codigo'];
    $usuarioInput = $row['usuario_input'];
    $filaCsv = $row['fila_csv'];
    $fechaVisita = (string)($row['fecha_visita'] ?? '');
    $fechaVisitaSql = (string)($row['fecha_visita_sql'] ?? '');
    $ordenVisita = (int)($row['orden_visita'] ?? 0);

    $errores = [];

    if ($codigo === '') {
        $errores[] = 'Código vacío';
    }

    if ($usuarioInput === '') {
        $errores[] = 'Usuario vacío';
    }

    if ($esOrdenCliente && $fechaVisitaSql === '') {
        $errores[] = 'Fecha de visita vacía o inválida';
    }

    if ($esOrdenCliente && $ordenVisita <= 0) {
        $errores[] = 'Orden de visita debe ser mayor que cero';
    }

    $local = $localesMap[normalize_text($codigo)] ?? null;

    if ($codigo !== '' && !$local) {
        $errores[] = 'Código de local no existe para la división seleccionada';
    }

    if ($esOrdenCliente && !$errores) {
        $routeKey = normalize_text($usuarioInput) . '|' . $fechaVisitaSql;
        $orderKey = $routeKey . '|' . $ordenVisita;
        $localRouteKey = $routeKey . '|' . normalize_text($codigo);
        if (isset($ordenesRutaVistos[$orderKey])) {
            $errores[] = 'Orden de visita duplicado para el mismo usuario y fecha';
        }
        if (isset($localesRutaVistos[$localRouteKey])) {
            $errores[] = 'Local duplicado para el mismo usuario y fecha';
        }
        if (!$errores) {
            $ordenesRutaVistos[$orderKey] = true;
            $localesRutaVistos[$localRouteKey] = true;
        }
    }

    $usuarioMatch = null;

    if ($usuarioInput !== '') {
        $keyNorm = normalize_text($usuarioInput);

        if (isset($usuariosMap[$keyNorm])) {
            $usuarioMatch = $usuariosMap[$keyNorm];
        } elseif (isset($usuariosMap[(string)$usuarioInput])) {
            $usuarioMatch = $usuariosMap[(string)$usuarioInput];
        }
    }

    if (!empty($errores)) {
        $filaError = [
            'fila_csv' => $filaCsv,
            'codigo' => $codigo,
            'usuario_input' => $usuarioInput,
            'id_division' => $idDivisionRuta,
            'division_nombre' => $divisionNombreRuta,
            'motivo' => implode(' | ', $errores),
        ];

        $filasInvalidas[] = $filaError;

        if ($codigo !== '' && !$local) {
            $localesNoEncontrados[] = [
                'fila_csv' => $filaCsv,
                'codigo' => $codigo,
                'usuario_input' => $usuarioInput,
                'id_division' => $idDivisionRuta,
                'division_nombre' => $divisionNombreRuta,
                'motivo' => 'Código no existe en local para la división seleccionada',
            ];
        }

        continue;
    }

    if (!$usuarioMatch) {
        $usuarioMatch = [
            'usuario_id' => '',
            'usuario_login' => $usuarioInput,
            'usuario_nombre' => $usuarioInput,
        ];
        $responsablesSinRegistro[normalize_text($usuarioInput)] = $usuarioInput;
    }

    $tieneCoords = has_valid_coords($local);
    if (($local['coordenadas_origen'] ?? '') === 'ADDRESS_VALIDATION' && $tieneCoords) {
        $validadosGoogle++;
    } elseif ($tieneCoords) {
        $coordenadasSql++;
    } else {
        $sinCoordenadas++;
    }

    $encontradosValidos[] = [
        'fila_csv'        => $filaCsv,
        'id_local'        => $local['id_local'],
        'codigo'          => $local['codigo'],
        'nombre'          => $local['nombre'],
        'direccion'       => $local['direccion'],
        'direccion_original' => $local['direccion_original'] ?: $local['direccion'],
        'direccion_google' => $local['direccion_google'],
        'comuna'          => $local['comuna'],
        'lat'             => $local['lat'],
        'lng'             => $local['lng'],
        'id_division'     => $local['id_division'],
        'division_nombre' => $local['division_nombre'],
        'tiene_coords'    => $tieneCoords,
        'coordenadas_origen' => $local['coordenadas_origen'],
        'estado_address_validation' => $local['estado_address_validation'],
        'motivo_validacion' => $local['motivo_validacion'],
        'usuario_input'   => $usuarioInput,
        'usuario_id'      => $usuarioMatch['usuario_id'],
        'usuario_login'   => $usuarioMatch['usuario_login'],
        'usuario_nombre'  => $usuarioMatch['usuario_nombre'],
        'fecha_visita'    => $fechaVisita,
        'fecha_visita_sql'=> $fechaVisitaSql,
        'orden_visita'    => $ordenVisita,
        'ultima_fecha_propuesta_sql' => $ultimasFechasPropuesta[(int)$local['id_local']] ?? '',
    ];
}

echo json_encode([
    'success' => true,
    'message' => 'Archivo procesado correctamente.',
    'mode' => $esOrdenCliente ? 'orden_cliente' : 'locales',
    'id_division' => $idDivisionRuta,
    'division_nombre' => $divisionNombreRuta,
    'total_csv' => count($rows),
    'encontrados' => $encontradosValidos,
    'no_encontrados' => $localesNoEncontrados,
    // Se conserva la clave por compatibilidad con clientes anteriores. Desde
    // ahora un responsable no registrado es válido para planificar rutas.
    'usuarios_no_validos' => [],
    'responsables_sin_registro' => array_values($responsablesSinRegistro),
    'filas_invalidas' => $filasInvalidas,
    'resumen' => [
        'validos' => count($encontradosValidos),
        'locales_no_encontrados' => count($localesNoEncontrados),
        'usuarios_no_validos' => 0,
        'responsables_sin_registro' => count($responsablesSinRegistro),
        'filas_invalidas' => count($filasInvalidas),
        'coordenadas_sql' => $coordenadasSql,
        'validados_google' => $validadosGoogle,
        'sin_coordenadas' => $sinCoordenadas,
        'intentos_address_validation' => $googleAttempts,
    ],
], JSON_UNESCAPED_UNICODE);
exit;
