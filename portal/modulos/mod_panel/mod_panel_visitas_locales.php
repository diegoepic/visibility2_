<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Panel de Visitas a Locales</title>
    <link rel="stylesheet" href="panel_visitas_dashboard.css">
    <style>
        html,body{min-height:100%;margin:0}body{padding:28px 18px;background:#eaf4f7;font-family:Inter,"Segoe UI",Arial,sans-serif}.visdash{margin:0 auto}.standalone-note{max-width:1280px;margin:0 auto 14px;color:#617680;font-size:12px;text-align:right}@media(max-width:700px){body{padding:12px 0}.standalone-note{padding:0 15px}}
    </style>
</head>
<body>
    <div class="standalone-note">Panel independiente · sin carga de mapa ni marcadores</div>
    <section class="visdash" id="panelVisitasLocales" data-api="panel_visitas_data.php">
        <div class="visdash-hero">
            <div><span class="visdash-kicker">CONTROL DE RUTAS</span><h1>Panel de Visitas a Locales</h1><p id="visdashSubtitle">Selecciona el alcance y carga los registros.</p></div>
            <div class="visdash-updated"><span>ACTUALIZADO</span><strong><?= date('d-m-Y') ?></strong></div>
        </div>
        <div class="visdash-body">
            <div class="visdash-scope">
                <label><span>División</span><select id="visdashDivision"><option value="">Cargando divisiones...</option></select></label>
                <label><span>Subdivisión</span><select id="visdashSubdivision" disabled><option value="">Todas las subdivisiones</option></select></label>
                <label><span>Estado campaña</span><select id="visdashCampaignStatus"><option value="1">En curso</option><option value="3">Finalizadas</option><option value="0">Ambos estados</option></select></label>
                <button type="button" id="visdashLoad">Cargar registros</button>
            </div>
            <div class="visdash-feedback" id="visdashFeedback" role="status">Selecciona una división para comenzar.</div>
            <div class="visdash-filters">
                <label><span>Merchandiser</span><select id="visdashUser" disabled><option value="">Todos los merchandiser</option></select><small id="visdashUserCount">0 merchandiser en los resultados</small></label>
                <label><span>Día planificado</span><select id="visdashDate" disabled><option value="">Todos los días</option></select><small id="visdashDateCount">0 días con ruta programada</small></label>
            </div>
            <div class="visdash-kpis">
                <article class="visdash-kpi kpi-visited"><div><span>LOCALES VISITADOS</span><b>◎</b></div><strong id="kpiVisited">0.0%</strong><p id="kpiVisitedDetail">0 de 0 locales programados</p><i><u id="barVisited"></u></i></article>
                <article class="visdash-kpi kpi-executed"><div><span>LOCALES EJECUTADOS</span><b>▣</b></div><strong id="kpiExecuted">0.0%</strong><p id="kpiExecutedDetail">0 gestiones válidas del programa</p><i><u id="barExecuted"></u></i></article>
                <article class="visdash-kpi kpi-pending"><div><span>LOCALES PENDIENTES</span><b>⌾</b></div><strong id="kpiPending">0.0%</strong><p id="kpiPendingDetail">0 locales sin visitar</p><i><u id="barPending"></u></i></article>
            </div>
            <div class="visdash-charts">
                <article class="visdash-card"><h2>Cumplimiento del período</h2><p>% del total programado</p><div class="vertical-chart" id="complianceChart"></div></article>
                <article class="visdash-card"><h2>Estado de gestión</h2><p>% de los locales visitados</p><div class="horizontal-chart" id="statusChart"></div></article>
            </div>
            <div class="visdash-tables">
                <article class="visdash-card"><h2>Cumplimiento por merchandiser</h2><p>Pendientes, visitados y cumplimiento del período seleccionado.</p><div class="visdash-table-scroll"><table><thead><tr><th>Merchan</th><th>Pendientes</th><th>Visitados</th><th>Total</th><th>% Cumpl.</th></tr></thead><tbody id="merchTableBody"></tbody></table></div></article>
                <article class="visdash-card"><h2>Detalle por estado de gestión</h2><p id="statusTableCaption">Porcentaje sobre locales visitados.</p><div class="visdash-table-scroll"><table><thead><tr><th>Estado</th><th>Locales</th><th>% del visitado</th></tr></thead><tbody id="statusTableBody"></tbody></table></div></article>
            </div>
        </div>
    </section>
    <script src="panel_visitas_dashboard.js"></script>
</body>
</html>
