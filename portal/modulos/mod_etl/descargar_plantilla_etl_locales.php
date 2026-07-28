<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($usuario_id)) {
    http_response_code(401);
    exit('Usuario no autenticado.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="plantilla_actualizacion_direcciones_locales.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$fp = fopen('php://output', 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['Código Local', 'Nombre', 'Dirección', 'Comuna', 'Cadena', 'Cuenta'], ';');
fclose($fp);
exit();
