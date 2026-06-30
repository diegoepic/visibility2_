v<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../index.php');
    exit();
}

$empresa_id  = intval($_SESSION['empresa_id']);
$division_id = intval($_SESSION['division_id']);

/* ── Filtros ── */
$filtro_division  = isset($_GET['division'])    ? intval($_GET['division'])    : 0;
$filtro_campana   = isset($_GET['id_campana'])  ? intval($_GET['id_campana'])  : 0;
$filtro_fecha_ini = trim($_GET['fecha_ini'] ?? '');
$filtro_fecha_fin = trim($_GET['fecha_fin'] ?? '');
$filtro_texto     = trim($_GET['q'] ?? '');
$filtro_ejecutor  = isset($_GET['id_ejecutor']) ? intval($_GET['id_ejecutor']) : 0;
/* Estado de campaña: 1 = en proceso (por defecto, solo activas), 3 = finalizadas */
$filtro_estado    = isset($_GET['estado']) ? intval($_GET['estado']) : 1;
if (!in_array($filtro_estado, [1, 3], true)) $filtro_estado = 1;
$eParam           = '&estado=' . $filtro_estado; // sufijo para preservar el filtro en los enlaces
$pagina           = max(1, intval($_GET['p'] ?? 1));
$por_pagina       = 10;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro_fecha_ini)) $filtro_fecha_ini = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro_fecha_fin))  $filtro_fecha_fin  = '';

