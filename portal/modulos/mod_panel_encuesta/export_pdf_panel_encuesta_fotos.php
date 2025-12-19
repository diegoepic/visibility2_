<?php
/**
 * export_pdf_panel_encuesta_fotos.php
 *
 * Endpoint dedicado SOLO a generar el PDF de fotos del Panel de Encuesta.
 * - Usa los mismos filtros que el panel (build_panel_encuesta_filters)
 * - Solo considera preguntas de tipo "foto" (via foto_only)
 * - Toma la ruta de la foto desde form_question_responses
 *   (answer_text / option_text)
 * - Embebe las imágenes como data URI (JPEG) para que Dompdf no tenga
 *   que leer archivos externos ni WebP directamente.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Sesión expirada");
}

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
@ini_set('memory_limit', '768M');
@set_time_limit(120);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_panel_encuesta/panel_encuesta_helpers.php';

$debugId = panel_encuesta_request_id();
header('X-Request-Id: '.$debugId);

$user_div   = (int)($_SESSION['division_id'] ?? 0);
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

$SRC = $_POST ?: $_GET;
$csrf_token = $SRC['csrf_token'] ?? '';
if (!panel_encuesta_validate_csrf(is_string($csrf_token) ? $csrf_token : '')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Token CSRF inválido.');
}

// -----------------------------------------------------------------------------
// Filtros compartidos con el panel (SOLO fotos)
// -----------------------------------------------------------------------------
list($whereSql, $types, $params, $metaFilters) =
    build_panel_encuesta_filters($empresa_id, $user_div, $SRC, [
        'foto_only'             => true,
        'enforce_date_fallback' => true,
    ]);

if (!empty($metaFilters['range_risky_no_scope'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Rango demasiado amplio sin filtros adicionales. Acota fechas o selecciona campaña.');
}

// Límite duro para no matar el servidor ni Dompdf
$MAX_ROWS = 250;

// -----------------------------------------------------------------------------
// Consulta principal: una fila por respuesta con foto
// - foto_url se saca desde form_question_responses (answer_text / option_text)
// - jefe_venta desde jefe_venta.nombre
// - vendedor desde vendedor.nombre_vendedor (join por id_vendedor externo)
// -----------------------------------------------------------------------------
$sql = "
  SELECT
    fqr.id AS resp_id,
    -- Ruta de la foto: primero answer_text (foto subida), fallback option_text
    CASE
      WHEN fqr.answer_text IS NOT NULL AND fqr.answer_text <> '' THEN fqr.answer_text
      WHEN o.option_text   IS NOT NULL AND o.option_text   <> '' THEN o.option_text
      ELSE NULL
    END AS foto_url,
    DATE_FORMAT(
      fqr.created_at,
      '%d/%m/%Y %H:%i:%s'
    ) AS fecha_foto,
    l.codigo           AS local_codigo,
    l.nombre           AS local_nombre,
    l.direccion        AS direccion,
    cm.comuna          AS comuna,
    cad.nombre         AS cadena,
    jv.nombre          AS jefe_venta,
    ve.nombre_vendedor AS vendedor_nombre,
    u.usuario          AS usuario
  FROM form_question_responses fqr
  JOIN form_questions fq       ON fq.id  = fqr.id_form_question
  JOIN formulario f            ON f.id   = fq.id_formulario
  JOIN local l                 ON l.id   = fqr.id_local
  LEFT JOIN comuna cm          ON cm.id  = l.id_comuna
  LEFT JOIN cadena cad         ON cad.id = l.id_cadena
  LEFT JOIN distrito d         ON d.id   = l.id_distrito
  LEFT JOIN jefe_venta jv      ON jv.id  = l.id_jefe_venta
  LEFT JOIN vendedor ve        ON ve.id = l.id_vendedor
  JOIN usuario u               ON u.id   = fqr.id_usuario
  JOIN visita v                ON v.id   = fqr.visita_id
  LEFT JOIN form_question_options o ON o.id = fqr.id_option
  $whereSql
  ORDER BY
    fqr.created_at DESC,
    fqr.id DESC
  LIMIT $MAX_ROWS
";

$t0 = microtime(true);
$st = $conn->prepare($sql);
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$rs = $st->get_result();

$rows     = [];
$rowCount = 0;

// -----------------------------------------------------------------------------
// Helpers locales
// -----------------------------------------------------------------------------

/**
 * Genera un thumbnail en JPEG embebido como data URI para Dompdf.
 * - $fotoUrl es la ruta tal como viene de la BD (ej: visibility2/app/uploads/.../img.webp)
 * - Si no se puede leer o convertir, devuelve null.
 * - maxW/maxH se subieron para tener más resolución interna (mejor zoom).
 */
