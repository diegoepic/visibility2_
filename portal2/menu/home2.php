<?php
session_start();

$mod = $_GET['mod'] ?? 'home';
$sub = $_GET['sub'] ?? '';

$rutas = [
    'home' => [
        'file' => __DIR__ . '/modulos/home.php',
    ],
    'login' => [
        'file' => __DIR__ . '/modulos/login.php',
    ],
    'favoritos' => [
        'file' => __DIR__ . '/modulos/favoritos.php',
    ],
    'mail' => [
        'file' => __DIR__ . '/modulos/mail.php',
    ],
    'servicios' => [
        'file' => __DIR__ . '/modulos/servicios.php',
        'subs' => [
            'hosting'       => __DIR__ . '/modulos/servicios_hosting.php',
            'soporte'       => __DIR__ . '/modulos/servicios_soporte.php',
            'integraciones' => __DIR__ . '/modulos/servicios_integraciones.php',
        ]
    ],
];

if (!isset($rutas[$mod])) {
    $mod = 'home';
    $sub = '';
}

if (
    isset($rutas[$mod]['subs']) &&
    $sub !== '' &&
    isset($rutas[$mod]['subs'][$sub])
) {
    $contenido = $rutas[$mod]['subs'][$sub];
} else {
    $sub = '';
    $contenido = $rutas[$mod]['file'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visibility</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/layout.css">
</head>
<body>

<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-area">
        <section class="content-panel">
            <?php include $contenido; ?>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggles = document.querySelectorAll('.submenu-toggle');

    toggles.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const parent = this.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
            }
        });
    });
});
</script>

</body>
</html>