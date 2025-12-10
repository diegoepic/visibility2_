<?php
// dashboard_iframes.php
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';

$division_id = isset($_GET['division']) 
    ? intval($_GET['division']) 
    : 0;

// 1) Intento 1: paneles activos con “panel de control” en main_label
$stmt = $conn->prepare("
  SELECT target_url AS iframe_html
  FROM dashboard_items
  WHERE id_division = ?
    AND is_active = 1
    AND main_label LIKE '%panel de control%'
  ORDER BY orden ASC
");
$stmt->bind_param("i", $division_id);
$stmt->execute();
$res = $stmt->get_result();
$iframes = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2) Si no hay ninguno, fallback al más reciente
if (count($iframes) === 0) {
    $stmt2 = $conn->prepare("
      SELECT target_url AS iframe_html
      FROM dashboard_items
      WHERE id_division = ?
        AND is_active = 1
      ORDER BY created_at DESC
      LIMIT 1
    ");
    $stmt2->bind_param("i", $division_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $fallback = $res2->fetch_assoc();
    $stmt2->close();

    if ($fallback) {
        $iframes[] = $fallback;
    } else {
        // ni siquiera hay nada activo en esa división
        header("HTTP/1.1 404 Not Found");
        echo "No se encontró ningún iframe para la división {$division_id}.";
        exit;
    }
}

// 3) Renderizamos el/los iframes
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel de Control</title>
  <style>
    html, body { margin:0; padding:0; height:100%; }
    .iframe-container {
      position:relative;
      width:100%; height:100%;
    }
    .iframe-container iframe {
      position:absolute;
      top:0; left:0;
      width:100%; height:100%;
      border:0;
    }
  </style>
</head>
<body>
  <?php foreach ($iframes as $row): ?>
    <div class="iframe-container">
      <?php echo trim($row['iframe_html']); ?>
    </div>
  <?php endforeach; ?>
</body>
</html>