function pe_build_thumb_data_uri(?string $fotoUrl, int $maxW = 240, int $maxH = 360): ?string
{
    if (!$fotoUrl) {
        return null;
    }
    $fs = panel_encuesta_photo_fs_path($fotoUrl);
    if ($fs === null) {
        return null;
    }

    $ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));

    // 1) Intentar con Imagick (maneja WebP, HEIC, etc.)
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick($fs);
            $im->setImageFormat('jpeg');
            $im->setImageCompression(Imagick::COMPRESSION_JPEG);
            $im->setImageCompressionQuality(85);
            $im->stripImage();

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w > $maxW || $h > $maxH) {
                $im->thumbnailImage($maxW, $maxH, true, true);
            }

            $blob = $im->getImageBlob();
            $im->clear();
            $im->destroy();

            return 'data:image/jpeg;base64,' . base64_encode($blob);
        } catch (\Throwable $e) {
            // Seguimos al fallback GD
        }
    }

    // 2) Fallback con GD
    $src = null;
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $src = @imagecreatefromjpeg($fs);
            break;
        case 'png':
            $src = @imagecreatefrompng($fs);
            break;
        case 'gif':
            $src = @imagecreatefromgif($fs);
            break;
        case 'webp':
            if (function_exists('imagecreatefromwebp')) {
                $src = @imagecreatefromwebp($fs);
            }
            break;
        default:
            if (function_exists('imagecreatefromstring')) {
                $raw = @file_get_contents($fs);
                if ($raw !== false) {
                    $src = @imagecreatefromstring($raw);
                }
            }
    }

    if (!$src) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $ratio = min($maxW / max(1, $w), $maxH / max(1, $h), 1.0);
    $tw = (int)floor($w * $ratio);
    $th = (int)floor($h * $ratio);

    $dst = imagecreatetruecolor($tw, $th);
    // Fondo blanco para evitar problemas al pasar a JPEG
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

    ob_start();
    imagejpeg($dst, null, 85);
    $blob = ob_get_clean();

    imagedestroy($dst);
    imagedestroy($src);

    if ($blob === false) {
        return null;
    }

    return 'data:image/jpeg;base64,' . base64_encode($blob);
}

// -----------------------------------------------------------------------------
// Construcción de filas (con thumbnails ya listos)
// -----------------------------------------------------------------------------
while ($r = $rs->fetch_assoc()) {
    $fotoUrl = $r['foto_url'] ?? null;
    if (!$fotoUrl) {
        continue; // si no tiene ruta de foto, no tiene sentido en este reporte
    }

    $resolvedUrl = panel_encuesta_resolve_photo_url($fotoUrl);
    $thumbDataUri = pe_build_thumb_data_uri($resolvedUrl ?? $fotoUrl);
    $linkUrl = $resolvedUrl;

    $rows[] = [
        'thumb'        => $thumbDataUri,
        'link'         => $linkUrl,
        'local_codigo' => (string)($r['local_codigo'] ?? ''),
        'local_nombre' => (string)($r['local_nombre'] ?? ''),
        'direccion'    => (string)($r['direccion'] ?? ''),
        'comuna'       => (string)($r['comuna'] ?? ''),
        'cadena'       => (string)($r['cadena'] ?? ''),
        'jefe_venta'   => (string)($r['jefe_venta'] ?? ''),
        'vendedor'     => (string)($r['vendedor_nombre'] ?? ''),
        'usuario'      => (string)($r['usuario'] ?? ''),
        'fecha_foto'   => (string)($r['fecha_foto'] ?? ''),
    ];
    $rowCount++;
}
$st->close();

$duration = microtime(true) - $t0;
$metaFilters['duration_sec'] = $duration;
$metaFilters['rows']         = $rowCount;
$metaFilters['max_rows']     = $MAX_ROWS;
$metaFilters['truncated']    = ($rowCount >= $MAX_ROWS);

// Log de uso del panel (mismo helper que el resto del módulo)
if (function_exists('log_panel_encuesta_query')) {
    // Firma: mysqli $conn, string $accion, int $filas, array $meta
    log_panel_encuesta_query($conn, 'pdf_fotos_inline', $rowCount, $metaFilters);
}

