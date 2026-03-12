<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require_once __DIR__ . '/panel_encuesta_helpers.php';
require_once __DIR__ . '/src/Bootstrap.php';
(new PanelEncuesta\Controllers\GeoController($conn))->handle();
