<?php
// /visibility2/app/csrf_refresh.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode(['status'=>'no_session']);
  exit;
}

if (
  !isset($_SESSION['csrf_token']) ||
  !is_string($_SESSION['csrf_token']) ||
  strlen($_SESSION['csrf_token']) < 32 ||
  (isset($_GET['rotate']) && $_GET['rotate'] === '1')
) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

echo json_encode([
  'status'     => 'ok',
  'csrf_token' => $_SESSION['csrf_token'],
]);
