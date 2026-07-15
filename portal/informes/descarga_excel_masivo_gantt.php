<?php
// descargar_excel_masivo.php (HOMOLOGADO a descargar_excel.php en orden de columnas)

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Limpia cualquier buffer previo
while (ob_get_level()) { ob_end_clean(); }
ob_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(0);

// Conexión
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
if (function_exists('mysqli_set_charset')) {
    mysqli_set_charset($conn, 'utf8mb4');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function fail(string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/plain; charset=UTF-8');
    http_response_code(500);
    exit($message);
}

/**
 * Helper seguro (igual al usado en la descarga individual)
 */
if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// -----------------------------------------------------------------------------
// Validación de parámetros
// -----------------------------------------------------------------------------
$inline               = isset($_GET['inline']) && $_GET['inline'] === '1';
$incluirFotosMaterial = !isset($_GET['fotos'])
    || $_GET['fotos'] === '1'
    || strtolower((string)$_GET['fotos']) === 'true';
$incluirFotosEncuesta = !isset($_GET['fotos_encuesta'])
    || $_GET['fotos_encuesta'] === '1'
    || strtolower((string)$_GET['fotos_encuesta']) === 'true';

if (isset($_GET['activas']) && (string)$_GET['activas'] === '1') {
    $idEmpresaActiva = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : 0;
    $idDivisionActiva = isset($_GET['id_division']) ? (int)$_GET['id_division'] : 0;
    $idSubdivisionActiva = isset($_GET['id_subdivision']) ? (int)$_GET['id_subdivision'] : 0;
    $idTradeActiva = isset($_GET['id_trade']) ? (int)$_GET['id_trade'] : 0;
    $idCategoriaFormularioActiva = isset($_GET['id_categoria_formulario']) ? (int)$_GET['id_categoria_formulario'] : 0;

    if ($idEmpresaActiva <= 0 || $idDivisionActiva <= 0) {
        die('Para descargar campaÃ±as activas debes enviar id_empresa e id_division.');
    }

    $sqlIdsActivas = "
        SELECT f.id
        FROM formulario f
        INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
        WHERE f.estado = 1
          AND f.id_empresa = ?
          AND f.id_division = ?
    ";
    $typesIdsActivas = 'ii';
    $paramsIdsActivas = [$idEmpresaActiva, $idDivisionActiva];

    if ($idSubdivisionActiva > 0) {
        $sqlIdsActivas .= " AND f.id_subdivision = ? ";
        $typesIdsActivas .= 'i';
        $paramsIdsActivas[] = $idSubdivisionActiva;
    }

    if ($idTradeActiva > 0) {
        $sqlIdsActivas .= " AND f.id_trade = ? ";
        $typesIdsActivas .= 'i';
        $paramsIdsActivas[] = $idTradeActiva;
    } elseif ($idTradeActiva === -1) {
        $sqlIdsActivas .= " AND (f.id_trade IS NULL OR f.id_trade <= 0) ";
    }

    if ($idCategoriaFormularioActiva > 0) {
        $sqlIdsActivas .= " AND f.id_categoria_formulario = ? ";
        $typesIdsActivas .= 'i';
        $paramsIdsActivas[] = $idCategoriaFormularioActiva;
    } elseif ($idCategoriaFormularioActiva === -1) {
        $sqlIdsActivas .= " AND (f.id_categoria_formulario IS NULL OR f.id_categoria_formulario <= 0) ";
    }

    $sqlIdsActivas .= "
        GROUP BY f.id
        ORDER BY MAX(f.fechaInicio) DESC
    ";

    $stmtIdsActivas = $conn->prepare($sqlIdsActivas);
    if (!$stmtIdsActivas) {
        fail('Error preparando campaÃ±as activas: ' . $conn->error);
    }

    $stmtIdsActivas->bind_param($typesIdsActivas, ...$paramsIdsActivas);
    $stmtIdsActivas->execute();
    $resIdsActivas = $stmtIdsActivas->get_result();

    $idsActivas = [];
    while ($rowIdsActivas = $resIdsActivas->fetch_assoc()) {
        $idsActivas[] = (int)$rowIdsActivas['id'];
    }
    $stmtIdsActivas->close();

    $_GET['ids'] = implode(',', array_unique(array_filter($idsActivas)));
}

if (!isset($_GET['ids'])) {
    die('No se recibieron campañas para descargar.');
}

$raw = $_GET['ids'];
if (is_array($raw)) {
    $ids = array_map('intval', $raw);
} else {
    $tokens = preg_split('/[,\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
    $ids    = array_map('intval', $tokens);
}

$ids = array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
if (empty($ids)) {
    die('Lista de campañas inválida.');
}

// -----------------------------------------------------------------------------
// Funciones compartidas con la descarga individual
// -----------------------------------------------------------------------------

function renderValorConImagen(string $valor, bool $inline): string {
    $vs = trim($valor);
    if ($vs === '') {
        return '';
    }

    $parts = preg_split('/\s*;\s*/', $vs);

    if ($inline) {
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') continue;

            if (!preg_match('#^https?://#i', $p)) {
                $out[] = e($p);
                continue;
            }

            $safe  = e($p);
            $out[] = '<a href="' . $safe . '" target="_blank">'
                   . '<img class="inline-img" src="' . $safe . '" '
                   . "data-toggle=\"modal\" data-target=\"#imgModal\" data-src=\"{$safe}\">"
                   . '</a>';
        }

        return $out ? implode('<br>', $out) : e($vs);
    }

    $out = [];
    foreach ($parts as $p) {
        if ($p === '') continue;

        if (preg_match('#^https?://#i', $p)) {
            $safe  = e($p);
            $out[] = '<a href="' . $safe . '">' . $safe . '</a>';
        } else {
            $out[] = e($p);
        }
    }

    return $out ? implode('; ', $out) : e($vs);
}

function getFormularioMeta(int $idForm): array {
    global $conn;

    $stmt = $conn->prepare('SELECT nombre, modalidad FROM formulario WHERE id = ? LIMIT 1');
    if (!$stmt) {
        fail('Error preparando getFormularioMeta: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        fail('Error ejecutando getFormularioMeta: ' . $err);
    }

    $stmt->bind_result($nombre, $modalidad);

    if (!$stmt->fetch()) {
        $stmt->close();
        return [];
    }

    $stmt->close();

    return [
        'nombre'    => $nombre,
        'modalidad' => $modalidad
    ];
}

function getCampaignData(int $idForm): array {
    global $conn;

    $sql = "
        SELECT
            f.id,
            f.nombre,

            CASE
                WHEN f.fechaInicio IS NULL
                     OR CAST(f.fechaInicio AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(f.fechaInicio)
            END AS fechaInicio,

            CASE
                WHEN f.fechaTermino IS NULL
                     OR CAST(f.fechaTermino AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(f.fechaTermino)
            END AS fechaTermino,

            f.modalidad AS modalidad,
            f.tipo,
            e.nombre AS nombre_empresa,
            de.nombre AS nombre_division,

            COUNT(DISTINCT l.codigo) AS locales_programados,

            COUNT(DISTINCT CASE
                WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria',
                    'local_cerrado','no_permitieron'
                )
                AND fq.fechaVisita IS NOT NULL
                AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
                THEN l.id
            END) AS locales_visitados,

            COUNT(DISTINCT CASE
                WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria'
                )
                THEN l.id
            END) AS locales_implementados
        FROM formulario f
        INNER JOIN empresa e             ON e.id = f.id_empresa
        LEFT JOIN division_empresa de    ON de.id = f.id_division
        INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
        INNER JOIN local l               ON l.id = fq.id_local
        WHERE f.id = ?
        GROUP BY
            f.id, f.nombre, f.modalidad, f.tipo, e.nombre, de.nombre,
            CASE
                WHEN f.fechaInicio IS NULL
                     OR CAST(f.fechaInicio AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(f.fechaInicio)
            END,
            CASE
                WHEN f.fechaTermino IS NULL
                     OR CAST(f.fechaTermino AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(f.fechaTermino)
            END
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando getCampaignData: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        fail('Error ejecutando getCampaignData: ' . $err);
    }

    $res = $stmt->get_result();
    if ($res === false) {
        $err = $stmt->error;
        $stmt->close();
        fail('Error obteniendo resultado getCampaignData: ' . $err);
    }

    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function getLocalesDetails(int $idForm): array {
    global $conn;

    $sql = "
        SELECT
            l.id AS idLocal,
            fq.id AS id_formularioQuestion,
            l.codigo AS codigo_local,
            CASE
              WHEN l.nombre REGEXP '^[0-9]+'
              THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
              ELSE CAST(l.codigo AS UNSIGNED)
            END AS numero_local,
            f.modalidad AS modalidad,
            UPPER(COALESCE(NULLIF(TRIM(t.nombre), ''), 'SIN TRADE')) AS trade,
            UPPER(f.nombre) AS nombreCampana,
            CASE UPPER(COALESCE(NULLIF(TRIM(f.prioridad_cliente), ''), 'MEDIO'))
                WHEN 'ALTO' THEN 'ALTA'
                WHEN 'ALTA' THEN 'ALTA'
                WHEN 'BAJO' THEN 'BAJA'
                WHEN 'BAJA' THEN 'BAJA'
                ELSE 'MEDIA'
            END AS prioridad,

            CASE
                WHEN f.fechaInicio IS NULL
                     OR CAST(f.fechaInicio AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(f.fechaInicio)
            END AS fechaInicio,

            CASE
                WHEN f.fechaTermino IS NULL
                     OR CAST(f.fechaTermino AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(f.fechaTermino)
            END AS fechaTermino,

            CASE
                WHEN fq.fechaVisita IS NULL
                     OR CAST(fq.fechaVisita AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(fq.fechaVisita)
            END AS fechaVisita,

            CASE
                WHEN rec.fecha_recepcion IS NOT NULL THEN rec.fecha_recepcion
                WHEN rec_form.fecha_recepcion IS NOT NULL THEN rec_form.fecha_recepcion
                ELSE NULL
            END AS fechaRecepcionMaterial,

            CASE
                WHEN fq.fechaVisita IS NULL
                     OR CAST(fq.fechaVisita AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE TIME(fq.fechaVisita)
            END AS hora,

            CASE
                WHEN fq.fechaPropuesta IS NULL
                     OR CAST(fq.fechaPropuesta AS CHAR(10)) = '0000-00-00'
                THEN NULL
                ELSE DATE(fq.fechaPropuesta)
            END AS fechaPropuesta,

            UPPER(l.nombre) AS nombre_local,
            UPPER(l.direccion) AS direccion_local,
            UPPER(cm.comuna) AS comuna,
            UPPER(re.region) AS region,
            UPPER(cu.nombre) AS cuenta,
            UPPER(ca.nombre) AS cadena,
            UPPER(COALESCE(fq.material, '')) AS material,
            UPPER(COALESCE(fq.categoria, '')) AS categoria,
            UPPER(COALESCE(fq.marca, ''))     AS marca,             
            UPPER(COALESCE(jv.nombre, '')) AS jefeVenta,
            COALESCE(fq.valor_propuesto, 0) AS valor_propuesto,
            COALESCE(fq.valor, 0) AS valor,
            UPPER(COALESCE(fq.observacion, '')) AS observacion,

            CASE
                WHEN fq.fechaVisita IS NOT NULL
                     AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
                THEN 'VISITADO'
                ELSE 'NO VISITADO'
            END AS ESTADO_VISTA,

            CASE
                WHEN f.modalidad = 'retiro' THEN
                    CASE
                        WHEN IFNULL(fq.valor, 0) >= 1 THEN 'RETIRADO'
                        WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO RETIRADO'
                        WHEN LOWER(fq.pregunta) = 'solo_implementado' THEN 'RETIRADO'
                        WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'RETIRADO'
                        ELSE 'NO RETIRADO'
                    END
                WHEN f.modalidad = 'entrega' THEN
                    CASE
                        WHEN IFNULL(fq.valor, 0) >= 1 THEN 'ENTREGADO'
                        WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO ENTREGADO'
                        WHEN LOWER(fq.pregunta) = 'solo_implementado' THEN 'ENTREGADO'
                        WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'ENTREGADO'
                        ELSE 'NO ENTREGADO'
                    END
                ELSE
                    CASE
                        WHEN IFNULL(fq.valor, 0) >= 1 THEN 'IMPLEMENTADO'
                        WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO IMPLEMENTADO'
                        WHEN LOWER(fq.pregunta) = 'solo_implementado' THEN 'IMPLEMENTADO'
                        WHEN LOWER(fq.pregunta) IN ('solo_auditado', 'solo_auditoria') THEN 'AUDITORIA'
                        WHEN LOWER(fq.pregunta) = 'retiro' THEN 'RETIRO'
                        WHEN LOWER(fq.pregunta) = 'entrega' THEN 'ENTREGA'
                        WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'IMPLEMENTADO/AUDITADO'
                        ELSE 'NO IMPLEMENTADO'
                    END
            END AS ESTADO_ACTIVIDAD,

            UPPER(
              REPLACE(
                CASE
                  WHEN IFNULL(fq.valor,0) = 0 THEN
                    TRIM(SUBSTRING_INDEX(REPLACE(COALESCE(fq.observacion,''),'|','-'),'-',1))
                  WHEN LOWER(fq.pregunta) IN ('en proceso','cancelado') THEN
                    TRIM(SUBSTRING_INDEX(REPLACE(COALESCE(fq.observacion,''),'|','-'),'-',1))
                  WHEN LOWER(fq.pregunta) IN ('solo_implementado','solo_auditoria') THEN
                    ''
                  ELSE
                    COALESCE(fq.pregunta,'')
                END
              , '_', ' ')
            ) AS MOTIVO,
            UPPER(u.usuario) AS gestionado_por
        FROM formularioQuestion fq
        INNER JOIN formulario   f  ON f.id  = fq.id_formulario
        LEFT  JOIN trade        t  ON t.id  = f.id_trade
        INNER JOIN local        l  ON l.id  = fq.id_local
        LEFT  JOIN jefe_venta   jv ON jv.id = l.id_jefe_venta
        INNER JOIN usuario      u  ON u.id  = fq.id_usuario
        INNER JOIN cuenta       cu ON cu.id = l.id_cuenta
        INNER JOIN cadena       ca ON ca.id = l.id_cadena
        INNER JOIN comuna       cm ON cm.id = l.id_comuna
        INNER JOIN region       re ON re.id = cm.id_region
        LEFT JOIN (
            SELECT
                mrd.id_formularioQuestion,
                MIN(mr.fecha_recepcion) AS fecha_recepcion
            FROM material_recepcion_detalle mrd
            INNER JOIN material_recepcion mr ON mr.id = mrd.id_recepcion
            WHERE mr.fecha_recepcion IS NOT NULL
            GROUP BY mrd.id_formularioQuestion
        ) rec ON rec.id_formularioQuestion = fq.id
        LEFT JOIN (
            SELECT
                id_formulario,
                MIN(fecha_recepcion) AS fecha_recepcion
            FROM material_recepcion
            WHERE fecha_recepcion IS NOT NULL
            GROUP BY id_formulario
        ) rec_form ON rec_form.id_formulario = f.id
        WHERE f.id = ?
        ORDER BY l.codigo, fq.fechaVisita ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando getLocalesDetails: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        fail('Error ejecutando getLocalesDetails: ' . $err);
    }

    $res = $stmt->get_result();
    if ($res === false) {
        $err = $stmt->error;
        $stmt->close();
        fail('Error obteniendo resultado getLocalesDetails: ' . $err);
    }

    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function getFotosImplementaciones($idForm, array $fqIds): array {
    global $conn;

    $normalizarFoto = function (string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $url     = str_replace('\\', '/', $url);
        $url     = ltrim($url, '/');
        $urlLow  = strtolower($url);
        $baseUrl = 'https://www.visibility.cl/';

        if (preg_match('#^visibility2/#i', $url)) {
            return $baseUrl . $urlLow;
        }
        if (preg_match('#^app/#i', $url)) {
            return $baseUrl . 'visibility2/' . $urlLow;
        }
        if (preg_match('#^uploads/#i', $url)) {
            return $baseUrl . 'visibility2/app/' . $urlLow;
        }

        return $baseUrl . 'visibility2/app/' . $urlLow;
    };

    if (empty($fqIds)) {
        return [];
    }

    $types        = str_repeat('i', count($fqIds) + 1);
    $placeholders = implode(',', array_fill(0, count($fqIds), '?'));
    $sql = "
        SELECT id_formularioQuestion, url
        FROM fotoVisita
        WHERE id_formulario = ?
          AND id_formularioQuestion IN ($placeholders)
        ORDER BY id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error preparando getFotosImplementaciones: " . $conn->error);
    }
    $params     = array_merge([$types], [$idForm], $fqIds);
    $bindParams = [];
    foreach ($params as $k => $v) {
        $bindParams[$k] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        die("Error en getFotosImplementaciones: " . $conn->error);
    }

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $fqId = (int)$row['id_formularioQuestion'];
        $out[$fqId][] = $normalizarFoto($row['url']);
    }
    return $out;
}

function getEncuestaPivot(int $idForm): array {
    global $conn;

    $allQuestions = [];
    $qry = "
        SELECT question_text
        FROM form_questions
        WHERE id_formulario = ?
        ORDER BY sort_order, id
    ";

    $stmtQ = $conn->prepare($qry);
    if (!$stmtQ) {
        fail('Error preparando preguntas encuesta: ' . $conn->error);
    }

    $stmtQ->bind_param('i', $idForm);

    if (!$stmtQ->execute()) {
        $err = $stmtQ->error;
        $stmtQ->close();
        fail('Error ejecutando preguntas encuesta: ' . $err);
    }

    $resQ = $stmtQ->get_result();
    if ($resQ === false) {
        $err = $stmtQ->error;
        $stmtQ->close();
        fail('Error obteniendo preguntas encuesta: ' . $err);
    }

    while ($rQ = $resQ->fetch_assoc()) {
        $allQuestions[] = (string)$rQ['question_text'];
    }
    $stmtQ->close();

    $sql = "
        SELECT
            ANY_VALUE(f.id) AS idCampana,
            ANY_VALUE(UPPER(f.nombre)) AS nombreCampana,
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
            CASE
                WHEN fqr.created_at IS NULL
                     OR CAST(fqr.created_at AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(fqr.created_at)
            END AS fechaVisita,
            fp.question_text AS question_text,
            UPPER(
              GROUP_CONCAT(
                fqr.answer_text
                ORDER BY fqr.id
                SEPARATOR '; '
              )
            ) AS concat_answers,
            GROUP_CONCAT(
              CASE
                WHEN fqr.valor IS NOT NULL
                     AND TRIM(CAST(fqr.valor AS CHAR)) <> ''
                     AND CAST(fqr.valor AS CHAR) <> '0.00'
                     AND CAST(fqr.valor AS CHAR) <> '0'
                THEN fqr.valor
              END
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
        GROUP BY
            l.codigo,
            CASE
                WHEN fqr.created_at IS NULL
                     OR CAST(fqr.created_at AS CHAR(19)) = '0000-00-00 00:00:00'
                THEN NULL
                ELSE DATE(fqr.created_at)
            END,
            fp.question_text
        ORDER BY l.codigo, fp.question_text
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando getEncuestaPivot: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        fail('Error ejecutando getEncuestaPivot: ' . $err);
    }

    $res = $stmt->get_result();
    if ($res === false) {
        $err = $stmt->error;
        $stmt->close();
        fail('Error obteniendo resultado getEncuestaPivot: ' . $err);
    }

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $fechaKey = $row['fechaVisita'] ?? 'SIN_FECHA';
        $key = $row['codigo_local'] . '_' . $fechaKey;

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'ID CAMPAÑA'     => $row['idCampana'],
                'NOMBRE CAMPAÑA' => $row['nombreCampana'],
                'CUENTA'         => $row['cuenta'],
                'CADENA'         => $row['cadena'],
                'CODIGO LOCAL'   => $row['codigo_local'],
                'N° LOCAL'       => $row['numero_local'],
                'LOCAL'          => $row['nombre_local'],
                'DIRECCION'      => $row['direccion_local'],
                'COMUNA'         => $row['comuna'],
                'REGION'         => $row['region'],
                'USUARIO'        => $row['usuario'],
                'FECHA VISITA'   => $row['fechaVisita'],
                'questions'      => []
            ];
        }

        $q = (string)$row['question_text'];
        $grouped[$key]['questions'][$q] = [
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
                $rowOut[$q] = $g['questions'][$q]['answer'];
                $rowOut[$q . '_valor'] = $g['questions'][$q]['valor'];
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
            if (strpos($c, '_valor') !== false) {
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

function removerFotosDeEncuesta(array $encuesta): array {
    $esUrlFoto = function (string $valor): bool {
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

// Render de tablas (sin envolver en <html>)
function renderSeccionTablas($locales, $encuesta, $inline, $fotosLocales, $maxFotosLocales, $allCampaignsData = []): string {
    $html = '';

    // Determinar etiquetas según modalidad
    // Si hay múltiples campañas con diferentes modalidades, usamos etiquetas genéricas
    $modalidades = [];
    foreach ($allCampaignsData as $campData) {
        if (!empty($campData)) {
            $modalidades[] = strtolower(trim($campData[0]['modalidad'] ?? ''));
        }
    }
    $modalidadesUnicas = array_unique($modalidades);

    $etiquetaMaterial = 'MATERIAL';
    $etiquetaCantidad = 'CANTIDAD MATERIAL EJECUTADO';

    // Solo cambiamos etiquetas si todas las campañas tienen la misma modalidad
    if (count($modalidadesUnicas) === 1) {
        $modalidadLower = reset($modalidadesUnicas);
        if ($modalidadLower === 'retiro') {
            $etiquetaMaterial = 'MATERIAL RETIRADO';
            $etiquetaCantidad = 'CANTIDAD MATERIAL RETIRADO';
        } elseif ($modalidadLower === 'entrega') {
            $etiquetaMaterial = 'MATERIAL ENTREGADO';
            $etiquetaCantidad = 'CANTIDAD MATERIAL ENTREGADO';
        }
    }

    // ---- Detalle de Locales (HOMOLOGADO a individual) ----
    if (!empty($locales)) {
        $html .= "<b>Detalle de Locales</b>"
              .  "<table border='1'"
              .  "       style='border-collapse:collapse; table-layout:auto; font-size:9pt;'>"
              .  "  <tr>"
              .  "    <th>ID LOCAL</th>"
              .  "    <th>CAMPAÑA</th>"
              .  "    <th>CUENTA</th>"
              .  "    <th>CADENA</th>"
              .  "    <th>CODIGO</th>"
              .  "    <th>N° LOCAL</th>"
              .  "    <th>LOCAL</th>"
              .  "    <th>DIRECCION</th>"
              .  "    <th>COMUNA</th>"
              .  "    <th>REGION</th>"
              .  "    <th>JEFE VENTA</th>"
              .  "    <th>FECHA INICIO</th>"
              .  "    <th>FECHA TÉRMINO</th>"
              .  "    <th>FECHA PLANIFICADA</th>"
              .  "    <th>FECHA VISITA</th>"
              .  "    <th>FECHA RECEPCION MATERIAL</th>"
              .  "    <th>HORA</th>"
              .  "    <th>USUARIO</th>"
              .  "    <th>ESTADO VISITA</th>"
              .  "    <th>ESTADO ACTIVIDAD</th>"
              .  "    <th>MOTIVO</th>"
                .  "    <th>{$etiquetaMaterial}</th>"
                .  "    <th>CATEGORIA</th>"
                .  "    <th>MARCA</th>"
                .  "    <th>{$etiquetaCantidad}</th>"
                .  "    <th>MATERIAL PROPUESTO</th>"
                .  "    <th>OBSERVACION</th>";

        if ($maxFotosLocales > 0) {
            for ($i = 1; $i <= $maxFotosLocales; $i++) {
                $html .= "<th>FOTO {$i}</th>";
            }
        }

        $html .= "</tr>";

        foreach ($locales as $l) {
            $fechaInicioCamp = ($l['fechaInicio'] !== null && $l['fechaInicio'] !== '0000-00-00')
                              ? $l['fechaInicio']
                              : '-';
            $fechaTerminoCamp = ($l['fechaTermino'] !== null && $l['fechaTermino'] !== '0000-00-00')
                              ? $l['fechaTermino']
                              : '-';
            $fechaPropuesta = ($l['fechaPropuesta'] !== null && $l['fechaPropuesta'] !== '0000-00-00')
                              ? $l['fechaPropuesta']
                              : '-';
            $fechaVisita    = ($l['fechaVisita'] !== null && $l['fechaVisita'] !== '0000-00-00')
                              ? $l['fechaVisita']
                              : '-';
            $fechaRecepcion = ($l['fechaRecepcionMaterial'] !== null && $l['fechaRecepcionMaterial'] !== '0000-00-00')
                              ? $l['fechaRecepcionMaterial']
                              : '-';

            $html .= "<tr>
                        <td>" . e($l['idLocal']) . "</td>
                        <td>" . e($l['nombreCampana']) . "</td>
                        <td>" . e($l['cuenta']) . "</td>
                        <td>" . e($l['cadena']) . "</td>
                        <td>" . e($l['codigo_local']) . "</td>
                        <td>" . e($l['numero_local']) . "</td>
                        <td>" . e($l['nombre_local']) . "</td>
                        <td>" . e($l['direccion_local']) . "</td>
                        <td>" . e($l['comuna']) . "</td>
                        <td>" . e($l['region']) . "</td>
                        <td>" . e($l['jefeVenta']) . "</td>
                        <td>{$fechaInicioCamp}</td>
                        <td>{$fechaTerminoCamp}</td>
                        <td>{$fechaPropuesta}</td>
                        <td>{$fechaVisita}</td>
                        <td>{$fechaRecepcion}</td>
                        <td>" . e($l['hora']) . "</td>
                        <td>" . e($l['gestionado_por']) . "</td>
                        <td>" . e($l['ESTADO_VISTA']) . "</td>
                        <td>" . e($l['ESTADO_ACTIVIDAD']) . "</td>
                        <td>" . e($l['MOTIVO']) . "</td>
                        <td>" . e($l['material']) . "</td>
                        <td>" . e($l['categoria']) . "</td>
                        <td>" . e($l['marca']) . "</td>                        
                        <td>" . e($l['valor']) . "</td>
                        <td>" . e($l['valor_propuesto']) . "</td>
                        <td>" . e($l['observacion']) . "</td>";

            $fotos = [];
            if (!empty($l['id_formularioQuestion']) && isset($fotosLocales[$l['id_formularioQuestion']])) {
                $fotos = $fotosLocales[$l['id_formularioQuestion']];
            }

            for ($fi = 0; $fi < $maxFotosLocales; $fi++) {
                $url   = trim((string)($fotos[$fi] ?? ''));
                $html .= '<td>' . renderValorConImagen($url, $inline) . '</td>';
            }

            $html .= '</tr>';
        }

        $html .= "</table><br>";
    }

    // ---- Encuesta (HOMOLOGADO a individual en orden de columnas render) ----
    $html .= "<b>Encuesta</b><table border='1' style='border-collapse:collapse; table-layout:auto; font-size:9pt;'><tr>";

    // En el individual: toma array_keys($encuesta[0]) para mantener orden estable
    $keys = !empty($encuesta) ? array_keys($encuesta[0]) : [];

    foreach ($keys as $k) {
        $html .= '<th>' . e($k) . '</th>';
    }
    $html .= '</tr>';

    foreach ($encuesta as $row) {
        $html .= '<tr>';
        foreach ($keys as $k) {
            $vs    = (string)($row[$k] ?? '');
            $html .= '<td>' . renderValorConImagen($vs, $inline) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';

    return $html;
}

function fechaValida($fecha): bool {
    return !empty($fecha) && $fecha !== '0000-00-00' && $fecha !== '0000-00-00 00:00:00';
}

function diferenciaDias($desde, $hasta): string {
    if (!fechaValida($desde) || !fechaValida($hasta)) {
        return '';
    }

    try {
        $d1 = new DateTime((string)$desde);
        $d2 = new DateTime((string)$hasta);
        return (string)$d1->diff($d2)->days;
    } catch (Throwable $e) {
        return '';
    }
}

function diferenciaDiasHabiles($desde, $hasta): string {
    if (!fechaValida($desde) || !fechaValida($hasta)) {
        return '';
    }

    try {
        $inicio = new DateTime((string)$desde);
        $fin = new DateTime((string)$hasta);
        if ($fin < $inicio) {
            return '';
        }

        $diasHabiles = 0;
        $cursor = clone $inicio;
        $cursor->modify('+1 day');

        while ($cursor <= $fin) {
            $diaSemana = (int)$cursor->format('N');
            if ($diaSemana <= 5) {
                $diasHabiles++;
            }
            $cursor->modify('+1 day');
        }

        return (string)$diasHabiles;
    } catch (Throwable $e) {
        return '';
    }
}

function promedioFechas(array $fechas): string {
    $timestamps = [];
    foreach ($fechas as $fecha) {
        if (!fechaValida($fecha)) {
            continue;
        }
        $ts = strtotime((string)$fecha);
        if ($ts !== false) {
            $timestamps[] = $ts;
        }
    }

    if (empty($timestamps)) {
        return '';
    }

    return date('Y-m-d', (int)round(array_sum($timestamps) / count($timestamps)));
}

function construirMetricaGantt(array $filas, string $region = ''): array {
    $localesProgramados = [];
    $localesVisitados = [];
    $fechasRecepcion = [];
    $visitasPorFecha = [];
    $fechaInicio = '';
    $fechaTermino = '';
    $ultimaFechaVisita = '';
    $nombreCampana = '';
    $prioridad = 'MEDIA';

    foreach ($filas as $fila) {
        $nombreCampana = $nombreCampana ?: (string)($fila['nombreCampana'] ?? '');
        $prioridadFila = trim((string)($fila['prioridad'] ?? ''));
        if ($prioridadFila !== '') {
            $prioridad = $prioridadFila;
        }

        if (!$fechaInicio && fechaValida($fila['fechaInicio'] ?? null)) {
            $fechaInicio = (string)$fila['fechaInicio'];
        }
        if (!$fechaTermino && fechaValida($fila['fechaTermino'] ?? null)) {
            $fechaTermino = (string)$fila['fechaTermino'];
        }

        $localKey = (string)($fila['idLocal'] ?? $fila['codigo_local'] ?? '');
        if ($localKey !== '') {
            $localesProgramados[$localKey] = true;
        }

        if (fechaValida($fila['fechaRecepcionMaterial'] ?? null)) {
            $fechasRecepcion[] = (string)$fila['fechaRecepcionMaterial'];
        }

        if (fechaValida($fila['fechaVisita'] ?? null)) {
            $fechaVisita = (string)$fila['fechaVisita'];
            $visitasPorFecha[$fechaVisita] = ($visitasPorFecha[$fechaVisita] ?? 0) + 1;
            if ($localKey !== '') {
                $localesVisitados[$localKey] = true;
            }
            if (!$ultimaFechaVisita || $fechaVisita > $ultimaFechaVisita) {
                $ultimaFechaVisita = $fechaVisita;
            }
        }
    }

    ksort($visitasPorFecha);
    $fechaRecepcionPromedio = promedioFechas($fechasRecepcion);
    $programados = count($localesProgramados);
    $visitados = count($localesVisitados);
    $avance = $programados > 0 ? round(($visitados / $programados) * 100, 2) : 0;

    return [
        'campana' => $nombreCampana,
        'prioridad' => $prioridad ?: 'MEDIA',
        'region' => $region,
        'fecha_inicio' => $fechaInicio,
        'fecha_termino' => $fechaTermino,
        'fecha_recepcion_promedio' => $fechaRecepcionPromedio,
        'ultima_fecha_visita' => $ultimaFechaVisita,
        'dias_inicio_ultima_visita' => diferenciaDias($fechaInicio, $ultimaFechaVisita),
        'dias_recepcion_ultima_visita' => diferenciaDias($fechaRecepcionPromedio, $ultimaFechaVisita),
        'locales_programados' => $programados,
        'locales_visitados' => $visitados,
        'avance' => $avance,
        'visitas_por_fecha' => $visitasPorFecha,
    ];
}

function renderResumenYGantt(array $locales): string {
    if (empty($locales)) {
        return '';
    }

    $porCampana = [];
    $porCampanaRegion = [];
    $fechasVisita = [];

    foreach ($locales as $fila) {
        $campana = (string)($fila['nombreCampana'] ?? 'SIN CAMPAÑA');
        $region = (string)($fila['region'] ?? 'SIN REGION');
        $porCampana[$campana][] = $fila;
        $porCampanaRegion[$campana . '||' . $region][] = $fila;

        if (fechaValida($fila['fechaVisita'] ?? null)) {
            $fechasVisita[(string)$fila['fechaVisita']] = true;
        }
    }

    $metricasCampana = [];
    foreach ($porCampana as $filasCampana) {
        $metricasCampana[] = construirMetricaGantt($filasCampana);
    }

    $metricasRegion = [];
    foreach ($porCampanaRegion as $key => $filasRegion) {
        $partesRegion = explode('||', $key, 2);
        $region = $partesRegion[1] ?? '';
        $metricasRegion[] = construirMetricaGantt($filasRegion, $region);
    }

    ksort($fechasVisita);
    $fechasVisita = array_keys($fechasVisita);

    $renderFilaBase = function (array $m, bool $incluyeRegion = false) use ($fechasVisita): string {
        $html = '<tr><td>' . e($m['campana']) . '</td>';

        if ($incluyeRegion) {
            $html .= '<td>' . e($m['region']) . '</td>';
        }

        $html .= '<td>' . e($m['fecha_inicio']) . '</td>'
              . '<td>' . e($m['fecha_termino']) . '</td>'
              . '<td>' . e($m['fecha_recepcion_promedio']) . '</td>'
              . '<td>' . e($m['ultima_fecha_visita']) . '</td>'
              . '<td>' . e($m['dias_inicio_ultima_visita']) . '</td>'
              . '<td>' . e($m['dias_recepcion_ultima_visita']) . '</td>'
              . '<td>' . e($m['locales_programados']) . '</td>'
              . '<td>' . e($m['locales_visitados']) . '</td>'
              . '<td>' . e(number_format((float)$m['avance'], 2, ',', '.')) . '%</td>';

        foreach ($fechasVisita as $fecha) {
            $cantidad = (int)($m['visitas_por_fecha'][$fecha] ?? 0);
            $html .= '<td' . ($cantidad > 0 ? " class='gantt-hit'" : '') . '>'
                  . ($cantidad > 0 ? e($cantidad) : '')
                  . '</td>';
        }

        return $html . '</tr>';
    };

    $html = "<div class='sheet-break'></div><h2>Resumen Ejecutivo por Campaña</h2>";
    $html .= "<table border='1' class='gantt-table'><tr>"
          . "<th>CAMPAÑA</th><th>FECHA INICIO</th><th>FECHA TERMINO</th>"
          . "<th>FECHA RECEPCION PROMEDIO</th><th>ULTIMA FECHA VISITA</th>"
          . "<th>DIAS INICIO A ULTIMA VISITA</th><th>DIAS RECEPCION A ULTIMA VISITA</th>"
          . "<th>LOCALES PROGRAMADOS</th><th>LOCALES VISITADOS</th><th>AVANCE VISITA</th>"
          . "<th>AVANCES POR VISITA</th></tr>";

    foreach ($metricasCampana as $m) {
        $avancesPorVisita = [];
        foreach ($m['visitas_por_fecha'] as $fecha => $cantidad) {
            $avancesPorVisita[] = $fecha . ': ' . $cantidad;
        }
        $html .= '<tr>'
              . '<td>' . e($m['campana']) . '</td>'
              . '<td>' . e($m['fecha_inicio']) . '</td>'
              . '<td>' . e($m['fecha_termino']) . '</td>'
              . '<td>' . e($m['fecha_recepcion_promedio']) . '</td>'
              . '<td>' . e($m['ultima_fecha_visita']) . '</td>'
              . '<td>' . e($m['dias_inicio_ultima_visita']) . '</td>'
              . '<td>' . e($m['dias_recepcion_ultima_visita']) . '</td>'
              . '<td>' . e($m['locales_programados']) . '</td>'
              . '<td>' . e($m['locales_visitados']) . '</td>'
              . '<td>' . e(number_format((float)$m['avance'], 2, ',', '.')) . '%</td>'
              . '<td>' . e(implode(' | ', $avancesPorVisita)) . '</td>'
              . '</tr>';
    }
    $html .= '</table>';

    $html .= "<div class='sheet-break'></div><h2>Carta Gantt por Campaña</h2>";
    $html .= "<table border='1' class='gantt-table'><tr>"
          . "<th>CAMPAÑA</th><th>FECHA INICIO</th><th>FECHA TERMINO</th>"
          . "<th>FECHA RECEPCION PROMEDIO</th><th>ULTIMA FECHA VISITA</th>"
          . "<th>DIAS INICIO A ULTIMA VISITA</th><th>DIAS RECEPCION A ULTIMA VISITA</th>"
          . "<th>LOCALES PROGRAMADOS</th><th>LOCALES VISITADOS</th><th>AVANCE VISITA</th>";
    foreach ($fechasVisita as $fecha) {
        $html .= '<th>' . e($fecha) . '</th>';
    }
    $html .= '</tr>';
    foreach ($metricasCampana as $m) {
        $html .= $renderFilaBase($m, false);
    }
    $html .= '</table>';

    $html .= "<div class='sheet-break'></div><h2>Carta Gantt por Campaña y Región</h2>";
    $html .= "<table border='1' class='gantt-table'><tr>"
          . "<th>CAMPAÑA</th><th>REGION</th><th>FECHA INICIO</th><th>FECHA TERMINO</th>"
          . "<th>FECHA RECEPCION PROMEDIO</th><th>ULTIMA FECHA VISITA</th>"
          . "<th>DIAS INICIO A ULTIMA VISITA</th><th>DIAS RECEPCION A ULTIMA VISITA</th>"
          . "<th>LOCALES PROGRAMADOS</th><th>LOCALES VISITADOS</th><th>AVANCE VISITA</th>";
    foreach ($fechasVisita as $fecha) {
        $html .= '<th>' . e($fecha) . '</th>';
    }
    $html .= '</tr>';
    foreach ($metricasRegion as $m) {
        $html .= $renderFilaBase($m, true);
    }
    $html .= '</table>';

    return $html;
}

// -----------------------------------------------------------------------------
// Armado de reportes por campaña
// -----------------------------------------------------------------------------
$reportes = [];

foreach ($ids as $formulario_id) {
    $meta = getFormularioMeta($formulario_id);
    if (empty($meta)) {
        continue;
    }

    $modalidad = strtolower(trim($meta['modalidad'] ?? ''));

    $campaignData   = getCampaignData($formulario_id);
    $localesDetails = [];
    $encuestaPivot  = [];

    switch ($modalidad) {
        case 'solo_implementacion':
        case 'retiro':
            $localesDetails = getLocalesDetails($formulario_id);
            break;
        case 'solo_auditoria':
            $encuestaPivot = getEncuestaPivot($formulario_id);
            break;
        case 'implementacion_auditoria':
        default:
            $localesDetails = getLocalesDetails($formulario_id);
            $encuestaPivot  = getEncuestaPivot($formulario_id);
            break;
    }

    $fotosLocales    = [];
    $maxFotosLocales = 0;
    if ($incluirFotosMaterial && !empty($localesDetails)) {
        $fqIds        = array_column($localesDetails, 'id_formularioQuestion');
        $fotosLocales = getFotosImplementaciones($formulario_id, $fqIds);
        foreach ($fotosLocales as $lista) {
            $maxFotosLocales = max($maxFotosLocales, count($lista));
        }
    }

    if (!$incluirFotosEncuesta && !empty($encuestaPivot)) {
        $encuestaPivot = removerFotosDeEncuesta($encuestaPivot);
    }

    foreach ($localesDetails as &$loc) {
        if (empty($loc['numero_local'])) {
            $loc['numero_local'] = preg_replace('/\D+/', '', (string)($loc['codigo_local'] ?? ''));
        }
    }
    unset($loc);

    if (empty($campaignData) && empty($localesDetails) && empty($encuestaPivot)) {
        continue;
    }

    $reportes[] = [
        'nombre'          => $meta['nombre'],
        'modalidad'       => $meta['modalidad'],
        'campaign'        => $campaignData,
        'locales'         => $localesDetails,
        'encuesta'        => $encuestaPivot,
        'fotosLocales'    => $fotosLocales,
        'maxFotosLocales' => $maxFotosLocales,
    ];
}

if (empty($reportes)) {
    die('No se encontraron datos para las campañas seleccionadas.');
}

// -----------------------------------------------------------------------------
// Render final (un solo HTML con todas las campañas en una sola tabla)
// -----------------------------------------------------------------------------
$localesGlobal         = [];
$encuestaGlobal        = [];
$fotosLocalesGlobal    = [];
$maxFotosLocalesGlobal = 0;

foreach ($reportes as $rep) {
    foreach ($rep['locales'] as $l) {
        $localesGlobal[] = $l;
    }
    foreach ($rep['encuesta'] as $e) {
        $encuestaGlobal[] = $e;
    }
    foreach ($rep['fotosLocales'] as $fqId => $urls) {
        $fotosLocalesGlobal[$fqId] = $urls;
        $maxFotosLocalesGlobal     = max($maxFotosLocalesGlobal, count($urls));
    }
}

$crearMetricas = function (array $filas, bool $porRegion = false): array {
    $grupos = [];
    $fechas = [];

    foreach ($filas as $fila) {
        $campana = trim((string)($fila['nombreCampana'] ?? 'SIN CAMPAÑA'));
        $trade = trim((string)($fila['trade'] ?? 'SIN TRADE'));
        $region = trim((string)($fila['region'] ?? 'SIN REGION'));
        $key = $porRegion ? $trade . '||' . $campana . '||' . $region : $trade . '||' . $campana;

        if (!isset($grupos[$key])) {
            $grupos[$key] = [
                'trade' => $trade,
                'campana' => $campana,
                'prioridad' => trim((string)($fila['prioridad'] ?? 'MEDIA')) ?: 'MEDIA',
                'region' => $porRegion ? $region : '',
                'fecha_inicio' => fechaValida($fila['fechaInicio'] ?? null) ? (string)$fila['fechaInicio'] : '',
                'fecha_termino' => fechaValida($fila['fechaTermino'] ?? null) ? (string)$fila['fechaTermino'] : '',
                'programados' => [],
                'visitados' => [],
                'recepciones' => [],
                'visitas' => [],
                'primera_visita' => '',
                'ultima_visita' => '',
            ];
        }

        $localId = (string)($fila['idLocal'] ?? $fila['codigo_local'] ?? '');
        if ($localId !== '') {
            $grupos[$key]['programados'][$localId] = true;
        }

        if ($localId !== '' && fechaValida($fila['fechaRecepcionMaterial'] ?? null)) {
            $fechaRecepcion = (string)$fila['fechaRecepcionMaterial'];
            if (
                !isset($grupos[$key]['recepciones'][$localId])
                || $fechaRecepcion < $grupos[$key]['recepciones'][$localId]
            ) {
                $grupos[$key]['recepciones'][$localId] = $fechaRecepcion;
            }
        }

        if (fechaValida($fila['fechaVisita'] ?? null)) {
            $fechaVisita = (string)$fila['fechaVisita'];
            $fechas[$fechaVisita] = true;
            if ($localId !== '') {
                $grupos[$key]['visitados'][$localId] = true;
                $grupos[$key]['visitas'][$fechaVisita][$localId] = true;
            }
            if ($grupos[$key]['ultima_visita'] === '' || $fechaVisita > $grupos[$key]['ultima_visita']) {
                $grupos[$key]['ultima_visita'] = $fechaVisita;
            }
            if ($grupos[$key]['primera_visita'] === '' || $fechaVisita < $grupos[$key]['primera_visita']) {
                $grupos[$key]['primera_visita'] = $fechaVisita;
            }
        }
    }

    $metricas = [];
    foreach ($grupos as $grupo) {
        $visitas = [];
        foreach ($grupo['visitas'] as $fecha => $localesFecha) {
            $visitas[$fecha] = count($localesFecha);
        }
        ksort($visitas);
        $fechaRecepcion = promedioFechas(array_values($grupo['recepciones']));

        $metricas[] = [
            'trade' => $grupo['trade'],
            'campana' => $grupo['campana'],
            'prioridad' => $grupo['prioridad'],
            'region' => $grupo['region'],
            'fecha_inicio' => $grupo['fecha_inicio'],
            'fecha_termino' => $grupo['fecha_termino'],
            'fecha_recepcion' => $fechaRecepcion,
            'primera_visita' => $grupo['primera_visita'],
            'ultima_visita' => $grupo['ultima_visita'],
            'dias_habiles_inicio_recepcion' => diferenciaDiasHabiles($grupo['fecha_inicio'], $fechaRecepcion),
            'dias_habiles_recepcion_primera_visita' => diferenciaDiasHabiles($fechaRecepcion, $grupo['primera_visita']),
            'programados' => count($grupo['programados']),
            'visitados' => count($grupo['visitados']),
            'visitas' => $visitas,
        ];
    }

    usort($metricas, function (array $a, array $b) use ($porRegion): int {
        $result = strcmp($a['trade'], $b['trade']);
        if ($result !== 0) {
            return $result;
        }
        $result = strcmp($a['campana'], $b['campana']);
        return ($result !== 0 || !$porRegion) ? $result : strcmp($a['region'], $b['region']);
    });
    ksort($fechas);

    return ['metricas' => $metricas, 'fechas' => array_keys($fechas)];
};

$escribirFecha = function (Worksheet $sheet, string $cell, string $fecha): void {
    if (!fechaValida($fecha)) {
        $sheet->setCellValueExplicit($cell, '', DataType::TYPE_STRING);
        return;
    }

    $timestamp = strtotime($fecha);
    if ($timestamp === false) {
        $sheet->setCellValueExplicit($cell, $fecha, DataType::TYPE_STRING);
        return;
    }

    $sheet->setCellValue($cell, ExcelDate::PHPToExcel($timestamp));
    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
};

$estiloTitulo = function (Worksheet $sheet, string $titulo, string $subtitulo, int $ultimaColumna): void {
    $ultimaLetra = Coordinate::stringFromColumnIndex($ultimaColumna);
    $sheet->mergeCells("A1:{$ultimaLetra}1");
    $sheet->setCellValue('A1', $titulo);
    $sheet->getStyle("A1:{$ultimaLetra}1")->applyFromArray([
        'font' => ['bold' => true, 'size' => 18, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4E78']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(30);

    $sheet->mergeCells("A2:{$ultimaLetra}2");
    $sheet->setCellValue('A2', $subtitulo);
    $sheet->getStyle("A2:{$ultimaLetra}2")->applyFromArray([
        'font' => ['italic' => true, 'color' => ['argb' => 'FF44546A']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9EAF7']],
    ]);
    $sheet->setShowGridlines(false);
    $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
};

$estiloEncabezado = function (Worksheet $sheet, int $fila, int $ultimaColumna): void {
    $ultimaLetra = Coordinate::stringFromColumnIndex($ultimaColumna);
    $sheet->getStyle("A{$fila}:{$ultimaLetra}{$fila}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9E2F3']],
        ],
    ]);
    $sheet->getRowDimension($fila)->setRowHeight(45);
};

$finalizarHoja = function (
    Worksheet $sheet,
    int $filaEncabezado,
    int $ultimaFila,
    int $ultimaColumna
): void {
    $ultimaLetra = Coordinate::stringFromColumnIndex($ultimaColumna);
    $sheet->unfreezePane();
    $sheet->setAutoFilter("A{$filaEncabezado}:{$ultimaLetra}{$ultimaFila}");
    $sheet->getStyle("A" . ($filaEncabezado + 1) . ":{$ultimaLetra}{$ultimaFila}")->applyFromArray([
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFD9D9D9']],
        ],
    ]);
};

$datosCampana = $crearMetricas($localesGlobal, false);
$datosRegion = $crearMetricas($localesGlobal, true);
$fechasGantt = array_values(array_unique(array_merge($datosCampana['fechas'], $datosRegion['fechas'])));
sort($fechasGantt);

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Visibility')
    ->setTitle('Carta Gantt de Campañas Activas')
    ->setSubject('Recepción de materiales y avance de visitas');

// Hoja 1: Resumen ejecutivo
$resumen = $spreadsheet->getActiveSheet();
$resumen->setTitle('Resumen Ejecutivo');
$resumen->getTabColor()->setARGB('FF1F4E78');
$headersResumen = [
    'CAMPAÑA', 'FECHA INICIO', 'FECHA TERMINO', 'FECHA RECEPCION PROMEDIO',
    'ULTIMA FECHA VISITA', 'DIAS INICIO A ULTIMA VISITA',
    'DIAS RECEPCION A ULTIMA VISITA', 'LOCALES PROGRAMADOS',
    'LOCALES VISITADOS', 'AVANCE VISITA', 'AVANCES POR VISITA',
];
array_splice($headersResumen, 1, 0, ['PRIORIDAD']);
array_unshift($headersResumen, 'TRADE');
array_splice($headersResumen, 9, 0, [
    'DIAS HABILES INICIO A RECEPCION',
    'DIAS HABILES RECEPCION A PRIMERA VISITA',
]);
$estiloTitulo(
    $resumen,
    'Resumen Ejecutivo de Campañas Activas',
    'Consolidado de recepción y visitas. Generado el ' . date('Y-m-d H:i'),
    count($headersResumen)
);
foreach ($headersResumen as $index => $header) {
    $resumen->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '4', $header);
}
$estiloEncabezado($resumen, 4, count($headersResumen));

$fila = 5;
foreach ($datosCampana['metricas'] as $metrica) {
    $resumen->setCellValueExplicit("A{$fila}", $metrica['trade'] ?? 'SIN TRADE', DataType::TYPE_STRING);
    $resumen->setCellValueExplicit("B{$fila}", $metrica['campana'], DataType::TYPE_STRING);
    $resumen->setCellValueExplicit("C{$fila}", $metrica['prioridad'] ?? 'MEDIA', DataType::TYPE_STRING);
    $escribirFecha($resumen, "D{$fila}", $metrica['fecha_inicio']);
    $escribirFecha($resumen, "E{$fila}", $metrica['fecha_termino']);
    $escribirFecha($resumen, "F{$fila}", $metrica['fecha_recepcion']);
    $escribirFecha($resumen, "G{$fila}", $metrica['ultima_visita']);
    $resumen->setCellValue("H{$fila}", "=IF(OR(D{$fila}=\"\",G{$fila}=\"\"),\"\",G{$fila}-D{$fila})");
    $resumen->setCellValue("I{$fila}", "=IF(OR(F{$fila}=\"\",G{$fila}=\"\"),\"\",G{$fila}-F{$fila})");
    $resumen->setCellValue("J{$fila}", $metrica['dias_habiles_inicio_recepcion']);
    $resumen->setCellValue("K{$fila}", $metrica['dias_habiles_recepcion_primera_visita']);
    $resumen->setCellValue("L{$fila}", $metrica['programados']);
    $resumen->setCellValue("M{$fila}", $metrica['visitados']);
    $resumen->setCellValue("N{$fila}", "=IF(L{$fila}=0,0,M{$fila}/L{$fila})");
    $avances = [];
    foreach ($metrica['visitas'] as $fecha => $cantidad) {
        $avances[] = $fecha . ': ' . $cantidad . ' local(es)';
    }
    $resumen->setCellValueExplicit("O{$fila}", implode(' | ', $avances), DataType::TYPE_STRING);
    $fila++;
}
$ultimaFilaResumen = max(5, $fila - 1);
$finalizarHoja($resumen, 4, $ultimaFilaResumen, count($headersResumen));
$resumen->getStyle("N5:N{$ultimaFilaResumen}")->getNumberFormat()->setFormatCode('0.0%');
$resumen->getStyle("H5:M{$ultimaFilaResumen}")->getNumberFormat()->setFormatCode('#,##0');
$resumen->getStyle("O5:O{$ultimaFilaResumen}")->getAlignment()->setWrapText(true);
$anchosResumen = [24, 38, 14, 14, 14, 22, 18, 22, 24, 24, 28, 18, 17, 14, 58];
foreach ($anchosResumen as $index => $ancho) {
    $resumen->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($ancho);
}

$crearHojaGantt = function (
    string $nombreHoja,
    string $titulo,
    array $metricas,
    bool $incluyeRegion
) use (
    $spreadsheet,
    $fechasGantt,
    $escribirFecha,
    $estiloTitulo,
    $estiloEncabezado,
    $finalizarHoja
): void {
    $sheet = new Worksheet($spreadsheet, $nombreHoja);
    $spreadsheet->addSheet($sheet);
    $sheet->getTabColor()->setARGB($incluyeRegion ? 'FF70AD47' : 'FFED7D31');

    $headers = ['CAMPAÑA'];
    if ($incluyeRegion) {
        $headers[] = 'REGION';
    }
    array_splice($headers, 1, 0, ['PRIORIDAD']);
    array_unshift($headers, 'TRADE');
    $headers = array_merge($headers, [
        'FECHA INICIO', 'FECHA TERMINO', 'FECHA RECEPCION PROMEDIO',
        'ULTIMA FECHA VISITA', 'DIAS INICIO A ULTIMA VISITA',
        'DIAS RECEPCION A ULTIMA VISITA', 'LOCALES PROGRAMADOS',
        'LOCALES VISITADOS', 'AVANCE VISITA',
    ]);
    $posLocalesProgramados = array_search('LOCALES PROGRAMADOS', $headers, true);
    if ($posLocalesProgramados !== false) {
        array_splice($headers, $posLocalesProgramados, 0, [
            'DIAS HABILES INICIO A RECEPCION',
            'DIAS HABILES RECEPCION A PRIMERA VISITA',
        ]);
    }

    $inicioGantt = count($headers) + 1;
    $ultimaColumna = count($headers) + count($fechasGantt);
    $estiloTitulo(
        $sheet,
        $titulo,
        'Cada celda verde representa locales únicos visitados en esa fecha.',
        max(1, $ultimaColumna)
    );

    foreach ($headers as $index => $header) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '4', $header);
    }
    foreach ($fechasGantt as $index => $fecha) {
        $cell = Coordinate::stringFromColumnIndex($inicioGantt + $index) . '4';
        $escribirFecha($sheet, $cell, $fecha);
        $sheet->getStyle($cell)->getAlignment()->setTextRotation(90);
    }
    $estiloEncabezado($sheet, 4, max(1, $ultimaColumna));
    foreach ($fechasGantt as $index => $_fecha) {
        $cell = Coordinate::stringFromColumnIndex($inicioGantt + $index) . '4';
        $sheet->getStyle($cell)->getAlignment()->setTextRotation(90);
    }

    $fila = 5;
    foreach ($metricas as $metrica) {
        $columna = 1;
        $sheet->setCellValueExplicit(
            Coordinate::stringFromColumnIndex($columna++) . $fila,
            $metrica['trade'] ?? 'SIN TRADE',
            DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            Coordinate::stringFromColumnIndex($columna++) . $fila,
            $metrica['campana'],
            DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            Coordinate::stringFromColumnIndex($columna++) . $fila,
            $metrica['prioridad'] ?? 'MEDIA',
            DataType::TYPE_STRING
        );
        if ($incluyeRegion) {
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($columna++) . $fila,
                $metrica['region'],
                DataType::TYPE_STRING
            );
        }

        $escribirFecha($sheet, Coordinate::stringFromColumnIndex($columna++) . $fila, $metrica['fecha_inicio']);
        $escribirFecha($sheet, Coordinate::stringFromColumnIndex($columna++) . $fila, $metrica['fecha_termino']);
        $escribirFecha($sheet, Coordinate::stringFromColumnIndex($columna++) . $fila, $metrica['fecha_recepcion']);
        $escribirFecha($sheet, Coordinate::stringFromColumnIndex($columna++) . $fila, $metrica['ultima_visita']);

        $colFechaInicio = Coordinate::stringFromColumnIndex($incluyeRegion ? 5 : 4);
        $colRecepcion = Coordinate::stringFromColumnIndex($incluyeRegion ? 7 : 6);
        $colUltimaVisita = Coordinate::stringFromColumnIndex($incluyeRegion ? 8 : 7);
        $sheet->setCellValue(
            Coordinate::stringFromColumnIndex($columna++) . $fila,
            "=IF(OR({$colFechaInicio}{$fila}=\"\",{$colUltimaVisita}{$fila}=\"\"),\"\",{$colUltimaVisita}{$fila}-{$colFechaInicio}{$fila})"
        );
        $sheet->setCellValue(
            Coordinate::stringFromColumnIndex($columna++) . $fila,
            "=IF(OR({$colRecepcion}{$fila}=\"\",{$colUltimaVisita}{$fila}=\"\"),\"\",{$colUltimaVisita}{$fila}-{$colRecepcion}{$fila})"
        );
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columna++) . $fila, $metrica['dias_habiles_inicio_recepcion']);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columna++) . $fila, $metrica['dias_habiles_recepcion_primera_visita']);
        $colProgramados = Coordinate::stringFromColumnIndex($columna);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columna++) . $fila, $metrica['programados']);
        $colVisitados = Coordinate::stringFromColumnIndex($columna);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columna++) . $fila, $metrica['visitados']);
        $sheet->setCellValue(
            Coordinate::stringFromColumnIndex($columna++) . $fila,
            "=IF({$colProgramados}{$fila}=0,0,{$colVisitados}{$fila}/{$colProgramados}{$fila})"
        );

        foreach ($fechasGantt as $fecha) {
            $cantidad = (int)($metrica['visitas'][$fecha] ?? 0);
            $cell = Coordinate::stringFromColumnIndex($columna++) . $fila;
            if ($cantidad > 0) {
                $sheet->setCellValue($cell, $cantidad);
                $sheet->getStyle($cell)->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF92D050']],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);
            }
        }
        $fila++;
    }

    $ultimaFila = max(5, $fila - 1);
    $finalizarHoja($sheet, 4, $ultimaFila, max(1, $ultimaColumna));
    $colAvance = Coordinate::stringFromColumnIndex($inicioGantt - 1);
    $sheet->getStyle("{$colAvance}5:{$colAvance}{$ultimaFila}")->getNumberFormat()->setFormatCode('0.0%');
    $primeraMetrica = Coordinate::stringFromColumnIndex($incluyeRegion ? 9 : 8);
    $ultimaMetrica = Coordinate::stringFromColumnIndex($inicioGantt - 2);
    $sheet->getStyle("{$primeraMetrica}5:{$ultimaMetrica}{$ultimaFila}")
        ->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getColumnDimension('A')->setWidth(24);
    $sheet->getColumnDimension('B')->setWidth(38);
    $sheet->getColumnDimension('C')->setWidth(14);
    if ($incluyeRegion) {
        $sheet->getColumnDimension('D')->setWidth(24);
    }
    for ($col = $incluyeRegion ? 5 : 4; $col < $inicioGantt; $col++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(18);
    }
    foreach ($fechasGantt as $index => $_fecha) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($inicioGantt + $index))->setWidth(5);
    }
};

