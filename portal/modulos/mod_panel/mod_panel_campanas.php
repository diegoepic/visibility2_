<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$nombreU      = $_SESSION['usuario_nombre'];
$apellido    = $_SESSION['usuario_apellido'];
$id_division = intval($_SESSION['division_id']);
$id_empresa  = intval($_SESSION['empresa_id']);

$division_nombre_sesion = '';
if ($id_division > 0) {
    $stmtSesionDiv = $conn->prepare("SELECT nombre FROM division_empresa WHERE id = ? LIMIT 1");
    $stmtSesionDiv->bind_param("i", $id_division);
    $stmtSesionDiv->execute();
    $stmtSesionDiv->bind_result($division_nombre_sesion);
    $stmtSesionDiv->fetch();
    $stmtSesionDiv->close();
}

$esUsuarioMC = strtoupper(trim($division_nombre_sesion)) === 'MC';

$division_filtro = $esUsuarioMC && isset($_GET['division'])
    ? intval($_GET['division'])
    : $id_division;

$subdivision_filtro = isset($_GET['subdivision']) 
    ? intval($_GET['subdivision']) 
    : 0;

$trade_filtro = isset($_GET['trade'])
    ? intval($_GET['trade'])
    : 0;

$categoria_filtro = isset($_GET['categoria'])
    ? intval($_GET['categoria'])
    : 0;


/* ======================================================
   CARGAR DIVISIONES DESDE FORMULARIO
====================================================== */

$sqlDiv = "
    SELECT DISTINCT d.id, d.nombre
    FROM formulario f
    INNER JOIN division_empresa d ON d.id = f.id_division
    WHERE f.estado = 1
      AND f.id_empresa = ?
      AND d.estado = 1
    ORDER BY d.nombre
";

$stmtDiv = $conn->prepare($sqlDiv);
$stmtDiv->bind_param("i", $id_empresa);
$stmtDiv->execute();
$resDiv = $stmtDiv->get_result();

$divisiones = [];
while ($row = $resDiv->fetch_assoc()) {
    $divisiones[] = $row;
}
$stmtDiv->close();

if (!$esUsuarioMC) {
    $divisiones = array_values(array_filter(
        $divisiones,
        static fn(array $division): bool => (int)$division['id'] === $division_filtro
    ));
}

/* ======================================================
   CARGAR TRADES Y CATEGORIAS PARA FILTROS
====================================================== */

$sqlTrades = "
    SELECT DISTINCT
        f.id_trade,
        COALESCE(NULLIF(TRIM(t.nombre), ''), CONCAT('TRADE ', f.id_trade)) AS nombre_trade
    FROM formulario f
    LEFT JOIN trade t ON t.id = f.id_trade
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    WHERE f.estado = 1
      AND f.id_empresa = ?
      AND f.id_division = ?
      AND f.id_trade IS NOT NULL
      AND f.id_trade > 0
";
$typesTrades = "ii";
$paramsTrades = [$id_empresa, $division_filtro];

if ($subdivision_filtro > 0) {
    $sqlTrades .= " AND f.id_subdivision = ? ";
    $typesTrades .= "i";
    $paramsTrades[] = $subdivision_filtro;
}

$sqlTrades .= " ORDER BY nombre_trade ASC ";

$stmtTrades = $conn->prepare($sqlTrades);
$stmtTrades->bind_param($typesTrades, ...$paramsTrades);
$stmtTrades->execute();
$resTrades = $stmtTrades->get_result();

$trades = [];
while ($row = $resTrades->fetch_assoc()) {
    $trades[] = $row;
}
$stmtTrades->close();

$sqlCategorias = "
    SELECT DISTINCT
        f.id_categoria_formulario,
        COALESCE(NULLIF(TRIM(cf.nombre), ''), CONCAT('CATEGORIA ', f.id_categoria_formulario)) AS nombre_categoria
    FROM formulario f
    LEFT JOIN categoria_formulario cf ON cf.id = f.id_categoria_formulario
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    WHERE f.estado = 1
      AND f.id_empresa = ?
      AND f.id_division = ?
      AND f.id_categoria_formulario IS NOT NULL
      AND f.id_categoria_formulario > 0
";
$typesCategorias = "ii";
$paramsCategorias = [$id_empresa, $division_filtro];

if ($subdivision_filtro > 0) {
    $sqlCategorias .= " AND f.id_subdivision = ? ";
    $typesCategorias .= "i";
    $paramsCategorias[] = $subdivision_filtro;
}

$sqlCategorias .= " ORDER BY nombre_categoria ASC ";

$stmtCategorias = $conn->prepare($sqlCategorias);
$stmtCategorias->bind_param($typesCategorias, ...$paramsCategorias);
$stmtCategorias->execute();
$resCategorias = $stmtCategorias->get_result();

$categorias = [];
while ($row = $resCategorias->fetch_assoc()) {
    $categorias[] = $row;
}
$stmtCategorias->close();


/* ======================================================
   UNIVERSO CAMPAÑAS ACTIVAS
====================================================== */

$sqlCampanas = "
    SELECT
        f.id,
        UPPER(f.nombre) AS nombre_campana,
        f.fechaInicio,
        f.fechaTermino,

        COUNT(DISTINCT fq.id_local) AS locales_asignados,

        COUNT(DISTINCT CASE 
            WHEN fq.countVisita > 0
            THEN fq.id_local
        END) AS locales_visitados,

        COUNT(DISTINCT CASE 
            WHEN fq.countVisita > 0
             AND fq.pregunta IN ('solo_auditoria','solo_implementado','implementado_auditado','completado')
            THEN fq.id_local
        END) AS locales_gestionados

    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id

    WHERE f.estado = 1
      AND f.id_empresa = ?
      AND f.id_division = ?
";

$paramsC = [$id_empresa, $division_filtro];
$typesC  = "ii";

if ($subdivision_filtro > 0) {
    $sqlCampanas .= " AND f.id_subdivision = ? ";
    $typesC .= "i";
    $paramsC[] = $subdivision_filtro;
}

if ($trade_filtro > 0) {
    $sqlCampanas .= " AND f.id_trade = ? ";
    $typesC .= "i";
    $paramsC[] = $trade_filtro;
} elseif ($trade_filtro === -1) {
    $sqlCampanas .= " AND (f.id_trade IS NULL OR f.id_trade <= 0) ";
}

if ($categoria_filtro > 0) {
    $sqlCampanas .= " AND f.id_categoria_formulario = ? ";
    $typesC .= "i";
    $paramsC[] = $categoria_filtro;
} elseif ($categoria_filtro === -1) {
    $sqlCampanas .= " AND (f.id_categoria_formulario IS NULL OR f.id_categoria_formulario <= 0) ";
}

$sqlCampanas .= "
    GROUP BY f.id
    ORDER BY f.fechaInicio DESC
";

$stmtC = $conn->prepare($sqlCampanas);
$stmtC->bind_param($typesC, ...$paramsC);
$stmtC->execute();
$resC = $stmtC->get_result();

$campanas = [];
while ($row = $resC->fetch_assoc()) {
    $campanas[] = $row;
}
$stmtC->close();

$idsCampanasActivas = array_map('intval', array_column($campanas, 'id'));
$urlDescargaMasivaActivas = '/visibility2/portal/informes/descarga_excel_masivo_gantt.php?activas=1'
    . '&id_empresa=' . urlencode((string)$id_empresa)
    . '&id_division=' . urlencode((string)$division_filtro)
    . '&id_subdivision=' . urlencode((string)$subdivision_filtro)
    . '&id_trade=' . urlencode((string)$trade_filtro)
    . '&id_categoria_formulario=' . urlencode((string)$categoria_filtro)
    . '&fotos=0&fotos_encuesta=0';


/* ======================================================
   CALCULO KPI GENERALES
====================================================== */

$totalCampanas = count($campanas);
$totalLocalesAsignados = 0;
$totalLocalesVisitados = 0;
$totalLocalesGestionados = 0;

foreach ($campanas as $c) {
    $totalLocalesAsignados  += (int)$c['locales_asignados'];
    $totalLocalesVisitados  += (int)$c['locales_visitados'];
    $totalLocalesGestionados+= (int)$c['locales_gestionados'];
}

$ratioVisitadosTotal = $totalLocalesAsignados > 0
    ? round(($totalLocalesVisitados / $totalLocalesAsignados) * 100, 1)
    : 0;

$ratioGestionadosTotal = $totalLocalesAsignados > 0
    ? round(($totalLocalesGestionados / $totalLocalesAsignados) * 100, 1)
    : 0;


function iconRatio($ratio) {
    if ($ratio >= 80) return '<i class="fas fa-check-circle text-success"></i>';
    if ($ratio >= 50) return '<i class="fas fa-exclamation-triangle text-warning"></i>';
    return '<i class="fas fa-times-circle text-danger"></i>';
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panel de Control - Coordinador</title>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <link rel="stylesheet" href="<?= '/visibility2/portal/css/mod_panel.css?v=' . time(); ?>">
<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">  
<style>
    body {
    background:
        radial-gradient(circle at top left, rgba(90, 142, 255, 0.15), transparent 28%),
        linear-gradient(180deg, #f5f8fc 0%, #eef3f9 100%);
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    color: #172848;
}

.modern-shell {
    max-width: 1540px;
}

.modern-page-header {
    border: 1px solid rgba(215,228,246,.95);
    border-radius: 30px;
    padding: 28px 30px;
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.14), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.92), rgba(245,249,255,.84));
    box-shadow:
        0 24px 55px rgba(70,95,140,.12),
        inset 0 1px 0 rgba(255,255,255,.88);
    backdrop-filter: blur(16px);
}

.modern-page-header-left {
    display: flex;
    align-items: center;
    gap: 18px;
}

.modern-page-icon {
    width: 68px;
    height: 68px;
    border-radius: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d7eff;
    font-size: 26px;
    box-shadow:
        0 12px 26px rgba(70,110,180,.12),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.modern-page-title {
    margin: 0;
    font-size: 30px;
    font-weight: 950;
    color: #172848;
}

.modern-page-subtitle {
    margin: 6px 0 0;
    color: #7285a4;
    font-size: 14px;
    font-weight: 650;
}

.modern-card {
    border: 1px solid rgba(215,228,246,.95);
    border-radius: 28px;
    overflow: hidden;
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.08), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.92), rgba(245,249,255,.84));
    box-shadow:
        0 24px 55px rgba(70,95,140,.10),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.modern-card-body {
    padding: 24px;
}

.section-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.section-title {
    margin: 0;
    color: #172848;
    font-size: 20px;
    font-weight: 900;
}

.section-subtitle {
    margin: 6px 0 0;
    color: #7285a4;
    font-size: 13px;
    font-weight: 650;
}

.modern-label {
    color: #294469;
    font-size: 13px;
    font-weight: 850;
    margin-bottom: 8px;
}

.modern-control {
    min-height: 46px;
    border-radius: 15px !important;
    border: 1px solid rgba(185,202,230,.78) !important;
    background: rgba(255,255,255,.92) !important;
    color: #28446f !important;
    font-size: 13px !important;
    font-weight: 700;
    box-shadow:
        0 8px 18px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.8) !important;
}

.modern-control:focus {
    border-color: #8eb7ff !important;
    box-shadow:
        0 0 0 4px rgba(90,142,255,.12),
        0 8px 18px rgba(76,108,163,.08) !important;
}

.modern-btn {
    min-height: 46px;
    border-radius: 15px !important;
    border: 0 !important;
    font-size: 13px !important;
    font-weight: 850 !important;
    box-shadow:
        0 14px 28px rgba(70,95,140,.12),
        inset 0 1px 0 rgba(255,255,255,.32) !important;
}

