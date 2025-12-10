<?php
// export_pdf_panel_encuesta.php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit("Sesión expirada");
}

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/mod_panel_encuesta/panel_encuesta_helpers.php';

$user_div   = (int)($_SESSION['division_id'] ?? 0);
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

// ==== parámetros (POST preferido, fallback GET) ====
$SRC = $_POST ?: $_GET;

// Tipo de salida (antes de definir límites)
$output = $SRC['output'] ?? 'pdf';
$output = in_array($output, ['pdf','html'], true) ? $output : 'pdf';

// WHERE + params centralizados (solo fotos)
list($whereSql, $types, $params, $metaFilters) =
    build_panel_encuesta_filters($empresa_id, $user_div, $SRC, [
        'foto_only'            => true,
        'enforce_date_fallback'=> true
    ]);

// ====== límites según tipo de salida ======
// PDF: más bajo para no reventar Dompdf
// HTML: puede manejar más filas
$maxRows = ($output === 'pdf') ? 800 : 4000;

// ====== consulta y agrupación (visita_id, pregunta_id) ======
$sql = "
  SELECT
    fqr.visita_id,
    fq.id AS pregunta_id,
    DATE_FORMAT(fqr.created_at,'%d/%m/%Y %H:%i:%s') AS fecha_subida,
    COALESCE(pm.foto_url, o.option_text, fqr.answer_text) AS foto_url,
    l.codigo          AS local_codigo,
    l.nombre          AS local_nombre,
    l.direccion       AS direccion,
    c.comuna          AS comuna,
    cad.nombre        AS cadena,
    jv.nombre         AS jefe_venta,
    u.usuario         AS usuario
  FROM form_question_responses fqr
  JOIN form_questions fq  ON fq.id = fqr.id_form_question
  JOIN formulario f       ON f.id  = fq.id_formulario
  JOIN local l            ON l.id  = fqr.id_local
  LEFT JOIN comuna c      ON c.id  = l.id_comuna
  LEFT JOIN cadena cad    ON cad.id= l.id_cadena
  LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
  JOIN usuario u          ON u.id  = fqr.id_usuario
  LEFT JOIN form_question_options o ON o.id = fqr.id_option
  LEFT JOIN form_question_photo_meta pm ON pm.resp_id = fqr.id
  $whereSql
  ORDER BY fqr.created_at DESC, fqr.id DESC
  LIMIT $maxRows
";

$st = $conn->prepare($sql);
if ($types) {
    $st->bind_param($types, ...$params);
}

$t0 = microtime(true);
$st->execute();
$rs = $st->get_result();

/**
 * Convierte una ruta/URL relativa en URL absoluta http(s) para mostrar
 * y usar como href.
 */
function make_abs_url_panel($path){
    if(!$path) return $path;
    if (preg_match('~^https?://~i', $path)) return $path;
    $p = ($path[0] ?? '') === '/' ? $path : ('/'.$path);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'www.visibility.cl';
    return $scheme.'://'.$host.$p;
}

/**
 * Dado un URL absoluta de foto, intenta resolverla a ruta local absoluta
 * (ej: /home/visibility/public_html/visibility2/app/uploads/...)
 * para que Dompdf lea el archivo directamente del disco.
 *
 * Si no se puede resolver, devuelve el mismo URL (Dompdf intentará http).
 */
