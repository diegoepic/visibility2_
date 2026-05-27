<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mb_internal_encoding('UTF-8');

/*
|--------------------------------------------------------------------------
| CONEXIÓN Y SESIÓN
|--------------------------------------------------------------------------
| No usamos db.php para evitar afectar funciones globales.
| Solo cargamos la conexión principal y los datos de sesión.
|--------------------------------------------------------------------------
*/

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($conn) || !$conn instanceof mysqli) {
    die("Error: conexión a base de datos no disponible.");
}

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

/*
|--------------------------------------------------------------------------
| HELPERS LOCALES DEL MÓDULO
|--------------------------------------------------------------------------
*/

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fetchAllAssoc(mysqli_stmt $stmt): array
{
    $rows = [];

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function generarTokenLocal(int $length = 32): string
{
    return bin2hex(random_bytes($length / 2));
}

function normalizarNombre(string $texto): string
{
    $texto = trim($texto);
    return $texto !== '' ? $texto : 'N/A';
}

function inicialesUsuario(string $nombreCompleto): string
{
    $partes = preg_split('/\s+/', trim($nombreCompleto));
    $iniciales = '';

    foreach ($partes as $parte) {
        if ($parte !== '') {
            $iniciales .= mb_substr($parte, 0, 1, 'UTF-8');
        }

        if (mb_strlen($iniciales, 'UTF-8') >= 2) {
            break;
        }
    }

    return mb_strtoupper($iniciales ?: 'U', 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| DATOS DE SESIÓN
|--------------------------------------------------------------------------
*/

$empresa_id_session = (int)($_SESSION['empresa_id'] ?? 0);
$empresa_nombre_session = $_SESSION['empresa_nombre'] ?? '';
$division_id_session = (int)($_SESSION['division_id'] ?? 0);
$perfil_nombre_session = $_SESSION['perfil_nombre'] ?? '';

$esMentecreativa = mb_strtolower(trim($empresa_nombre_session), 'UTF-8') === 'mentecreativa';

/*
|--------------------------------------------------------------------------
| CSRF LOCAL
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generarTokenLocal(32);
}

/*
|--------------------------------------------------------------------------
| FILTROS GET OPCIONALES
|--------------------------------------------------------------------------
| Estos filtros no rompen la tabla actual, pero nos dejan listos
| para agregar controles modernos arriba.
|--------------------------------------------------------------------------
*/

$filtroEstado   = $_GET['estado']   ?? 'todos';
$filtroEmpresa  = $_GET['empresa']  ?? 'todos';
$filtroDivision = $_GET['division'] ?? 'todos';
$filtroPerfil   = $_GET['perfil']   ?? 'todos';

$estadosPermitidos = ['todos', 'activos', 'inactivos'];

if (!in_array($filtroEstado, $estadosPermitidos, true)) {
    $filtroEstado = 'todos';
}

$filtroEmpresaSql  = $filtroEmpresa !== 'todos' ? (int)$filtroEmpresa : 0;
$filtroDivisionSql = $filtroDivision !== 'todos' ? (int)$filtroDivision : 0;
$filtroPerfilSql   = $filtroPerfil !== 'todos' ? (int)$filtroPerfil : 0;

/*
|--------------------------------------------------------------------------
| LISTA DE EMPRESAS ACTIVAS
|--------------------------------------------------------------------------
*/

$empresas = [];

$sqlEmpresas = "
    SELECT id, nombre, activo
    FROM empresa
    WHERE activo = 1
    ORDER BY nombre ASC
";

if ($stmt = $conn->prepare($sqlEmpresas)) {
    $empresas = fetchAllAssoc($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| LISTA DE PERFILES
|--------------------------------------------------------------------------
*/

$perfiles = [];

$sqlPerfiles = "
    SELECT id, nombre
    FROM perfil
    ORDER BY nombre ASC
";

if ($stmt = $conn->prepare($sqlPerfiles)) {
    $perfiles = fetchAllAssoc($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| LISTA DE DIVISIONES
|--------------------------------------------------------------------------
*/

$divisiones = [];

$sqlDivisiones = "
    SELECT id, nombre, id_empresa
    FROM division_empresa
    WHERE estado = 1
";

$paramsDiv = [];
$typesDiv = '';

if (!$esMentecreativa && $empresa_id_session > 0) {
    $sqlDivisiones .= " AND id_empresa = ?";
    $paramsDiv[] = $empresa_id_session;
    $typesDiv .= 'i';
}

$sqlDivisiones .= " ORDER BY nombre ASC";

if ($stmt = $conn->prepare($sqlDivisiones)) {
    if (!empty($paramsDiv)) {
        $stmt->bind_param($typesDiv, ...$paramsDiv);
    }

    $divisiones = fetchAllAssoc($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| CONSULTA PRINCIPAL DE USUARIOS
|--------------------------------------------------------------------------
| Se replica la lógica de obtenerUsuariosConActividad(),
| pero queda dentro de esta página para personalización.
|--------------------------------------------------------------------------
*/

$usuarios = [];

$sqlUsuarios = "
    SELECT 
        u.id,
        CONCAT(TRIM(COALESCE(u.nombre, '')), ' ', TRIM(COALESCE(u.apellido, ''))) AS nombre_completo,
        u.usuario AS nombre_login,
        COALESCE(e.nombre, 'N/A') AS nombre_empresa,
        COALESCE(d.nombre, 'N/A') AS nombre_division,
        COALESCE(p.nombre, 'N/A') AS nombre_perfil,
        DATE(u.fechaCreacion) AS fechaCreacion,
        CASE 
            WHEN u.last_login IS NULL THEN NULL
            ELSE DATE_FORMAT(u.last_login, '%Y-%m-%d %H:%i')
        END AS UltimoLogin,
        COALESCE(u.login_count, 0) AS logeos,
        COALESCE(u.activo, 0) AS activo,
        u.id_empresa,
        u.id_division,
        u.id_perfil
    FROM usuario u
    LEFT JOIN empresa e ON u.id_empresa = e.id
    LEFT JOIN division_empresa d ON u.id_division = d.id
    LEFT JOIN perfil p ON u.id_perfil = p.id
    WHERE 1 = 1
";

$params = [];
$types = '';

/*
| Seguridad por empresa:
| Si no es Mentecreativa, solo ve usuarios de su empresa.
*/
if (!$esMentecreativa && $empresa_id_session > 0) {
    $sqlUsuarios .= " AND u.id_empresa = ?";
    $params[] = $empresa_id_session;
    $types .= 'i';
}

/*
| Filtros opcionales
*/
if ($filtroEstado === 'activos') {
    $sqlUsuarios .= " AND u.activo = 1";
} elseif ($filtroEstado === 'inactivos') {
    $sqlUsuarios .= " AND u.activo = 0";
}

if ($esMentecreativa && $filtroEmpresaSql > 0) {
    $sqlUsuarios .= " AND u.id_empresa = ?";
    $params[] = $filtroEmpresaSql;
    $types .= 'i';
}

if ($filtroDivisionSql > 0) {
    $sqlUsuarios .= " AND u.id_division = ?";
    $params[] = $filtroDivisionSql;
    $types .= 'i';
}

if ($filtroPerfilSql > 0) {
    $sqlUsuarios .= " AND u.id_perfil = ?";
    $params[] = $filtroPerfilSql;
    $types .= 'i';
}

$sqlUsuarios .= "
    ORDER BY COALESCE(u.login_count, 0) DESC, u.nombre ASC
";

if ($stmt = $conn->prepare($sqlUsuarios)) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $usuarios = fetchAllAssoc($stmt);
    $stmt->close();
} else {
    die("Error preparando consulta de usuarios: " . $conn->error);
}

/*
|--------------------------------------------------------------------------
| KPI Y RESÚMENES
|--------------------------------------------------------------------------
*/

$totalUsuarios = count($usuarios);
$totalActivos = 0;
$totalInactivos = 0;
$totalLogeos = 0;

$usuariosPorPerfil = [];
$usuariosActivosPorDivision = [];
$usuariosPorDivision = [];
$usuariosPorEmpresa = [];
$topUsuariosLogeos = [];

foreach ($usuarios as $usuario) {
    $activo = (int)($usuario['activo'] ?? 0);
    $logeos = (int)($usuario['logeos'] ?? 0);

    $perfil = normalizarNombre($usuario['nombre_perfil'] ?? 'N/A');
    $division = normalizarNombre($usuario['nombre_division'] ?? 'N/A');
    $empresa = normalizarNombre($usuario['nombre_empresa'] ?? 'N/A');

    if ($activo === 1) {
        $totalActivos++;

        if (!isset($usuariosActivosPorDivision[$division])) {
            $usuariosActivosPorDivision[$division] = 0;
        }

        $usuariosActivosPorDivision[$division]++;
    } else {
        $totalInactivos++;
    }

    $totalLogeos += $logeos;

    if (!isset($usuariosPorPerfil[$perfil])) {
        $usuariosPorPerfil[$perfil] = 0;
    }
    $usuariosPorPerfil[$perfil]++;

    if (!isset($usuariosPorDivision[$division])) {
        $usuariosPorDivision[$division] = 0;
    }
    $usuariosPorDivision[$division]++;

    if (!isset($usuariosPorEmpresa[$empresa])) {
        $usuariosPorEmpresa[$empresa] = 0;
    }
    $usuariosPorEmpresa[$empresa]++;

    $topUsuariosLogeos[] = [
        'nombre' => trim($usuario['nombre_completo'] ?? 'N/A'),
        'usuario' => $usuario['nombre_login'] ?? '',
        'logeos' => $logeos,
    ];
}

arsort($usuariosPorPerfil);
arsort($usuariosActivosPorDivision);
arsort($usuariosPorDivision);
arsort($usuariosPorEmpresa);

usort($topUsuariosLogeos, function ($a, $b) {
    return $b['logeos'] <=> $a['logeos'];
});

$topUsuariosLogeos = array_slice($topUsuariosLogeos, 0, 10);

$totalDivisiones = count(array_filter(array_keys($usuariosPorDivision), function ($division) {
    return $division !== 'N/A';
}));

$totalEmpresas = count(array_filter(array_keys($usuariosPorEmpresa), function ($empresa) {
    return $empresa !== 'N/A';
}));

$porcentajeActivos = $totalUsuarios > 0
    ? round(($totalActivos / $totalUsuarios) * 100, 1)
    : 0;

$promedioLogeos = $totalUsuarios > 0
    ? round($totalLogeos / $totalUsuarios, 2)
    : 0;

/*
|--------------------------------------------------------------------------
| DATA PARA CHART.JS
|--------------------------------------------------------------------------
*/

$chartPerfilesLabels = array_keys($usuariosPorPerfil);
$chartPerfilesValues = array_values($usuariosPorPerfil);

$chartDivisionesLabels = array_keys($usuariosActivosPorDivision);
$chartDivisionesValues = array_values($usuariosActivosPorDivision);

$chartTopLogeosLabels = array_map(function ($row) {
    return $row['usuario'] ?: $row['nombre'];
}, $topUsuariosLogeos);

$chartTopLogeosValues = array_column($topUsuariosLogeos, 'logeos');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Mentecreativa | Visibility 2</title>
    <link rel="icon" href="../images/logo/logo-Visibility.png" type="image/png">
    <!-- Meta y Enlaces -->
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 4 desde CDN -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.12/css/dataTables.bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.2.2/css/buttons.bootstrap.min.css">    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
       

    <style>
/* Hacer visibles las flechas de orden */
table.dataTable thead th.sorting,
table.dataTable thead th.sorting_asc,
table.dataTable thead th.sorting_desc{
    background-image:none !important;
}

table.dataTable thead th.sorting:before,
table.dataTable thead th.sorting_asc:before,
table.dataTable thead th.sorting_desc:before{
    content:"▲";
    position:absolute;
    right:12px;
    top:calc(50% - 11px);
    font-size:10px;
    color:#cbd5e1;
    opacity:.45;
}

table.dataTable thead th.sorting:after,
table.dataTable thead th.sorting_asc:after,
table.dataTable thead th.sorting_desc:after{
    content:"▼";
    position:absolute;
    right:12px;
    top:calc(50% + 1px);
    font-size:10px;
    color:#cbd5e1;
    opacity:.45;
}

table.dataTable thead th.sorting_asc:before{
    color:#ffffff;
    opacity:1;
}

table.dataTable thead th.sorting_asc:after{
    opacity:.18;
}

table.dataTable thead th.sorting_desc:after{
    color:#ffffff;
    opacity:1;
}

table.dataTable thead th.sorting_desc:before{
    opacity:.18;
}


@media (max-width: 768px){
    .stats-title{
        font-size:1.35rem;
    }

    .stats-box{
        width:100%;
    }
}
.table-bordered {
    border: 0px solid #dee2e6;
}
        
/* =========================================================
   USER STATS MODERN UI
========================================================= */

.user-stats-page {
    background:
        radial-gradient(circle at 12% 8%, rgba(95, 160, 255, .10), transparent 34%),
        linear-gradient(180deg, #f6f9fd 0%, #eef3f9 100%);
    min-height: 100vh;
    padding: 26px;
}

.user-stats-shell {
    max-width: 1480px;
    margin: 0 auto;
}

.user-hero,
.user-chart-card,
.user-table-card {
    border-radius: 30px;
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.10), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.90), rgba(245,249,255,.82));
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 24px 55px rgba(70,95,140,.12),
        inset 0 1px 0 rgba(255,255,255,.88);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}

.user-hero {
    padding: 30px;
    margin-bottom: 22px;
}

.user-hero-main {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
    margin-bottom: 26px;
}

.user-hero-title-wrap {
    display: flex;
    gap: 16px;
    align-items: center;
}

.user-hero-icon {
    width: 66px;
    height: 66px;
    border-radius: 22px;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d83ff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 25px;
    box-shadow:
        0 12px 26px rgba(70,110,180,.12),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.user-hero-title {
    margin: 0;
    font-size: 28px;
    font-weight: 950;
    color: #172848;
    letter-spacing: .3px;
}

.user-hero-subtitle {
    margin: 6px 0 0;
    font-size: 14px;
    color: #7285a4;
    font-weight: 650;
}

.user-date-chip {
    height: 48px;
    padding: 0 18px;
    border-radius: 16px;
    border: 1px solid rgba(205,220,242,.95);
    background: rgba(255,255,255,.88);
    color: #2c4d77;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 800;
    box-shadow:
        0 12px 24px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.9);
}

/* KPI */

.user-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
}

.user-kpi-card {
    border-radius: 24px;
    background: rgba(255,255,255,.85);
    border: 1px solid rgba(220,230,245,.95);
    box-shadow:
        0 14px 30px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
    padding: 20px;
    display: flex;
    gap: 16px;
    align-items: center;
}

.user-kpi-icon {
    width: 74px;
    height: 74px;
    min-width: 74px;
    border-radius: 22px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    box-shadow: 0 16px 34px rgba(70,95,140,.18);
}

.user-kpi-icon.blue { background: linear-gradient(135deg, #4d7eff, #6c78ff); }
.user-kpi-icon.green { background: linear-gradient(135deg, #60d5ae, #1fb57c); }
.user-kpi-icon.purple { background: linear-gradient(135deg, #7d5fff, #5f47ff); }
.user-kpi-icon.cyan { background: linear-gradient(135deg, #4aaeff, #2b7dff); }

.user-kpi-label {
    display: block;
    font-size: 12px;
    font-weight: 850;
    color: #6e809e;
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 5px;
}

.user-kpi-value {
    margin: 0;
    font-size: 24px;
    font-weight: 950;
    color: #172848;
}

.user-kpi-help {
    display: block;
    margin-top: 6px;
    color: #7e90aa;
    font-size: 13px;
    font-weight: 700;
}

.user-kpi-progress {
    width: 100%;
    height: 6px;
    background: #e8eef7;
    border-radius: 999px;
    overflow: hidden;
    margin-top: 10px;
}

.user-kpi-progress div {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #1fb57c, #67d19f);
}

/* Charts */

.user-chart-grid {
    display: grid;
    grid-template-columns: 1.05fr 1.05fr 1.3fr;
    gap: 18px;
    margin-bottom: 22px;
}

.user-chart-card {
    padding: 20px;
}

.user-chart-head {
    margin-bottom: 14px;
}

.user-chart-head h3 {
    margin: 0;
    font-size: 20px;
    font-weight: 900;
    color: #1d3156;
}

.user-chart-body {
    height: 300px;
    position: relative;
}

.user-chart-donut-wrap {
    height: 300px;
}

/* Table area */

.user-table-card {
    padding: 20px;
}

.user-table-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.user-table-toolbar-left,
.user-table-toolbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-export-modern {
    height: 42px;
    padding: 0 16px;
    border-radius: 14px;
    border: 1px solid rgba(200,215,238,.9);
    background: rgba(255,255,255,.82);
    color: #355277;
    font-size: 13px;
    font-weight: 850;
    box-shadow:
        0 8px 18px rgba(70,95,140,.06),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.btn-export-modern.active {
    background: linear-gradient(135deg, #4d7eff, #6a73ff);
    color: #fff;
    border-color: transparent;
}

.user-search-modern {
    min-width: 260px;
    height: 44px;
    border-radius: 15px;
    background: rgba(255,255,255,.88);
    border: 1px solid rgba(190,205,230,.9);
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 14px;
    box-shadow:
        0 8px 18px rgba(70,95,140,.06),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.user-search-modern i {
    color: #7a8ba7;
}

.user-search-modern input {
    width: 100%;
    border: 0;
    outline: none;
    background: transparent;
    font-size: 13px;
    color: #28446f;
    font-weight: 700;
}

.user-show-modern {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 800;
    color: #355277;
}

.user-show-modern select {
    height: 42px;
    border-radius: 14px;
    border: 1px solid rgba(190,205,230,.9);
    background: rgba(255,255,255,.88);
    color: #28446f;
    font-weight: 800;
    padding: 0 12px;
}

/* Table */

.user-table-shell {
    border-radius: 24px;
    overflow: hidden;
}

.user-modern-table {
    margin: 0 !important;
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 12px;
}

.user-modern-table thead th {
    background: linear-gradient(135deg, #12284d, #172544);
    color: #fff;
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .45px;
    border: 0 !important;
    padding: 16px;
    vertical-align: middle;
}

.user-modern-table thead th:first-child {
    border-top-left-radius: 18px;
    border-bottom-left-radius: 18px;
}
.user-modern-table thead th:last-child {
    border-top-right-radius: 18px;
    border-bottom-right-radius: 18px;
}

.user-modern-table tbody tr {
    background: rgba(255,255,255,.82);
    box-shadow:
        0 10px 24px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.user-modern-table tbody td {
    padding: 16px;
    vertical-align: middle;
    border-top: 1px solid rgba(228,236,247,.92) !important;
    border-bottom: 1px solid rgba(228,236,247,.92) !important;
    border-left: 0 !important;
    border-right: 0 !important;
    color: #223a5d;
    font-size: 13px;
    font-weight: 700;
    background: transparent !important;
}

.user-modern-table tbody td:first-child {
    border-left: 1px solid rgba(228,236,247,.92) !important;
    border-top-left-radius: 18px;
    border-bottom-left-radius: 18px;
}

.user-modern-table tbody td:last-child {
    border-right: 1px solid rgba(228,236,247,.92) !important;
    border-top-right-radius: 18px;
    border-bottom-right-radius: 18px;
}

.user-cell-name {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar-pill {
    width: 38px;
    height: 38px;
    min-width: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4d7eff, #6a73ff);
    color: #fff;
    font-size: 13px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 10px 20px rgba(77,126,255,.26);
}

.user-name-text {
    font-weight: 900;
    color: #1b3054;
}

.user-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .2px;
}

.user-badge.estado-activo {
    background: #d7f2df;
    color: #0f8a4d;
}

.user-badge.estado-inactivo {
    background: #ffe1e1;
    color: #d04a4a;
}

.user-badge.perfil-purple {
    background: #eee7ff;
    color: #7856ff;
}

.user-badge.perfil-blue {
    background: #e4eeff;
    color: #3f73ff;
}

.user-badge.perfil-green {
    background: #ddf7e7;
    color: #159b63;
}

.user-badge.perfil-default {
    background: #edf1f7;
    color: #4e6484;
}

/* DataTables overrides */

.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dt-buttons {
    display: none !important;
}

.dataTables_wrapper .dataTables_paginate {
    margin-top: 16px;
    text-align: center;
}

.dataTables_wrapper .paginate_button {
    border: 0 !important;
    border-radius: 12px !important;
    background: rgba(255,255,255,.85) !important;
    color: #355277 !important;
    box-shadow: 0 8px 18px rgba(70,95,140,.06);
    margin: 0 4px;
}

.dataTables_wrapper .paginate_button.current {
    background: linear-gradient(135deg, #4d7eff, #6a73ff) !important;
    color: #fff !important;
}

/* Responsive */

@media (max-width: 1280px) {
    .user-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .user-chart-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .user-stats-page {
        padding: 16px;
    }

    .user-hero,
    .user-chart-card,
    .user-table-card {
        padding: 18px;
        border-radius: 24px;
    }

    .user-hero-title {
        font-size: 22px;
    }

    .user-kpi-grid {
        grid-template-columns: 1fr;
    }

    .user-search-modern {
        min-width: 100%;
    }

    .user-table-toolbar-right {
        width: 100%;
    }
}

/* =========================================================
   FIX HEADER TABLA MODERNA - TEXTO VISIBLE
========================================================= */

.user-modern-table thead th {
    background: rgba(244, 248, 255, .96) !important;
    color: #172848 !important;
    text-shadow: none !important;
    border-top: 1px solid rgba(215, 228, 246, .95) !important;
    border-bottom: 1px solid rgba(215, 228, 246, .95) !important;
    font-size: 12px !important;
    font-weight: 950 !important;
}

.user-modern-table thead th.sorting:before,
.user-modern-table thead th.sorting_asc:before,
.user-modern-table thead th.sorting_desc:before,
.user-modern-table thead th.sorting:after,
.user-modern-table thead th.sorting_asc:after,
.user-modern-table thead th.sorting_desc:after {
    color: #64748b !important;
    opacity: .55 !important;
}

.user-modern-table thead th.sorting_asc:before,
.user-modern-table thead th.sorting_desc:after {
    color: #2563eb !important;
    opacity: 1 !important;
}

/* =========================================================
   OCULTAR MENSAJES INTERNOS / RESIDUALES DE DATATABLES
========================================================= */

#tablaUsuariosModern_processing,
.dataTables_processing {
    display: none !important;
    opacity: 0 !important;
    visibility: hidden !important;
}

#tablaUsuariosModern tbody td.dataTables_empty,
#tablaUsuariosModern tbody tr td.dataTables_empty {
    display: none !important;
}

#tablaUsuariosModern tbody tr:has(td.dataTables_empty) {
    display: none !important;
}

/* Oculta textos informativos automáticos de DataTables */
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dt-buttons {
    display: none !important;
}
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
    <div class="module-card">
    <section class="content">
        <div class="container-fluid">
            <!-- Mostrar mensajes de éxito o error -->
            <?php if (isset($_SESSION['success_formulario'])): ?>
                <div class="alert alert-success">
                    <?php
                    echo $_SESSION['success_formulario'];
                    unset($_SESSION['success_formulario']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_formulario'])): ?>
                <div class="alert alert-danger">
                    <?php
                    echo $_SESSION['error_formulario'];
                    unset($_SESSION['error_formulario']);
                    ?>
                </div>
            <?php endif; ?>
            
<div class="user-stats-page">
  <div class="user-stats-shell">

    <!-- HERO -->
    <section class="user-hero">
      <div class="user-hero-main">
        <div class="user-hero-title-wrap">
          <div class="user-hero-icon">
            <i class="fas fa-users"></i>
          </div>
          <div>
            <h1 class="user-hero-title">ESTADÍSTICAS DE USUARIO</h1>
            <p class="user-hero-subtitle">
              Visualiza actividad de acceso, último login, cantidad de logeos y estado de cada usuario.
            </p>
          </div>
        </div>
      </div>

      <!-- KPI -->
      <div class="user-kpi-grid">
        <div class="user-kpi-card">
          <div class="user-kpi-icon blue">
            <i class="fas fa-user-friends"></i>
          </div>
          <div class="user-kpi-content">
            <span class="user-kpi-label">Usuarios analizados</span>
            <h3 class="user-kpi-value"><?= number_format($totalUsuarios, 0, ',', '.'); ?></h3>
            <small class="user-kpi-help">Total en el sistema</small>
          </div>
        </div>

        <div class="user-kpi-card">
          <div class="user-kpi-icon green">
            <i class="fas fa-user-check"></i>
          </div>
          <div class="user-kpi-content">
            <span class="user-kpi-label">Usuarios activos</span>
            <h3 class="user-kpi-value"><?= number_format($totalActivos, 0, ',', '.'); ?></h3>
            <small class="user-kpi-help"><?= $porcentajeActivos; ?>% del total</small>
            <div class="user-kpi-progress">
              <div style="width: <?= $porcentajeActivos; ?>%;"></div>
            </div>
          </div>
        </div>

        <div class="user-kpi-card">
          <div class="user-kpi-icon purple">
            <i class="fas fa-building"></i>
          </div>
          <div class="user-kpi-content">
            <span class="user-kpi-label">Divisiones activas</span>
            <h3 class="user-kpi-value"><?= number_format($totalDivisiones, 0, ',', '.'); ?></h3>
            <small class="user-kpi-help">Con usuarios registrados</small>
          </div>
        </div>

        <div class="user-kpi-card">
          <div class="user-kpi-icon cyan">
            <i class="fas fa-chart-line"></i>
          </div>
          <div class="user-kpi-content">
            <span class="user-kpi-label">Promedio de logeos</span>
            <h3 class="user-kpi-value"><?= number_format($promedioLogeos, 2, ',', '.'); ?></h3>
            <small class="user-kpi-help">Por usuario</small>
          </div>
        </div>
      </div>
    </section>

    <!-- CHARTS -->
    <section class="user-chart-grid">
      <div class="user-chart-card">
        <div class="user-chart-head">
          <h3>Usuarios por perfil</h3>
        </div>
        <div class="user-chart-body user-chart-donut-wrap">
          <canvas id="chartPerfiles"></canvas>
        </div>
      </div>

      <div class="user-chart-card">
        <div class="user-chart-head">
          <h3>Usuarios activos por división</h3>
        </div>
        <div class="user-chart-body">
          <canvas id="chartDivisiones"></canvas>
        </div>
      </div>

      <div class="user-chart-card">
        <div class="user-chart-head">
          <h3>Top usuarios por logeos</h3>
        </div>
        <div class="user-chart-body">
          <canvas id="chartLogeos"></canvas>
        </div>
      </div>
    </section>

    <!-- TABLA -->
    <section class="user-table-card">
      <div class="user-table-toolbar">
        <div class="user-table-toolbar-left">
            <button type="button" class="btn-export-modern active" data-export="copy">Copiar</button>
            <button type="button" class="btn-export-modern" data-export="csv">CSV</button>
            <button type="button" class="btn-export-modern" data-export="excel">Excel</button>
            <button type="button" class="btn-export-modern" data-export="pdf">PDF</button>
            <button type="button" class="btn-export-modern" data-export="print">Print</button>
        </div>

        <div class="user-table-toolbar-right">
          <div class="user-search-modern">
            <i class="fas fa-search"></i>
            <input type="text" id="customUserSearch" placeholder="Buscar usuario...">
          </div>

          <div class="user-show-modern">
            <span>Mostrar</span>
            <select id="customLengthSelect">
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
            <span>registros</span>
          </div>
        </div>
      </div>

      <div class="table-responsive user-table-shell">
        <table id="tablaUsuariosModern" class="table user-modern-table">
          <thead>
            <tr>
              <th>Nombre Completo</th>
              <th>Usuario</th>
              <th>Empresa</th>
              <th>División</th>
              <th>Perfil</th>
              <th>Fecha Creación</th>
              <th>Último Login</th>
              <th>Logeos</th>
              <th>Estado</th>
            </tr>
          </thead>
            <tbody>
              <?php foreach ($usuarios as $usuario): ?>
                <?php
                  $nombreCompleto = normalizarNombre($usuario['nombre_completo'] ?? 'N/A');
                  $iniciales = inicialesUsuario($nombreCompleto);
            
                  $nombreLogin = normalizarNombre($usuario['nombre_login'] ?? 'N/A');
                  $empresa = normalizarNombre($usuario['nombre_empresa'] ?? 'N/A');
                  $division = normalizarNombre($usuario['nombre_division'] ?? 'N/A');
                  $perfil = normalizarNombre($usuario['nombre_perfil'] ?? 'N/A');
                  $fechaCreacion = $usuario['fechaCreacion'] ?? '-';
                  $ultimoLogin = $usuario['UltimoLogin'] ?? '';
                  $logeos = (int)($usuario['logeos'] ?? 0);
                  $activo = (int)($usuario['activo'] ?? 0) === 1;
            
                  $perfilClass = 'default';
            
                  if (stripos($perfil, 'editor') !== false) {
                      $perfilClass = 'purple';
                  } elseif (stripos($perfil, 'ejecutor') !== false) {
                      $perfilClass = 'blue';
                  } elseif (stripos($perfil, 'admin') !== false || stripos($perfil, 'administrador') !== false) {
                      $perfilClass = 'green';
                  }
            
                  $estadoTexto = $activo ? 'Activo' : 'Inactivo';
                  $estadoClass = $activo ? 'activo' : 'inactivo';
                ?>
            
                <tr>
                  <td>
                    <div class="user-cell-name">
                      <div class="user-avatar-pill">
                        <?= h($iniciales); ?>
                      </div>
            
                      <div class="user-name-text">
                        <?= h(mb_strtoupper($nombreCompleto, 'UTF-8')); ?>
                      </div>
                    </div>
                  </td>
            
                  <td><?= h(mb_strtoupper($nombreLogin, 'UTF-8')); ?></td>
            
                  <td><?= h(mb_strtoupper($empresa, 'UTF-8')); ?></td>
            
                  <td><?= h(mb_strtoupper($division, 'UTF-8')); ?></td>
            
                  <td>
                    <span class="user-badge perfil-<?= h($perfilClass); ?>">
                      <?= h(mb_strtoupper($perfil, 'UTF-8')); ?>
                    </span>
                  </td>
            
                  <td><?= h($fechaCreacion); ?></td>
            
                  <td>
                    <?php if (!empty($ultimoLogin)): ?>
                      <?= h($ultimoLogin); ?>
                    <?php else: ?>
                      <span class="text-muted">Nunca</span>
                    <?php endif; ?>
                  </td>
            
                  <td><?= number_format($logeos, 0, ',', '.'); ?></td>
            
                  <td>
                    <span class="user-badge estado-<?= h($estadoClass); ?>">
                      <?= h($estadoTexto); ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
        </table>
      </div>
    </section>

  </div>
</div>
        </div>
    </section>
</div>    
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="/visibility2/portal/dist/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.10.12/js/dataTables.bootstrap.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/2.5.0/jszip.min.js"></script>
<script src="https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/pdfmake.min.js"></script>
<script src="https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/vfs_fonts.js"></script>

<script src="https://cdn.datatables.net/buttons/1.2.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.2/js/buttons.bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.2/js/buttons.colVis.min.js"></script>


<script>
/* =========================================================
   CHART DATA
========================================================= */
const perfilesLabels = <?= json_encode($chartPerfilesLabels, JSON_UNESCAPED_UNICODE); ?>;
const perfilesValues = <?= json_encode($chartPerfilesValues, JSON_UNESCAPED_UNICODE); ?>;

const divisionesLabels = <?= json_encode($chartDivisionesLabels, JSON_UNESCAPED_UNICODE); ?>;
const divisionesValues = <?= json_encode($chartDivisionesValues, JSON_UNESCAPED_UNICODE); ?>;

const logeosLabels = <?= json_encode($chartTopLogeosLabels, JSON_UNESCAPED_UNICODE); ?>;
const logeosValues = <?= json_encode($chartTopLogeosValues, JSON_UNESCAPED_UNICODE); ?>;
/* =========================================================
   CHART 1 - PERFILES
========================================================= */
new Chart(document.getElementById('chartPerfiles'), {
    type: 'doughnut',
    data: {
        labels: perfilesLabels,
        datasets: [{
            data: perfilesValues
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    boxWidth: 12,
                    usePointStyle: true,
                    pointStyle: 'circle'
                }
            }
        }
    }
});

/* =========================================================
   CHART 2 - DIVISIONES
========================================================= */
new Chart(document.getElementById('chartDivisiones'), {
    type: 'bar',
    data: {
        labels: divisionesLabels,
        datasets: [{
            label: 'Usuarios activos',
            data: divisionesValues,
            borderRadius: 10
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: {
                beginAtZero: true
            }
        }
    }
});

/* =========================================================
   CHART 3 - TOP LOGEOS
========================================================= */
new Chart(document.getElementById('chartLogeos'), {
    type: 'line',
    data: {
        labels: logeosLabels,
        datasets: [{
            label: 'Logeos',
            data: logeosValues,
            tension: 0.35,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        }
    }
});
</script>

<script>
$(document).ready(function () {
    document.title = "Estadísticas de Usuario";

    $.fn.dataTable.ext.errMode = 'none';

    if ($.fn.DataTable.isDataTable('#tablaUsuariosModern')) {
        $('#tablaUsuariosModern').DataTable().destroy();
    }

    const tabla = $('#tablaUsuariosModern').DataTable({
        dom: 'Brtip',
        processing: false,
        paging: true,
        autoWidth: false,
        pageLength: 10,
        order: [[7, 'desc']],
        buttons: [
            {
                extend: 'copyHtml5',
                title: 'Estadísticas de Usuario'
            },
            {
                extend: 'csvHtml5',
                title: 'Estadísticas de Usuario'
            },
            {
                extend: 'excelHtml5',
                title: 'Estadísticas de Usuario'
            },
            {
                extend: 'pdfHtml5',
                title: 'Estadísticas de Usuario',
                orientation: 'landscape',
                pageSize: 'A4'
            },
            {
                extend: 'print',
                title: 'Estadísticas de Usuario'
            }
        ],
        language: {
            processing: "",
            loadingRecords: "",
            emptyTable: "",
            zeroRecords: "",
            search: "Buscar:",
            lengthMenu: "Mostrar _MENU_ registros",
            info: "",
            infoEmpty: "",
            infoFiltered: "",
            paginate: {
                first: "Primero",
                last: "Último",
                next: "Siguiente",
                previous: "Anterior"
            }
        },
        initComplete: function () {
            $('.dataTables_processing').hide();
            $('.dataTables_empty').closest('tr').hide();
        },
        drawCallback: function () {
            $('.dataTables_processing').hide();
            $('.dataTables_empty').closest('tr').hide();
        }
    });

    $('#customUserSearch').on('keyup', function () {
        tabla.search(this.value).draw();
    });

    $('#customLengthSelect').on('change', function () {
        tabla.page.len($(this).val()).draw();
    });

    $('[data-export]').on('click', function () {
        const type = $(this).data('export');

        $('.btn-export-modern').removeClass('active');
        $(this).addClass('active');

        const exportIndex = {
            copy: 0,
            csv: 1,
            excel: 2,
            pdf: 3,
            print: 4
        };

        if (typeof exportIndex[type] !== 'undefined') {
            tabla.button(exportIndex[type]).trigger();
        }
    });
});
</script>



</body>
</html>