$crearHojaGantt('Carta Gantt', 'Carta Gantt por Campaña', $datosCampana['metricas'], false);
$crearHojaGantt('Gantt Detallada', 'Carta Gantt Detallada por Campaña y Región', $datosRegion['metricas'], true);
foreach ($spreadsheet->getAllSheets() as $sheet) {
    $sheet->unfreezePane();
}
$spreadsheet->setActiveSheetIndex(0);

$downloadToken = $_GET['download_token'] ?? '';
if ($downloadToken !== '') {
    setcookie('fileDownloadToken', $downloadToken, 0, '/');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = 'Carta_Gantt_Campanas_Activas_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(true);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;

$html = <<<HTML
<html>
  <head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
      table { width: 100%; table-layout: auto; border-collapse: collapse; }
      th, td {
        border: 1px solid #666;
        padding: 4px;
        white-space: normal;
        word-wrap: break-word;
        vertical-align: top;
      }
      img.inline-img {
        max-width: 120px;
        max-height: 120px;
        cursor: pointer;
      }
      .info-box {
        background-color: #f0f0f0;
        border: 2px solid #333;
        padding: 10px;
        margin-bottom: 20px;
        font-size: 10pt;
      }
      .info-box table {
        width: auto;
        border: none;
      }
      .info-box td {
        border: none;
        padding: 3px 10px;
      }
      .sheet-break {
        page-break-before: always;
        mso-special-character: line-break;
      }
      .gantt-table th {
        background-color: #d9ead3;
        font-weight: bold;
        text-align: center;
      }
      .gantt-hit {
        background-color: #92d050;
        text-align: center;
        font-weight: bold;
      }
    </style>
  </head>
  <body>
HTML;

// Para el descarga masiva, recopilamos los datos de todas las campañas
$allCampaignsData = [];
foreach ($reportes as $rep) {
    if (!empty($rep['campaign'])) {
        $allCampaignsData[] = $rep['campaign'];
    }
}

$html .= renderResumenYGantt($localesGlobal);

$html .= renderSeccionTablas(
    $localesGlobal,
    $encuestaGlobal,
    $inline,
    $fotosLocalesGlobal,
    $maxFotosLocalesGlobal,
    $allCampaignsData
);

if ($inline) {
    $html .= <<<HTML
<!-- Modal -->
<div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 bg-transparent">
      <div class="modal-body p-0 text-center">
        <img id="modalImg" src="" class="img-fluid rounded">
      </div>
    </div>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script>
  $('#imgModal').on('show.bs.modal', function(e) {
    var src = $(e.relatedTarget).data('src');
    $('#modalImg').attr('src', src);
  });
</script>
</body></html>
HTML;

    echo $html;
    exit;
}

$html .= '</body></html>';

$content = "\xEF\xBB\xBF" . $html;
$content = strtr(
    $content,
    [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U',
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'Ñ'=>'N','ñ'=>'n',
        'Ü'=>'U','ü'=>'u'
    ]
);

$downloadToken = $_GET['download_token'] ?? '';
if ($downloadToken !== '') {
    setcookie('fileDownloadToken', $downloadToken, 0, '/');
}

header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header("Expires: 0");
header('Content-Disposition: attachment; filename=Reporte_Masivo_' . date('Ymd_His') . '.xls');
header("Content-Length: " . strlen($content));
echo $content;
exit;