function panel_encuesta_pdf_img_src($url){
    if (!$url) return $url;

    // Obtenemos el path de la URL, ej: /visibility2/app/uploads/uploads_fotos_pregunta/...
    $parts = @parse_url($url);
    $path  = $parts['path'] ?? '';
    if (!$path) {
        return $url;
    }

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docroot === '') {
        return $url;
    }

    // Ruta real en filesystem
    $fs = realpath($docroot.$path);
    if (!$fs || !is_file($fs)) {
        // Si no la encontramos, que Dompdf intente con la URL HTTP
        return $url;
    }

    $ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));

    // Si NO es WebP, devolvemos directamente la ruta absoluta de archivo
    if ($ext !== 'webp') {
        // OJO: sin "file://", Dompdf acepta /home/visibility/...
        return $fs;
    }

    // --- Es WebP: convertimos a JPG en un tmp para que Dompdf lo pueda usar ---
    $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'panel_encuesta_pdf';
    if (!is_dir($tmpBase)) {
        @mkdir($tmpBase, 0777, true);
    }

    // Nombre determinístico para no regenerar siempre lo mismo
    $jpgPath = $tmpBase.DIRECTORY_SEPARATOR.sha1($fs).'.jpg';

    if (!is_file($jpgPath)) {
        $ok = false;

        // 1) Intentar con GD
        if (function_exists('imagecreatefromwebp') && function_exists('imagejpeg')) {
            $img = @imagecreatefromwebp($fs);
            if ($img) {
                @imagejpeg($img, $jpgPath, 90);
                imagedestroy($img);
                $ok = is_file($jpgPath);
            }
        }

        // 2) Intentar con Imagick si GD no pudo
        if (!$ok && class_exists('Imagick')) {
            try {
                $im = new Imagick($fs);
                $im->setImageFormat('jpeg');
                $im->writeImage($jpgPath);
                $im->clear();
                $im->destroy();
                $ok = is_file($jpgPath);
            } catch (\Throwable $e) {
                // ignoramos, usaremos fallback
            }
        }

        // Si no pudimos convertir, devolvemos la ruta original
        if (!$ok) {
            return $fs;
        }
    }

    // Devolvemos la ruta ABSOLUTA al JPG (sin file://)
    return $jpgPath;
}



// Agrupamos filas por visita + pregunta
$groups    = [];
$rowsCount = 0;

while($r = $rs->fetch_assoc()){
    $key = ($r['visita_id'] ?? '0').'|'.($r['pregunta_id'] ?? '0');

    if (!isset($groups[$key])) {
        $groups[$key] = [
            'fotos'        => [],
            'local_codigo' => $r['local_codigo'],
            'local_nombre' => $r['local_nombre'],
            'direccion'    => $r['direccion'],
            'comuna'       => $r['comuna'],
            'cadena'       => $r['cadena'],
            'jefe_venta'   => $r['jefe_venta'],
            'usuario'      => $r['usuario'],
            'fecha_subida' => $r['fecha_subida'],
        ];
    }

    $u = (string)($r['foto_url'] ?? '');
    if ($u !== '') {
        // Normalizamos a URL absoluta http(s)
        $u = make_abs_url_panel($u);
        $groups[$key]['fotos'][] = $u;
    }

    $rowsCount++;
}
$st->close();

$rows        = array_values($groups);
$duration    = microtime(true) - $t0;
$isTruncated = ($rowsCount >= $maxRows);

// metadatos para log
$metaFilters['duration_sec']    = $duration;
$metaFilters['rows']            = $rowsCount;
$metaFilters['max_rows']        = $maxRows;
$metaFilters['max_total_rows']  = $maxRows;
if ($isTruncated) {
    $metaFilters['truncated_total'] = 1;
}

// encabezados de debug
if ($isTruncated) {
    @header('X-Export-Truncated: 1');
    @header('X-Export-MaxRows: '.$maxRows);
}

// Log si existe helper
if (function_exists('log_panel_encuesta_query')) {
    log_panel_encuesta_query($conn, 'pdf_fotos', $rowsCount, $metaFilters);
}

/* ============================================================
 * 1) SALIDA HTML (debug / preview) - &output=html
 * ============================================================*/
