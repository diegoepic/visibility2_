<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/src/Bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
(new PanelEncuesta\Controllers\ExportController($conn))->handlePdf();