// Si no hay filas, devolvemos un mensaje legible en vez de PDF vacío
if ($rowCount === 0) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No se encontraron fotos con los filtros seleccionados.";
    exit;
}

// -----------------------------------------------------------------------------
// Generar HTML para Dompdf
// -----------------------------------------------------------------------------
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Reporte de Fotos – Panel de Encuesta</title>
  <style>
    body{
      font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
      font-size: 9px;
      color:#222;
    }
    h2{
      margin:0 0 8px 0;
      font-size:14px;
    }
    table{
      width:100%;
      border-collapse:collapse;
      table-layout:fixed;
    }
    th,td{
      border:1px solid #cccccc;
      padding:4px;
      vertical-align:top;
      word-wrap:break-word;
      overflow-wrap:break-word;
    }
    th{
      background:#f2f4f8;
      font-weight:bold;
    }
.col-img{
  width:80px;
  text-align:center;
  vertical-align:middle;
  padding-top:20px;  
  padding-bottom:4px;
}
    .col-cod{width:70px;}
    .col-local{width:120px;}
    .col-dir{width:180px;}
    .col-comuna{width:90px;}
    .col-cadena{width:100px;}
    .col-jefe{width:110px;}
    .col-vend{width:110px;}
    .col-user{width:80px;}
    .col-fecha{width:110px;}
    .small{
      font-size:8px;
      color:#666;
    }
    .thumb-pdf{
      width:70px;      /* tamaño visual fijo */
      height:auto;
      display:block;
      margin:0 auto;  /* centrada horizontalmente */
    }
  </style>
</head>
<body>
  <h2>Reporte de Fotos – Panel de Encuesta</h2>
  <?php if (!empty($metaFilters['truncated'])): ?>
    <p class="small">
      Aviso: por rendimiento este PDF muestra como máximo <?php echo (int)$MAX_ROWS; ?> fotos.
      Ajusta los filtros si necesitas un rango más acotado.
    </p>
  <?php endif; ?>
  <table>
    <thead>
      <tr>
        <th class="col-img">Imagen</th>
        <th class="col-cod">Cód. Local</th>
        <th class="col-local">Local</th>
        <th class="col-dir">Dirección</th>
        <th class="col-comuna">Comuna</th>
        <th class="col-cadena">Cadena</th>
        <th class="col-jefe">Jefe de Ventas</th>
        <th class="col-vend">Vendedor</th>
        <th class="col-user">Usuario</th>
        <th class="col-fecha">Fecha</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="col-img">
<?php if ($r['thumb']): ?>
  <?php if ($r['link']): ?>
    <a href="<?php echo htmlspecialchars($r['link'], ENT_QUOTES, 'UTF-8'); ?>"
       target="_blank" rel="noopener noreferrer">
      <img src="<?php echo htmlspecialchars($r['thumb'], ENT_QUOTES, 'UTF-8'); ?>"
           class="thumb-pdf" alt="Foto">
    </a>
  <?php else: ?>
      <img src="<?php echo htmlspecialchars($r['thumb'], ENT_QUOTES, 'UTF-8'); ?>"
           class="thumb-pdf" alt="Foto">
  <?php endif; ?>

<?php else: ?>
          
          <?php endif; ?>
        </td>
        <td><?php echo htmlspecialchars($r['local_codigo'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($r['local_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($r['direccion'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($r['comuna'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($r['cadena'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($r['jefe_venta'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($r['vendedor'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($r['usuario'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($r['fecha_foto'], ENT_QUOTES, 'UTF-8'); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
<?php
$html = ob_get_clean();

// -----------------------------------------------------------------------------
// Renderizar con Dompdf
// -----------------------------------------------------------------------------
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/visibility2/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$loaded = false;
foreach ($autoloadPaths as $p) {
    if (is_file($p)) {
        require_once $p;
        $loaded = true;
        break;
    }
}

if (!$loaded || !class_exists('\\Dompdf\\Dompdf')) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<p>No se encontró Dompdf (vendor/autoload.php). HTML generado como fallback.</p>";
    echo $html;
    exit;
}

$dompdf = new \Dompdf\Dompdf([
    'isRemoteEnabled'      => false,  // no necesitamos recursos remotos, todo es inline
    'isHtml5ParserEnabled' => true,
    'dpi'                  => 144,    // más DPI => mejor nitidez al hacer zoom
    'chroot'               => rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'),
]);

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'panel_encuesta_fotos_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
