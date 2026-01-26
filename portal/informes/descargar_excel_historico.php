<?php
ob_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/descargar_excel_historico.error.log');
error_reporting(E_ALL);

ini_set('memory_limit', '1024M');
set_time_limit(0);

// Iniciar sesión si aún no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Liberar la sesión para evitar bloqueos
session_write_close();

// Incluir el archivo de conexión a la base de datos
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
mysqli_set_charset($conn, 'utf8mb4');

/**
 * Helper de escape seguro que convierte null -> '' y castea a string
 */
if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// 1) Validar parámetros
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    if (ob_get_length()) { ob_end_clean(); }
    die('ID de formulario inválido.');
}
$formulario_id         = intval($_GET['id']);
$inline                = isset($_GET['inline']) && $_GET['inline'] === '1';
$incluirFotosMaterial  = !isset($_GET['fotos'])
    || $_GET['fotos'] === '1'
    || strtolower((string)$_GET['fotos']) === 'true';
$incluirFotosEncuesta  = !isset($_GET['fotos_encuesta'])
    || $_GET['fotos_encuesta'] === '1'
    || strtolower((string)$_GET['fotos_encuesta']) === 'true';

$stmt_form = $conn->prepare("SELECT nombre FROM formulario WHERE id = ? LIMIT 1");
$stmt_form->bind_param("i", $formulario_id);
$stmt_form->execute();
$stmt_form->bind_result($nombreForm);
if (!$stmt_form->fetch()) {
    if (ob_get_length()) { ob_end_clean(); }
    die("Formulario no encontrado.");
}
$stmt_form->close();

$stmt_modal = $conn->prepare("SELECT modalidad FROM formulario WHERE id = ? LIMIT 1");
$stmt_modal->bind_param("i", $formulario_id);
$stmt_modal->execute();
$stmt_modal->bind_result($modalidad);
$stmt_modal->fetch();
$stmt_modal->close();

// -----------------------------------------------------------------------------
// Funciones de obtención de datos DESDE gestion_visita + visita (HISTÓRICO)
// -----------------------------------------------------------------------------

