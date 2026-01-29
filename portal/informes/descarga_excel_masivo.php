<?php
// descargar_excel_masivo.php (HOMOLOGADO a descargar_excel.php en orden de columnas)

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
    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $stmt->bind_result($nombre, $modalidad);
    if (!$stmt->fetch()) {
        $stmt->close();
        return [];
    }
    $stmt->close();
    return ['nombre' => $nombre, 'modalidad' => $modalidad];
}

function getCampaignData($idForm) {
    global $conn;
    $sql = "
        SELECT
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino,
            f.modalidad AS modalidad,
            f.tipo,
            e.nombre AS nombre_empresa,
            de.nombre AS nombre_division,
            COUNT(DISTINCT l.codigo) AS locales_programados,
            SUM(
                CASE WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria',
                    'local_cerrado','no_permitieron'
                ) AND fq.fechaVisita <> '0000-00-00 00:00:00' THEN 1 ELSE 0 END
            ) AS locales_visitados,
            SUM(
                CASE WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria'
                ) THEN 1 ELSE 0 END
            ) AS locales_implementados
        FROM formulario f
        INNER JOIN empresa e             ON e.id = f.id_empresa
        LEFT JOIN division_empresa de    ON de.id = f.id_division
        INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
        INNER JOIN local l               ON l.id = fq.id_local
        WHERE f.id = ?
        GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, f.modalidad, f.tipo, e.nombre, de.nombre
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function getLocalesDetails($idForm) {
    global $conn;
    $sql = "
        SELECT
            l.id                               AS idLocal,
            fq.id                              AS id_formularioQuestion,
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
            DATE(fq.fechaVisita) AS fechaVisita,
            TIME(fq.fechaVisita) AS hora,
            DATE(fq.fechaPropuesta) AS fechaPropuesta,
            UPPER(l.nombre) AS nombre_local,
            UPPER(l.direccion) AS direccion_local,
            UPPER(cm.comuna) AS comuna,
            UPPER(re.region) AS region,
            UPPER(cu.nombre) AS cuenta,
            UPPER(ca.nombre) AS cadena,
            UPPER(fq.material) AS material,
            UPPER(jv.nombre) AS jefeVenta,
            fq.valor_propuesto,
            fq.valor,
            UPPER(fq.observacion) AS observacion,
            CASE
                WHEN fq.fechaVisita IS NOT NULL
                     AND fq.fechaVisita <> '0000-00-00 00:00:00'
                THEN 'VISITADO'
                ELSE 'NO VISITADO'
            END                                AS ESTADO_VISTA,
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
                        WHEN LOWER(fq.pregunta) = 'solo_implementado'      THEN 'IMPLEMENTADO'
                        WHEN LOWER(fq.pregunta) = 'solo_auditado'          THEN 'AUDITORIA'
                        WHEN LOWER(fq.pregunta) = 'solo_auditoria'         THEN 'AUDITORIA'
                        WHEN LOWER(fq.pregunta) = 'retiro'                 THEN 'RETIRO'
                        WHEN LOWER(fq.pregunta) = 'entrega'                THEN 'ENTREGA'
                        WHEN LOWER(fq.pregunta) = 'implementado_auditado'  THEN 'IMPLEMENTADO/AUDITADO'
                        ELSE 'NO IMPLEMENTADO'
                    END
            END AS ESTADO_ACTIVIDAD,
            UPPER(
              REPLACE(
                CASE
                  WHEN IFNULL(fq.valor,0) = 0 THEN
                    TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion,'|','-'),'-',1))
                  WHEN LOWER(fq.pregunta) IN ('en proceso','cancelado') THEN
                    TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion,'|','-'),'-',1))
                  WHEN LOWER(fq.pregunta) IN ('solo_implementado','solo_auditoria') THEN
                    ''
                  ELSE
                    fq.pregunta
                END
              , '_', ' ')
            ) AS MOTIVO,
            UPPER(u.usuario)                    AS gestionado_por
        FROM formularioQuestion fq
        INNER JOIN formulario   f  ON f.id  = fq.id_formulario
        INNER JOIN local        l  ON l.id  = fq.id_local
        LEFT  JOIN jefe_venta   jv ON jv.id = l.id_jefe_venta
        INNER JOIN usuario      u  ON u.id  = fq.id_usuario
        INNER JOIN cuenta       cu ON cu.id = l.id_cuenta
        INNER JOIN cadena       ca ON ca.id = l.id_cadena
        INNER JOIN comuna       cm ON cm.id = l.id_comuna
        INNER JOIN region       re ON re.id = cm.id_region
        WHERE f.id = ?
        ORDER BY l.codigo, fq.fechaVisita ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        die("Error en getLocalesDetails: " . $conn->error);
    }
    return $res->fetch_all(MYSQLI_ASSOC);
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
    if (!$resQ) die("Error al obtener preguntas: " . $conn->error);
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
    if (!$res) die("Error en getEncuestaPivot: " . $conn->error);

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $key = $row['codigo_local'] . '_' . $row['fechaVisita'];
        if (!isset($grouped[$key])) {
            // HOMOLOGADO: orden base igual al individual
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
        $q = $row['question_text'];
        $grouped[$key]['questions'][$q] = [
            'answer' => $row['concat_answers'] ?? '',
            'valor'  => $row['concat_valores'] ?? ''
        ];
    }

    $final = [];
    foreach ($grouped as $g) {
        // HOMOLOGADO: orden base igual al individual
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
                $rowOut[$q]            = $g['questions'][$q]['answer'];
                $rowOut[$q . '_valor'] = $g['questions'][$q]['valor'];
            } else {
                $rowOut[$q]            = '';
                $rowOut[$q . '_valor'] = '';
            }
        }
        $final[] = $rowOut;
    }

    // Eliminar columnas *_valor vacías
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
              .  "    <th>HORA</th>"
              .  "    <th>USUARIO</th>"
              .  "    <th>ESTADO VISITA</th>"
              .  "    <th>ESTADO ACTIVIDAD</th>"
              .  "    <th>MOTIVO</th>"
              .  "    <th>{$etiquetaMaterial}</th>"
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

            $html .= "<tr>
                        <td>" . e($l['idLocal']) . "</td>
                        <td>" . e($l['nombreCampaña']) . "</td>
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
                        <td>" . e($l['hora']) . "</td>
                        <td>" . e($l['gestionado_por']) . "</td>
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

header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header("Expires: 0");
header('Content-Disposition: attachment; filename=Reporte_Masivo_' . date('Ymd_His') . '.xls');
header("Content-Length: " . strlen($content));
echo $content;
exit;