/* ── Divisiones ── */
$stmtDiv = $conn->prepare("
    SELECT DISTINCT d.id, d.nombre
    FROM formulario f
    INNER JOIN division_empresa d ON d.id = f.id_division
    WHERE f.id_empresa = ? AND f.estado = ? AND f.tipo = 1
      AND f.modalidad IN ('implementacion_auditoria','solo_implementacion') AND d.estado = 1
    ORDER BY d.nombre
");
$stmtDiv->bind_param('ii', $empresa_id, $filtro_estado);
$stmtDiv->execute();
$divisiones = $stmtDiv->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtDiv->close();

/* ── Campañas con conteos (paginadas) ── */
$sqlBase = "
    FROM formulario f
    WHERE f.id_empresa = ? AND f.tipo = 1
      AND f.modalidad IN ('implementacion_auditoria','solo_implementacion')
      AND f.deleted_at IS NULL
      AND f.estado = ?
";
$pBase = [$empresa_id, $filtro_estado]; $tBase = 'ii';
if ($filtro_division > 0) { $sqlBase .= " AND f.id_division = ?"; $pBase[] = $filtro_division; $tBase .= 'i'; }
if ($filtro_texto !== '')  { $sqlBase .= " AND f.nombre LIKE ?"; $pBase[] = '%' . $filtro_texto . '%'; $tBase .= 's'; }

/* Count total */
$stmtCnt = $conn->prepare("SELECT COUNT(*) AS total $sqlBase");
$stmtCnt->bind_param($tBase, ...$pBase);
$stmtCnt->execute();
$totalCampanas = (int)$stmtCnt->get_result()->fetch_assoc()['total'];
$stmtCnt->close();
$totalPaginas = max(1, (int)ceil($totalCampanas / $por_pagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $por_pagina;

/* Fetch campañas paginadas */
$sqlCamp = "SELECT f.id, f.nombre, f.fechaInicio, f.fechaTermino, f.modalidad $sqlBase ORDER BY f.fechaInicio DESC LIMIT ? OFFSET ?";
$pCamp   = array_merge($pBase, [$por_pagina, $offset]);
$tCamp   = $tBase . 'ii';
$stmtC = $conn->prepare($sqlCamp);
$stmtC->bind_param($tCamp, ...$pCamp);
$stmtC->execute();
$campanas = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtC->close();

/* ── Resumen de recepciones por campaña (para badges en la lista) ── */
$campanaIds = array_column($campanas, 'id');
$recResumen = [];
if (!empty($campanaIds)) {
    $ph = implode(',', array_fill(0, count($campanaIds), '?'));
    $stmtR = $conn->prepare("
        SELECT mr.id_formulario,
               COUNT(DISTINCT mr.id_usuario) AS ejecutores_registrados,
               COUNT(mr.id)                  AS total_registros,
               COUNT(DISTINCT fq.id_usuario) AS ejecutores_asignados
        FROM formulario f
        INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
        LEFT JOIN material_recepcion mr ON mr.id_formulario = f.id AND mr.id_empresa = ?
        WHERE f.id IN ($ph)
        GROUP BY f.id
    ");
    $stmtR->bind_param('i' . str_repeat('i', count($campanaIds)), $empresa_id, ...$campanaIds);
    $stmtR->execute();
    foreach ($stmtR->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $recResumen[(int)$row['id_formulario']] = $row;
    }
    $stmtR->close();
}

/* ── Detalle completo si hay campaña seleccionada ── */
$campSelec          = null;
$detalleRecepciones = [];
$ejecutoresSinRec   = [];
$resumenMateriales  = [];
$chartEjecutores    = [];
$chartMateriales    = [];

if ($filtro_campana > 0) {
    /* Datos básicos de la campaña */
    $stmtCS = $conn->prepare("SELECT id, nombre, modalidad, fechaInicio, fechaTermino FROM formulario WHERE id = ? AND id_empresa = ? LIMIT 1");
    $stmtCS->bind_param('ii', $filtro_campana, $empresa_id);
    $stmtCS->execute();
    $campSelec = $stmtCS->get_result()->fetch_assoc();
    $stmtCS->close();

    if ($campSelec) {
        /* Recepciones con filtro de fecha */
        $sqlDet = "
            SELECT mr.id, mr.fecha_recepcion, mr.created_at, mr.numero_guia, mr.foto_guia_url, mr.observacion,
                   CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,'')) AS nombre_ejecutor,
                   u.usuario, u.id AS id_usuario,
                   u.centro_distribucion,
                   co.comuna AS nombre_comuna,
                   r.region  AS nombre_region,
                   GROUP_CONCAT(CONCAT(mrd.nombre_material,' (',CAST(mrd.cantidad_recibida AS CHAR),')')
                       ORDER BY mrd.nombre_material SEPARATOR ', ') AS resumen_mat
            FROM material_recepcion mr
            JOIN usuario u ON u.id = mr.id_usuario
            LEFT JOIN comuna co ON co.id = u.id_comuna
            LEFT JOIN region r  ON r.id  = co.id_region
            LEFT JOIN material_recepcion_detalle mrd ON mrd.id_recepcion = mr.id
            WHERE mr.id_formulario = ? AND mr.id_empresa = ?
        ";
        $pDet = [$filtro_campana, $empresa_id]; $tDet = 'ii';
        if ($filtro_fecha_ini !== '') { $sqlDet .= " AND mr.fecha_recepcion >= ?"; $pDet[] = $filtro_fecha_ini; $tDet .= 's'; }
        if ($filtro_fecha_fin !== '') { $sqlDet .= " AND mr.fecha_recepcion <= ?"; $pDet[] = $filtro_fecha_fin; $tDet .= 's'; }
        if ($filtro_ejecutor  >   0) { $sqlDet .= " AND mr.id_usuario = ?";       $pDet[] = $filtro_ejecutor;  $tDet .= 'i'; }
        $sqlDet .= " GROUP BY mr.id ORDER BY mr.fecha_recepcion DESC, mr.created_at DESC";
        $stmtDet = $conn->prepare($sqlDet);
        $stmtDet->bind_param($tDet, ...$pDet);
        $stmtDet->execute();
        $detalleRecepciones = $stmtDet->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtDet->close();

        /* Desglose por material de cada recepción (para fila expandible en historial) */
        $detallesMap = [];
        if (!empty($detalleRecepciones)) {
            $recIds  = array_column($detalleRecepciones, 'id');
            $phRec   = implode(',', array_fill(0, count($recIds), '?'));
            $stmtMRD = $conn->prepare("SELECT id_recepcion, nombre_material, cantidad_propuesta, cantidad_recibida FROM material_recepcion_detalle WHERE id_recepcion IN ($phRec) ORDER BY nombre_material");
            $stmtMRD->bind_param(str_repeat('i', count($recIds)), ...$recIds);
            $stmtMRD->execute();
            foreach ($stmtMRD->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $detallesMap[(int)$row['id_recepcion']][] = $row;
            }
            $stmtMRD->close();
        }

        /* Todos los ejecutores asignados */
        $stmtEj = $conn->prepare("
            SELECT DISTINCT u.id, CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,'')) AS nombre_ejecutor, u.usuario
            FROM formularioQuestion fq
            JOIN usuario u ON u.id = fq.id_usuario
            WHERE fq.id_formulario = ?
        ");
        $stmtEj->bind_param('i', $filtro_campana);
        $stmtEj->execute();
        $todosEjecutores = $stmtEj->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtEj->close();

        /* IDs que sí registraron */
        $idsConRec = array_unique(array_column($detalleRecepciones, 'id_usuario'));
        foreach ($todosEjecutores as $ej) {
            if (!in_array((int)$ej['id'], $idsConRec, true)) {
                $ejecutoresSinRec[] = $ej;
            }
        }

        /* Materiales propuestos por esta campaña (total global) */
        $stmtMat = $conn->prepare("
            SELECT fq.material AS nombre, SUM(fq.valor_propuesto) AS propuesto
            FROM formularioQuestion fq
            WHERE fq.id_formulario = ? AND fq.material IS NOT NULL AND TRIM(fq.material) != ''
            GROUP BY fq.material HAVING SUM(fq.valor_propuesto) > 0 ORDER BY fq.material
        ");
        $stmtMat->bind_param('i', $filtro_campana);
        $stmtMat->execute();
        $matPropuestos = $stmtMat->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtMat->close();

        /* Total recibido por material (sumando todas las recepciones) */
        $stmtRec2 = $conn->prepare("
            SELECT mrd.nombre_material, SUM(mrd.cantidad_recibida) AS recibido
            FROM material_recepcion mr
            JOIN material_recepcion_detalle mrd ON mrd.id_recepcion = mr.id
            WHERE mr.id_formulario = ? AND mr.id_empresa = ?
            GROUP BY mrd.nombre_material
        ");
        $stmtRec2->bind_param('ii', $filtro_campana, $empresa_id);
        $stmtRec2->execute();
        $totRecibMap = [];
        foreach ($stmtRec2->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $totRecibMap[$r['nombre_material']] = (float)$r['recibido'];
        }
        $stmtRec2->close();

        foreach ($matPropuestos as $mp) {
            $recibido = $totRecibMap[$mp['nombre']] ?? 0.0;
            $propuesto = (float)$mp['propuesto'];
            $pct = $propuesto > 0 ? round($recibido / $propuesto * 100, 1) : 0.0;
            $resumenMateriales[] = [
                'nombre'    => $mp['nombre'],
                'propuesto' => $propuesto,
                'recibido'  => $recibido,
                'pct'       => $pct,
            ];
            $chartMateriales[] = ['label' => $mp['nombre'], 'propuesto' => $propuesto, 'recibido' => $recibido];
        }

        /* Datos por ejecutor para el gráfico.
         * Se separa el origen de ejecutores asignados (formularioQuestion)
         * del cálculo de cantidades recibidas (material_recepcion + detalle)
         * para evitar multiplicación por número de locales asignados. */
        $stmtCE = $conn->prepare("
            SELECT
                u.id                                                              AS id_usuario,
                CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))         AS nombre,
                COALESCE(agg.total_recibido, 0)                                   AS total_recibido,
                COALESCE(agg.registros, 0)                                        AS registros
            FROM (
                SELECT DISTINCT fq.id_usuario
                FROM formularioQuestion fq
                WHERE fq.id_formulario = ?
            ) AS asignados
            JOIN usuario u ON u.id = asignados.id_usuario
            LEFT JOIN (
                SELECT mr.id_usuario,
                       SUM(mrd.cantidad_recibida) AS total_recibido,
                       COUNT(DISTINCT mr.id)       AS registros
                FROM material_recepcion mr
                JOIN material_recepcion_detalle mrd ON mrd.id_recepcion = mr.id
                WHERE mr.id_formulario = ? AND mr.id_empresa = ?
                GROUP BY mr.id_usuario
            ) AS agg ON agg.id_usuario = u.id
            ORDER BY total_recibido DESC
        ");
        $stmtCE->bind_param('iii', $filtro_campana, $filtro_campana, $empresa_id);
        $stmtCE->execute();
        $chartEjecutores = $stmtCE->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtCE->close();

        /* Desglose por ejecutor × material (para tooltip en la tabla) */
        $tooltipPorEj = [];
        $stmtTE = $conn->prepare("
            SELECT mr.id_usuario,
                   mrd.nombre_material,
                   SUM(mrd.cantidad_recibida) AS total
            FROM material_recepcion mr
            JOIN material_recepcion_detalle mrd ON mrd.id_recepcion = mr.id
            WHERE mr.id_formulario = ? AND mr.id_empresa = ?
            GROUP BY mr.id_usuario, mrd.nombre_material
            ORDER BY mr.id_usuario, mrd.nombre_material
        ");
        $stmtTE->bind_param('ii', $filtro_campana, $empresa_id);
        $stmtTE->execute();
        foreach ($stmtTE->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $tooltipPorEj[(int)$row['id_usuario']][] = [
                'mat'   => $row['nombre_material'],
                'total' => (float)$row['total'],
            ];
        }
        $stmtTE->close();

        /* Propuesto por ejecutor (para columna % en tab ejecutores) */
        $propuestoPorEj = [];
        $stmtPE = $conn->prepare("
            SELECT fq.id_usuario, SUM(CAST(fq.valor_propuesto AS DECIMAL(10,2))) AS total_propuesto
            FROM formularioQuestion fq
            WHERE fq.id_formulario = ? AND fq.material IS NOT NULL AND TRIM(fq.material) != ''
            GROUP BY fq.id_usuario
        ");
        $stmtPE->bind_param('i', $filtro_campana);
        $stmtPE->execute();
        foreach ($stmtPE->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $propuestoPorEj[(int)$row['id_usuario']] = (float)$row['total_propuesto'];
        }
        $stmtPE->close();

        /* Reorganizar tooltipPorEj por material (para tab materiales) */
        $tooltipPorMat = [];
        foreach ($tooltipPorEj as $ejId => $lineas) {
            $ejNombre = '';
            foreach ($chartEjecutores as $ce) {
                if ((int)$ce['id_usuario'] === $ejId) { $ejNombre = trim($ce['nombre']); break; }
            }
            foreach ($lineas as $l) {
                $tooltipPorMat[$l['mat']][] = ['nombre' => $ejNombre, 'total' => $l['total']];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recepción de Materiales</title>
    <link rel="stylesheet" href="../assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/main-responsive.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { background:#f4f6f9; font-family:'Source Sans Pro',sans-serif; }
        /* ── Layout ── */
        .rm-layout { display:flex; gap:0; min-height:calc(100vh - 40px); }
        .rm-sidebar {
            width:320px; min-width:300px; max-width:340px;
            background:#fff; border-right:1px solid #e4e9f0;
            display:flex; flex-direction:column;
            position:sticky; top:0; height:100vh; overflow:hidden;
        }
        .rm-main { flex:1; padding:22px; overflow-y:auto; min-width:0; }
        /* ── Sidebar ── */
        .rm-sidebar-head { padding:16px; border-bottom:1px solid #e4e9f0; }
        .rm-sidebar-head h4 { margin:0; font-size:15px; font-weight:700; color:#1a3a5c; }
        .rm-sidebar-head p  { margin:2px 0 0; font-size:11px; color:#8fa0b5; }
        .rm-search { padding:10px 14px; border-bottom:1px solid #e4e9f0; }
        .rm-search input {
            width:100%; border:1px solid #d8e2ee; border-radius:8px;
            padding:7px 12px; font-size:13px; outline:none;
        }
        .rm-search input:focus { border-color:#3a7bd5; }
        .rm-filters { padding:8px 14px; border-bottom:1px solid #e4e9f0; display:flex; gap:8px; flex-wrap:wrap; }
        .rm-filters select { border:1px solid #d8e2ee; border-radius:7px; padding:4px 8px; font-size:12px; color:#334; flex:1; min-width:100px; }
        .rm-camp-list { flex:1; overflow-y:auto; padding:8px; }
        .rm-camp-item {
            display:block; padding:11px 13px; border-radius:10px;
            border:1px solid transparent; margin-bottom:6px;
            text-decoration:none; color:inherit; cursor:pointer;
            transition:all .15s;
        }
        .rm-camp-item:hover { background:#f0f7ff; border-color:#c6deff; }
        .rm-camp-item.active { background:#eaf3ff; border-color:#3a7bd5; }
        .rm-camp-name { font-size:13px; font-weight:700; color:#1a3a5c; line-height:1.3; }
        .rm-camp-dates { font-size:11px; color:#8fa0b5; margin-top:2px; }
        .rm-camp-badges { margin-top:6px; display:flex; gap:5px; flex-wrap:wrap; }
        .badge-cob { padding:2px 8px; border-radius:8px; font-size:11px; font-weight:600; }
        .cob-ok   { background:#d4edda; color:#155724; }
        .cob-warn { background:#fff3cd; color:#856404; }
        .cob-zero { background:#f8d7da; color:#721c24; }
        /* ── Paginación ── */
        .rm-pagination { padding:10px 14px; border-top:1px solid #e4e9f0; display:flex; align-items:center; justify-content:space-between; font-size:12px; }
        .rm-pagination a, .rm-pagination span {
            padding:4px 10px; border-radius:6px; text-decoration:none;
            border:1px solid #d8e2ee; color:#334; margin:0 2px;
        }
        .rm-pagination a:hover { background:#f0f7ff; border-color:#3a7bd5; }
        .rm-pagination .active-page { background:#3a7bd5; color:#fff; border-color:#3a7bd5; }
        /* ── Main area ── */
        .rm-empty-sel {
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            min-height:400px; color:#b0bccc; text-align:center;
        }
        .rm-empty-sel i { font-size:56px; margin-bottom:12px; opacity:.4; }
        /* ── Tabs ── */
        .rm-tabs { display:flex; gap:0; border-bottom:2px solid #e4e9f0; margin-bottom:20px; }
        .rm-tab {
            padding:9px 18px; font-size:13px; font-weight:700; color:#8fa0b5;
            border-bottom:2px solid transparent; margin-bottom:-2px; cursor:pointer;
            text-decoration:none;
        }
        .rm-tab.active { color:#3a7bd5; border-bottom-color:#3a7bd5; }
        /* ── KPI cards ── */
        .kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
        .kpi-card {
            background:#fff; border-radius:14px; padding:16px 18px;
            box-shadow:0 2px 10px rgba(40,80,140,.07);
        }
        .kpi-num  { font-size:26px; font-weight:800; color:#1a3a5c; line-height:1; }
        .kpi-lbl  { font-size:11px; color:#8fa0b5; margin-top:4px; text-transform:uppercase; letter-spacing:.5px; }
        .kpi-card.green .kpi-num { color:#1a7d3a; }
        .kpi-card.red   .kpi-num { color:#c0392b; }
        .kpi-card.orange .kpi-num { color:#e67e22; }
        /* ── Alert strip ── */
        .alert-strip {
            background:#fff8e1; border:1px solid #ffe082; border-radius:10px;
            padding:10px 14px; margin-bottom:16px; font-size:13px; color:#795548;
            display:flex; align-items:flex-start; gap:10px;
        }
        .alert-strip i { margin-top:2px; flex-shrink:0; color:#f39c12; }
        /* ── Material progress bar ── */
        .mat-progress-row {
            background:#fff; border-radius:12px; padding:14px 16px;
            margin-bottom:10px; box-shadow:0 1px 6px rgba(40,80,140,.06);
        }
        .mat-prog-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
        .mat-prog-name   { font-size:13px; font-weight:700; color:#1a3a5c; }
        .mat-prog-nums   { font-size:12px; color:#8fa0b5; }
        .mat-prog-bar    { height:8px; border-radius:4px; background:#e8eef8; overflow:hidden; }
        .mat-prog-fill   { height:100%; border-radius:4px; transition:width .4s; }
        .fill-ok   { background:#27ae60; }
        .fill-warn { background:#f39c12; }
        .fill-low  { background:#e74c3c; }
        /* ── Tabla detalle ── */
        .det-table { width:100%; border-collapse:collapse; font-size:13px; }
        .det-table th { background:#3a7bd5; color:#fff; padding:9px 12px; text-align:left; font-size:12px; }
        .det-table td { padding:9px 12px; border-bottom:1px solid #f0f4f8; vertical-align:middle; }
        .det-table tr:hover td { background:#f7faff; }
        .foto-thumb { width:52px; height:38px; object-fit:cover; border-radius:5px; border:1px solid #dde; cursor:pointer; }
        /* ── Ejecutores sin registrar ── */
        .no-rec-list { display:flex; flex-wrap:wrap; gap:8px; }
        .no-rec-chip {
            background:#fff1f0; border:1px solid #ffc9c9; border-radius:20px;
            padding:5px 12px; font-size:12px; color:#c0392b; font-weight:600;
        }
        /* ── Chart containers ── */
        .chart-box {
            background:#fff; border-radius:14px; padding:18px;
            box-shadow:0 2px 10px rgba(40,80,140,.07); margin-bottom:20px;
        }
        .chart-box h5 { font-size:14px; font-weight:700; color:#1a3a5c; margin:0 0 14px; }
        /* ── Header campaña ── */
        .rm-camp-header {
            background:#fff; border-radius:14px; padding:16px 20px;
            margin-bottom:20px; box-shadow:0 2px 10px rgba(40,80,140,.07);
            display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;
        }
        .rm-camp-header h3 { margin:0; font-size:17px; font-weight:800; color:#1a3a5c; }
        .rm-camp-header small { color:#8fa0b5; font-size:12px; }
        @media(max-width:900px){
            .rm-layout { flex-direction:column; }
            .rm-sidebar { width:100%; max-width:100%; height:auto; position:static; }
            .kpi-row { grid-template-columns:repeat(2,1fr); }
        }
        @media(max-width:576px){
            .kpi-row { grid-template-columns:1fr 1fr; }
        }
        /* ── Tooltip desglose de materiales ── */
        .tt-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: help;
        }
        .tt-wrap .tt-box {
            display: none;
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: #1a3a5c;
            color: #fff;
            padding: 9px 13px;
            border-radius: 9px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            box-shadow: 0 6px 20px rgba(0,0,0,.22);
            pointer-events: none;
            min-width: 150px;
            line-height: 1.7;
        }
        .tt-wrap .tt-box::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 7px solid transparent;
            border-top-color: #1a3a5c;
        }
        .tt-wrap:hover .tt-box { display: block; }
        .tt-icon { color: #3a7bd5; font-size: 13px; opacity: .75; }
        /* ── Detalle expandible historial ── */
        .det-expand-btn { background:none; border:none; padding:2px 5px; color:#3a7bd5; cursor:pointer; font-size:12px; border-radius:4px; vertical-align:middle; }
        .det-expand-btn:hover { background:#e8f0ff; }
        .det-expand-row { display:none; }
        .det-expand-row > td { padding:0 !important; border:none !important; }
        .det-sub-wrap { padding:8px 16px 8px 48px; background:#f7faff; border-bottom:2px solid #e4e9f0; }
        .det-sub-table { width:100%; border-collapse:collapse; font-size:12px; }
        .det-sub-table th { background:#dce8ff; color:#1a3a5c; padding:4px 10px; font-size:11px; text-align:left; }
        .det-sub-table td { padding:4px 10px; border-bottom:1px solid #e8eef8; }
        .det-sub-table tr:last-child td { border-bottom:none; }
        .delta-neg { color:#e74c3c; font-weight:700; }
        .delta-ok  { color:#27ae60; font-weight:700; }
        /* ── Miniaturas múltiples foto ── */
        .fotos-thumb-strip { display:flex; gap:4px; flex-wrap:wrap; }
        .fotos-thumb-strip img { width:44px; height:34px; object-fit:cover; border-radius:4px; border:1px solid #dde; cursor:zoom-in; }
        /* ── Modal carrusel ── */
        .modal-nav-btn { background:rgba(255,255,255,.15); border:none; color:#fff; font-size:20px; padding:4px 10px; cursor:pointer; border-radius:6px; line-height:1; }
        .modal-nav-btn:hover { background:rgba(255,255,255,.3); }
        .modal-nav-btn:disabled { opacity:.3; cursor:default; }
        .modal-foto-counter { font-size:12px; color:#aaa; }
    </style>
</head>
<body>
<div style="padding:14px 20px 0; border-bottom:1px solid #e4e9f0; background:#fff; margin-bottom:0;">
    <h2 style="margin:0; font-size:18px; font-weight:800; color:#1a3a5c;">
        <i class="fa fa-cubes" style="color:#3a7bd5; margin-right:8px;"></i>Recepción de Materiales
    </h2>
    <p style="margin:3px 0 10px; font-size:12px; color:#8fa0b5;">Trazabilidad de retiro de materiales por ejecutor y campaña</p>
</div>

<div class="rm-layout">
    <!-- ═══════════════════════════════════════
         SIDEBAR: Lista de campañas
    ═══════════════════════════════════════ -->
    <div class="rm-sidebar">
        <div class="rm-sidebar-head">
            <h4><i class="fa fa-list-ul"></i> Campañas</h4>
            <p><?= $totalCampanas ?> campaña<?= $totalCampanas !== 1 ? 's' : '' ?> elegible<?= $totalCampanas !== 1 ? 's' : '' ?></p>
        </div>

        <!-- Búsqueda por texto -->
        <form method="GET" id="frmBusqueda" class="rm-search">
            <input type="hidden" name="estado" value="<?= $filtro_estado ?>">
            <?php if ($filtro_division > 0): ?>
                <input type="hidden" name="division" value="<?= $filtro_division ?>">
            <?php endif; ?>
            <?php if ($filtro_campana > 0): ?>
                <input type="hidden" name="id_campana" value="<?= $filtro_campana ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?= htmlspecialchars($filtro_texto, ENT_QUOTES) ?>"
                   placeholder="Buscar campaña…" autocomplete="off"
                   oninput="this.form.submit()">
        </form>

        <!-- Filtros: estado (siempre) + división (si hay más de una) -->
        <div class="rm-filters">
            <!-- Estado de campaña: en proceso (activas, por defecto) / finalizadas. Al cambiar se
                 reinicia la lista (sin campaña seleccionada). -->
            <select aria-label="Estado de campaña"
                    onchange="window.location='?estado='+this.value+'<?= $filtro_division ? '&division='.$filtro_division : '' ?><?= $filtro_texto ? '&q='.urlencode($filtro_texto) : '' ?>'">
                <option value="1" <?= $filtro_estado === 1 ? 'selected' : '' ?>>En curso</option>
                <option value="3" <?= $filtro_estado === 3 ? 'selected' : '' ?>>Finalizadas</option>
            </select>
            <?php if (count($divisiones) > 1): ?>
            <select aria-label="División"
                    onchange="window.location='?division='+this.value+'&estado=<?= $filtro_estado ?><?= $filtro_texto ? '&q='.urlencode($filtro_texto) : '' ?>'">
                <option value="0">Todas las divisiones</option>
                <?php foreach ($divisiones as $div): ?>
                    <option value="<?= $div['id'] ?>" <?= $filtro_division == $div['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($div['nombre'], ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>

        <!-- Lista campañas paginada -->
        <div class="rm-camp-list">
            <?php if (empty($campanas)): ?>
                <p style="text-align:center; color:#b0bccc; padding:20px; font-size:13px;">Sin campañas</p>
            <?php else: ?>
                <?php foreach ($campanas as $c):
                    $cid  = (int)$c['id'];
                    $rec  = $recResumen[$cid] ?? ['ejecutores_registrados'=>0,'ejecutores_asignados'=>0,'total_registros'=>0];
                    $nEjA = (int)$rec['ejecutores_asignados'];
                    $nEjR = (int)$rec['ejecutores_registrados'];
                    $pct  = $nEjA > 0 ? round($nEjR / $nEjA * 100) : 0;
                    $cobClass = $pct >= 80 ? 'cob-ok' : ($pct >= 40 ? 'cob-warn' : 'cob-zero');
                    $nReg = (int)$rec['total_registros'];
                    $fi = $c['fechaInicio']  ? date('d/m/Y', strtotime($c['fechaInicio']))  : '—';
                    $ft = $c['fechaTermino'] ? date('d/m/Y', strtotime($c['fechaTermino'])) : '—';
                    $urlCamp = '?id_campana=' . $cid
                        . $eParam
                        . ($filtro_division ? '&division='.$filtro_division : '')
                        . ($filtro_texto    ? '&q='.urlencode($filtro_texto) : '')
                        . '&p=' . $pagina;
                ?>
                <a href="<?= $urlCamp ?>" class="rm-camp-item <?= $filtro_campana === $cid ? 'active' : '' ?>">
                    <div class="rm-camp-name"><?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?></div>
                    <div class="rm-camp-dates"><?= $fi ?> — <?= $ft ?></div>
                    <div class="rm-camp-badges">
                        <span class="badge-cob <?= $cobClass ?>"><?= $pct ?>% ejecutores</span>
              
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Paginación -->
        <?php if ($totalPaginas > 1): ?>
        <div class="rm-pagination">
            <span style="color:#8fa0b5;"><?= (($pagina-1)*$por_pagina+1) ?>–<?= min($pagina*$por_pagina,$totalCampanas) ?> de <?= $totalCampanas ?></span>
            <div>
                <?php if ($pagina > 1): ?>
                    <a href="?p=<?= $pagina-1 ?><?= $eParam ?><?= $filtro_campana ? '&id_campana='.$filtro_campana : '' ?><?= $filtro_division ? '&division='.$filtro_division : '' ?><?= $filtro_texto ? '&q='.urlencode($filtro_texto) : '' ?>">‹</a>
                <?php endif; ?>
                <?php for ($pg = max(1,$pagina-2); $pg <= min($totalPaginas,$pagina+2); $pg++): ?>
                    <a href="?p=<?= $pg ?><?= $eParam ?><?= $filtro_campana ? '&id_campana='.$filtro_campana : '' ?><?= $filtro_division ? '&division='.$filtro_division : '' ?><?= $filtro_texto ? '&q='.urlencode($filtro_texto) : '' ?>"
                       class="<?= $pg === $pagina ? 'active-page' : '' ?>"><?= $pg ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="?p=<?= $pagina+1 ?><?= $eParam ?><?= $filtro_campana ? '&id_campana='.$filtro_campana : '' ?><?= $filtro_division ? '&division='.$filtro_division : '' ?><?= $filtro_texto ? '&q='.urlencode($filtro_texto) : '' ?>">›</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════
         MAIN: Detalle campaña seleccionada
    ═══════════════════════════════════════ -->
    <div class="rm-main">
    <?php if (!$campSelec): ?>
        <div class="rm-empty-sel">
            <i class="fa fa-hand-o-left"></i>
            <p style="font-size:15px; font-weight:600;">Selecciona una campaña para ver el detalle</p>
            <p style="font-size:12px;">Verás gráficos, estado por ejecutor y trazabilidad completa de materiales.</p>
        </div>

    <?php else:
        $nAsig   = count($chartEjecutores);
        $nConRec = count(array_filter($chartEjecutores, fn($e) => (float)$e['total_recibido'] > 0));
        $nSinRec = count($ejecutoresSinRec);
        $totReg  = array_sum(array_column($chartEjecutores, 'registros'));
        $totMatProp = array_sum(array_column($resumenMateriales, 'propuesto'));
        $totMatRecib = array_sum(array_column($resumenMateriales, 'recibido'));
        $pctGlobal = $totMatProp > 0 ? round($totMatRecib / $totMatProp * 100, 1) : 0;

        $tab = $_GET['tab'] ?? 'resumen';
        $tabUrl = fn($t) => '?id_campana='.$filtro_campana.'&tab='.$t
            . $eParam
            . ($filtro_division ? '&division='.$filtro_division : '')
            . ($filtro_texto    ? '&q='.urlencode($filtro_texto) : '')
            . ($filtro_fecha_ini ? '&fecha_ini='.$filtro_fecha_ini : '')
            . ($filtro_fecha_fin ? '&fecha_fin='.$filtro_fecha_fin : '');
    ?>

        <!-- Header campaña -->
        <div class="rm-camp-header">
            <div>
                <h3><?= htmlspecialchars($campSelec['nombre'], ENT_QUOTES) ?></h3>
                <small>
                    <?= htmlspecialchars($campSelec['modalidad'], ENT_QUOTES) ?>
                    &nbsp;·&nbsp;
                    <?= $campSelec['fechaInicio'] ? date('d/m/Y', strtotime($campSelec['fechaInicio'])) : '—' ?>
                    →
                    <?= $campSelec['fechaTermino'] ? date('d/m/Y', strtotime($campSelec['fechaTermino'])) : '—' ?>
                </small>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <!-- Filtros de fecha -->
                <form method="GET" style="display:flex; gap:6px; align-items:center;">
                    <input type="hidden" name="id_campana" value="<?= $filtro_campana ?>">
                    <input type="hidden" name="estado" value="<?= $filtro_estado ?>">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES) ?>">
                    <?php if ($filtro_division): ?><input type="hidden" name="division" value="<?= $filtro_division ?>"><?php endif; ?>
                    <?php if ($filtro_texto): ?><input type="hidden" name="q" value="<?= htmlspecialchars($filtro_texto, ENT_QUOTES) ?>"><?php endif; ?>
                    <input type="date" name="fecha_ini" value="<?= $filtro_fecha_ini ?>"
                           style="border:1px solid #d8e2ee; border-radius:7px; padding:5px 8px; font-size:12px;">
                    <input type="date" name="fecha_fin" value="<?= $filtro_fecha_fin ?>"
                           style="border:1px solid #d8e2ee; border-radius:7px; padding:5px 8px; font-size:12px;">
                    <button type="submit" class="btn btn-sm btn-primary" style="border-radius:7px;">
                        <i class="fa fa-filter"></i>
                    </button>
                    <?php if ($filtro_fecha_ini || $filtro_fecha_fin): ?>
                        <a href="?id_campana=<?= $filtro_campana ?><?= $eParam ?>&tab=<?= $tab ?>" class="btn btn-sm btn-default" style="border-radius:7px;">
                            <i class="fa fa-times"></i>
                        </a>
                    <?php endif; ?>
                </form>
                <a href="/visibility2/portal/informes/descargar_excel_recepcion.php?id_campana=<?= $filtro_campana ?>&empresa_id=<?= $empresa_id ?><?= $filtro_fecha_ini ? '&fecha_ini='.$filtro_fecha_ini : '' ?><?= $filtro_fecha_fin ? '&fecha_fin='.$filtro_fecha_fin : '' ?>"
                   class="btn btn-sm btn-success" style="border-radius:7px;">
                    <i class="fa fa-file-excel-o"></i> Excel
                </a>
            </div>
        </div>

        <?php if ($nSinRec > 0): ?>
        <div class="alert-strip">
            <i class="fa fa-exclamation-triangle"></i>
            <div>
                <strong><?= $nSinRec ?> ejecutor<?= $nSinRec !== 1 ? 'es' : '' ?> sin registrar:</strong>
                <?= implode(', ', array_map(fn($e) => htmlspecialchars(trim($e['nombre_ejecutor']), ENT_QUOTES), $ejecutoresSinRec)) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- KPIs -->
        <div class="kpi-row">
            <div class="kpi-card <?= $nSinRec === 0 ? 'green' : 'orange' ?>">
                <div class="kpi-num"><?= $nConRec ?>/<?= $nAsig ?></div>
                <div class="kpi-lbl">Ejecutores con recepción</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-num"><?= $totReg ?></div>
                <div class="kpi-lbl">Registros totales</div>
            </div>
            <div class="kpi-card <?= $pctGlobal >= 80 ? 'green' : ($pctGlobal >= 40 ? 'orange' : 'red') ?>">
                <div class="kpi-num"><?= $pctGlobal ?>%</div>
                <div class="kpi-lbl">Material recibido vs propuesto</div>
            </div>
            <div class="kpi-card <?= $nSinRec === 0 ? 'green' : 'red' ?>">
                <div class="kpi-num"><?= $nSinRec ?></div>
                <div class="kpi-lbl">Ejecutores sin registrar</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="rm-tabs">
            <a href="<?= $tabUrl('resumen') ?>" class="rm-tab <?= $tab==='resumen'?'active':'' ?>"><i class="fa fa-bar-chart"></i> Resumen</a>
            <a href="<?= $tabUrl('ejecutores') ?>" class="rm-tab <?= $tab==='ejecutores'?'active':'' ?>"><i class="fa fa-users"></i> Por Ejecutor</a>
            <a href="<?= $tabUrl('materiales') ?>" class="rm-tab <?= $tab==='materiales'?'active':'' ?>"><i class="fa fa-cubes"></i> Por Material</a>
            <a href="<?= $tabUrl('historial') ?>" class="rm-tab <?= $tab==='historial'?'active':'' ?>"><i class="fa fa-list"></i> Historial</a>
        </div>

        <!-- ── TAB: RESUMEN ── -->
        <?php if ($tab === 'resumen'): ?>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:20px;">
            <!-- Gráfico materiales: propuesto vs recibido -->
            <div class="chart-box">
                <h5><i class="fa fa-cubes" style="color:#3a7bd5;"></i> Materiales: Propuesto vs Recibido</h5>
                <?php if (empty($chartMateriales)): ?>
                    <p style="color:#aaa; font-size:13px; text-align:center; padding:20px;">Sin datos</p>
                <?php else: ?>
                    <canvas id="chartMat" height="200"></canvas>
                <?php endif; ?>
            </div>
            <!-- Gráfico ejecutores: cobertura -->
            <div class="chart-box">
                <h5><i class="fa fa-users" style="color:#27ae60;"></i> Cobertura por Ejecutor</h5>
                <canvas id="chartEj" height="200"></canvas>
            </div>
        </div>

        <!-- Progreso por material -->
        <?php if (!empty($resumenMateriales)): ?>
        <div style="background:#fff; border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(40,80,140,.07); margin-bottom:20px;">
            <h5 style="font-size:14px; font-weight:700; color:#1a3a5c; margin:0 0 14px;">Estado por Material</h5>
            <?php foreach ($resumenMateriales as $rm): ?>
                <?php
                $fillClass = $rm['pct'] >= 80 ? 'fill-ok' : ($rm['pct'] >= 40 ? 'fill-warn' : 'fill-low');
                $fillW     = min(100, $rm['pct']);
                ?>
                <div class="mat-progress-row">
                    <div class="mat-prog-header">
                        <span class="mat-prog-name"><?= htmlspecialchars($rm['nombre'], ENT_QUOTES) ?></span>
                        <span class="mat-prog-nums">
                            <strong><?= number_format($rm['recibido'], 0) ?></strong> / <?= number_format($rm['propuesto'], 0) ?> unid.
                            &nbsp;·&nbsp; <strong><?= $rm['pct'] ?>%</strong>
                        </span>
                    </div>
                    <div class="mat-prog-bar">
                        <div class="mat-prog-fill <?= $fillClass ?>" style="width:<?= $fillW ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Ejecutores sin registrar -->
        <?php if (!empty($ejecutoresSinRec)): ?>
        <div style="background:#fff; border-radius:14px; padding:18px; box-shadow:0 2px 10px rgba(40,80,140,.07);">
            <h5 style="font-size:14px; font-weight:700; color:#c0392b; margin:0 0 12px;">
                <i class="fa fa-user-times"></i> Sin Registrar (<?= count($ejecutoresSinRec) ?>)
            </h5>
            <div class="no-rec-list">
                <?php foreach ($ejecutoresSinRec as $e): ?>
                    <span class="no-rec-chip"><i class="fa fa-user"></i> <?= htmlspecialchars(trim($e['nombre_ejecutor']), ENT_QUOTES) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── TAB: POR EJECUTOR ── -->
        <?php elseif ($tab === 'ejecutores'): ?>

        <div class="chart-box" style="margin-bottom:20px;">
            <h5><i class="fa fa-bar-chart" style="color:#3a7bd5;"></i> Unidades recibidas por ejecutor</h5>
            <?php if (empty($chartEjecutores)): ?>
                <p style="color:#aaa; font-size:13px; text-align:center; padding:20px;">Sin datos</p>
            <?php else: ?>
                <canvas id="chartEjBar" height="120"></canvas>
            <?php endif; ?>
        </div>

        <div style="background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 2px 10px rgba(40,80,140,.07);">
            <table class="det-table">
                <thead>
                    <tr>
                        <th>Ejecutor</th>
                        <th>Propuesto</th>
                        <th>Recibido</th>
                        <th>% Recibido</th>
                        <th>Registros</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($chartEjecutores as $ej):
                    $tieneRec   = (float)$ej['total_recibido'] > 0;
                    $ejUid      = (int)$ej['id_usuario'];
                    $lineasTip  = $tooltipPorEj[$ejUid] ?? [];
                    $ejProp     = $propuestoPorEj[$ejUid] ?? 0.0;
                    $ejRecib    = (float)$ej['total_recibido'];
                    $ejPct      = $ejProp > 0 ? round($ejRecib / $ejProp * 100, 1) : 0.0;
                    $pctColor   = $ejPct >= 80 ? '#27ae60' : ($ejPct >= 40 ? '#f39c12' : '#e74c3c');
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars(trim($ej['nombre']), ENT_QUOTES) ?></strong></td>
                        <td><?= $ejProp > 0 ? number_format($ejProp, 0) : '<span style="color:#aaa;">—</span>' ?></td>
                        <td>
                            <?php if (!empty($lineasTip)): ?>
                                <div class="tt-wrap">
                                    <strong><?= number_format($ejRecib, 0) ?></strong>
                                    <i class="fa fa-info-circle tt-icon"></i>
                                    <div class="tt-box">
                                        <?php foreach ($lineasTip as $l): ?>
                                            <span style="opacity:.7;"><?= htmlspecialchars($l['mat'], ENT_QUOTES) ?>:</span>
                                            <strong><?= number_format($l['total'], 0) ?> unid.</strong><br>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span style="color:#aaa;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ejProp > 0): ?>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <div style="flex:1; height:6px; background:#e8eef8; border-radius:3px; overflow:hidden; min-width:50px;">
                                        <div style="width:<?= min(100,$ejPct) ?>%; height:100%; background:<?= $pctColor ?>; border-radius:3px;"></div>
                                    </div>
                                    <span style="font-size:12px; font-weight:700; color:<?= $pctColor ?>; min-width:38px;"><?= $ejPct ?>%</span>
                                </div>
                            <?php else: ?>
                                <span style="color:#aaa; font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$ej['registros'] ?></td>
                        <td>
                            <?php if ($tieneRec): ?>
                                <span style="background:#d4edda; color:#155724; padding:2px 10px; border-radius:8px; font-size:11px; font-weight:700;">
                                    <i class="fa fa-check"></i> Registrado
                                </span>
                            <?php else: ?>
                                <span style="background:#f8d7da; color:#721c24; padding:2px 10px; border-radius:8px; font-size:11px; font-weight:700;">
                                    <i class="fa fa-times"></i> Sin registrar
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ── TAB: POR MATERIAL ── -->
        <?php elseif ($tab === 'materiales'): ?>

        <?php if (empty($resumenMateriales)): ?>
            <p style="color:#aaa; text-align:center; padding:40px;">Sin materiales propuestos para esta campaña.</p>
        <?php else: ?>
        <div class="chart-box" style="margin-bottom:20px;">
            <h5><i class="fa fa-bar-chart" style="color:#3a7bd5;"></i> Propuesto vs Recibido por Material</h5>
            <canvas id="chartMatBar" height="120"></canvas>
        </div>

        <div style="background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 2px 10px rgba(40,80,140,.07);">
            <table class="det-table">
                <thead>
                    <tr><th>Material</th><th>Propuesto</th><th>Recibido</th><th>% Recibido</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($resumenMateriales as $rmIdx => $rm):
                    $est       = $rm['pct'] >= 80 ? ['#d4edda','#155724','Completo'] : ($rm['pct'] >= 40 ? ['#fff3cd','#856404','Parcial'] : ['#f8d7da','#721c24','Bajo']);
                    $barColor  = $est[0] === '#d4edda' ? '#27ae60' : ($est[0] === '#fff3cd' ? '#f39c12' : '#e74c3c');
                    $ejLines   = $tooltipPorMat[$rm['nombre']] ?? [];
                    $matRowId  = 'mat-row-' . $rmIdx;
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($rm['nombre'], ENT_QUOTES) ?></strong></td>
                        <td><?= number_format($rm['propuesto'], 0) ?></td>
                        <td><?= number_format($rm['recibido'], 0) ?></td>
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; height:6px; background:#e8eef8; border-radius:3px; overflow:hidden;">
                                    <div style="width:<?= min(100,$rm['pct']) ?>%; height:100%; background:<?= $barColor ?>; border-radius:3px;"></div>
                                </div>
                                <span style="font-size:12px; font-weight:700; color:#1a3a5c; min-width:38px;"><?= $rm['pct'] ?>%</span>
                            </div>
                        </td>
                        <td>
                            <span style="background:<?= $est[0] ?>; color:<?= $est[1] ?>; padding:2px 10px; border-radius:8px; font-size:11px; font-weight:700;"><?= $est[2] ?></span>
                        </td>
                        <td>
                            <?php if (!empty($ejLines)): ?>
                                <button class="det-expand-btn" onclick="toggleDet('<?= $matRowId ?>')" title="Ver por ejecutor">
                                    <i class="fa fa-users"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($ejLines)): ?>
                    <tr class="det-expand-row" id="det-row-<?= $matRowId ?>">
                        <td colspan="6">
                            <div class="det-sub-wrap">
                                <table class="det-sub-table">
                                    <thead><tr><th>Ejecutor</th><th>Recibido</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($ejLines as $el): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($el['nombre'], ENT_QUOTES) ?></td>
                                            <td><strong><?= number_format($el['total'], 0) ?></strong> unid.</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── TAB: HISTORIAL ── -->
        <?php elseif ($tab === 'historial'): ?>

        <!-- Filtro por ejecutor -->
        <?php if (!empty($todosEjecutores)):
            $urlHistBase = '?id_campana='.$filtro_campana.'&tab=historial'.$eParam
                .($filtro_division ? '&division='.$filtro_division : '')
                .($filtro_fecha_ini ? '&fecha_ini='.$filtro_fecha_ini : '')
                .($filtro_fecha_fin ? '&fecha_fin='.$filtro_fecha_fin : '');
        ?>
        <div style="margin-bottom:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <label style="font-size:12px; color:#8fa0b5; font-weight:600; margin:0;">Ejecutor:</label>
            <select onchange="window.location='<?= $urlHistBase ?>&id_ejecutor='+this.value"
                    style="border:1px solid #d8e2ee; border-radius:7px; padding:5px 10px; font-size:13px;">
                <option value="0">Todos</option>
                <?php foreach ($todosEjecutores as $ej): ?>
                    <option value="<?= $ej['id'] ?>" <?= $filtro_ejecutor === (int)$ej['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(trim($ej['nombre_ejecutor']), ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($filtro_ejecutor > 0): ?>
                <a href="<?= $urlHistBase ?>" class="btn btn-xs btn-default"><i class="fa fa-times"></i> Quitar filtro</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($detalleRecepciones)): ?>
            <p style="color:#aaa; text-align:center; padding:40px;">Sin recepciones registradas<?= ($filtro_fecha_ini || $filtro_fecha_fin || $filtro_ejecutor) ? ' con los filtros aplicados' : '' ?>.</p>
        <?php else: ?>
        <div style="overflow-x:auto; background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(40,80,140,.07);">
            <table class="det-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Ejecutor</th>
                        <th>Región / Comuna</th>
                        <th>CD</th>
                        <th>Fecha retiro</th>
                        <th>Registrado</th>
                        <th>Materiales recibidos</th>
                        <th>N° Guía</th>
                        <th>Fotos</th>
                        <th>Observación</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($detalleRecepciones as $i => $d):
                    $recId    = (int)$d['id'];
                    $detLines = $detallesMap[$recId] ?? [];
                    $fotosRaw = $d['foto_guia_url'];
                    $fotos    = $fotosRaw ? (json_decode($fotosRaw, true) ?: [$fotosRaw]) : [];
                    $caption  = htmlspecialchars('Guía N° '.($d['numero_guia'] ?? '—').' — '.trim($d['nombre_ejecutor']), ENT_QUOTES);
                ?>
                    <tr>
                        <td style="color:#aaa; font-size:12px;"><?= $i + 1 ?></td>
                        <td>
                            <strong><?= htmlspecialchars(trim($d['nombre_ejecutor']), ENT_QUOTES) ?></strong><br>
                            <small style="color:#8fa0b5;"><?= htmlspecialchars($d['usuario'], ENT_QUOTES) ?></small>
                        </td>
                        <td style="font-size:12px;">
                            <?php if (!empty($d['nombre_comuna'])): ?>
                                <span style="color:#8fa0b5; font-size:11px;"><?= htmlspecialchars($d['nombre_region'] ?? '—', ENT_QUOTES) ?></span><br>
                                <strong><?= htmlspecialchars($d['nombre_comuna'], ENT_QUOTES) ?></strong>
                            <?php else: ?>
                                <span style="color:#aaa;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;">
                            <?= !empty($d['centro_distribucion']) ? htmlspecialchars($d['centro_distribucion'], ENT_QUOTES) : '<span style="color:#aaa;">—</span>' ?>
                        </td>
                        <td style="font-weight:700; color:#217346; white-space:nowrap;">
                            <?= date('d/m/Y', strtotime($d['fecha_recepcion'])) ?>
                        </td>
                        <td style="color:#aaa; font-size:11px; white-space:nowrap;">
                            <?= date('d/m/Y H:i', strtotime($d['created_at'])) ?>
                        </td>
                        <td style="font-size:12px; max-width:220px;">
                            <?php if (!empty($detLines)): ?>
                                <button class="det-expand-btn" onclick="toggleDet(<?= $recId ?>)" title="Ver desglose por material">
                                    <i class="fa fa-list-ul"></i>
                                </button>
                            <?php endif; ?>
                            <?= htmlspecialchars($d['resumen_mat'] ?? '—', ENT_QUOTES) ?>
                        </td>
                        <td style="font-size:12px; white-space:nowrap;">
                            <span id="guia-text-<?= $recId ?>"><?= htmlspecialchars($d['numero_guia'] ?? '—', ENT_QUOTES) ?></span>
                            <button class="btn-edit-guia det-expand-btn"
                                    data-id="<?= $recId ?>"
                                    data-guia="<?= htmlspecialchars($d['numero_guia'] ?? '', ENT_QUOTES) ?>"
                                    title="Editar N° Guía" style="margin-left:4px;">
                                <i class="fa fa-pencil"></i>
                            </button>
                        </td>
                        <td>
                            <?php if (!empty($fotos)): ?>
                                <div class="fotos-thumb-strip">
                                    <?php foreach ($fotos as $fIdx => $fUrl): ?>
                                        <img src="<?= htmlspecialchars($fUrl, ENT_QUOTES) ?>"
                                             class="js-foto-modal"
                                             data-fotos="<?= htmlspecialchars(json_encode($fotos), ENT_QUOTES) ?>"
                                             data-idx="<?= $fIdx ?>"
                                             data-caption="<?= $caption ?>"
                                             alt="Guía">
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#aaa; font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px; max-width:160px; color:#666;">
                            <?= htmlspecialchars($d['observacion'] ?? '—', ENT_QUOTES) ?>
                        </td>
                    </tr>
                    <?php if (!empty($detLines)): ?>
                    <tr class="det-expand-row" id="det-row-<?= $recId ?>">
                        <td colspan="10">
                            <div class="det-sub-wrap">
                                <table class="det-sub-table">
                                    <thead><tr><th>Material</th><th>Propuesto</th><th>Recibido</th><th>Diferencia</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($detLines as $dl):
                                        $diff = (float)$dl['cantidad_recibida'] - (float)$dl['cantidad_propuesta'];
                                        $diffClass = $diff >= 0 ? 'delta-ok' : 'delta-neg';
                                        $diffStr   = ($diff >= 0 ? '+' : '') . number_format($diff, 0);
                                    ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($dl['nombre_material'], ENT_QUOTES) ?></strong></td>
                                            <td><?= number_format((float)$dl['cantidad_propuesta'], 0) ?></td>
                                            <td><strong><?= number_format((float)$dl['cantidad_recibida'], 0) ?></strong></td>
                                            <td class="<?= $diffClass ?>"><?= $diffStr ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php endif; // fin tabs ?>

    <?php endif; // fin campSelec ?>
    </div><!-- /.rm-main -->
</div><!-- /.rm-layout -->

<!-- ── Modal editar N° Guía ── -->
<div id="modalEditGuia" style="display:none; position:fixed; inset:0; z-index:9998;
     background:rgba(0,0,0,.55); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:16px; padding:26px 24px; width:100%; max-width:380px;
                box-shadow:0 20px 50px rgba(0,0,0,.28);">
        <h5 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#1a3a5c;">
            <i class="fa fa-pencil" style="margin-right:7px; color:#3a7bd5;"></i>Editar N° Guía
        </h5>
        <p style="margin:0 0 14px; font-size:12px; color:#8fa0b5;">Modifica el número de guía de despacho para este registro.</p>
        <input type="text" id="inputEditGuia" placeholder="Ingrese el N° de guía"
               style="width:100%; border:1px solid #d8e2ee; border-radius:9px; padding:10px 13px;
                      font-size:14px; margin-bottom:16px; outline:none; box-sizing:border-box;">
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button onclick="cerrarEditGuia()"
                    style="border:1px solid #d8e2ee; border-radius:9px; padding:9px 18px;
                           font-size:13px; background:#fff; cursor:pointer; color:#555; font-weight:600;">
                Cancelar
            </button>
            <button onclick="guardarGuia()" id="btnGuardarGuia"
                    style="background:#3a7bd5; border:none; border-radius:9px; padding:9px 18px;
                           font-size:13px; color:#fff; font-weight:700; cursor:pointer;">
                <i class="fa fa-save" style="margin-right:5px;"></i>Guardar
            </button>
        </div>
    </div>
</div>

<!-- ── Modal foto guía (carrusel multi-foto) ── -->
<div id="modalFotoGuia" style="
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,.8); align-items:center; justify-content:center; padding:20px;">
    <div style="
        max-width:720px; width:100%; background:#fff;
        border-radius:16px; overflow:hidden;
        box-shadow:0 20px 60px rgba(0,0,0,.5);">
        <div style="
            display:flex; align-items:center; justify-content:space-between;
            padding:14px 18px; border-bottom:1px solid #e4e9f0; background:#f8fafc;">
            <span id="modalFotoCaption" style="font-size:13px; font-weight:700; color:#1a3a5c;"></span>
            <div style="display:flex; align-items:center; gap:10px;">
                <span id="modalFotoCounter" class="modal-foto-counter"></span>
                <button onclick="cerrarModalFoto()" style="
                    border:none; background:none; font-size:22px;
                    color:#888; cursor:pointer; line-height:1;">&times;</button>
            </div>
        </div>
        <div style="padding:16px; text-align:center; background:#f0f4f8; position:relative;">
            <button id="modalPrev" class="modal-nav-btn" onclick="modalNav(-1)"
                    style="position:absolute; left:12px; top:50%; transform:translateY(-50%);">&#8249;</button>
            <img id="modalFotoImg" src="" alt="Guía de despacho" style="
                max-width:100%; max-height:70vh;
                border-radius:10px; object-fit:contain;
                box-shadow:0 4px 20px rgba(0,0,0,.15);">
            <button id="modalNext" class="modal-nav-btn" onclick="modalNav(1)"
                    style="position:absolute; right:12px; top:50%; transform:translateY(-50%);">&#8250;</button>
        </div>
        <div style="padding:10px 18px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e4e9f0;">
            <div id="modalDots" style="display:flex; gap:6px;"></div>
            <a id="modalFotoLink" href="#" target="_blank"
               style="font-size:12px; color:#3a7bd5; text-decoration:none;">
                <i class="fa fa-external-link"></i> Abrir en nueva pestaña
            </a>
        </div>
    </div>
</div>
<script>
/* ── Modal carrusel ── */
var _modalFotos = [];
var _modalIdx   = 0;

function abrirModalFoto(fotos, idx, caption) {
    _modalFotos = fotos;
    _modalIdx   = idx;
    document.getElementById('modalFotoCaption').textContent = caption || 'Foto guía';
    renderModalFoto();
    var m = document.getElementById('modalFotoGuia');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function renderModalFoto() {
    var n   = _modalFotos.length;
    var url = _modalFotos[_modalIdx];
    document.getElementById('modalFotoImg').src   = url;
    document.getElementById('modalFotoLink').href = url;
    document.getElementById('modalFotoCounter').textContent = n > 1 ? (_modalIdx + 1) + ' / ' + n : '';
    document.getElementById('modalPrev').style.display = (n > 1) ? '' : 'none';
    document.getElementById('modalNext').style.display = (n > 1) ? '' : 'none';
    document.getElementById('modalPrev').disabled = (_modalIdx === 0);
    document.getElementById('modalNext').disabled = (_modalIdx === n - 1);
    /* dots */
    var dots = document.getElementById('modalDots');
    dots.innerHTML = '';
    if (n > 1) {
        for (var i = 0; i < n; i++) {
            var d = document.createElement('span');
            d.style.cssText = 'width:8px;height:8px;border-radius:50%;background:' + (i === _modalIdx ? '#3a7bd5' : '#ccc') + ';display:inline-block;cursor:pointer;';
            d.setAttribute('data-i', i);
            d.onclick = function() { _modalIdx = parseInt(this.getAttribute('data-i')); renderModalFoto(); };
            dots.appendChild(d);
        }
    }
}

function modalNav(dir) {
    _modalIdx = Math.max(0, Math.min(_modalFotos.length - 1, _modalIdx + dir));
    renderModalFoto();
}


function cerrarModalFoto() {
    document.getElementById('modalFotoGuia').style.display = 'none';
    document.getElementById('modalFotoImg').src = '';
    document.body.style.overflow = '';
}

document.querySelectorAll('.js-foto-modal').forEach(function (img) {
    img.addEventListener('click', function () {
        var fotos   = JSON.parse(this.dataset.fotos || '[]');
        var idx     = parseInt(this.dataset.idx || '0');
        var caption = this.dataset.caption || 'Foto(s) guía';
        if (!fotos.length && this.dataset.src) fotos = [this.dataset.src];
        abrirModalFoto(fotos, idx, caption);
    });
});

document.getElementById('modalFotoGuia').addEventListener('click', function (e) {
    if (e.target === this) cerrarModalFoto();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') cerrarModalFoto();
    if (e.key === 'ArrowRight') modalNav(1);
    if (e.key === 'ArrowLeft')  modalNav(-1);
});

/* ── Filas expandibles ── */
function toggleDet(id) {
    var row = document.getElementById('det-row-' + id);
    if (!row) return;
    var isHidden = row.style.display === 'none' || row.style.display === '';
    row.style.display = isHidden ? 'table-row' : 'none';
}

/* ── Editar N° Guía ── */
var _editGuiaId = null;

document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-edit-guia');
    if (!btn) return;
    _editGuiaId = btn.dataset.id;
    document.getElementById('inputEditGuia').value = btn.dataset.guia || '';
    document.getElementById('modalEditGuia').style.display = 'flex';
    setTimeout(function () { document.getElementById('inputEditGuia').focus(); }, 80);
});

function cerrarEditGuia() {
    document.getElementById('modalEditGuia').style.display = 'none';
    _editGuiaId = null;
}

function guardarGuia() {
    if (!_editGuiaId) return;
    var nuevoGuia = document.getElementById('inputEditGuia').value.trim();
    var btn = document.getElementById('btnGuardarGuia');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin" style="margin-right:5px;"></i>Guardando…';

    fetch('mod_recepcion_actualizar_guia.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(_editGuiaId) + '&numero_guia=' + encodeURIComponent(nuevoGuia)
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.status === 'success') {
            var span = document.getElementById('guia-text-' + _editGuiaId);
            if (span) span.textContent = nuevoGuia || '—';
            var editBtn = document.querySelector('.btn-edit-guia[data-id="' + _editGuiaId + '"]');
            if (editBtn) editBtn.dataset.guia = nuevoGuia;
            cerrarEditGuia();
        } else {
            alert(data.message || 'Error al guardar.');
        }
    })
    .catch(function () { alert('Error de conexión.'); })
    .finally(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save" style="margin-right:5px;"></i>Guardar';
    });
}

document.getElementById('modalEditGuia').addEventListener('click', function (e) {
    if (e.target === this) cerrarEditGuia();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && document.getElementById('modalEditGuia').style.display === 'flex') {
        guardarGuia();
    }
});
</script>

<?php if ($campSelec && !empty($chartMateriales)): ?>
<script>
const MAT_LABELS   = <?= json_encode(array_column($chartMateriales, 'label')) ?>;
const MAT_PROP     = <?= json_encode(array_column($chartMateriales, 'propuesto')) ?>;
const MAT_RECIB    = <?= json_encode(array_column($chartMateriales, 'recibido')) ?>;
const EJ_LABELS    = <?= json_encode(array_column($chartEjecutores, 'nombre')) ?>;
const EJ_RECIB     = <?= json_encode(array_column($chartEjecutores, 'total_recibido')) ?>;
const N_ASIG       = <?= $nAsig ?>;
const N_CON_REC    = <?= $nConRec ?>;

const chartOpts = {
    responsive: true,
    plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
    scales: {
        x: { ticks: { font: { size: 11 } } },
        y: { ticks: { font: { size: 11 } }, beginAtZero: true }
    }
};

/* Gráfico materiales (resumen tab) */
const ctxMat = document.getElementById('chartMat');
if (ctxMat) {
    new Chart(ctxMat, {
        type: 'bar',
        data: {
            labels: MAT_LABELS,
            datasets: [
                { label: 'Propuesto', data: MAT_PROP,  backgroundColor: 'rgba(180,200,240,.6)', borderColor: '#3a7bd5', borderWidth:1 },
                { label: 'Recibido',  data: MAT_RECIB, backgroundColor: 'rgba(39,174,96,.55)',  borderColor: '#27ae60', borderWidth:1 }
            ]
        },
        options: { ...chartOpts, scales: { ...chartOpts.scales, x: { stacked: false } } }
    });
}


const ctxEj = document.getElementById('chartEj');
if (ctxEj) {
    new Chart(ctxEj, {
        type: 'doughnut',
        data: {
            labels: ['Con recepción', 'Sin registrar'],
            datasets: [{ data: [N_CON_REC, N_ASIG - N_CON_REC],
                backgroundColor: ['#27ae60','#e74c3c'], borderWidth: 0 }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font:{size:11} } } } }
    });
}

/* Bar ejecutores (tab ejecutores) */
const ctxEjBar = document.getElementById('chartEjBar');
if (ctxEjBar) {
    new Chart(ctxEjBar, {
        type: 'bar',
        data: {
            labels: EJ_LABELS,
            datasets: [{ label: 'Unidades recibidas', data: EJ_RECIB,
                backgroundColor: EJ_RECIB.map(v => v > 0 ? 'rgba(39,174,96,.6)' : 'rgba(231,76,60,.5)'),
                borderColor: EJ_RECIB.map(v => v > 0 ? '#27ae60' : '#e74c3c'), borderWidth: 1 }]
        },
        options: { ...chartOpts, plugins: { legend: { display: false } } }
    });
}

/* Bar materiales (tab materiales) */
const ctxMatBar = document.getElementById('chartMatBar');
if (ctxMatBar) {
    new Chart(ctxMatBar, {
        type: 'bar',
        data: {
            labels: MAT_LABELS,
            datasets: [
                { label: 'Propuesto', data: MAT_PROP,  backgroundColor: 'rgba(180,200,240,.6)', borderColor: '#3a7bd5', borderWidth:1 },
                { label: 'Recibido',  data: MAT_RECIB, backgroundColor: 'rgba(39,174,96,.55)',  borderColor: '#27ae60', borderWidth:1 }
            ]
        },
        options: chartOpts
    });
}
</script>
<?php endif; ?>
</body>
</html>