function getCampaignData($idForm) {
    global $conn;
    $sql = "
        SELECT
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino,
            f.modalidad,
            f.tipo,
            e.nombre AS nombre_empresa,
            de.nombre AS nombre_division,

            /* Locales programados: desde formularioQuestion (base) */
            (SELECT COUNT(DISTINCT l2.codigo)
             FROM formularioQuestion fq2
             JOIN local l2 ON l2.id = fq2.id_local
             WHERE fq2.id_formulario = f.id) AS locales_programados,

            /* Locales visitados: desde gestion_visita */
            (SELECT COUNT(DISTINCT l3.codigo)
             FROM gestion_visita gv3
             JOIN local l3 ON l3.id = gv3.id_local
             WHERE gv3.id_formulario = f.id
               AND gv3.fecha_visita IS NOT NULL
               AND gv3.fecha_visita <> '0000-00-00 00:00:00') AS locales_visitados,

            /* Locales implementados: desde gestion_visita */
            (SELECT COUNT(DISTINCT l4.codigo)
             FROM gestion_visita gv4
             JOIN local l4 ON l4.id = gv4.id_local
             WHERE gv4.id_formulario = f.id
               AND (IFNULL(gv4.valor_real, 0) > 0
                    OR LOWER(gv4.estado_gestion) IN ('implementado_auditado','solo_implementado','solo_auditoria'))) AS locales_implementados

        FROM formulario f
        INNER JOIN empresa e             ON e.id = f.id_empresa
        LEFT JOIN division_empresa de    ON de.id = f.id_division
        WHERE f.id = ?
        GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, f.modalidad, f.tipo, e.nombre, de.nombre
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Obtiene el detalle histórico desde gestion_visita + visita
 * Una fila por cada registro en gestion_visita (histórico completo)
 */
function getLocalesDetailsHistorico($idForm) {
    global $conn;
    $sql = "
        SELECT
            l.id                               AS idLocal,
            gv.id                              AS id_gestion_visita,
            gv.id_formularioQuestion           AS id_formularioQuestion,
            l.codigo                           AS codigo_local,
            CASE
              WHEN l.nombre REGEXP '^[0-9]+'
              THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
              ELSE CAST(l.codigo AS UNSIGNED)
            END AS numero_local,
            f.modalidad AS modalidad,
            UPPER(f.nombre) AS nombreCampaña,
            DATE(f.fechaInicio) AS fechaInicio,
            DATE(f.fechaTermino) AS fechaTermino,

            /* Fecha y hora de la visita desde gestion_visita o visita */
            DATE(COALESCE(
                NULLIF(gv.fecha_visita, '0000-00-00 00:00:00'),
                v.fecha_fin,
                v.fecha_inicio
            )) AS fechaVisita,
            TIME(COALESCE(
                NULLIF(gv.fecha_visita, '0000-00-00 00:00:00'),
                v.fecha_fin,
                v.fecha_inicio
            )) AS hora,

            /* Fecha planificada desde formularioQuestion si existe */
            DATE(fq.fechaPropuesta) AS fechaPropuesta,

            UPPER(l.nombre) AS nombre_local,
            UPPER(l.direccion) AS direccion_local,
            UPPER(cm.comuna) AS comuna,
            UPPER(re.region) AS region,
            UPPER(cu.nombre) AS cuenta,
            UPPER(ca.nombre) AS cadena,

            /* Material: preferir catálogo, si no usar el de FQ */
            UPPER(COALESCE(m.nombre, fq.material, 'N/A')) AS material,

            UPPER(jv.nombre) AS jefeVenta,

            /* Valor propuesto desde FQ */
            fq.valor_propuesto,

            /* Valor ejecutado desde gestion_visita */
            gv.valor_real AS valor,

            /* Observación: priorizar gestion_visita */
            UPPER(COALESCE(gv.observacion, fq.observacion, '')) AS observacion,

            /* Estado visita */
            CASE
                WHEN gv.fecha_visita IS NOT NULL
                     AND gv.fecha_visita <> '0000-00-00 00:00:00'
                THEN 'VISITADO'
                WHEN v.id IS NOT NULL
                THEN 'VISITADO'
                ELSE 'NO VISITADO'
            END AS ESTADO_VISTA,

            /* Estado actividad basado en gestion_visita */
            CASE
                WHEN f.modalidad = 'retiro' THEN
                    CASE
                        WHEN IFNULL(gv.valor_real, 0) >= 1 THEN 'RETIRADO'
                        WHEN LOWER(gv.estado_gestion) = 'solo_implementado' THEN 'RETIRADO'
                        WHEN LOWER(gv.estado_gestion) = 'implementado_auditado' THEN 'RETIRADO'
                        ELSE 'NO RETIRADO'
                    END
                WHEN f.modalidad = 'entrega' THEN
                    CASE
                        WHEN IFNULL(gv.valor_real, 0) >= 1 THEN 'ENTREGADO'
                        WHEN LOWER(gv.estado_gestion) = 'solo_implementado' THEN 'ENTREGADO'
                        WHEN LOWER(gv.estado_gestion) = 'implementado_auditado' THEN 'ENTREGADO'
                        ELSE 'NO ENTREGADO'
                    END
                ELSE
                    CASE
                        WHEN IFNULL(gv.valor_real, 0) >= 1 THEN 'IMPLEMENTADO'
                        WHEN LOWER(gv.estado_gestion) = 'solo_implementado'      THEN 'IMPLEMENTADO'
                        WHEN LOWER(gv.estado_gestion) = 'solo_auditado'          THEN 'AUDITORIA'
                        WHEN LOWER(gv.estado_gestion) = 'solo_auditoria'         THEN 'AUDITORIA'
                        WHEN LOWER(gv.estado_gestion) = 'retiro'                 THEN 'RETIRO'
                        WHEN LOWER(gv.estado_gestion) = 'entrega'                THEN 'ENTREGA'
                        WHEN LOWER(gv.estado_gestion) = 'implementado_auditado'  THEN 'IMPLEMENTADO/AUDITADO'
                        WHEN LOWER(gv.estado_gestion) = 'pendiente'              THEN 'PENDIENTE'
                        WHEN LOWER(gv.estado_gestion) = 'cancelado'              THEN 'CANCELADO'
                        ELSE 'NO IMPLEMENTADO'
                    END
            END AS ESTADO_ACTIVIDAD,

            /* Motivo: priorizar gestion_visita */
            UPPER(
              REPLACE(
                COALESCE(
                  NULLIF(gv.motivo_no_implementacion, ''),
                  NULLIF(TRIM(SUBSTRING_INDEX(REPLACE(COALESCE(gv.observacion,''),'|','-'),'-',1)), ''),
                  gv.estado_gestion,
                  ''
                )
              , '_', ' ')
            ) AS MOTIVO,

            UPPER(u.usuario) AS gestionado_por,

            /* ID de visita para referencia */
            gv.visita_id

        FROM gestion_visita gv
        INNER JOIN formulario   f  ON f.id  = gv.id_formulario
        INNER JOIN local        l  ON l.id  = gv.id_local
        LEFT  JOIN visita       v  ON v.id  = gv.visita_id
        LEFT  JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
        LEFT  JOIN material     m  ON m.id  = gv.id_material
        LEFT  JOIN jefe_venta   jv ON jv.id = l.id_jefe_venta
        INNER JOIN usuario      u  ON u.id  = gv.id_usuario
        INNER JOIN cuenta       cu ON cu.id = l.id_cuenta
        INNER JOIN cadena       ca ON ca.id = l.id_cadena
        INNER JOIN comuna       cm ON cm.id = l.id_comuna
        INNER JOIN region       re ON re.id = cm.id_region
        WHERE f.id = ?
        ORDER BY l.codigo, gv.fecha_visita ASC, gv.created_at ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        if (ob_get_length()) { ob_end_clean(); }
        die("Error en getLocalesDetailsHistorico: " . $conn->error);
    }
    return $res->fetch_all(MYSQLI_ASSOC);
}

