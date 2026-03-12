<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

ini_set('memory_limit', '1024M');
set_time_limit(0);
date_default_timezone_set('America/Santiago');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

mysqli_set_charset($conn, 'utf8mb4');

function fail(string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(400);
    exit($message);
}

function limpiarNombreArchivo(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '') {
        return 'encuesta';
    }

    $texto = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $texto);
    $texto = preg_replace('/\s+/u', '_', $texto);

    return $texto !== '' ? $texto : 'encuesta';
}

function normalizarBooleanGet(string $key, bool $default = true): bool
{
    if (!isset($_GET[$key])) {
        return $default;
    }

    $value = strtolower(trim((string)$_GET[$key]));
    return in_array($value, ['1', 'true', 'si', 'sí', 'yes'], true);
}

function obtenerFormularioBase(mysqli $conn, int $idForm): array
{
    $sql = "
        SELECT id, nombre, modalidad
        FROM formulario
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando formulario: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        fail('Formulario no encontrado.');
    }

    return $row;
}

function normalizarUrlEncuesta(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    if (
        !preg_match('#(uploads|visibility2|app/|pregunta_)\b#i', $url)
        && !preg_match('#\.(webp|jpe?g|png|gif)(?:\?.*)?$#i', $url)
    ) {
        return $url;
    }

    $url = str_replace('\\', '/', $url);
    $url = ltrim($url, '/');
    $baseUrl = 'https://www.visibility.cl/';

    if (preg_match('#^visibility2/#i', $url)) {
        return $baseUrl . $url;
    }
    if (preg_match('#^app/#i', $url)) {
        return $baseUrl . 'visibility2/' . $url;
    }
    if (preg_match('#^uploads_fotos_pregunta/#i', $url)) {
        return $baseUrl . 'visibility2/app/uploads/' . $url;
    }
    if (preg_match('#^uploads/#i', $url)) {
        return $baseUrl . 'visibility2/app/' . $url;
    }

    return $baseUrl . 'visibility2/app/' . $url;
}

function getEncuestaPivot(mysqli $conn, int $idForm): array
{
    $allQuestions = [];

    $qry = "
        SELECT question_text
        FROM form_questions
        WHERE id_formulario = ?
        ORDER BY sort_order
    ";

    $stmtQ = $conn->prepare($qry);
    if (!$stmtQ) {
        fail('Error preparando preguntas de encuesta: ' . $conn->error);
    }

    $stmtQ->bind_param('i', $idForm);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();

    while ($rQ = $resQ->fetch_assoc()) {
        $allQuestions[] = (string)$rQ['question_text'];
    }

    $stmtQ->close();

    $sql = "
        SELECT
            f.id AS id_campana,
            ANY_VALUE(UPPER(f.nombre)) AS nombre_campana,
            l.codigo AS codigo_local,
            ANY_VALUE(
                CASE
                    WHEN l.nombre REGEXP '^[0-9]+'
                        THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
                    ELSE ''
                END
            ) AS numero_local,
            ANY_VALUE(UPPER(l.nombre)) AS nombre_local,
            ANY_VALUE(UPPER(l.direccion)) AS direccion_local,
            ANY_VALUE(UPPER(cu.nombre)) AS cuenta,
            ANY_VALUE(UPPER(ca.nombre)) AS cadena,
            ANY_VALUE(UPPER(cm.comuna)) AS comuna,
            ANY_VALUE(UPPER(re.region)) AS region,
            ANY_VALUE(UPPER(u.usuario)) AS usuario,
            DATE(fqr.created_at) AS fecha_visita,
            fp.question_text AS question_text,
            UPPER(
                GROUP_CONCAT(
                    fqr.answer_text
                    ORDER BY fqr.id
                    SEPARATOR '; '
                )
            ) AS concat_answers,
            GROUP_CONCAT(
                CASE WHEN fqr.valor <> '0.00' THEN fqr.valor END
                ORDER BY fqr.id
                SEPARATOR '; '
            ) AS concat_valores
        FROM formulario f
        JOIN form_questions fp           ON fp.id_formulario = f.id
        JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
        JOIN usuario u                   ON u.id = fqr.id_usuario
        JOIN local l                     ON l.id = fqr.id_local
        JOIN cuenta cu                   ON cu.id = l.id_cuenta
        JOIN cadena ca                   ON ca.id = l.id_cadena
        JOIN comuna cm                   ON cm.id = l.id_comuna
        JOIN region re                   ON re.id = cm.id_region
        WHERE f.id = ?
        GROUP BY l.codigo, DATE(fqr.created_at), fp.question_text
        ORDER BY l.codigo ASC, fp.question_text ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando getEncuestaPivot: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();

    $grouped = [];

    while ($row = $res->fetch_assoc()) {
        $key = $row['codigo_local'] . '_' . $row['fecha_visita'];

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'ID CAMPAÑA'     => $row['id_campana'],
                'NOMBRE CAMPAÑA' => $row['nombre_campana'],
                'CUENTA'         => $row['cuenta'],
                'CADENA'         => $row['cadena'],
                'CODIGO LOCAL'   => $row['codigo_local'],
                'N° LOCAL'       => $row['numero_local'],
                'LOCAL'          => $row['nombre_local'],
                'DIRECCION'      => $row['direccion_local'],
                'COMUNA'         => $row['comuna'],
                'REGION'         => $row['region'],
                'USUARIO'        => $row['usuario'],
                'FECHA VISITA'   => $row['fecha_visita'],
                'questions'      => []
            ];
        }

        $grouped[$key]['questions'][(string)$row['question_text']] = [
            'answer' => $row['concat_answers'] ?? '',
            'valor'  => $row['concat_valores'] ?? ''
        ];
    }

    $stmt->close();

    $final = [];

    foreach ($grouped as $g) {
        $rowOut = [
            'ID CAMPAÑA'     => $g['ID CAMPAÑA'],
            'NOMBRE CAMPAÑA' => $g['NOMBRE CAMPAÑA'],
            'CUENTA'         => $g['CUENTA'],
            'CADENA'         => $g['CADENA'],
            'CODIGO LOCAL'   => $g['CODIGO LOCAL'],
            'N° LOCAL'       => $g['N° LOCAL'],
            'LOCAL'          => $g['LOCAL'],
            'DIRECCION'      => $g['DIRECCION'],
            'COMUNA'         => $g['COMUNA'],
            'REGION'         => $g['REGION'],
            'USUARIO'        => $g['USUARIO'],
            'FECHA VISITA'   => $g['FECHA VISITA']
        ];

        foreach ($allQuestions as $q) {
            if (isset($g['questions'][$q])) {
                $answer = (string)$g['questions'][$q]['answer'];
                $parts = array_map('trim', explode(';', $answer));
                $normalizedParts = [];

                foreach ($parts as $part) {
                    if ($part === '') {
                        continue;
                    }
                    $normalizedParts[] = normalizarUrlEncuesta($part);
                }

                $rowOut[$q] = implode('; ', $normalizedParts);
                $rowOut[$q . '_valor'] = (string)$g['questions'][$q]['valor'];
            } else {
                $rowOut[$q] = '';
                $rowOut[$q . '_valor'] = '';
            }
        }

        $final[] = $rowOut;
    }

    $valorCols = [];
    foreach ($final as $r) {
        foreach ($r as $c => $v) {
            if (strpos((string)$c, '_valor') !== false) {
                $valorCols[$c] = $valorCols[$c] ?? false;
                if (trim((string)$v) !== '') {
                    $valorCols[$c] = true;
                }
            }
        }
    }

    foreach ($final as &$r) {
        foreach ($valorCols as $c => $has) {
            if (!$has) {
                unset($r[$c]);
            }
        }
    }
    unset($r);

    return $final;
}

