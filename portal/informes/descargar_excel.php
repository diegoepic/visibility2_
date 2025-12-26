<?php 
ob_clean();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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
 * Idempotente (no redefine si ya existe).
 */
if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// 1) Validar parámetros
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
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
// Funciones de obtención de datos (sin filtro de fechas)
// -----------------------------------------------------------------------------

function getCampaignData($idForm) {
    global $conn;
    $sql = "
        SELECT 
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino,
            e.nombre AS nombre_empresa,
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
        INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
        INNER JOIN local l               ON l.id = fq.id_local
        WHERE f.id = ?
        GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre
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
                WHEN IFNULL(fq.valor, 0) >= 1 THEN 'IMPLEMENTADO'
                WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO IMPLEMENTADO'
                WHEN LOWER(fq.pregunta) = 'solo_implementado'      THEN 'IMPLEMENTADO'
                WHEN LOWER(fq.pregunta) = 'solo_auditado'          THEN 'AUDITORIA'
                WHEN LOWER(fq.pregunta) = 'solo_auditoria'         THEN 'AUDITORIA'
                WHEN LOWER(fq.pregunta) = 'retiro'                 THEN 'RETIRO'
                WHEN LOWER(fq.pregunta) = 'entrega'                THEN 'ENTREGA'
                WHEN LOWER(fq.pregunta) = 'implementado_auditado'  THEN 'IMPLEMENTADO/AUDITADO'
                ELSE 'NO IMPLEMENTADO'
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

        // Si ya es absoluta, devolver tal cual
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // Quitar slash inicial y agregar prefijo completo para hacerlo clickeable
        $url = ltrim($url, '/');
        return 'https://www.visibility.cl/visibility2/app/' . $url;
    };

    $fqIds = array_values(array_filter(array_unique(array_map('intval', $fqIds)), function ($v) {
        return $v > 0;
    }));

    if (empty($fqIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($fqIds), '?'));
    $types        = 'i' . str_repeat('i', count($fqIds));
    $sql          = "
        SELECT id_formularioQuestion, url
        FROM fotoVisita
        WHERE id_formulario = ?
          AND id_formularioQuestion IN ($placeholders)
        ORDER BY id ASC
    ";

    $stmt       = $conn->prepare($sql);
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

function renderValorConImagen(string $valor, bool $inline): string {
    $vs = trim($valor);
    if ($vs === '') {
        return '';
    }

    // Soportar varias URLs separadas por ';'
    $parts = preg_split('/\s*;\s*/', $vs);

    // MODO INLINE (vista en navegador)
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

    // MODO EXCEL (no inline): siempre hipervínculos
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

    // 3.1) Traer el orden de preguntas (cabeceras)
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

    // 3.2) Consulta pivot-friendly (cumple ONLY_FULL_GROUP_BY)
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

    // 3.3) Agrupar en estructura {local+fecha} → {pregunta: respuestas}
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

    // 3.4) Pivot final con todas las preguntas en columnas
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

    // 3.5) Eliminar columnas *_valor vacías
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
// Generador de HTML/XLS
// -----------------------------------------------------------------------------

function generarExcel($campaign, $locales, $encuesta, $archivo = null, $inline = false, $fotosLocales = [], $maxFotosLocales = 0) {
    $normalizarUrlEncuesta = function (string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Si ya es absoluta, devolver tal cual
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // Solo intentar normalizar si parece ruta de imagen
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

    // Normalizar URLs de imágenes en las respuestas de encuesta
    foreach ($encuesta as &$fila) {
        foreach ($fila as $col => $valor) {
            $valorStr = trim((string)($valor ?? ''));
            if ($valorStr === '') {
                continue;
            }

            // Soporta una o varias URLs separadas por ';'
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
                // Volvemos a unir por '; ' para no cambiar el formato
                $fila[$col] = implode('; ', $parts);
            }
        }
    }
    unset($fila);

    // Construcción del HTML
    $html = <<<HTML
<html>
  <head>
    <meta charset="UTF-8">
    <!-- Bootstrap CSS para el modal -->
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
    </style>
  </head>
  <body>
HTML;

    // —– Detalle de Locales —— 
    if (!empty($locales)) {
        $html .= "<b>Detalle de Locales</b>
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
                      <th>FECHA PLANIFICADA</th>
                      <th>FECHA VISITA</th>
                      <th>HORA</th>
                      <th>ESTADO VISITA</th>
                      <th>ESTADO ACTIVIDAD</th>
                      <th>MOTIVO</th>
                      <th>MATERIAL</th>
                      <th>CANTIDAD MATERIAL EJECUTADO</th>
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

    // —– Encuesta Pivot —— 
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

    // Modal de Bootstrap para ver la imagen ampliada (solo si es inline)
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

<!-- Scripts de Bootstrap y JS para manejar el clic -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script>
  // Al abrir el modal, toma la URL del atributo data-src de la miniatura
  $('#imgModal').on('show.bs.modal', function(e) {
    var src = $(e.relatedTarget).data('src');
    $('#modalImg').attr('src', src);
  });
</script>
</body></html>
HTML;
    } else {
        // Cierre normal de HTML si no es inline
        $html .= "</body></html>";
    }

    // Si es inline, devolvemos el HTML sin forzar descarga
    if ($inline) {
        return $html;
    }

    // Si no es inline, forzamos descarga Excel
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

// Inicializamos vacíos
$localesDetails = [];
$encuestaPivot  = [];

// Modalidad inteligente
switch (strtolower(trim($modalidad))) {
    case 'solo_implementacion':
        $localesDetails = getLocalesDetails($formulario_id);
        break;

    case 'solo_auditoria':
        $encuestaPivot = getEncuestaPivot($formulario_id);
        break;

    case 'implementacion_auditoria':
        $localesDetails = getLocalesDetails($formulario_id);
        $encuestaPivot  = getEncuestaPivot($formulario_id);
        break;

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

// Normaliza número de local en caso necesario
foreach ($localesDetails as &$loc) {
    if (empty($loc['numero_local'])) {
        $loc['numero_local'] = preg_replace('/\D+/', '', (string)($loc['codigo_local'] ?? ''));
    }
}
unset($loc);

// Si no hay datos
if (empty($campaignData) && empty($localesDetails) && empty($encuestaPivot)) {
    die("No se encontraron datos para la campaña.");
}

// Nombre archivo
$nombreCampaña = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$nombreForm);
$archivo = "Reporte_{$nombreCampaña}_" . date('Y-m-d_His') . ".xls";

// Render / descarga
if ($inline) {
    echo generarExcel($campaignData, $localesDetails, $encuestaPivot, null, true, $fotosLocales, $maxFotosLocales);
    exit();
}

generarExcel($campaignData, $localesDetails, $encuestaPivot, $archivo, false, $fotosLocales, $maxFotosLocales);
?>