<?php
require_once __DIR__ . '/controllers/MapaCampanaController.php';

$controller = new MapaCampanaController();
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$wantsJson = (isset($_GET['format']) && $_GET['format'] === 'json') || (strpos($accept, 'application/json') !== false);
if ($wantsJson) {
    $controller->apiLocales();
} else {
    $controller->index();
}
