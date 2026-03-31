<?php
// File: portal/modulos/mod_mapa/mapa_locales.php
session_start();
if (!isset($_SESSION['usuario_id'])) {
  header('Location: /visibility2/portal/index.php');
  exit;
}

if (!isset($_GET['formulario_id'])) {
  die("Parámetro formulario_id faltante.");
}
$formulario_id = intval($_GET['formulario_id']);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mapa de Locales - Campaña #<?= $formulario_id ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script>
    // lo usamos en el JS
    const CAMPAIGN_ID = <?= json_encode($formulario_id) ?>;
  </script>
  <link rel="stylesheet" href="/visibility2/portal/assets/plugins/bootstrap/css/bootstrap.min.css">
  <style>
    #map { width:100%; height:80vh; }
    .info-window { min-width:200px; }
    .info-window img { max-width:100%; height:auto; margin-bottom:8px; }
  </style>
</head>
<body>
  <div class="container-fluid p-0">
    <nav class="navbar navbar-light bg-light">
      <a class="navbar-brand" href="#">Mapa de Locales</a>
      <button class="btn btn-secondary" onclick="history.back()">← Volver</button>
    </nav>
    <div id="map"></div>
  </div>

  <script src="https://maps.googleapis.com/maps/api/js?key=TU_GOOGLE_MAPS_KEY"></script>
  <script src="/visibility2/portal/modulos/mod_mapa/mapa_locales.js"></script>
</body>
</html>