function removerFotosDeEncuesta(array $encuesta): array
{
    $esUrlFoto = static function (string $valor): bool {
        $v = trim($valor);
        if ($v === '') {
            return false;
        }

        return preg_match('#https?://#i', $v)
            || preg_match('#\.(?:jpe?g|png|gif|webp)(?:\?.*)?$#i', $v)
            || preg_match('#\buploads#i', $v)
            || preg_match('#\bvisibility2\b#i', $v);
    };

    foreach ($encuesta as &$fila) {
        foreach ($fila as $col => $valor) {
            $valorStr = (string)($valor ?? '');
            if ($valorStr === '') {
                continue;
            }

            $partsFiltradas = [];
            foreach (preg_split('/\s*;\s*/', $valorStr) as $parte) {
                if ($parte === '') {
                    continue;
                }
                if ($esUrlFoto($parte)) {
                    continue;
                }
                $partsFiltradas[] = $parte;
            }

            $fila[$col] = $partsFiltradas ? implode('; ', $partsFiltradas) : '';
        }
    }
    unset($fila);

    return $encuesta;
}

function exportarEncuestaCsv(array $encuesta, string $archivo): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $downloadToken = $_GET['download_token'] ?? '';
    if ($downloadToken !== '') {
        setcookie('fileDownloadToken', $downloadToken, 0, '/');
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        fail('No fue posible generar el archivo CSV.');
    }

    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if (empty($encuesta)) {
        fputcsv($output, ['SIN DATOS'], ';');
        fclose($output);
        exit;
    }

    $headers = array_keys($encuesta[0]);
    fputcsv($output, $headers, ';');

    foreach ($encuesta as $fila) {
        $row = [];
        foreach ($headers as $header) {
            $row[] = (string)($fila[$header] ?? '');
        }
        fputcsv($output, $row, ';');
    }

    fclose($output);
    exit;
}

$formularioId = isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)
    ? (int)$_GET['id']
    : 0;

if ($formularioId <= 0) {
    fail('ID de formulario inválido.');
}

$incluirFotosEncuesta = normalizarBooleanGet('fotos_encuesta', true);

$formularioBase = obtenerFormularioBase($conn, $formularioId);
$nombreForm = (string)$formularioBase['nombre'];

$encuestaPivot = getEncuestaPivot($conn, $formularioId);

if (!$incluirFotosEncuesta && !empty($encuestaPivot)) {
    $encuestaPivot = removerFotosDeEncuesta($encuestaPivot);
}

if (empty($encuestaPivot)) {
    fail('No se encontraron datos de encuesta para la campaña.');
}

$nombreCampana = limpiarNombreArchivo($nombreForm);
$archivo = 'Encuesta_' . $nombreCampana . '_' . date('Y-m-d_His') . '.csv';

exportarEncuestaCsv($encuestaPivot, $archivo);