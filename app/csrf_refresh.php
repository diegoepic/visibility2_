<?php
// /visibility2/app/csrf_refresh.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(401);
  session_write_close();
  echo json_encode(['ok'=>false,'error'=>'NO_SESSION']);
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
$token = $_SESSION['csrf_token'];
session_write_close();

echo json_encode([
  'ok'         => true,
  'status'     => 'ok',
  'csrf_token' => $token,
]);