function getFotosImplementaciones($idForm, array $gvIds): array {
    global $conn;

    $normalizarFoto = function (string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $url = ltrim($url, '/');
        return 'https://www.visibility.cl/visibility2/app/' . $url;
    };

    $gvIds = array_values(array_filter(array_unique(array_map('intval', $gvIds)), function ($v) {
        return $v > 0;
    }));

    if (empty($gvIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($gvIds), '?'));
    $types        = 'i' . str_repeat('i', count($gvIds));

    // Buscar fotos por id_formularioQuestion (vinculado a gestion_visita)
    $sql = "
        SELECT fv.id_formularioQuestion, fv.url
        FROM fotoVisita fv
        WHERE fv.id_formulario = ?
          AND fv.id_formularioQuestion IN (
              SELECT gv.id_formularioQuestion
              FROM gestion_visita gv
              WHERE gv.id IN ($placeholders)
          )
        ORDER BY fv.id ASC
    ";

    $stmt       = $conn->prepare($sql);
    $params     = array_merge([$types], [$idForm], $gvIds);
    $bindParams = [];
    foreach ($params as $k => $v) {
        $bindParams[$k] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        return [];
    }

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $fqId = (int)$row['id_formularioQuestion'];
        $out[$fqId][] = $normalizarFoto($row['url']);
    }
    return $out;
}

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
            $safe = e($p);
            $out[] =
                '<a href="' . $safe . '" target="_blank">' .
                    '<img class="inline-img" src="' . $safe . '" ' .
                        'data-toggle="modal" data-target="#imgModal" data-src="' . $safe . '">' .
                '</a>';
        }
        return $out ? implode('<br>', $out) : e($vs);
    }

    $out = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        if (preg_match('#^https?://#i', $p)) {
            $safe = e($p);
            $out[] = '<a href="' . $safe . '">' . $safe . '</a>';
        } else {
            $out[] = e($p);
        }
    }
    return $out ? implode('; ', $out) : e($vs);
}

