<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$id_empresa  = intval($_SESSION['empresa_id'] ?? 0);
$id_division = intval($_SESSION['division_id'] ?? 0);

$baseUrl = 'https://visibility.cl';

$sql = "
    SELECT id, titulo, subtitulo, image_url, target_url, orden
    FROM dashboard_carousel_items
    WHERE is_active = 1
    ORDER BY orden ASC, id ASC
";
$res = $conn->query($sql);

$items = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
}

$total = count($items);
if ($total <= 0) {
    $total = 1;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Carrusel 3D interactivo</title>
  <link rel="stylesheet" href="css/dashboard1beta.css" />
</head>
<body>

  <div class="scene" id="scene">
    <div class="a3d" id="carousel" style="--n: <?= $total ?>">

      <?php if (!empty($items)): ?>
        <?php foreach ($items as $i => $item): ?>
            <a
              href="<?= htmlspecialchars($item['target_url']) ?>"
              class="card-link"
              style="--i: <?= $i ?>"
              title="<?= htmlspecialchars($item['titulo']) ?>"
            >
              <img
                class="card"
                src="<?= htmlspecialchars($baseUrl . $item['image_url']) ?>"
                alt="<?= htmlspecialchars($item['titulo']) ?>"
              />
            </a>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="card-empty" style="--i:0;">
          No hay imágenes activas en el carrusel
        </div>
      <?php endif; ?>

    </div>
  </div>

  <script src="js/dashboard1beta.js"></script>
</body>
</html>