.modern-btn-primary {
    background: linear-gradient(135deg, #4d7eff, #6a73ff) !important;
    color: #fff !important;
}

.modern-btn-success {
    background: linear-gradient(135deg, #16a34a, #22c55e) !important;
    color: #fff !important;
}

.modern-btn.disabled,
.modern-btn:disabled {
    opacity: .55;
    pointer-events: none;
}

.modern-kpi-card {
    border-radius: 24px;
    background: rgba(255,255,255,.88);
    border: 1px solid rgba(220,230,245,.95);
    box-shadow:
        0 14px 30px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    min-height: 110px;
}

.modern-kpi-icon {
    width: 62px;
    height: 62px;
    min-width: 62px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    box-shadow: 0 14px 30px rgba(70,95,140,.18);
}

.modern-kpi-icon.blue   { background: linear-gradient(135deg, #4d7eff, #6c78ff); }
.modern-kpi-icon.cyan   { background: linear-gradient(135deg, #34b6ff, #1a8cff); }
.modern-kpi-icon.green  { background: linear-gradient(135deg, #60d5ae, #1fb57c); }
.modern-kpi-icon.purple { background: linear-gradient(135deg, #7d5fff, #5f47ff); }

.modern-kpi-label {
    font-size: 12px;
    font-weight: 850;
    color: #6e809e;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 6px;
}

.modern-kpi-value {
    font-size: 28px;
    line-height: 1;
    font-weight: 950;
    color: #172848;
}

.modern-table {
    border-collapse: separate !important;
    border-spacing: 0 12px !important;
    margin: 0 !important;
}

.modern-table thead th {
    background: rgba(244,248,255,.96) !important;
    color: #172848 !important;
    border: 0 !important;
    font-size: 12px !important;
    font-weight: 950 !important;
    text-transform: uppercase;
    letter-spacing: .45px;
    padding: 16px !important;
    white-space: nowrap;
}

.modern-table thead th:first-child {
    border-top-left-radius: 18px;
    border-bottom-left-radius: 18px;
}

.modern-table thead th:last-child {
    border-top-right-radius: 18px;
    border-bottom-right-radius: 18px;
}

.modern-table tbody tr {
    background: rgba(255,255,255,.88);
    box-shadow:
        0 10px 24px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.modern-table tbody td {
    background: transparent !important;
    color: #223a5d !important;
    font-size: 13px !important;
    font-weight: 700;
    padding: 16px !important;
    border-top: 1px solid rgba(228,236,247,.92) !important;
    border-bottom: 1px solid rgba(228,236,247,.92) !important;
    border-left: 0 !important;
    border-right: 0 !important;
    vertical-align: middle !important;
}

.modern-table tbody td:first-child {
    border-left: 1px solid rgba(228,236,247,.92) !important;
    border-top-left-radius: 18px !important;
    border-bottom-left-radius: 18px !important;
}

.modern-table tbody td:last-child {
    border-right: 1px solid rgba(228,236,247,.92) !important;
    border-top-right-radius: 18px !important;
    border-bottom-right-radius: 18px !important;
}

.campaign-title {
    font-weight: 900;
    color: #1b3054;
    line-height: 1.25;
}

.mini-progress-wrap {
    min-width: 140px;
}

.mini-progress-label {
    display: flex;
    justify-content: flex-end;
    font-size: 12px;
    color: #5f7396;
    font-weight: 800;
    margin-bottom: 6px;
}

.mini-progress {
    height: 10px;
    border-radius: 999px;
    background: #e8eef7;
    overflow: hidden;
}

.mini-progress-bar {
    height: 100%;
    border-radius: 999px;
}

.mini-progress-bar.blue {
    background: linear-gradient(90deg, #4d7eff, #6a73ff);
}

.mini-progress-bar.green {
    background: linear-gradient(90deg, #1fb57c, #60d5ae);
}

.modern-badge {
    display: inline-flex;
    align-items: center;
    min-height: 32px;
    padding: 7px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .2px;
}

.badge-success {
    background: #d7f2df;
    color: #0f8a4d;
}

.badge-warning {
    background: #fff0cc;
    color: #a86b00;
}

.badge-danger {
    background: #ffe1e1;
    color: #d04a4a;
}

@media (max-width: 768px) {
    .modern-page-header-left {
        align-items: flex-start;
        flex-direction: column;
    }

    .modern-page-title {
        font-size: 24px;
    }

    .modern-card-body {
        padding: 18px;
    }

    .modern-kpi-card {
        min-height: auto;
    }
}

/* =========================================================
   DATATABLES MODERNO / TABLA COMPACTA
========================================================= */

.compact-campaign-table {
    border-spacing: 0 8px !important;
}

.compact-campaign-table thead th {
    padding: 12px 14px !important;
    font-size: 11px !important;
}

.compact-campaign-table tbody td {
    padding: 12px 14px !important;
    font-size: 12.5px !important;
}

.compact-campaign-table .campaign-title {
    max-width: 420px;
    font-size: 12.5px;
    line-height: 1.25;
}

.compact-campaign-table .mini-progress-wrap {
    min-width: 120px;
}

.compact-campaign-table .mini-progress-label {
    font-size: 11px;
    margin-bottom: 5px;
}

.compact-campaign-table .mini-progress {
    height: 8px;
}

.compact-campaign-table .modern-badge {
    min-height: 28px;
    padding: 5px 12px;
    font-size: 11px;
}

/* Toolbar DataTables */

.dt-modern-toolbar {
    gap: 14px;
    padding: 14px 16px;
    margin-bottom: 14px !important;
    border-radius: 20px;
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(220,230,245,.95);
    box-shadow:
        0 10px 24px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.8);
}

.dt-modern-toolbar label {
    margin: 0;
    color: #5f7396;
    font-size: 12px;
    font-weight: 850;
    display: flex;
    align-items: center;
    gap: 8px;
}

.dt-modern-left,
.dt-modern-right {
    display: flex;
    align-items: center;
    gap: 10px;
}

.modern-dt-search {
    width: 290px !important;
    min-height: 42px;
    border-radius: 15px !important;
    border: 1px solid rgba(185,202,230,.78) !important;
    background: rgba(255,255,255,.95) !important;
    color: #28446f !important;
    font-size: 13px !important;
    font-weight: 700;
    padding: 8px 14px !important;
    box-shadow:
        0 8px 18px rgba(70,95,140,.05),
        inset 0 1px 0 rgba(255,255,255,.8) !important;
}

.modern-dt-search:focus {
    border-color: #8eb7ff !important;
    box-shadow:
        0 0 0 4px rgba(90,142,255,.12),
        0 8px 18px rgba(76,108,163,.08) !important;
    outline: none !important;
}

.modern-dt-length {
    min-height: 38px;
    border-radius: 13px !important;
    border: 1px solid rgba(185,202,230,.78) !important;
    background: rgba(255,255,255,.95) !important;
    color: #28446f !important;
    font-size: 12px !important;
    font-weight: 800;
}

.dt-modern-footer {
    gap: 14px;
    padding: 12px 4px 0;
}

.dataTables_info {
    color: #7285a4 !important;
    font-size: 12px !important;
    font-weight: 750;
}

.dataTables_paginate .pagination {
    margin: 0 !important;
}

.page-item .page-link {
    border: 0 !important;
    min-width: 36px;
    height: 36px;
    border-radius: 12px !important;
    margin: 0 3px;
    color: #355277 !important;
    font-size: 12px;
    font-weight: 850;
    background: rgba(255,255,255,.82) !important;
    box-shadow:
        0 8px 16px rgba(70,95,140,.06),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.page-item.active .page-link {
    background: linear-gradient(135deg, #4d7eff, #6a73ff) !important;
    color: #fff !important;
}

.page-item.disabled .page-link {
    opacity: .5;
}

/* Estado vacío */

.modern-empty-state {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px;
    border-radius: 20px;
    background: rgba(229,242,255,.96);
    border: 1px solid rgba(195,220,250,.9);
    color: #245b95;
}

.modern-empty-state i {
    width: 42px;
    height: 42px;
    min-width: 42px;
    border-radius: 15px;
    background: rgba(255,255,255,.75);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4d7eff;
    font-size: 18px;
}

.modern-empty-state strong {
    display: block;
    font-size: 14px;
    font-weight: 950;
}

.modern-empty-state span {
    display: block;
    margin-top: 3px;
    font-size: 12px;
    font-weight: 700;
    color: #5f7396;
}

@media (max-width: 768px) {
    .dt-modern-toolbar {
        flex-direction: column;
        align-items: stretch !important;
    }

    .dt-modern-left,
    .dt-modern-right {
        width: 100%;
        justify-content: space-between;
    }

    .modern-dt-search {
        width: 100% !important;
    }

    .dt-modern-footer {
        flex-direction: column;
        align-items: stretch !important;
    }
}

.campaign-date-cell {
    white-space: nowrap;
    color: #5f7396 !important;
    font-size: 12px !important;
    font-weight: 900 !important;
}

/* =========================================================
   AJUSTE NOMBRES LARGOS CAMPAÑAS
========================================================= */

#tablaCampanas {
    table-layout: fixed;
    width: 100% !important;
}

#tablaCampanas th:nth-child(1),
#tablaCampanas td:nth-child(1) {
    width: 34%;
}

#tablaCampanas th:nth-child(2),
#tablaCampanas td:nth-child(2) {
    width: 90px;
}

#tablaCampanas th:nth-child(3),
#tablaCampanas td:nth-child(3),
#tablaCampanas th:nth-child(4),
#tablaCampanas td:nth-child(4),
#tablaCampanas th:nth-child(5),
#tablaCampanas td:nth-child(5) {
    width: 95px;
}

#tablaCampanas th:nth-child(6),
#tablaCampanas td:nth-child(6),
#tablaCampanas th:nth-child(7),
#tablaCampanas td:nth-child(7) {
    width: 135px;
}

#tablaCampanas th:nth-child(8),
#tablaCampanas td:nth-child(8) {
    width: 90px;
}

.campaign-cell {
    max-width: 0;
}

.campaign-name-cell {
    width: 100%;
    overflow: hidden;
}

.campaign-title {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;

    overflow: hidden;
    text-overflow: ellipsis;

    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;

    max-width: 100% !important;
    line-height: 1.25;
    font-size: 12.5px;
    font-weight: 900;
    color: #1b3054;
}

/* Evita que los números y fechas se desarmen */
#tablaCampanas td:not(:first-child),
#tablaCampanas th:not(:first-child) {
    white-space: nowrap;
}

/* Mantiene compactas las barras */
#tablaCampanas .mini-progress-wrap {
    min-width: 105px;
}

/* =========================================================
   BOTÓN Y MODAL DETALLE CAMPAÑA
========================================================= */

.btn-campaign-detail {
    min-height: 32px;
    border-radius: 999px !important;
    padding: 6px 13px !important;
    background: rgba(255,255,255,.86) !important;
    color: #355277 !important;
    border: 1px solid rgba(200,215,238,.9) !important;
    font-size: 11px !important;
    font-weight: 900 !important;
    box-shadow:
        0 8px 18px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.btn-campaign-detail:hover {
    background: linear-gradient(135deg, #4d7eff, #6a73ff) !important;
    color: #fff !important;
    transform: translateY(-1px);
}

.campaign-detail-modal {
    border: 0 !important;
    border-radius: 28px !important;
    overflow: hidden;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.12), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.98), rgba(245,249,255,.96)) !important;
    box-shadow:
        0 34px 95px rgba(20,45,90,.30),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
}

.campaign-detail-header {
    min-height: 82px;
    background: rgba(248,251,255,.96) !important;
    border-bottom: 1px solid rgba(205,218,238,.78) !important;
    align-items: center;
}

.campaign-detail-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.campaign-detail-icon {
    width: 54px;
    height: 54px;
    min-width: 54px;
    border-radius: 18px;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d7eff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    box-shadow:
        0 12px 26px rgba(70,110,180,.12),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.campaign-detail-header .modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 950;
    color: #172848;
}

.campaign-detail-header p {
    margin: 4px 0 0;
    font-size: 12px;
    font-weight: 700;
    color: #7285a4;
}

.campaign-detail-close {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: rgba(240,246,255,.95) !important;
    color: #536782 !important;
    text-shadow: none !important;
    opacity: 1 !important;
}

.campaign-detail-body {
    padding: 22px !important;
}

.campaign-detail-main-card {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    padding: 18px;
    border-radius: 22px;
    margin-bottom: 18px;
    background: rgba(255,255,255,.86);
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 14px 30px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.campaign-detail-label {
    display: block;
    color: #7285a4;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .45px;
    margin-bottom: 6px;
}

.campaign-detail-main-card h4 {
    margin: 0;
    color: #172848;
    font-size: 17px;
    line-height: 1.35;
    font-weight: 950;
}

.campaign-detail-kpi {
    height: 100%;
    min-height: 92px;
    padding: 16px;
    border-radius: 20px;
    background: rgba(255,255,255,.80);
    border: 1px solid rgba(220,230,245,.95);
    box-shadow:
        0 10px 24px rgba(70,95,140,.06),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.campaign-detail-kpi span {
    display: block;
    color: #7285a4;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 8px;
}

.campaign-detail-kpi strong {
    display: block;
    color: #172848;
    font-size: 22px;
    font-weight: 950;
    line-height: 1;
}

.campaign-detail-progress-block {
    margin-top: 4px;
    padding: 18px;
    border-radius: 22px;
    background: rgba(244,248,255,.78);
    border: 1px solid rgba(220,230,245,.95);
}

.campaign-detail-progress-row + .campaign-detail-progress-row {
    margin-top: 18px;
}

.campaign-detail-progress-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #28446f;
    font-size: 13px;
    font-weight: 900;
    margin-bottom: 8px;
}

.campaign-detail-progress-head span {
    color: #5f7396;
}

.campaign-detail-progress {
    height: 12px;
    border-radius: 999px;
    background: #e8eef7;
    overflow: hidden;
}

.campaign-detail-progress-bar {
    height: 100%;
    border-radius: 999px;
    transition: width .25s ease;
}

.campaign-detail-progress-bar.blue {
    background: linear-gradient(90deg, #4d7eff, #6a73ff);
}

.campaign-detail-progress-bar.green {
    background: linear-gradient(90deg, #1fb57c, #60d5ae);
}

@media (max-width: 768px) {
    .campaign-detail-main-card {
        flex-direction: column;
    }
}

/* =========================================================
   MODAL FULL DETALLE CAMPAÑA
========================================================= */

.campaign-detail-full-dialog {
    width: 95vw;
    max-width: 95vw;
    height: 95vh;
    margin: 2.5vh auto;
}

.campaign-detail-full-content {
    height: 95vh;
    border: 0 !important;
    border-radius: 28px !important;
    overflow: hidden;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.12), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.98), rgba(245,249,255,.96)) !important;
    box-shadow:
        0 34px 95px rgba(20,45,90,.30),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
}

.campaign-detail-full-header {
    min-height: 86px;
    border-bottom: 1px solid rgba(205,218,238,.78) !important;
    background: rgba(248,251,255,.96) !important;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.campaign-detail-header-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

.campaign-detail-big-icon {
    width: 58px;
    height: 58px;
    border-radius: 20px;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d7eff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    box-shadow:
        0 12px 26px rgba(70,110,180,.12),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.campaign-detail-full-close {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: rgba(240,246,255,.95) !important;
    color: #536782 !important;
    text-shadow: none !important;
    opacity: 1 !important;
}

.campaign-detail-full-body {
    height: calc(95vh - 86px);
    overflow-y: auto;
    padding: 24px !important;
}

.campaign-detail-loading {
    min-height: 280px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4d6286;
    font-weight: 700;
}

.campaign-detail-top-card {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    padding: 22px;
    border-radius: 24px;
    background: rgba(255,255,255,.86);
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 14px 30px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.campaign-detail-label {
    display: block;
    color: #7285a4;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .45px;
    margin-bottom: 6px;
}

.campaign-detail-top-card h3 {
    color: #172848;
    font-weight: 950;
    line-height: 1.25;
}

.campaign-detail-top-text {
    color: #7285a4;
    font-size: 13px;
    font-weight: 700;
}

.campaign-detail-kpi-card,
.campaign-detail-mini-kpi {
    height: 100%;
    min-height: 108px;
    padding: 18px;
    border-radius: 22px;
    background: rgba(255,255,255,.84);
    border: 1px solid rgba(220,230,245,.95);
    box-shadow:
        0 10px 24px rgba(70,95,140,.06),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.campaign-detail-kpi-card.alt {
    background: rgba(245,249,255,.96);
}

.campaign-detail-kpi-card span,
.campaign-detail-mini-kpi span {
    display: block;
    color: #7285a4;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 8px;
}

.campaign-detail-kpi-card strong,
.campaign-detail-mini-kpi strong {
    display: block;
    color: #172848;
    font-size: 24px;
    font-weight: 950;
    line-height: 1;
}

.campaign-detail-kpi-card small {
    display: block;
    margin-top: 10px;
    color: #7083a3;
    font-size: 12px;
    font-weight: 700;
}

.campaign-timeline-card {
    padding: 24px;
    border-radius: 24px;
    background: rgba(255,255,255,.86);
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 14px 30px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.campaign-timeline-wrap {
    position: relative;
    height: 180px;
    margin-top: 10px;
}

.campaign-timeline-line {
    position: absolute;
    left: 0;
    right: 0;
    top: 72px;
    height: 10px;
    border-radius: 999px;
    background: linear-gradient(90deg, #e7eef8, #dbe7f7);
}

.campaign-timeline-node {
    position: absolute;
    top: 40px;
    transform: translateX(-50%);
    text-align: center;
    width: 170px;
}

.campaign-timeline-node.start {
    transform: none;
    text-align: left;
    width: 160px;
}

.campaign-timeline-node.end {
    transform: translateX(-100%);
    text-align: right;
    width: 180px;
}

.campaign-timeline-node .dot {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #4d7eff;
    margin: 0 auto 12px;
    box-shadow: 0 0 0 8px rgba(77,126,255,.12);
}

.campaign-timeline-node.start .dot {
    margin-left: 0;
}

.campaign-timeline-node.end .dot {
    margin-right: 0;
    margin-left: auto;
}

.campaign-timeline-node .dot.blue {
    background: #4d7eff;
    box-shadow: 0 0 0 8px rgba(77,126,255,.12);
}

.campaign-timeline-node .dot.green {
    background: #1fb57c;
    box-shadow: 0 0 0 8px rgba(31,181,124,.12);
}

.campaign-timeline-node .dot.purple {
    background: #6a73ff;
    box-shadow: 0 0 0 8px rgba(106,115,255,.12);
}

.campaign-timeline-node .label strong {
    display: block;
    color: #1d3559;
    font-size: 13px;
    font-weight: 900;
}

.campaign-timeline-node .label span {
    display: block;
    margin-top: 5px;
    color: #6f829f;
    font-size: 12px;
    font-weight: 700;
}

@media (max-width: 992px) {
    .campaign-detail-full-dialog {
        width: 98vw;
        max-width: 98vw;
        height: 96vh;
        margin: 2vh auto;
    }

    .campaign-detail-full-content {
        height: 96vh;
    }

    .campaign-detail-full-body {
        height: calc(96vh - 86px);
    }

    .campaign-detail-top-card {
        flex-direction: column;
    }

    .campaign-timeline-wrap {
        overflow-x: auto;
        overflow-y: hidden;
        height: 210px;
        min-width: 900px;
    }
}

/* =========================================================
   TIMELINE V2 / ROAD TIMELINE FINAL
========================================================= */

.timeline-v2-wrapper {
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 12px 0 18px;
}

.timeline-v2-wrapper::-webkit-scrollbar {
    height: 10px;
}

.timeline-v2-wrapper::-webkit-scrollbar-track {
    background: rgba(220,230,245,.75);
    border-radius: 999px;
}

.timeline-v2-wrapper::-webkit-scrollbar-thumb {
    background: rgba(75,95,125,.55);
    border-radius: 999px;
}

.timeline-v2 {
    position: relative;
    min-width: 1500px;
    height: 760px !important;
    padding: 0;
}

/* =========================
   CARRETERA
========================= */

.timeline-v2-road {
    position: absolute;
    left: 70px;
    right: 70px;
    top: 245px;
    height: 125px;
    z-index: 1;
    pointer-events: none;
}

.timeline-road-svg {
    width: 100%;
    height: 100%;
    overflow: visible;
}

.road-shadow {
    fill: none;
    stroke: rgba(110, 128, 156, 0.14);
    stroke-width: 36;
    stroke-linecap: round;
}

.road-main {
    fill: none;
    stroke: #737f8f;
    stroke-width: 30;
    stroke-linecap: round;
    filter: drop-shadow(0 8px 16px rgba(60, 80, 110, 0.18));
}

.road-center-line {
    fill: none;
    stroke: rgba(255,255,255,0.92);
    stroke-width: 2.8;
    stroke-dasharray: 10 10;
    stroke-linecap: round;
}

/* =========================
   CAPAS
========================= */

.timeline-v2-events,
.timeline-v2-gaps,
.timeline-v2-specials {
    position: absolute;
    inset: 0;
}

.timeline-v2-events {
    z-index: 4;
}

.timeline-v2-gaps {
    z-index: 5;
}

.timeline-v2-specials {
    z-index: 8;
    pointer-events: none;
}

/* =========================
   HITOS ARRIBA
========================= */

.timeline-v2-event {
    position: absolute;
    top: 16px;
    width: 190px;
    transform: translateX(-50%);
    text-align: center;
}

.timeline-v2-card {
    position: relative;
    z-index: 4;
    min-height: 92px;
    padding: 12px;
    border-radius: 20px;
    background: rgba(255,255,255,.97);
    border: 1px solid rgba(210,223,242,.95);
    box-shadow:
        0 14px 30px rgba(70,95,140,.10),
        inset 0 1px 0 rgba(255,255,255,.92);
}

.timeline-v2-card::after {
    content: "";
    position: absolute;
    left: 50%;
    bottom: -9px;
    width: 16px;
    height: 16px;
    background: rgba(255,255,255,.97);
    border-right: 1px solid rgba(210,223,242,.95);
    border-bottom: 1px solid rgba(210,223,242,.95);
    transform: translateX(-50%) rotate(45deg);
}

.timeline-v2-date {
    font-size: 12px;
    font-weight: 950;
    color: #203861;
    margin-bottom: 8px;
    letter-spacing: .2px;
}

.timeline-v2-tags {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.timeline-v2-tag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 11px;
    line-height: 1.2;
    font-weight: 850;
    white-space: normal;
}

.timeline-v2-tag.tag-blue {
    background: rgba(77,126,255,.13);
    color: #3567dc;
}

.timeline-v2-tag.tag-green {
    background: rgba(31,181,124,.13);
    color: #16905f;
}

.timeline-v2-tag.tag-purple {
    background: rgba(106,115,255,.13);
    color: #5a63e8;
}

.timeline-v2-tag.tag-yellow {
    background: rgba(255,193,7,.18);
    color: #a46f00;
}

.timeline-v2-tag.tag-neutral {
    background: rgba(120,140,170,.12);
    color: #526782;
}

.timeline-v2-stem {
    position: absolute;
    left: 50%;
    top: 124px;
    width: 2px;
    height: 122px;
    background: linear-gradient(180deg, rgba(77,126,255,.28), rgba(77,126,255,.05));
    transform: translateX(-50%);
    z-index: 2;
}

.timeline-v2-dot {
    position: absolute;
    left: 50%;
    top: 236px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #1fb57c;
    border: 4px solid #d9f4e9;
    transform: translateX(-50%);
    box-shadow:
        0 0 0 8px rgba(31,181,124,.12),
        0 8px 18px rgba(31,181,124,.18);
    z-index: 6;
}

.timeline-v2-event.type-inicio .timeline-v2-dot {
    background: #4d7eff;
    border-color: #dfe8ff;
    box-shadow:
        0 0 0 8px rgba(77,126,255,.12),
        0 8px 18px rgba(77,126,255,.20);
}

.timeline-v2-event.type-termino .timeline-v2-dot {
    background: #6a73ff;
    border-color: #e2e5ff;
    box-shadow:
        0 0 0 8px rgba(106,115,255,.12),
        0 8px 18px rgba(106,115,255,.18);
}

/* =========================
   TRAMOS SIN VISITA ABAJO
========================= */

.timeline-v2-gap {
    position: absolute;
    top: 455px !important;
    z-index: 5;
}

.timeline-v2-gap.lane-1 {
    top: 590px !important;
}

.timeline-v2-gap-dot {
    position: absolute;
    left: 50%;
    top: -18px;
    width: 17px;
    height: 17px;
    border-radius: 50%;
    background: #f0a54a;
    border: 3px solid #fff4de;
    transform: translateX(-50%);
    box-shadow:
        0 0 0 6px rgba(240,165,74,.14),
        0 6px 14px rgba(199,125,25,.16);
    z-index: 4;
}

.timeline-v2-gap-stem {
    position: absolute;
    left: 50%;
    top: calc(var(--gap-stem-height) * -1);
    width: 2px;
    height: var(--gap-stem-height);
    transform: translateX(-50%);
    background: linear-gradient(180deg, rgba(240,165,74,.36), rgba(240,165,74,.08));
}

.timeline-v2-gap-bar {
    position: absolute;
    left: 0;
    top: 52px;
    width: 100%;
    height: 9px;
    border-radius: 999px;
    background: linear-gradient(90deg, #ffd166, #f4a261);
    box-shadow:
        inset 0 1px 1px rgba(255,255,255,.35),
        0 8px 16px rgba(194,125,36,.10);
}

.timeline-v2-gap-label {
    position: absolute;
    left: 50%;
    top: 74px;
    transform: translateX(-50%);
    width: var(--label-width);
    max-width: 320px;
    min-width: 170px;
    background: rgba(255,248,233,.98);
    border: 1px solid rgba(244,210,141,.95);
    border-radius: 15px;
    padding: 10px 12px;
    box-shadow:
        0 10px 22px rgba(120,95,20,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.timeline-v2-gap-label strong {
    display: block;
    font-size: 11px;
    font-weight: 950;
    color: #8f6100;
    margin-bottom: 4px;
    line-height: 1.25;
    white-space: normal;
}

.timeline-v2-gap-label span {
    display: block;
    font-size: 11px;
    font-weight: 750;
    color: #a5751e;
    line-height: 1.35;
}

/* =========================
   ÍCONOS ESPECIALES
========================= */

.timeline-special {
    position: absolute;
    transform: translateX(-50%);
    text-align: center;
    width: 150px;
}

/* Inicio: abajo, alineado al inicio real */
.timeline-special-start {
    top: 350px;
}

/* Final / Cumplido: arriba de la carretera */
.timeline-special-progress,
.timeline-special-success {
    top: 145px;
}

.timeline-special-start,
.timeline-special-progress,
.timeline-special-success {
    position: absolute;
}

.timeline-special-icon {
    width: 68px;
    height: 68px;
    margin: 0 auto 8px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;

    background: rgba(255,255,255,.98);
    border: 2px dashed rgba(92,132,230,.45);
    box-shadow:
        0 14px 26px rgba(70,95,140,.12),
        inset 0 1px 0 rgba(255,255,255,.95);

    font-size: 28px;
    color: #3567dc;
}

.timeline-special-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 30px;
    padding: 6px 13px;
    border-radius: 999px;
    background: #eef4ff;
    color: #2e63d6;
    border: 1px solid rgba(92,132,230,.22);
    font-size: 12px;
    font-weight: 900;
    box-shadow: 0 8px 18px rgba(92,132,230,.10);
}

.timeline-special-small-text {
    margin-top: 6px;
    color: #607696;
    font-size: 11px;
    font-weight: 800;
    line-height: 1.25;
}

.timeline-special-start .timeline-special-icon {
    color: #3567dc;
    border-color: rgba(92,132,230,.45);
}

.timeline-special-start .timeline-special-badge {
    background: #eef4ff;
    color: #2e63d6;
}

.timeline-special-progress .timeline-special-icon {
    color: #2e63d6;
    border-color: rgba(92,132,230,.45);
}

.timeline-special-progress .timeline-special-badge {
    background: #eef4ff;
    color: #2e63d6;
    border-color: rgba(92,132,230,.25);
}

.timeline-special-success .timeline-special-icon {
    color: #149659;
    border-color: rgba(20,150,89,.45);
    background: rgba(255,255,255,.98);
}

.timeline-special-success .timeline-special-badge {
    background: #e9f9ef;
    color: #149659;
    border-color: rgba(20,150,89,.24);
}

/* =========================================================
   IMÁGENES TIMELINE - INICIO / META / CAMIONETA
========================================================= */

.timeline-special-icon-img {
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
    overflow: visible !important;
}

.timeline-special-img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    filter: drop-shadow(0 12px 16px rgba(35, 55, 90, .20));
}

/* Imagen de inicio */
.timeline-special-img-inicio {
    width: 78px;
    height: 78px;
}

/* Imagen de meta cumplida */
.timeline-special-img-meta {
    width: 82px;
    height: 82px;
}

/* Camioneta un poco más ancha */
.timeline-special-img-camioneta {
    width: 104px;
    height: 74px;
    transform: translateX(6px);
}

/* Ajuste caja cuando es camioneta */
.timeline-special-progress .timeline-special-icon {
    width: 110px;
    height: 78px;
}

/* Ajuste caja cuando es meta cumplida */
.timeline-special-success .timeline-special-icon {
    width: 86px;
    height: 86px;
}

/* Ajuste caja inicio */
.timeline-special-start .timeline-special-icon {
    width: 86px;
    height: 86px;
}

/* =========================================================
   ANALÍTICA REGIONAL
========================================================= */

.campaign-region-section {
    margin-top: 28px;
}

.region-summary-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 16px;
}

.region-summary-card {
    border: 1px solid rgba(215,228,246,.95);
    border-radius: 22px;
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.08), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.95), rgba(245,249,255,.88));
    padding: 16px 18px;
    min-height: 118px;
    box-shadow:
        0 15px 28px rgba(69,96,148,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
    display: flex;
    gap: 14px;
    align-items: flex-start;
}

.region-summary-icon {
    width: 52px;
    height: 52px;
    min-width: 52px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
    box-shadow: 0 10px 20px rgba(70,95,140,.14);
}

.icon-blue   { background: linear-gradient(135deg, #4d7eff, #6c74ff); }
.icon-orange { background: linear-gradient(135deg, #ffb554, #ff8a3d); }
.icon-green  { background: linear-gradient(135deg, #31c67b, #1ea960); }
.icon-purple { background: linear-gradient(135deg, #8c63ff, #6c4fff); }
.icon-red    { background: linear-gradient(135deg, #ff7c8a, #ff5f69); }

.region-summary-label {
    color: #7b8daa;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 8px;
}

.region-summary-value {
    color: #172848;
    font-size: 28px;
    font-weight: 950;
    line-height: 1.1;
}

.region-summary-helper {
    margin-top: 4px;
    color: #7285a4;
    font-size: 12px;
    font-weight: 700;
}

.region-panel-card {
    border: 1px solid rgba(215,228,246,.95);
    border-radius: 26px;
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.06), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.94), rgba(245,249,255,.88));
    box-shadow:
        0 18px 42px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
    overflow: hidden;
    height: 100%;
}

.region-panel-head {
    padding: 22px 24px 14px;
    border-bottom: 1px solid rgba(225,235,248,.85);
}

.region-panel-title {
    margin: 0;
    color: #172848;
    font-size: 20px;
    font-weight: 950;
}

.region-panel-subtitle {
    margin: 6px 0 0;
    color: #7285a4;
    font-size: 13px;
    font-weight: 700;
}

.region-user-list {
    padding: 18px 20px 20px;
}

.region-block {
    border: 1px solid rgba(224,234,248,.9);
    border-radius: 20px;
    background: rgba(255,255,255,.82);
    padding: 14px 16px;
    margin-bottom: 14px;
}

.region-block:last-child {
    margin-bottom: 0;
}

.region-block-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
}

.region-name {
    color: #172848;
    font-size: 15px;
    font-weight: 900;
}

.region-meta {
    color: #7285a4;
    font-size: 12px;
    font-weight: 700;
}

.region-progress-row,
.user-progress-row {
    display: grid;
    grid-template-columns: 150px 1fr 80px;
    gap: 12px;
    align-items: center;
    margin-bottom: 8px;
}

.user-progress-row {
    grid-template-columns: 150px 1fr 95px;
    padding-left: 16px;
}

.user-progress-row:last-child {
    margin-bottom: 0;
}

.progress-label {
    color: #355277;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.progress-track {
    width: 100%;
    height: 10px;
    border-radius: 999px;
    background: #e8eff8;
    overflow: hidden;
    position: relative;
}

.progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #4d7eff, #31c67b);
}

.progress-fill.danger {
    background: linear-gradient(90deg, #ff8f67, #ff5e6f);
}

.progress-fill.warning {
    background: linear-gradient(90deg, #ffcb57, #ff9f43);
}

.progress-value {
    color: #172848;
    font-size: 12px;
    font-weight: 900;
    text-align: right;
}

.region-sla-wrap {
    padding: 18px 20px 20px;
}

.region-sla-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 10px;
}

.region-sla-table thead th {
    color: #7b8daa;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 0 10px 6px;
    white-space: nowrap;
}

.region-sla-table tbody tr {
    background: rgba(255,255,255,.86);
    box-shadow: 0 8px 18px rgba(70,95,140,.05);
}

.region-sla-table tbody td {
    padding: 12px 10px;
    color: #264164;
    font-size: 12px;
    font-weight: 800;
    border-top: 1px solid rgba(224,234,248,.9);
    border-bottom: 1px solid rgba(224,234,248,.9);
}

.region-sla-table tbody td:first-child {
    border-left: 1px solid rgba(224,234,248,.9);
    border-top-left-radius: 14px;
    border-bottom-left-radius: 14px;
}

.region-sla-table tbody td:last-child {
    border-right: 1px solid rgba(224,234,248,.9);
    border-top-right-radius: 14px;
    border-bottom-right-radius: 14px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 92px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .35px;
}

.status-success {
    background: rgba(49,198,123,.15);
    color: #14854d;
}

.status-primary {
    background: rgba(77,126,255,.14);
    color: #355bdb;
}

.status-warning {
    background: rgba(255,193,75,.18);
    color: #a36d00;
}

.status-danger {
    background: rgba(255,95,105,.15);
    color: #d33d4c;
}

.status-neutral {
    background: rgba(205,215,230,.32);
    color: #65758d;
}

@media (max-width: 1400px) {
    .region-summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 992px) {
    .region-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .region-summary-grid {
        grid-template-columns: 1fr;
    }

    .region-progress-row,
    .user-progress-row {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .user-progress-row {
        padding-left: 0;
    }

    .region-sla-wrap {
        overflow-x: auto;
    }

    .region-sla-table {
        min-width: 720px;
    }
}
</style>

</head>

<body>

<div class="container-fluid modern-shell py-4">

  <!-- HEADER -->
  <div class="modern-page-header mb-4">
    <div class="modern-page-header-left">
      <div class="modern-page-icon">
        <i class="fas fa-bullhorn"></i>
      </div>
      <div>
        <h1 class="modern-page-title">Panel de Control - Campañas</h1>
        <p class="modern-page-subtitle">
          Bienvenido, <?= htmlspecialchars($nombreU . ' ' . $apellido); ?>.
          Revisa desempeño, visitas y ejecución de campañas activas.
        </p>
      </div>
    </div>
  </div>

  <!-- FILTROS -->
  <div class="modern-card mb-4">
    <div class="modern-card-body">
      <div class="section-title-row">
        <div>
          <h3 class="section-title">Filtros de análisis</h3>
          <p class="section-subtitle">Selecciona división y subdivisión para actualizar la vista.</p>
        </div>
      </div>

      <form method="GET" class="row align-items-end">
        <div class="col-lg-2 col-md-4 mb-3">
          <label class="modern-label"><strong>División</strong></label>
          <?php if (!$esUsuarioMC): ?>
            <input type="hidden" name="division" value="<?= (int)$division_filtro ?>">
          <?php endif; ?>
          <select name="<?= $esUsuarioMC ? 'division' : 'division_locked' ?>" class="form-control modern-control" <?= $esUsuarioMC ? '' : 'disabled' ?>>
            <?php foreach ($divisiones as $d): ?>
              <option value="<?= $d['id'] ?>" <?= ($d['id'] == $division_filtro ? 'selected' : '') ?>>
                <?= htmlspecialchars($d['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-lg-2 col-md-4 mb-3">
          <label class="modern-label"><strong>Subdivisión</strong></label>
          <select name="subdivision" id="subdivision" class="form-control modern-control">
            <option value="0">Todas</option>
          </select>
        </div>

        <div class="col-lg-2 col-md-4 mb-3">
          <label class="modern-label"><strong>Trade</strong></label>
          <select name="trade" id="trade" class="form-control modern-control">
            <option value="0">Todos</option>
            <option value="-1" <?= ($trade_filtro === -1 ? 'selected' : '') ?>>SIN TRADE</option>
            <?php foreach ($trades as $trade): ?>
              <option value="<?= (int)$trade['id_trade'] ?>" <?= ((int)$trade['id_trade'] === $trade_filtro ? 'selected' : '') ?>>
                <?= htmlspecialchars($trade['nombre_trade'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-lg-2 col-md-4 mb-3">
          <label class="modern-label"><strong>Categoria</strong></label>
          <select name="categoria" id="categoria" class="form-control modern-control">
            <option value="0">Todas</option>
            <option value="-1" <?= ($categoria_filtro === -1 ? 'selected' : '') ?>>SIN CATEGORIA</option>
            <?php foreach ($categorias as $categoria): ?>
              <option value="<?= (int)$categoria['id_categoria_formulario'] ?>" <?= ((int)$categoria['id_categoria_formulario'] === $categoria_filtro ? 'selected' : '') ?>>
                <?= htmlspecialchars($categoria['nombre_categoria'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-lg-2 col-md-4 mb-3">
          <button type="submit" class="btn modern-btn modern-btn-primary w-100">
            <i class="fas fa-filter mr-2"></i> Filtrar
          </button>
        </div>

        <div class="col-lg-2 col-md-4 mb-3">
          <a
            href="<?= htmlspecialchars($urlDescargaMasivaActivas, ENT_QUOTES, 'UTF-8') ?>"
            class="btn modern-btn modern-btn-success w-100 <?= empty($idsCampanasActivas) ? 'disabled' : '' ?>"
            <?= empty($idsCampanasActivas) ? 'aria-disabled="true" tabindex="-1"' : '' ?>
          >
            <i class="fas fa-file-excel mr-2"></i> Descargar gantt
          </a>
        </div>
      </form>
    </div>
  </div>

  <!-- KPI -->
  <div class="row mb-4">
    <div class="col-md-3 mb-3">
      <div class="modern-kpi-card">
        <div class="modern-kpi-icon blue">
          <i class="fas fa-bullhorn"></i>
        </div>
        <div>
          <div class="modern-kpi-label">Campañas activas</div>
          <div class="modern-kpi-value"><?= $totalCampanas ?></div>
        </div>
      </div>
    </div>

    <div class="col-md-3 mb-3">
      <div class="modern-kpi-card">
        <div class="modern-kpi-icon cyan">
          <i class="fas fa-store"></i>
        </div>
        <div>
          <div class="modern-kpi-label">Locales asignados</div>
          <div class="modern-kpi-value"><?= number_format($totalLocalesAsignados, 0, ',', '.') ?></div>
        </div>
      </div>
    </div>

    <div class="col-md-3 mb-3">
      <div class="modern-kpi-card">
        <div class="modern-kpi-icon green">
          <i class="fas fa-route"></i>
        </div>
        <div>
          <div class="modern-kpi-label">% visita total</div>
          <div class="modern-kpi-value"><?= $ratioVisitadosTotal ?>%</div>
        </div>
      </div>
    </div>

    <div class="col-md-3 mb-3">
      <div class="modern-kpi-card">
        <div class="modern-kpi-icon purple">
          <i class="fas fa-check-double"></i>
        </div>
        <div>
          <div class="modern-kpi-label">% ejecución total</div>
          <div class="modern-kpi-value"><?= $ratioGestionadosTotal ?>%</div>
        </div>
      </div>
    </div>
  </div>

<!-- TABLA -->
<div class="modern-card">
  <div class="modern-card-body">

    <div class="section-title-row mb-3">
      <div>
        <h3 class="section-title">Campañas activas</h3>
        <p class="section-subtitle">Resumen ejecutivo por campaña, con avance de visita y ejecución.</p>
      </div>
    </div>

    <?php if (!empty($campanas)): ?>
      <div class="table-responsive">
        <table id="tablaCampanas" class="table modern-table compact-campaign-table mb-0">
          <thead>
            <tr>
              <th>Campaña</th>
              <th class="text-center">Inicio</th>
              <th class="text-center">Asignados</th>
              <th class="text-center">Visitados</th>
              <th class="text-center">Gestionados</th>
              <th class="text-center">% Visita</th>
              <th class="text-center">% Ejecución</th>
              <th class="text-center">Detalle</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($campanas as $c): ?>
              <?php
                $asignados   = (int)$c['locales_asignados'];
                $visitados   = (int)$c['locales_visitados'];
                $gestionados = (int)$c['locales_gestionados'];

                $ratioV = $asignados > 0 ? round(($visitados / $asignados) * 100, 1) : 0;
                $ratioG = $asignados > 0 ? round(($gestionados / $asignados) * 100, 1) : 0;
                
                $fechaInicioRaw = $c['fechaInicio'] ?? null;
                
                $fechaInicioMostrar = !empty($fechaInicioRaw)
                    ? date('d-m-Y', strtotime($fechaInicioRaw))
                    : '-';
                
                $fechaInicioOrden = !empty($fechaInicioRaw)
                    ? date('Ymd', strtotime($fechaInicioRaw))
                    : 0;                

                if ($ratioG >= 80) {
                    $estadoTexto = 'Alto';
                    $estadoClass = 'success';
                } elseif ($ratioG >= 50) {
                    $estadoTexto = 'Medio';
                    $estadoClass = 'warning';
                } else {
                    $estadoTexto = 'Bajo';
                    $estadoClass = 'danger';
                }
              ?>

              <tr>
                <td class="campaign-cell" title="<?= htmlspecialchars($c['nombre_campana'], ENT_QUOTES, 'UTF-8') ?>">
                  <div class="campaign-name-cell">
                    <div class="campaign-title">
                      <?= htmlspecialchars($c['nombre_campana']) ?>
                    </div>
                  </div>
                </td>
                
                <td class="text-center campaign-date-cell" data-order="<?= $fechaInicioOrden ?>">
                  <?= $fechaInicioMostrar ?>
                </td>                

                <td class="text-center" data-order="<?= $asignados ?>">
                  <strong><?= number_format($asignados, 0, ',', '.') ?></strong>
                </td>

                <td class="text-center" data-order="<?= $visitados ?>">
                  <strong><?= number_format($visitados, 0, ',', '.') ?></strong>
                </td>

                <td class="text-center" data-order="<?= $gestionados ?>">
                  <strong><?= number_format($gestionados, 0, ',', '.') ?></strong>
                </td>

                <td data-order="<?= $ratioV ?>">
                  <div class="mini-progress-wrap">
                    <div class="mini-progress-label">
                      <span><?= number_format($ratioV, 1, ',', '.') ?>%</span>
                    </div>
                    <div class="mini-progress">
                      <div class="mini-progress-bar blue" style="width: <?= min(100, $ratioV) ?>%;"></div>
                    </div>
                  </div>
                </td>

                <td data-order="<?= $ratioG ?>">
                  <div class="mini-progress-wrap">
                    <div class="mini-progress-label">
                      <span><?= number_format($ratioG, 1, ',', '.') ?>%</span>
                    </div>
                    <div class="mini-progress">
                      <div class="mini-progress-bar green" style="width: <?= min(100, $ratioG) ?>%;"></div>
                    </div>
                  </div>
                </td>

                <td class="text-center">
                  <button 
                    type="button"
                    class="btn btn-sm btn-campaign-detail"
                    data-id="<?= (int)$c['id'] ?>"
                    data-campana="<?= htmlspecialchars($c['nombre_campana'], ENT_QUOTES, 'UTF-8') ?>"
                    data-toggle="modal"
                    data-target="#modalDetalleCampanaFull">
                    <i class="fas fa-eye"></i>
                    Ver detalle
                  </button>
                </td>
              </tr>

            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="modern-empty-state">
        <i class="fas fa-info-circle"></i>
        <div>
          <strong>No se encontraron campañas activas</strong>
          <span>Prueba cambiando la división o subdivisión seleccionada.</span>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

</div>

<!-- MODAL FULL DETALLE CAMPAÑA -->
<div class="modal fade" id="modalDetalleCampanaFull" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered campaign-detail-full-dialog" role="document">
    <div class="modal-content campaign-detail-full-content">

      <div class="modal-header campaign-detail-full-header">
        <div class="campaign-detail-header-left">
          <div class="campaign-detail-big-icon">
            <i class="fas fa-bullhorn"></i>
          </div>
          <div>
            <h4 class="modal-title mb-1">Detalle analítico de campaña</h4>
            <p class="mb-0" id="modalDetalleSubtitulo">Cargando información...</p>
          </div>
        </div>

        <button type="button" class="close campaign-detail-full-close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body campaign-detail-full-body">

        <div id="detalleCampanaLoading" class="campaign-detail-loading">
          <div class="spinner-border text-primary mr-2" role="status"></div>
          <span>Cargando detalle de campaña...</span>
        </div>

        <div id="detalleCampanaContenido" style="display:none;">

          <!-- CABECERA -->
          <div class="campaign-detail-top-card mb-4">
            <div>
              <div class="campaign-detail-label">Campaña</div>
              <h3 id="detalleNombreCampana" class="mb-2">-</h3>
              <p class="campaign-detail-top-text mb-0">
                Vista detallada del flujo de ejecución desde el inicio hasta la última gestión registrada.
              </p>
            </div>

            <div>
              <span id="detalleEstadoCampana" class="modern-badge badge-success">-</span>
            </div>
          </div>


          <!-- KPI OPERATIVOS -->
          <div class="row">
            <div class="col-md-2 mb-3">
              <div class="campaign-detail-mini-kpi">
                <span>Asignados</span>
                <strong id="detalleAsignados">0</strong>
              </div>
            </div>

            <div class="col-md-2 mb-3">
              <div class="campaign-detail-mini-kpi">
                <span>Visitados</span>
                <strong id="detalleVisitados">0</strong>
              </div>
            </div>

            <div class="col-md-2 mb-3">
              <div class="campaign-detail-mini-kpi">
                <span>Gestionados</span>
                <strong id="detalleGestionados">0</strong>
              </div>
            </div>

            <div class="col-md-3 mb-3">
              <div class="campaign-detail-mini-kpi">
                <span>% visita</span>
                <strong id="detalleRatioVisita">0%</strong>
              </div>
            </div>

            <div class="col-md-3 mb-3">
              <div class="campaign-detail-mini-kpi">
                <span>% ejecución</span>
                <strong id="detalleRatioEjecucion">0%</strong>
              </div>
            </div>
          </div>

          <!-- KPI TIEMPOS -->
          <div class="row">
            <div class="col-md-3 mb-3">
              <div class="campaign-detail-kpi-card">
                <span>Fecha inicio</span>
                <strong id="detalleFechaInicio">-</strong>
                <small>Punto de partida de la campaña</small>
              </div>
            </div>

            <div class="col-md-3 mb-3">
              <div class="campaign-detail-kpi-card">
                <span>Primera visita</span>
                <strong id="detallePrimeraVisita">-</strong>
                <small>Primera gestión registrada</small>
              </div>
            </div>

            <div class="col-md-3 mb-3">
              <div class="campaign-detail-kpi-card">
                <span>Última visita</span>
                <strong id="detalleUltimaVisita">-</strong>
                <small>Última gestión registrada</small>
              </div>
            </div>

            <div class="col-md-3 mb-3">
              <div class="campaign-detail-kpi-card">
                <span>Término planificado</span>
                <strong id="detalleFechaTermino">-</strong>
                <small>Fecha de cierre esperada</small>
              </div>
            </div>
          </div>

            <!-- KPI DURACIONES -->
            <div class="row">
              <div class="col-md-3 mb-3">
                <div class="campaign-detail-kpi-card alt">
                  <span>Días hasta primera visita</span>
                  <strong id="detalleDiasPrimera">-</strong>
                  <small>Días hábiles desde inicio a primera gestión</small>
                </div>
              </div>
            
              <div class="col-md-3 mb-3">
                <div class="campaign-detail-kpi-card alt">
                  <span>Días planificados</span>
                  <strong id="detalleDiasPlanificados">-</strong>
                  <small>Días hábiles entre inicio y término planificado</small>
                </div>
              </div>
            
              <div class="col-md-3 mb-3">
                <div class="campaign-detail-kpi-card alt">
                  <span>Días entre visitas</span>
                  <strong id="detalleDiasEntre">-</strong>
                  <small>Días hábiles entre primera y última visita</small>
                </div>
              </div>
            
              <div class="col-md-3 mb-3">
                <div class="campaign-detail-kpi-card alt">
                  <span>Días hasta última visita</span>
                  <strong id="detalleDiasUltima">-</strong>
                  <small>Días hábiles desde inicio a última gestión</small>
                </div>
              </div>
            </div>

          <!-- LÍNEA DE TIEMPO -->
            <div class="campaign-timeline-card mt-2">
              <div class="section-title-row mb-3">
                <div>
                  <h4 class="section-title mb-1">Línea de tiempo del flujo</h4>
                  <p class="section-subtitle mb-0">
                    Arriba se muestran las fechas con visitas efectivas y sus cantidades. 
                    Abajo se muestran los tramos hábiles sin visitas.
                  </p>
                </div>
              </div>
            
              <div class="timeline-v2-wrapper">
                <div id="timelineV2" class="timeline-v2">
                
                    <div class="timeline-v2-road">
                        <svg viewBox="0 0 1600 180" preserveAspectRatio="none" class="timeline-road-svg">
                            <path
                                class="road-shadow"
                                d="M40,90 
                                   C120,90 140,35 220,35
                                   S320,145 420,145
                                   S520,35 620,35
                                   S720,145 820,145
                                   S920,35 1020,35
                                   S1120,145 1220,145
                                   S1320,35 1420,35
                                   S1500,90 1560,90" />
                
                            <path
                                id="timelineRoadPath"
                                class="road-main"
                                d="M40,90 
                                   C120,90 140,35 220,35
                                   S320,145 420,145
                                   S520,35 620,35
                                   S720,145 820,145
                                   S920,35 1020,35
                                   S1120,145 1220,145
                                   S1320,35 1420,35
                                   S1500,90 1560,90" />
                
                            <path
                                class="road-center-line"
                                d="M40,90 
                                   C120,90 140,35 220,35
                                   S320,145 420,145
                                   S520,35 620,35
                                   S720,145 820,145
                                   S920,35 1020,35
                                   S1120,145 1220,145
                                   S1320,35 1420,35
                                   S1500,90 1560,90" />
                        </svg>
                    </div>
                
                    <div id="timelineV2Events" class="timeline-v2-events"></div>
                    <div id="timelineV2Gaps" class="timeline-v2-gaps"></div>
                    <div id="timelineV2Specials" class="timeline-v2-specials"></div>
                </div>
              </div>
            </div>
            
            <!-- =======================
                 ANALÍTICA REGIONAL
            ======================= -->
            <div class="campaign-region-section mt-4">
                <div id="regionSummaryCards" class="region-summary-grid"></div>
            
                <div class="row mt-4">
                    <div class="col-lg-6 mb-4">
                        <div class="region-panel-card">
                            <div class="region-panel-head">
                                <div>
                                    <h4 class="region-panel-title">Avance por región y usuario</h4>
                                    <p class="region-panel-subtitle">
                                        Detecta qué regiones avanzan más lento y qué usuario concentra el rezago.
                                    </p>
                                </div>
                            </div>
            
                            <div id="regionUserProgressList" class="region-user-list">
                                <div class="modern-empty-state">
                                    <i class="fas fa-layer-group"></i>
                                    <div>
                                        <strong>Sin datos regionales</strong>
                                        <span>Aún no hay información para mostrar.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
            
                    <div class="col-lg-6 mb-4">
                        <div class="region-panel-card">
                            <div class="region-panel-head">
                                <div>
                                    <h4 class="region-panel-title">SLA por región</h4>
                                    <p class="region-panel-subtitle">
                                        Observa primera visita, última visita y estado operativo de cada región.
                                    </p>
                                </div>
                            </div>
            
                            <div id="regionSlaTableWrap" class="region-sla-wrap">
                                <div class="modern-empty-state">
                                    <i class="fas fa-clock"></i>
                                    <div>
                                        <strong>Sin tabla SLA</strong>
                                        <span>No hay registros regionales todavía.</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {

const TIMELINE_ICONS = {
    inicio: '/visibility2/portal/images/icon/inicio_mc.png',
    metaCumplida: '/visibility2/portal/images/icon/meta_cumplida_mc.png',
    camioneta: '/visibility2/portal/images/icon/camioneta_movimiento_derecha.png'
};

function getRoadPointByTimelineX(timelineX, timelineWidth) {
    const svg = document.querySelector('.timeline-road-svg');
    const path = document.getElementById('timelineRoadPath');

    if (!svg || !path) {
        return { x: timelineX, y: 285 };
    }

    const roadLeft = 70;
    const roadTop = 245;
    const roadWidth = timelineWidth - 140;
    const roadHeight = 125;

    const viewBox = svg.viewBox.baseVal;
    const localX = ((timelineX - roadLeft) / roadWidth) * viewBox.width;

    const totalLength = path.getTotalLength();

    let start = 0;
    let end = totalLength;
    let point = path.getPointAtLength(0);

    for (let i = 0; i < 35; i++) {
        const mid = (start + end) / 2;
        point = path.getPointAtLength(mid);

        if (point.x < localX) {
            start = mid;
        } else {
            end = mid;
        }
    }

    const finalPoint = path.getPointAtLength((start + end) / 2);

    const yInTimeline = roadTop + ((finalPoint.y / viewBox.height) * roadHeight);

    return {
        x: timelineX,
        y: yInTimeline
    };
}

function renderTimelineV2(timeline, opts = {}) {
    
    const $timeline = $('#timelineV2');
    const $events = $('#timelineV2Events');
    const $gaps = $('#timelineV2Gaps');
    const $specials = $('#timelineV2Specials');

    $events.empty();
    $gaps.empty();
    $specials.empty();

    if (!timeline || !Array.isArray(timeline.eventos) || !timeline.eventos.length) {
        $timeline.css('width', '1500px');
        return;
    }

    const parseIso = (iso) => {
        if (!iso) return 0;
        return new Date(iso + 'T00:00:00').getTime();
    };

    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

    const eventos = [...timeline.eventos].sort((a, b) => {
        const fa = parseIso(a.fecha_iso);
        const fb = parseIso(b.fecha_iso);
        if (fa !== fb) return fa - fb;
        return Number(a.pos || 0) - Number(b.pos || 0);
    });

    const gaps = Array.isArray(timeline.sin_visitas)
        ? [...timeline.sin_visitas].sort((a, b) => parseIso(a.desde_iso) - parseIso(b.desde_iso))
        : [];

    const padding = 120;
    const minEventGap = 215;
    const baseWidth = Math.max(
        1500,
        padding * 2 + Math.max(0, eventos.length - 1) * minEventGap
    );

    let usableWidth = baseWidth - (padding * 2);

    function posToX(pos) {
        const cleanPos = clamp(Number(pos || 0), 0, 100);
        return padding + ((cleanPos / 100) * usableWidth);
    }

    let lastX = padding - minEventGap;

    const eventosAjustados = eventos.map((ev, index) => {
        const rawX = posToX(ev.pos);
        const minXForIndex = padding + (index * minEventGap);
        const x = Math.max(rawX, minXForIndex, lastX + minEventGap);

        lastX = x;

        return {
            ...ev,
            _x: x
        };
    });

    const finalWidth = Math.max(baseWidth, lastX + padding + 120);
    usableWidth = finalWidth - (padding * 2);
    $timeline.css('width', finalWidth + 'px');

    // =========================
    // EVENTOS ARRIBA
    // =========================
eventosAjustados.forEach((ev) => {
    let itemsHtml = '';
    let mainType = 'visita';

    (ev.items || []).forEach((it) => {
        let cls = 'tag-neutral';

        if (it.tipo === 'inicio') cls = 'tag-blue';
        if (it.tipo === 'termino') cls = 'tag-purple';
        if (it.tipo === 'visita') cls = 'tag-green';
        if (it.tipo === 'meta') cls = 'tag-yellow';

        if (it.tipo === 'inicio') mainType = 'inicio';
        if (it.tipo === 'termino') mainType = 'termino';
        if (it.tipo === 'visita') mainType = 'visita';

        itemsHtml += `
            <div class="timeline-v2-tag ${cls}">
                ${escapeHtml(it.label)}
            </div>
        `;
    });

    const roadPoint = getRoadPointByTimelineX(ev._x, finalWidth);
    const dotTop = roadPoint.y - 12;
    const stemTop = 124;
    const stemHeight = Math.max(40, dotTop - stemTop);

    const eventHtml = `
        <div class="timeline-v2-event type-${mainType}" style="left:${ev._x}px;">
            <div class="timeline-v2-card">
                <div class="timeline-v2-date">${escapeHtml(ev.fecha || '-')}</div>
                <div class="timeline-v2-tags">
                    ${itemsHtml}
                </div>
            </div>
            <div class="timeline-v2-stem" style="height:${stemHeight}px;"></div>
            <div class="timeline-v2-dot" style="top:${dotTop}px;"></div>
        </div>
    `;

    $events.append(eventHtml);
});

    // =========================
    // TRAMOS SIN VISITA ABAJO
    // =========================
let laneEnd = [-999999, -999999];

gaps.forEach((gap) => {
    let left = posToX(gap.left);
    let right = posToX(gap.right);

    if (right < left) {
        const tmp = left;
        left = right;
        right = tmp;
    }

    const width = Math.max(42, right - left);
    const labelWidth = clamp(width + 90, 170, 320);

    let lane = 0;
    if (left < laneEnd[0] + 30 && left >= laneEnd[1] + 30) {
        lane = 1;
    } else if (left < laneEnd[0] + 30 && left < laneEnd[1] + 30) {
        lane = laneEnd[0] <= laneEnd[1] ? 0 : 1;
    }

    laneEnd[lane] = left + labelWidth;

    const textoFecha = gap.desde === gap.hasta
        ? gap.desde
        : `${gap.desde} al ${gap.hasta}`;

    const centerX = left + (width / 2);
    const roadPoint = getRoadPointByTimelineX(centerX, finalWidth);

    const offsetFromRoad = lane === 0 ? 95 : 220;
    const gapTop = roadPoint.y + offsetFromRoad;
    const stemHeight = offsetFromRoad - 10;

    const gapHtml = `
        <div 
            class="timeline-v2-gap lane-${lane}" 
            style="
                left:${left}px;
                top:${gapTop}px;
                width:${width}px;
                --label-width:${labelWidth}px;
                --gap-stem-height:${stemHeight}px;
            "
        >
            <div class="timeline-v2-gap-dot"></div>
            <div class="timeline-v2-gap-stem"></div>
            <div class="timeline-v2-gap-bar"></div>
            <div class="timeline-v2-gap-label">
                <strong>${escapeHtml(textoFecha)}</strong>
                <span>${Number(gap.dias || 0)} día(s) hábil(es) sin visitas</span>
            </div>
        </div>
    `;

    $gaps.append(gapHtml);
});

// =========================
// ÍCONO ESPECIAL DE INICIO / FINAL
// =========================

function eventoTieneTipo(ev, tipo) {
    return Array.isArray(ev.items) && ev.items.some(function(item) {
        return item.tipo === tipo;
    });
}

const startEvent = eventosAjustados.find(function(ev) {
    return eventoTieneTipo(ev, 'inicio');
}) || eventosAjustados[0];

if (startEvent) {
    const startRoadPoint = getRoadPointByTimelineX(startEvent._x, finalWidth);
    const startTop = startRoadPoint.y - 95;

    const startHtml = `
        <div class="timeline-special timeline-special-start" style="left:${startEvent._x}px; top:${startTop}px;">
            <div class="timeline-special-icon timeline-special-icon-img">
                <img 
                    src="${TIMELINE_ICONS.inicio}" 
                    alt="Inicio campaña"
                    class="timeline-special-img timeline-special-img-inicio"
                >
            </div>
            <div class="timeline-special-badge">Inicio</div>
            <div class="timeline-special-small-text">Punto de partida</div>
        </div>
    `;

    $specials.append(startHtml);
}

const terminoEvent = eventosAjustados.find(function(ev) {
    return eventoTieneTipo(ev, 'termino');
});

const ultimaVisitaEvent = eventosAjustados.find(function(ev) {
    return Array.isArray(ev.items) && ev.items.some(function(item) {
        return item.tipo === 'meta' && item.label === 'Última visita';
    });
});

const lastEvent = eventosAjustados[eventosAjustados.length - 1];

const visitaPct = Number(opts.visitaPct || 0);
const cumplido = visitaPct >= 100;

/*
| Si está cumplido, el hito final debe ir en la última visita real.
| Si no está cumplido, se deja en el último evento visible.
*/
const endEvent = cumplido
    ? (ultimaVisitaEvent || lastEvent || terminoEvent)
    : (lastEvent || terminoEvent);

if (endEvent) {
    const endRoadPoint = getRoadPointByTimelineX(endEvent._x, finalWidth);
    const endTop = endRoadPoint.y - 150;

    const endClass = cumplido ? 'timeline-special-success' : 'timeline-special-progress';
    const endBadge = cumplido ? '¡Cumplido!' : 'En curso';
    const endText = cumplido
        ? '100% de visitas completadas'
        : 'Campaña aún no completa';

    const endIconSrc = cumplido
        ? TIMELINE_ICONS.metaCumplida
        : TIMELINE_ICONS.camioneta;

    const endAlt = cumplido
        ? 'Meta cumplida'
        : 'Camioneta en movimiento';

    const endImgClass = cumplido
        ? 'timeline-special-img-meta'
        : 'timeline-special-img-camioneta';

    const endHtml = `
        <div class="timeline-special ${endClass}" style="left:${endEvent._x}px; top:${endTop}px;">
            <div class="timeline-special-badge">${endBadge}</div>
            <div class="timeline-special-icon timeline-special-icon-img">
                <img 
                    src="${endIconSrc}" 
                    alt="${endAlt}"
                    class="timeline-special-img ${endImgClass}"
                >
            </div>
            <div class="timeline-special-small-text">${endText}</div>
        </div>
    `;

    $specials.append(endHtml);
}

}

function limpiarRegionAnalytics() {
    $('#regionSummaryCards').html('');

    $('#regionUserProgressList').html(`
        <div class="modern-empty-state">
            <i class="fas fa-layer-group"></i>
            <div>
                <strong>Sin datos regionales</strong>
                <span>Aún no hay información para mostrar.</span>
            </div>
        </div>
    `);

    $('#regionSlaTableWrap').html(`
        <div class="modern-empty-state">
            <i class="fas fa-clock"></i>
            <div>
                <strong>Sin tabla SLA</strong>
                <span>No hay registros regionales todavía.</span>
            </div>
        </div>
    `);
}

function renderRegionAnalytics(data) {
    if (!data) {
        limpiarRegionAnalytics();
        return;
    }

    $('#regionSummaryCards').html(buildRegionSummaryCards(data));
    $('#regionUserProgressList').html(buildRegionUserList(data));
    $('#regionSlaTableWrap').html(buildRegionSlaTable(data));
}

function formatearDiasHabiles(valor) {
    if (valor === null || valor === undefined || valor === '') {
        return '-';
    }

    const dias = Number(valor);

    if (Number.isNaN(dias)) {
        return '-';
    }

    if (dias < 0) {
        return Math.abs(dias) + ' días antes';
    }

    if (dias === 0) {
        return '0 días';
    }

    return dias + ' días hábiles';
}

    
$(document).on('click', '.btn-campaign-detail', function() {
    const id = $(this).data('id') || 0;
    const nombre = $(this).data('campana') || 'Detalle de campaña';

    $('#modalDetalleSubtitulo').text(nombre);
    $('#detalleCampanaLoading').show();
    $('#detalleCampanaContenido').hide();

    limpiarDetalleCampana();

    $.ajax({
        url: 'ajax_detalle_campana.php',
        type: 'GET',
        dataType: 'json',
        data: { id: id },
        success: function(resp) {
            if (!resp.ok || !resp.data) {
                $('#detalleCampanaLoading').html(
                    '<div class="text-danger font-weight-bold">No fue posible cargar el detalle de la campaña.</div>'
                );
                return;
            }

            const d = resp.data;

            $('#detalleNombreCampana').text(d.nombre_campana || '-');
            $('#modalDetalleSubtitulo').text(d.nombre_campana || '-');

            $('#detalleEstadoCampana')
                .removeClass('badge-success badge-warning badge-danger')
                .addClass('badge-' + (d.estado_class || 'success'))
                .text(d.estado_texto || '-');

            $('#detalleFechaInicio').text(d.fecha_inicio || '-');
            $('#detallePrimeraVisita').text(d.primera_visita || '-');
            $('#detalleUltimaVisita').text(d.ultima_visita || '-');
            $('#detalleFechaTermino').text(d.fecha_termino || '-');

            $('#detalleDiasPrimera').text(formatearDiasHabiles(d.dias_hasta_primera));
            $('#detalleDiasPlanificados').text(formatearDiasHabiles(d.dias_planificados));
            $('#detalleDiasEntre').text(formatearDiasHabiles(d.dias_entre_visitas));
            $('#detalleDiasUltima').text(formatearDiasHabiles(d.dias_hasta_ultima));

            $('#detalleAsignados').text(Number(d.locales_asignados || 0).toLocaleString('es-CL'));
            $('#detalleVisitados').text(Number(d.locales_visitados || 0).toLocaleString('es-CL'));
            $('#detalleGestionados').text(Number(d.locales_gestionados || 0).toLocaleString('es-CL'));

            $('#detalleRatioVisita').text((d.ratio_visita ?? 0).toLocaleString('es-CL') + '%');
            $('#detalleRatioEjecucion').text((d.ratio_ejecucion ?? 0).toLocaleString('es-CL') + '%');

            $('#detalleCampanaLoading').hide();
            $('#detalleCampanaContenido').fadeIn(180);
            
            renderTimelineV2(d.timeline_v2 || null, {
                visitaPct: Number(d.ratio_visita ?? d.pct_visita_total ?? d.porcentaje_visita ?? 0)
            });
            
            renderRegionAnalytics(d.region_analytics || null);
        },
        error: function(xhr) {
            $('#detalleCampanaLoading').html(
                '<div class="text-danger font-weight-bold">Error al consultar el detalle de la campaña.</div>'
            );
            console.error(xhr.responseText);
        }
    });
});

    /*
    |--------------------------------------------------------------------------
    | DATATABLES - CONFIGURACIÓN GENERAL
    |--------------------------------------------------------------------------
    */

    if ($.fn.dataTable) {
        $.fn.dataTable.ext.errMode = 'none';
    }

    $('#tablaCampanas').on('error.dt', function(e, settings, techNote, message) {
        console.warn('DataTables warning oculto:', message);
    });

    /*
    |--------------------------------------------------------------------------
    | TABLA CAMPAÑAS
    |--------------------------------------------------------------------------
    | Orden:
    | 0 = Campaña
    | 1 = Fecha inicio
    | 2 = Asignados
    | 3 = Visitados
    | 4 = Gestionados
    | 5 = % Visita
    | 6 = % Ejecución
    | 7 = Estado
    |--------------------------------------------------------------------------
    */

    if ($('#tablaCampanas').length) {

        if ($.fn.DataTable.isDataTable('#tablaCampanas')) {
            $('#tablaCampanas').DataTable().clear().destroy();
        }

        const tablaCampanas = $('#tablaCampanas').DataTable({
            order: [[1, 'desc']],
            pageLength: 15,
            lengthMenu: [
                [10, 15, 25, 50, -1],
                [10, 15, 25, 50, 'Todas']
            ],
            autoWidth: false,
            responsive: false,

            dom:
                '<"dt-modern-toolbar d-flex flex-wrap justify-content-between align-items-center mb-3"' +
                    '<"dt-modern-left"l>' +
                    '<"dt-modern-right"f>' +
                '>' +
                'rt' +
                '<"dt-modern-footer d-flex flex-wrap justify-content-between align-items-center mt-3"' +
                    '<"dt-modern-info"i>' +
                    '<"dt-modern-pages"p>' +
                '>',

            language: {
                decimal: ',',
                thousands: '.',
                emptyTable: 'No hay campañas disponibles',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ campañas',
                infoEmpty: 'Mostrando 0 a 0 de 0 campañas',
                infoFiltered: '(filtrado de _MAX_ campañas en total)',
                lengthMenu: 'Mostrar _MENU_',
                loadingRecords: 'Cargando...',
                processing: 'Procesando...',
                search: '',
                searchPlaceholder: 'Buscar campaña, fecha, estado o avance...',
                zeroRecords: 'No se encontraron resultados',
                paginate: {
                    first: 'Primero',
                    last: 'Último',
                    next: 'Siguiente',
                    previous: 'Anterior'
                },
                aria: {
                    sortAscending: ': activar para ordenar ascendente',
                    sortDescending: ': activar para ordenar descendente'
                }
            },

            columnDefs: [
                { type: 'num', targets: [1, 2, 3, 4, 5, 6] },
                { orderable: false, targets: 7 },
                { className: 'text-center', targets: [1, 2, 3, 4, 7] }
            ],

            initComplete: function() {
                $('#tablaCampanas_filter input')
                    .addClass('modern-dt-search')
                    .attr('placeholder', 'Buscar campaña, fecha, estado o avance...');

                $('#tablaCampanas_length select')
                    .addClass('modern-dt-length');
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | SUBDIVISIONES DINÁMICAS
    |--------------------------------------------------------------------------
    */

    function cargarSubdivisiones(idDivision, selected = 0) {
        if (!idDivision) {
            $('#subdivision').html('<option value="0">Todas</option>');
            return;
        }

        $.ajax({
            url: 'ajax_subdivisiones.php',
            type: 'GET',
            dataType: 'json',
            data: {
                division: idDivision
            },
            success: function(response) {
                let options = '<option value="0">Todas</option>';

                if (Array.isArray(response)) {
                    response.forEach(function(sub) {
                        const selectedAttr = String(sub.id) === String(selected) ? 'selected' : '';
                        options += `
                            <option value="${sub.id}" ${selectedAttr}>
                                ${escapeHtml(sub.nombre)}
                            </option>
                        `;
                    });
                }

                $('#subdivision').html(options);
            },
            error: function(xhr) {
                console.error('Error al cargar subdivisiones:', xhr.responseText);
                $('#subdivision').html('<option value="0">Todas</option>');
            }
        });
    }

    $('[name="division"]').on('change', function() {
        const divisionId = $(this).val();
        cargarSubdivisiones(divisionId, 0);
        $('#trade, #categoria').val('0');
    });

    $('#subdivision').on('change', function() {
        $('#trade, #categoria').val('0');
    });

    const divisionInicial = $('[name="division"]').val();
    const subdivisionInicial = <?= intval($subdivision_filtro) ?>;

    if (divisionInicial) {
        cargarSubdivisiones(divisionInicial, subdivisionInicial);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    function escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }

        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    
function formatNumberCL(value) {
    return Number(value || 0).toLocaleString('es-CL');
}

function buildRegionSummaryCards(data) {
    const resumen = data?.resumen || {};
    const empateGeneral = resumen.empate_general_avance === true;

    let regionLenta = '-';
    let regionTop = '-';

    if (empateGeneral) {
        regionLenta = 'Empate general';
        regionTop = 'Empate general';
    } else {
        regionLenta = resumen.region_mas_lenta
            ? `${escapeHtml(resumen.region_mas_lenta.region_nombre)} - ${resumen.region_mas_lenta.avance}%`
            : '-';

        regionTop = resumen.region_mayor_avance
            ? `${escapeHtml(resumen.region_mayor_avance.region_nombre)} - ${resumen.region_mayor_avance.avance}%`
            : '-';
    }

    return `
        <div class="region-summary-card">
            <div class="region-summary-icon icon-blue"><i class="fas fa-globe-americas"></i></div>
            <div>
                <div class="region-summary-label">Regiones activas</div>
                <div class="region-summary-value">${formatNumberCL(resumen.regiones_activas || 0)}</div>
                <div class="region-summary-helper">Con actividad registrada</div>
            </div>
        </div>

        <div class="region-summary-card">
            <div class="region-summary-icon icon-orange"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="region-summary-label">Región más lenta</div>
                <div class="region-summary-value" style="font-size:20px;">${regionLenta}</div>
                <div class="region-summary-helper">
                    ${empateGeneral ? 'Todas tienen el mismo avance' : 'Menor avance relativo'}
                </div>
            </div>
        </div>

        <div class="region-summary-card">
            <div class="region-summary-icon icon-green"><i class="fas fa-chart-line"></i></div>
            <div>
                <div class="region-summary-label">Mayor avance</div>
                <div class="region-summary-value" style="font-size:20px;">${regionTop}</div>
                <div class="region-summary-helper">
                    ${empateGeneral ? 'No hay diferencia entre regiones' : 'Región más adelantada'}
                </div>
            </div>
        </div>

        <div class="region-summary-card">
            <div class="region-summary-icon icon-purple"><i class="fas fa-stopwatch"></i></div>
            <div>
                <div class="region-summary-label">SLA promedio</div>
                <div class="region-summary-value">${formatNumberCL(resumen.sla_promedio || 0)} días</div>
                <div class="region-summary-helper">Hasta primera visita</div>
            </div>
        </div>

        <div class="region-summary-card">
            <div class="region-summary-icon icon-red"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="region-summary-label">Regiones en riesgo</div>
                <div class="region-summary-value">${formatNumberCL(resumen.regiones_en_riesgo || 0)}</div>
                <div class="region-summary-helper">Con rezago o demora SLA</div>
            </div>
        </div>
    `;
}

function getProgressClass(avance) {
    avance = Number(avance || 0);

    if (avance < 40) return 'danger';
    if (avance < 70) return 'warning';
    return '';
}

function buildRegionUserList(data) {
    const regiones = (data && data.regiones) ? data.regiones : [];
    const usuariosPorRegion = (data && data.usuarios_por_region) ? data.usuarios_por_region : {};

    if (!regiones.length) {
        return `
            <div class="modern-empty-state">
                <i class="fas fa-layer-group"></i>
                <div>
                    <strong>Sin datos regionales</strong>
                    <span>No hay actividad por región para esta campaña.</span>
                </div>
            </div>
        `;
    }

    let html = '';

    regiones.forEach(function(region) {
        const usuarios = usuariosPorRegion[String(region.id_region)] || [];
        const fillClass = getProgressClass(region.avance);

        html += `
            <div class="region-block">
                <div class="region-block-top">
                    <div>
                        <div class="region-name">${escapeHtml(region.region_nombre || 'SIN REGIÓN')}</div>
                        <div class="region-meta">
                            ${formatNumberCL(region.visitados)} / ${formatNumberCL(region.asignados)} locales visitados
                        </div>
                    </div>
                    <div class="progress-value">${Number(region.avance || 0).toLocaleString('es-CL')}%</div>
                </div>

                <div class="region-progress-row">
                    <div class="progress-label">Avance región</div>
                    <div class="progress-track">
                        <div class="progress-fill ${fillClass}" style="width:${Number(region.avance || 0)}%;"></div>
                    </div>
                    <div class="progress-value">${Number(region.avance || 0).toLocaleString('es-CL')}%</div>
                </div>
        `;

        if (usuarios.length) {
            usuarios.forEach(function(usuario) {
                const fillClassUser = getProgressClass(usuario.avance);

                html += `
                    <div class="user-progress-row">
                        <div class="progress-label">
                            <i class="fas fa-user mr-1" style="opacity:.6;"></i>
                            ${escapeHtml(usuario.usuario_nombre || 'SIN USUARIO')}
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill ${fillClassUser}" style="width:${Number(usuario.avance || 0)}%;"></div>
                        </div>
                        <div class="progress-value">
                            ${formatNumberCL(usuario.visitados)} / ${formatNumberCL(usuario.asignados)}
                            &nbsp;(${Number(usuario.avance || 0).toLocaleString('es-CL')}%)
                        </div>
                    </div>
                `;
            });
        }

        html += `</div>`;
    });

    return html;
}

function buildRegionSlaTable(data) {
    const regiones = (data && data.regiones) ? data.regiones : [];

    if (!regiones.length) {
        return `
            <div class="modern-empty-state">
                <i class="fas fa-clock"></i>
                <div>
                    <strong>Sin tabla SLA</strong>
                    <span>No hay registros por región para esta campaña.</span>
                </div>
            </div>
        `;
    }

    let html = `
        <div class="table-responsive">
            <table class="region-sla-table">
                <thead>
                    <tr>
                        <th>Región</th>
                        <th>Asignados</th>
                        <th>Visitados</th>
                        <th>Avance</th>
                        <th>Primera visita</th>
                        <th>Última visita</th>
                        <th>Días hasta primera</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
    `;

    regiones.forEach(function(region) {
        html += `
            <tr>
                <td>${escapeHtml(region.region_nombre || 'SIN REGIÓN')}</td>
                <td>${formatNumberCL(region.asignados)}</td>
                <td>${formatNumberCL(region.visitados)}</td>
                <td>${Number(region.avance || 0).toLocaleString('es-CL')}%</td>
                <td>${escapeHtml(region.primera_visita || '-')}</td>
                <td>${escapeHtml(region.ultima_visita || '-')}</td>
                <td>${region.dias_hasta_primera !== null ? region.dias_hasta_primera : '-'}</td>
                <td>
                    <span class="status-pill ${escapeHtml(region.estado_class || 'status-neutral')}">
                        ${escapeHtml(region.estado || '-')}
                    </span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    return html;
}

function formatNumberCL(value) {
    return Number(value || 0).toLocaleString('es-CL');
}

function getProgressClass(avance) {
    avance = Number(avance || 0);

    if (avance < 40) return 'danger';
    if (avance < 70) return 'warning';
    return '';
}

function buildRegionSummaryCards(data) {
    const resumen = data?.resumen || {};

    const regionLenta = resumen.region_mas_lenta
        ? `${escapeHtml(resumen.region_mas_lenta.region_nombre)} - ${resumen.region_mas_lenta.avance}%`
        : '-';

    const regionTop = resumen.region_mayor_avance
        ? `${escapeHtml(resumen.region_mayor_avance.region_nombre)} - ${resumen.region_mayor_avance.avance}%`
        : '-';

    return `
        <div class="region-summary-card">
            <div class="region-summary-icon icon-blue"><i class="fas fa-globe-americas"></i></div>
            <div>
                <div class="region-summary-label">Regiones activas</div>
                <div class="region-summary-value">${formatNumberCL(resumen.regiones_activas || 0)}</div>
                <div class="region-summary-helper">Con actividad registrada</div>
            </div>
        </div>

        <div class="region-summary-card">
            <div class="region-summary-icon icon-orange"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="region-summary-label">Región más lenta</div>
                <div class="region-summary-value" style="font-size:20px;">${regionLenta}</div>
                <div class="region-summary-helper">Menor avance relativo</div>
            </div>
        </div>

        <div class="region-summary-card">
            <div class="region-summary-icon icon-green"><i class="fas fa-chart-line"></i></div>
            <div>
                <div class="region-summary-label">Mayor avance</div>
                <div class="region-summary-value" style="font-size:20px;">${regionTop}</div>
                <div class="region-summary-helper">Región más adelantada</div>
            </div>
        </div>

        <div class="region-summary-card">
            <div class="region-summary-icon icon-purple"><i class="fas fa-stopwatch"></i></div>
            <div>
                <div class="region-summary-label">SLA promedio</div>
                <div class="region-summary-value">${formatNumberCL(resumen.sla_promedio || 0)} días</div>
                <div class="region-summary-helper">Hasta primera visita</div>
            </div>
        </div>

        <div class="region-summary-card">
            <div class="region-summary-icon icon-red"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="region-summary-label">Regiones en riesgo</div>
                <div class="region-summary-value">${formatNumberCL(resumen.regiones_en_riesgo || 0)}</div>
                <div class="region-summary-helper">Con rezago o demora SLA</div>
            </div>
        </div>
    `;
}

function buildRegionUserList(data) {
    const regiones = data?.regiones || [];
    const usuariosPorRegion = data?.usuarios_por_region || {};

    if (!regiones.length) {
        return `
            <div class="modern-empty-state">
                <i class="fas fa-layer-group"></i>
                <div>
                    <strong>Sin datos regionales</strong>
                    <span>No hay actividad por región para esta campaña.</span>
                </div>
            </div>
        `;
    }

    let html = '';

    regiones.forEach(function(region) {
        const usuarios = usuariosPorRegion[String(region.id_region)] || [];
        const fillClass = getProgressClass(region.avance);

        html += `
            <div class="region-block">
                <div class="region-block-top">
                    <div>
                        <div class="region-name">${escapeHtml(region.region_nombre || 'SIN REGIÓN')}</div>
                        <div class="region-meta">
                            ${formatNumberCL(region.visitados)} / ${formatNumberCL(region.asignados)} locales visitados
                        </div>
                    </div>
                    <div class="progress-value">${Number(region.avance || 0).toLocaleString('es-CL')}%</div>
                </div>

                <div class="region-progress-row">
                    <div class="progress-label">Avance región</div>
                    <div class="progress-track">
                        <div class="progress-fill ${fillClass}" style="width:${Number(region.avance || 0)}%;"></div>
                    </div>
                    <div class="progress-value">${Number(region.avance || 0).toLocaleString('es-CL')}%</div>
                </div>
        `;

        usuarios.forEach(function(usuario) {
            const fillClassUser = getProgressClass(usuario.avance);

            html += `
                <div class="user-progress-row">
                    <div class="progress-label">
                        <i class="fas fa-user mr-1" style="opacity:.6;"></i>
                        ${escapeHtml(usuario.usuario_nombre || 'SIN USUARIO')}
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill ${fillClassUser}" style="width:${Number(usuario.avance || 0)}%;"></div>
                    </div>
                    <div class="progress-value">
                        ${formatNumberCL(usuario.visitados)} / ${formatNumberCL(usuario.asignados)}
                        (${Number(usuario.avance || 0).toLocaleString('es-CL')}%)
                    </div>
                </div>
            `;
        });

        html += `</div>`;
    });

    return html;
}

function buildRegionSlaTable(data) {
    const regiones = data?.regiones || [];

    if (!regiones.length) {
        return `
            <div class="modern-empty-state">
                <i class="fas fa-clock"></i>
                <div>
                    <strong>Sin tabla SLA</strong>
                    <span>No hay registros por región para esta campaña.</span>
                </div>
            </div>
        `;
    }

    let html = `
        <div class="table-responsive">
            <table class="region-sla-table">
                <thead>
                    <tr>
                        <th>Región</th>
                        <th>Asignados</th>
                        <th>Visitados</th>
                        <th>Avance</th>
                        <th>Primera visita</th>
                        <th>Última visita</th>
                        <th>Días hasta primera</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
    `;

    regiones.forEach(function(region) {
        html += `
            <tr>
                <td>${escapeHtml(region.region_nombre || 'SIN REGIÓN')}</td>
                <td>${formatNumberCL(region.asignados)}</td>
                <td>${formatNumberCL(region.visitados)}</td>
                <td>${Number(region.avance || 0).toLocaleString('es-CL')}%</td>
                <td>${escapeHtml(region.primera_visita || '-')}</td>
                <td>${escapeHtml(region.ultima_visita || '-')}</td>
                <td>${region.dias_hasta_primera !== null ? region.dias_hasta_primera : '-'}</td>
                <td>
                    <span class="status-pill ${escapeHtml(region.estado_class || 'status-neutral')}">
                        ${escapeHtml(region.estado || '-')}
                    </span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    return html;
}


function renderRegionAnalytics(data) {
    $('#regionSummaryCards').html(buildRegionSummaryCards(data));
    $('#regionUserProgressList').html(buildRegionUserList(data));
    $('#regionSlaTableWrap').html(buildRegionSlaTable(data));
}

function limpiarDetalleCampana() {
    $('#detalleNombreCampana').text('-');

    $('#detalleEstadoCampana')
        .removeClass('badge-success badge-warning badge-danger')
        .addClass('badge-success')
        .text('-');

    $('#detalleFechaInicio, #detallePrimeraVisita, #detalleUltimaVisita, #detalleFechaTermino').text('-');
    $('#detalleDiasPrimera, #detalleDiasUltima, #detalleDiasEntre, #detalleDiasPlanificados').text('-');

    $('#detalleAsignados, #detalleVisitados, #detalleGestionados').text('0');
    $('#detalleRatioVisita, #detalleRatioEjecucion').text('0%');

    $('#timelineV2Events').empty();
    $('#timelineV2Gaps').empty();
    $('#timelineV2Specials').empty();
    $('#timelineV2').css('width', '1500px');

    limpiarRegionAnalytics();
}

});
</script>
</body>
</html>