function getEncuestaPivot($idForm) {
    global $conn;

    $allQuestions = [];
    $qry = "
        SELECT question_text
        FROM form_questions
        WHERE id_formulario = ?
        ORDER BY sort_order
    ";
    $stmtQ = $conn->prepare($qry);
    $stmtQ->bind_param('i', $idForm);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();
    if (!$resQ) {
        if (ob_get_length()) { ob_end_clean(); }
        die("Error al obtener preguntas: " . $conn->error);
    }
    while ($rQ = $resQ->fetch_assoc()) {
        $allQuestions[] = $rQ['question_text'];
    }
    $stmtQ->close();

    $sql = "
        SELECT
            f.id AS idCampana,
            ANY_VALUE(UPPER(f.nombre))           AS nombreCampana,
            l.codigo                             AS codigo_local,
            ANY_VALUE(
              CASE
                WHEN l.nombre REGEXP '^[0-9]+'
                THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
                ELSE ''
              END
            )                                    AS numero_local,
            ANY_VALUE(UPPER(l.nombre))           AS nombre_local,
            ANY_VALUE(UPPER(l.direccion))        AS direccion_local,
            ANY_VALUE(UPPER(cu.nombre))          AS cuenta,
            ANY_VALUE(UPPER(ca.nombre))          AS cadena,
            ANY_VALUE(UPPER(cm.comuna))          AS comuna,
            ANY_VALUE(UPPER(re.region))          AS region,
            ANY_VALUE(UPPER(u.usuario))          AS usuario,
            DATE(fqr.created_at)                 AS fechaVisita,
            UPPER(fp.question_text)              AS question_text,
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
        GROUP BY
            l.codigo,
            DATE(fqr.created_at),
            fp.question_text
        ORDER BY l.codigo, fp.question_text
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        if (ob_get_length()) { ob_end_clean(); }
        die("Error en getEncuestaPivot: " . $conn->error);
    }

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $key = $row['codigo_local'] . '_' . $row['fechaVisita'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'ID CAMPAÑA'     => $row['idCampana'],
                'CODIGO LOCAL'   => $row['codigo_local'],
                'N° LOCAL'       => $row['numero_local'],
                'NOMBRE CAMPAÑA' => $row['nombreCampana'],
                'LOCAL'          => $row['nombre_local'],
                'DIRECCION'      => $row['direccion_local'],
                'CUENTA'         => $row['cuenta'],
                'CADENA'         => $row['cadena'],
                'COMUNA'         => $row['comuna'],
                'REGION'         => $row['region'],
                'USUARIO'        => $row['usuario'],
                'FECHA VISITA'   => $row['fechaVisita'],
                'questions'      => []
            ];
        }
        $q = $row['question_text'];
        $grouped[$key]['questions'][$q] = [
            'answer' => $row['concat_answers'] ?? '',
            'valor'  => $row['concat_valores'] ?? ''
        ];
    }

    $final = [];
    foreach ($grouped as $g) {
        $rowOut = [
            'ID CAMPAÑA'     => $g['ID CAMPAÑA'],
            'CODIGO LOCAL'   => $g['CODIGO LOCAL'],
            'N° LOCAL'       => $g['N° LOCAL'],
            'NOMBRE CAMPAÑA' => $g['NOMBRE CAMPAÑA'],
            'CUENTA'         => $g['CUENTA'],
            'CADENA'         => $g['CADENA'],
            'LOCAL'          => $g['LOCAL'],
            'DIRECCION'      => $g['DIRECCION'],
            'COMUNA'         => $g['COMUNA'],
            'REGION'         => $g['REGION'],
            'USUARIO'        => $g['USUARIO'],
            'FECHA VISITA'   => $g['FECHA VISITA']
        ];
        foreach ($allQuestions as $q) {
            if (isset($g['questions'][$q])) {
                $rowOut[$q]            = $g['questions'][$q]['answer'];
                $rowOut[$q . '_valor'] = $g['questions'][$q]['valor'];
            } else {
                $rowOut[$q]            = '';
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
            if (!$has) unset($r[$c]);
        }
    }
    unset($r);

    return $final;
}

