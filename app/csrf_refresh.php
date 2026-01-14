<?php
// /visibility2/app/csrf_refresh.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(401);
  session_write_close();
  echo json_encode([
    'ok' => false,
    'error_code' => 'NO_SESSION',
    'message' => 'Sesi¨®n expirada',
    'retryable' => false
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// Rotaci¨®n autom¨¢tica del CSRF token cada hora (seguridad mejorada)
$CSRF_TTL = 3600; // 1 hora en segundos
$needsRotation = false;

if (!isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token']) ||
    strlen($_SESSION['csrf_token']) < 32) {
  $needsRotation = true;
}

// Verificar si el token ha expirado por tiempo
if (!$needsRotation && isset($_SESSION['csrf_token_time'])) {
  $elapsed = time() - (int)$_SESSION['csrf_token_time'];
  if ($elapsed > $CSRF_TTL) {
    $needsRotation = true;
  }
}

// Permitir rotaci¨®n manual expl¨ªcita
if (!$needsRotation && isset($_GET['rotate']) && $_GET['rotate'] === '1') {
  $needsRotation = true;
}

// Generar nuevo token si es necesario
if ($needsRotation) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  $_SESSION['csrf_token_time'] = time();
}

$token = $_SESSION['csrf_token'];
session_write_close();

echo json_encode([
  'ok'         => true,
  'status'     => 'ok',
  'csrf_token' => $token,
], JSON_UNESCAPED_UNICODE);