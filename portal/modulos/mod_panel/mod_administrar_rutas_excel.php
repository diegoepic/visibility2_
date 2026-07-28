<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$profile = strtolower(trim((string)($_SESSION['perfil_nombre'] ?? '')));
$canManage = in_array($profile, ['editor', 'coordinador', 'administrador', 'admin'], true);
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Administración de sets de rutas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        body{background:#f4f7fb;color:#17324d}.page{padding:26px}.card{border:0;border-radius:14px;box-shadow:0 4px 18px rgba(22,50,79,.08)}
        .card-header{background:#004aad;color:#fff;border-radius:14px 14px 0 0!important;font-weight:700}.table thead th{background:#0b376b;color:#fff;white-space:nowrap}
        .status-activo{background:#d9f7e7;color:#08783f}.status-finalizado{background:#e7eaf0;color:#485362}.badge{font-size:.78rem}.help{font-size:.88rem;color:#66788a}
    </style>
</head>
<body>
<main class="container-fluid page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div><h2 class="mb-1"><i class="fa-solid fa-route me-2 text-primary"></i>Administración de sets de rutas</h2><div class="text-muted">Carga y publica planificaciones para la visual cliente.</div></div>
        <a class="btn btn-outline-primary" href="/visibility2/portal/modulos/mod_panel/rutas_excel_sets_api.php?action=template"><i class="fa-solid fa-file-arrow-down me-2"></i>Descargar plantilla</a>
    </div>

    <div id="alertBox"></div>

    <?php if ($canManage): ?>
    <section class="card mb-4">
        <div class="card-header"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Crear nuevo set</div>
        <div class="card-body">
            <form id="uploadForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="row g-3">
                    <div class="col-lg-4"><label class="form-label">Nombre del set</label><input class="form-control" name="nombre" maxlength="150" required placeholder="Ej. Rutas agosto Región Costa"></div>
                    <div class="col-lg-3"><label class="form-label">División</label><select class="form-select" name="id_division" id="newDivision" required><option value="">Seleccionar</option></select></div>
                    <div class="col-lg-3"><label class="form-label">Subdivisión</label><select class="form-select" name="id_subdivision" id="newSubdivision" required disabled><option value="">Seleccionar división</option></select></div>
                    <div class="col-lg-2"><label class="form-label">Estado inicial</label><select class="form-select" name="estado"><option value="activo">Activo</option><option value="finalizado">Finalizado</option></select></div>
                    <div class="col-lg-7"><label class="form-label">Descripción (opcional)</label><input class="form-control" name="descripcion" maxlength="500" placeholder="Periodo, campaña u observaciones"></div>
                    <div class="col-lg-5"><label class="form-label">Archivo Excel o CSV</label><input class="form-control" type="file" name="archivo" accept=".xlsx,.xls,.csv" required></div>
                </div>
                <div class="help mt-3">Columnas requeridas: <strong>CODIGO LOCAL</strong>, <strong>USUARIO</strong> y <strong>FECHA VISITA</strong>. ORDEN VISITA es opcional. El código se valida dentro de la división y el usuario dentro de la división/subdivisión seleccionadas.</div>
                <button class="btn btn-primary mt-3" id="uploadButton"><i class="fa-solid fa-upload me-2"></i>Crear set</button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <section class="card">
        <div class="card-header"><i class="fa-solid fa-layer-group me-2"></i>Sets cargados</div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-4"><select id="filterDivision" class="form-select"><option value="">Todas las divisiones</option></select></div>
                <div class="col-md-4"><select id="filterSubdivision" class="form-select" disabled><option value="">Todas las subdivisiones</option></select></div>
                <div class="col-md-2"><select id="filterStatus" class="form-select"><option value="">Todos los estados</option><option value="activo">Activos</option><option value="finalizado">Finalizados</option></select></div>
                <div class="col-md-2"><button id="reloadButton" class="btn btn-outline-primary w-100"><i class="fa-solid fa-rotate me-2"></i>Actualizar</button></div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0"><thead><tr><th>Set</th><th>Alcance</th><th>Estado</th><th>Filas</th><th>Creado</th><th>Acciones</th></tr></thead><tbody id="setsBody"><tr><td colspan="6" class="text-center py-4">Cargando...</td></tr></tbody></table>
            </div>
        </div>
    </section>
</main>
<script>
const API='/visibility2/portal/modulos/mod_panel/rutas_excel_sets_api.php';
const CSRF=<?= json_encode($_SESSION['csrf_token']) ?>;
const CAN_MANAGE=<?= $canManage ? 'true' : 'false' ?>;
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
function alertMessage(message,type='success'){document.getElementById('alertBox').innerHTML=`<div class="alert alert-${type} alert-dismissible fade show">${esc(message)}<button class="btn-close" data-bs-dismiss="alert"></button></div>`;window.scrollTo({top:0,behavior:'smooth'});}
async function json(url,options={}){const r=await fetch(url,options);let data;try{data=await r.json()}catch(e){throw new Error(`Respuesta inválida del servidor (HTTP ${r.status}).`)}if(!r.ok||!data.ok)throw new Error(data.message||`HTTP ${r.status}`);return data;}
function fillSelect(select,rows,placeholder,keep=false){const current=keep?select.value:'';select.innerHTML=`<option value="">${placeholder}</option>`+rows.map(r=>`<option value="${r.id}">${esc(r.nombre)}</option>`).join('');if(current)select.value=current;select.disabled=false;}
async function loadCatalogs(divisionId='',target=null){const data=await json(`${API}?action=catalogs${divisionId?`&id_division=${encodeURIComponent(divisionId)}`:''}`);if(!divisionId){fillSelect(document.getElementById('filterDivision'),data.divisions,'Todas las divisiones');if(CAN_MANAGE)fillSelect(document.getElementById('newDivision'),data.divisions,'Seleccionar');}if(target)fillSelect(target,data.subdivisions,target.id==='filterSubdivision'?'Todas las subdivisiones':'Seleccionar');return data;}
async function loadSets(){const params=new URLSearchParams({action:'sets'});const d=document.getElementById('filterDivision').value,s=document.getElementById('filterSubdivision').value,e=document.getElementById('filterStatus').value;if(d)params.set('id_division',d);if(s)params.set('id_subdivision',s);if(e)params.set('estado',e);const body=document.getElementById('setsBody');body.innerHTML='<tr><td colspan="6" class="text-center py-4">Cargando...</td></tr>';try{const data=await json(`${API}?${params}`);body.innerHTML=data.data.length?data.data.map(r=>{const next=r.estado==='activo'?'finalizado':'activo';return `<tr><td><strong>${esc(r.nombre)}</strong><div class="small text-muted">#${r.id} · ${esc(r.descripcion||r.archivo_original||'')}</div></td><td>${esc(r.division)}<div class="small text-muted">${esc(r.subdivision)}</div></td><td><span class="badge status-${r.estado}">${esc(r.estado.toUpperCase())}</span></td><td><strong>${r.filas_validas}</strong> válidas<div class="small text-danger">${r.filas_rechazadas} rechazadas</div></td><td>${esc(r.created_at)}<div class="small text-muted">${esc((r.creado_por_nombre||'').trim())}</div></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" title="Descargar data" href="${API}?action=download&id_set=${r.id}"><i class="fa-solid fa-download"></i></a> ${Number(r.filas_rechazadas)>0?`<a class="btn btn-sm btn-outline-danger" title="Descargar rechazadas" href="${API}?action=rejections&id_set=${r.id}"><i class="fa-solid fa-triangle-exclamation"></i></a>`:''} ${CAN_MANAGE?`<button class="btn btn-sm btn-outline-secondary" title="Cambiar a ${next}" onclick="changeStatus(${r.id},'${next}')"><i class="fa-solid fa-${next==='finalizado'?'flag-checkered':'play'}"></i></button>`:''}</td></tr>`}).join(''):'<tr><td colspan="6" class="text-center py-4 text-muted">No hay sets para los filtros seleccionados.</td></tr>';}catch(e){body.innerHTML=`<tr><td colspan="6" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`;}}
async function changeStatus(id,status){if(!confirm(`¿Cambiar este set a ${status.toUpperCase()}?`))return;const fd=new FormData();fd.set('action','status');fd.set('csrf_token',CSRF);fd.set('id_set',id);fd.set('estado',status);try{const data=await json(API,{method:'POST',body:fd});alertMessage(data.message);loadSets();}catch(e){alertMessage(e.message,'danger');}}
document.getElementById('filterDivision').addEventListener('change',async e=>{const target=document.getElementById('filterSubdivision');if(e.target.value)await loadCatalogs(e.target.value,target);else{target.innerHTML='<option value="">Todas las subdivisiones</option>';target.disabled=true;}loadSets();});
document.getElementById('filterSubdivision').addEventListener('change',loadSets);document.getElementById('filterStatus').addEventListener('change',loadSets);document.getElementById('reloadButton').addEventListener('click',loadSets);
if(CAN_MANAGE){document.getElementById('newDivision').addEventListener('change',async e=>{const target=document.getElementById('newSubdivision');if(e.target.value)await loadCatalogs(e.target.value,target);else{target.innerHTML='<option value="">Seleccionar división</option>';target.disabled=true;}});document.getElementById('uploadForm').addEventListener('submit',async e=>{e.preventDefault();const button=document.getElementById('uploadButton');button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Procesando';try{const data=await json(API,{method:'POST',body:new FormData(e.target)});alertMessage(`${data.message} ${data.valid_rows} filas válidas y ${data.rejected_rows} rechazadas.`);e.target.reset();document.getElementById('newSubdivision').disabled=true;loadSets();}catch(err){alertMessage(err.message,'danger');}finally{button.disabled=false;button.innerHTML='<i class="fa-solid fa-upload me-2"></i>Crear set';}});}
(async()=>{try{await loadCatalogs();await loadSets();}catch(e){alertMessage(e.message,'danger')}})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