// -----------------------------------------------------------------------------
// Generador de HTML/XLS (idéntico a descargar_excel.php)
// -----------------------------------------------------------------------------

function generarExcel($campaign, $locales, $encuesta, $archivo = null, $inline = false, $fotosLocales = [], $maxFotosLocales = 0, $modalidad = '') {
    $normalizarUrlEncuesta = function (string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (!preg_match('#(uploads|visibility2|app/|pregunta_)\b#i', $url)
            && !preg_match('#\.(webp|jpe?g|png|gif)$#i', $url)) {
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
        if (preg_match('#^uploads_fotos_pregunta/#i', $url)) {
            return $baseUrl . 'visibility2/app/uploads/' . $urlLow;
        }
        if (preg_match('#^uploads/#i', $url)) {
            return $baseUrl . 'visibility2/app/' . $urlLow;
        }

        return $baseUrl . 'visibility2/app/' . $urlLow;
    };

    foreach ($encuesta as &$fila) {
        foreach ($fila as $col => $valor) {
            $valorStr = trim((string)($valor ?? ''));
            if ($valorStr === '') {
                continue;
            }

            $parts   = array_map('trim', explode(';', $valorStr));
            $changed = false;

            foreach ($parts as &$p) {
                if ($p === '') {
                    continue;
                }
                $normalized = $normalizarUrlEncuesta($p);
                if ($normalized !== $p) {
                    $p       = $normalized;
                    $changed = true;
                }
            }
            unset($p);

            if ($changed) {
                $fila[$col] = implode('; ', $parts);
            }
        }
    }
    unset($fila);

    $modalidadLower = strtolower(trim($modalidad));
    $etiquetaMaterial = 'MATERIAL';
    $etiquetaCantidad = 'CANTIDAD MATERIAL EJECUTADO';

    if ($modalidadLower === 'retiro') {
        $etiquetaMaterial = 'MATERIAL RETIRADO';
        $etiquetaCantidad = 'CANTIDAD MATERIAL RETIRADO';
    } elseif ($modalidadLower === 'entrega') {
        $etiquetaMaterial = 'MATERIAL ENTREGADO';
        $etiquetaCantidad = 'CANTIDAD MATERIAL ENTREGADO';
    }

    $html = <<<HTML
<html>
  <head>
    <meta charset="UTF-8">
    <link rel="stylesheet"
          href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
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
      .historico-badge {
        background-color: #17a2b8;
        color: white;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 9pt;
        margin-left: 10px;
      }
    </style>
  </head>
  <body>
HTML;

    // Recuadro de Información del Formulario
    if (!empty($campaign)) {
        $campInfo = $campaign[0];
        $fechaInicio = !empty($campInfo['fechaInicio']) ? date('d-m-Y', strtotime($campInfo['fechaInicio'])) : '-';
        $fechaTermino = !empty($campInfo['fechaTermino']) ? date('d-m-Y', strtotime($campInfo['fechaTermino'])) : '-';
        $modalidadDisplay = ucwords(str_replace('_', ' ', $campInfo['modalidad'] ?? '-'));

        $tipoDisplay = '-';
        if (isset($campInfo['tipo'])) {
            switch ($campInfo['tipo']) {
                case 1:
                    $tipoDisplay = 'Campaña Programada';
                    break;
                case 2:
                    $tipoDisplay = 'Campaña Complementaria';
                    break;
                case 3:
                    $tipoDisplay = 'Campaña IPT';
                    break;
                default:
                    $tipoDisplay = 'Tipo ' . $campInfo['tipo'];
            }
        }

        $html .= "<div class='info-box'>";
        $html .= "<b>DATOS DEL FORMULARIO</b><span class='historico-badge'>REPORTE HISTORICO</span><br>";
        $html .= "<table>";
        $html .= "<tr><td><b>Campaña:</b></td><td>" . e($campInfo['nombre']) . "</td></tr>";
        $html .= "<tr><td><b>Fecha Inicio:</b></td><td>" . e($fechaInicio) . "</td></tr>";
        $html .= "<tr><td><b>Fecha Término:</b></td><td>" . e($fechaTermino) . "</td></tr>";
        $html .= "<tr><td><b>Modalidad:</b></td><td>" . e($modalidadDisplay) . "</td></tr>";
        $html .= "<tr><td><b>Tipo:</b></td><td>" . e($tipoDisplay) . "</td></tr>";
        $html .= "<tr><td><b>Empresa:</b></td><td>" . e($campInfo['nombre_empresa'] ?? '-') . "</td></tr>";
        $html .= "<tr><td><b>División:</b></td><td>" . e($campInfo['nombre_division'] ?? '-') . "</td></tr>";
        $html .= "<tr><td><b>Fuente de datos:</b></td><td>gestion_visita + visita (histórico)</td></tr>";
        $html .= "</table>";
        $html .= "</div>";
    }

    // Detalle de Locales
    if (!empty($locales)) {
        $html .= "<b>Detalle de Locales (Histórico de Visitas)</b>
                  <table border='1'
                         style='border-collapse:collapse; table-layout:auto; font-size:9pt;'>
                    <tr>
                      <th>ID LOCAL</th>
                      <th>CODIGO</th>
                      <th>N° LOCAL</th>
                      <th>CAMPAÑA</th>
                      <th>CUENTA</th>
                      <th>CADENA</th>
                      <th>LOCAL</th>
                      <th>DIRECCION</th>
                      <th>COMUNA</th>
                      <th>REGION</th>
                      <th>JEFE VENTA</th>
                      <th>USUARIO</th>
                      <th>FECHA INICIO</th>
                      <th>FECHA TÉRMINO</th>
                      <th>FECHA PLANIFICADA</th>
                      <th>FECHA VISITA</th>
                      <th>HORA</th>
                      <th>ESTADO VISITA</th>
                      <th>ESTADO ACTIVIDAD</th>
                      <th>MOTIVO</th>
                      <th>{$etiquetaMaterial}</th>
                      <th>{$etiquetaCantidad}</th>
                      <th>MATERIAL PROPUESTO</th>
                      <th>OBSERVACION</th>
";

        if ($maxFotosLocales > 0) {
            for ($i = 1; $i <= $maxFotosLocales; $i++) {
                $html .= "                      <th>FOTO {$i}</th>\n";
            }
        }

        $html .= "
                    </tr>";

        foreach ($locales as $l) {
            $fechaPropuesta = ($l['fechaPropuesta'] !== null && $l['fechaPropuesta'] !== '0000-00-00')
                              ? $l['fechaPropuesta']
                              : '-';
            $fechaVisita    = ($l['fechaVisita'] !== null && $l['fechaVisita'] !== '0000-00-00')
                              ? $l['fechaVisita']
                              : '-';

            $fechaInicioCamp = ($l['fechaInicio'] !== null && $l['fechaInicio'] !== '0000-00-00')
                              ? $l['fechaInicio']
                              : '-';
            $fechaTerminoCamp = ($l['fechaTermino'] !== null && $l['fechaTermino'] !== '0000-00-00')
                              ? $l['fechaTermino']
                              : '-';

            $html .= "<tr>
                        <td>" . e($l['idLocal']) . "</td>
                        <td>" . e($l['codigo_local']) . "</td>
                        <td>" . e($l['numero_local']) . "</td>
                        <td>" . e($l['nombreCampaña']) . "</td>
                        <td>" . e($l['cuenta']) . "</td>
                        <td>" . e($l['cadena']) . "</td>
                        <td>" . e($l['nombre_local']) . "</td>
                        <td>" . e($l['direccion_local']) . "</td>
                        <td>" . e($l['comuna']) . "</td>
                        <td>" . e($l['region']) . "</td>
                        <td>" . e($l['jefeVenta']) . "</td>
                        <td>" . e($l['gestionado_por']) . "</td>
                        <td>{$fechaInicioCamp}</td>
                        <td>{$fechaTerminoCamp}</td>
                        <td>{$fechaPropuesta}</td>
                        <td>{$fechaVisita}</td>
                        <td>" . e($l['hora']) . "</td>
                        <td>" . e($l['ESTADO_VISTA']) . "</td>
                        <td>" . e($l['ESTADO_ACTIVIDAD']) . "</td>
                        <td>" . e($l['MOTIVO']) . "</td>
                        <td>" . e($l['material']) . "</td>
                        <td>" . e($l['valor']) . "</td>
                        <td>" . e($l['valor_propuesto']) . "</td>
                        <td>" . e($l['observacion']) . "</td>";

            $fotos = [];
            if (!empty($l['id_formularioQuestion']) && isset($fotosLocales[$l['id_formularioQuestion']])) {
                $fotos = $fotosLocales[$l['id_formularioQuestion']];
            }

            for ($fi = 0; $fi < $maxFotosLocales; $fi++) {
                $url = trim((string)($fotos[$fi] ?? ''));
                $html .= "<td>" . renderValorConImagen($url, $inline) . "</td>";
            }

            $html .= "</tr>";
        }

        $html .= "</table><br>";
    }

    // Encuesta Pivot
    $html .= "<b>Encuesta</b>
              <table border='1'
                     style='border-collapse:collapse; table-layout:auto; font-size:9pt;'>
                <tr>";
    if (!empty($encuesta)) {
        $keys = array_keys($encuesta[0]);
        foreach ($keys as $k) {
            $html .= "<th>" . e($k) . "</th>";
        }
    }
    $html .= "</tr>";
    foreach ($encuesta as $row) {
        $html .= "<tr>";
        foreach ($row as $v) {
            $vs = (string)($v ?? '');
            $html .= "<td>" . renderValorConImagen($vs, $inline) . "</td>";
        }
        $html .= "</tr>";
    }
    $html .= "</table>";

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
    } else {
        $html .= "</body></html>";
    }

    if ($inline) {
        return $html;
    }

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

    if (ob_get_length()) { ob_end_clean(); }

    header("Cache-Control: no-cache, must-revalidate");
    header("Pragma: no-cache");
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Expires: 0");
    header("Content-Disposition: attachment; filename={$archivo}");
    header("Content-Length: " . strlen($content));
    echo $content;
    exit();
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

// -----------------------------------------------------------------------------
// Punto de entrada
// -----------------------------------------------------------------------------
$campaignData = getCampaignData($formulario_id);

$localesDetails = [];
$encuestaPivot  = [];

switch (strtolower(trim($modalidad))) {
    case 'solo_implementacion':
        $localesDetails = getLocalesDetailsHistorico($formulario_id);
        break;

    case 'solo_auditoria':
        $encuestaPivot = getEncuestaPivot($formulario_id);
        break;

    case 'implementacion_auditoria':
        $localesDetails = getLocalesDetailsHistorico($formulario_id);
        $encuestaPivot  = getEncuestaPivot($formulario_id);
        break;

    default:
        $localesDetails = getLocalesDetailsHistorico($formulario_id);
        $encuestaPivot  = getEncuestaPivot($formulario_id);
        break;
}

$fotosLocales    = [];
$maxFotosLocales = 0;
if ($incluirFotosMaterial && !empty($localesDetails)) {
    $gvIds        = array_column($localesDetails, 'id_gestion_visita');
    $fotosLocales = getFotosImplementaciones($formulario_id, $gvIds);
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
    if (ob_get_length()) { ob_end_clean(); }
    die("No se encontraron datos históricos para la campaña.");
}

$nombreCampaña = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$nombreForm);
$archivo = "Historico_{$nombreCampaña}_" . date('Y-m-d_His') . ".xls";

if ($inline) {
    if (ob_get_length()) { ob_end_clean(); }
    echo generarExcel($campaignData, $localesDetails, $encuestaPivot, null, true, $fotosLocales, $maxFotosLocales, $modalidad);
    exit();
}

generarExcel($campaignData, $localesDetails, $encuestaPivot, $archivo, false, $fotosLocales, $maxFotosLocales, $modalidad);