if ($output === 'html') {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <title>Reporte de Fotos – Panel de Encuesta (HTML)</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <style>
        body{
          font-family: Arial, Helvetica, sans-serif;
          font-size: 13px;
          color:#222;
          margin:16px;
          background:#f7f7f7;
        }
        h2{ margin:0 0 8px 0; }
        .small{ font-size:11px; color:#555; }
        table{
          width:100%;
          border-collapse:collapse;
          background:#fff;
        }
        th,td{
          border:1px solid #e0e0e0;
          padding:6px;
          vertical-align:middle;
          word-wrap:break-word;
        }
        th{
          background:#f5f7fb;
          font-weight:bold;
        }
        .col-img{width:220px;}
        .col-cod{width:90px;}
        .col-cadena{width:120px;}
        .col-user{width:110px;}
        .col-fecha{width:140px;}

        .thumb-wrap{
          display:flex;
          flex-wrap:wrap;
          gap:4px;
        }
        .thumb{
          max-height:90px;
          max-width:120px;
          object-fit:cover;
          cursor:pointer;
          border-radius:4px;
          box-shadow:0 1px 4px rgba(0,0,0,.25);
        }
        .thumb-count{
          margin-top:2px;
        }
        .link-list a{
          display:block;
          font-size:10px;
          color:#004a99;
          text-decoration:none;
          word-break:break-all;
        }
        .link-list a:hover{
          text-decoration:underline;
        }
      </style>
    </head>
    <body>
      <h2>Reporte de Fotos – Panel de Encuesta (HTML)</h2>
      <?php if ($isTruncated): ?>
        <p class="small">
          Aviso: este reporte está limitado a <?= (int)$maxRows ?> registros por motivos de rendimiento.
          Ajusta los filtros si necesitas un rango más acotado.
        </p>
      <?php else: ?>
        <p class="small">Haz clic en una imagen para verla más grande.</p>
      <?php endif; ?>

      <table>
        <thead>
          <tr>
            <th class="col-img">Imagen / Links</th>
            <th class="col-cod">Cód. Local</th>
            <th>Local</th>
            <th>Dirección</th>
            <th class="col-cadena">Cadena</th>
            <th>Jefe de venta</th>
            <th class="col-user">Usuario</th>
            <th class="col-fecha">Fecha Subida</th>
          </tr>
        </thead>
        <tbody>
        <?php
        foreach($rows as $r):
          $fotos = $r['fotos'] ?: [];
          $cnt   = count($fotos);
        ?>
          <tr>
            <td>
              <?php if ($cnt === 0): ?>
                <span class="small">Sin imagen</span>
              <?php else: ?>
                <div class="thumb-wrap">
                  <?php foreach ($fotos as $u): ?>
                    <a href="<?=htmlspecialchars($u)?>" target="_blank">
                      <img
                        src="<?=htmlspecialchars($u)?>"
                        class="thumb"
                        alt=""
                        loading="lazy"
                      >
                    </a>
                  <?php endforeach; ?>
                </div>
                <div class="small thumb-count">
                  <?=$cnt?> foto<?=($cnt>1?'s':'')?>
                </div>
              <?php endif; ?>
            </td>
            <td><?=htmlspecialchars($r['local_codigo'] ?? '')?></td>
            <td><?=htmlspecialchars($r['local_nombre'] ?? '')?></td>
            <td><?=htmlspecialchars(trim(($r['direccion'] ?? '').(($r['comuna']??'')!=='' ? ' - '.$r['comuna'] : ''))) ?></td>
            <td><?=htmlspecialchars($r['cadena'] ?? '')?></td>
            <td><?=htmlspecialchars($r['jefe_venta'] ?? '')?></td>
            <td><?=htmlspecialchars($r['usuario'] ?? '')?></td>
            <td><?=htmlspecialchars($r['fecha_subida'] ?? '')?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </body>
    </html>
    <?php
    exit;
}

/* ============================================================
 * 2) GENERACIÓN DEL PDF (DOMPDF)
 * ============================================================*/
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
      font-size: 10px;
      color:#222;
    }
    h2{
      margin:0 0 8px 0;
      font-size:14px;
    }
    table{
      width:100%;
      border-collapse:collapse;
    }
    th,td{
      border:1px solid #cccccc;
      padding:4px;
      vertical-align:top;
    }
    th{
      background:#f2f4f8;
      font-weight:bold;
    }
    .col-img{width:180px;}
    .col-cod{width:90px;}
    .col-cadena{width:120px;}
    .col-user{width:110px;}
    .col-fecha{width:140px;}
    .small{
      font-size:11px;
      color:#666;
    }
    .thumb-grid-pdf{
      display:flex;
      flex-wrap:wrap;
      gap:4px;
    }
    .thumb-pdf{
      max-width:150px;
      max-height:110px;
      object-fit:cover;
      display:block;
    }
  </style>
</head>
<body>
  <h2>Reporte de Fotos – Panel de Encuesta</h2>
  <?php if ($isTruncated): ?>
    <p class="small">
      Aviso: este PDF está limitado a <?= (int)$maxRows ?> registros por motivos de rendimiento.
      Ajusta los filtros si necesitas más detalle.
    </p>
  <?php endif; ?>
  <table>
    <thead>
      <tr>
        <th class="col-img">Imagen</th>
        <th class="col-cod">Cód. Local</th>
        <th>Local</th>
        <th>Dirección</th>
        <th class="col-cadena">Cadena</th>
        <th>Jefe de venta</th>
        <th class="col-user">Usuario</th>
        <th class="col-fecha">Fecha Subida</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): $fotos = $r['fotos'] ?: []; $cnt = count($fotos); ?>
        <tr>
          <td>
            <?php if ($cnt === 0): ?>
              <span class="small">Sin imagen</span>
            <?php else: ?>
              <div class="thumb-grid-pdf">
                <?php foreach ($fotos as $u): ?>
                  <?php $srcPdf = panel_encuesta_pdf_img_src($u); ?>
                  <a href="<?=htmlspecialchars($u)?>">
                    <img src="<?=htmlspecialchars($srcPdf)?>" class="thumb-pdf" alt="">
                  </a>
                <?php endforeach; ?>
              </div>
              <?php if ($cnt > 1): ?>
                <div class="small">Total: <?=$cnt?> fotos</div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?=htmlspecialchars($r['local_codigo'] ?? '')?></td>
          <td><?=htmlspecialchars($r['local_nombre'] ?? '')?></td>
          <td><?=htmlspecialchars(trim(($r['direccion'] ?? '').(($r['comuna']??'')!=='' ? ' - '.$r['comuna'] : ''))) ?></td>
          <td><?=htmlspecialchars($r['cadena'] ?? '')?></td>
          <td><?=htmlspecialchars($r['jefe_venta'] ?? '')?></td>
          <td><?=htmlspecialchars($r['usuario'] ?? '')?></td>
          <td><?=htmlspecialchars($r['fecha_subida'] ?? '')?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
<?php
$pdfHtml = ob_get_clean();

// ====== PDF con Dompdf (misma lógica que tenías antes) ======
$autoloadPaths = [
  __DIR__ . '/vendor/autoload.php',
  $_SERVER['DOCUMENT_ROOT'].'/vendor/autoload.php',
  $_SERVER['DOCUMENT_ROOT'].'/visibility2/vendor/autoload.php',
  __DIR__ . '/../../vendor/autoload.php'
];

$loaded = false;
foreach($autoloadPaths as $p){
    if (is_file($p)){
        require_once $p;
        $loaded=true;
        break;
    }
}

if ($loaded && class_exists('\\Dompdf\\Dompdf')) {
  $dompdf = new \Dompdf\Dompdf([
    'isRemoteEnabled'      => true,
    'isHtml5ParserEnabled' => true,
    'dpi'                  => 96,
    // Esto le dice a Dompdf que puede leer archivos dentro de DOCUMENT_ROOT
    'chroot'               => rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'),
  ]);
  $dompdf->loadHtml($pdfHtml, 'UTF-8');
  $dompdf->setPaper('A4', 'landscape');
  $dompdf->render();
  $dompdf->stream('panel_fotos_'.date('Ymd_His').'.pdf', ['Attachment'=>true]);
}
exit;
