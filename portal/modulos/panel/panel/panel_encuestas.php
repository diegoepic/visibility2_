<?php
// panel/panel_encuestas.php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: /login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Encuestas</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/bootstrap.min.css">
<link rel="stylesheet" href="/assets/datatables.min.css">
<style>
body{background:#f8f9fa;}
.card{border-radius:14px; box-shadow:0 3px 12px rgba(0,0,0,.06);}
.badge{font-size:.8rem;}
.select-multi{min-height:120px;}
.sticky-topbar{position:sticky;top:0;z-index:1020;background:#f8f9fa;}
.small{color:#6c757d;}
</style>
</head>
<body>

<div class="container-fluid py-3">
  <div class="sticky-topbar py-2">
    <h3 class="mb-3">Analítica de Encuestas</h3>

    <div class="card p-3 mb-3">
      <form id="filtros" class="row g-2 align-items-end">
        <div class="col-12 col-md-2">
          <label class="form-label">Modo</label>
          <select class="form-select" name="mode" id="mode">
            <option value="global">Global (por Set)</option>
            <option value="campaign">Campaña</option>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">División</label>
          <select class="form-select" multiple id="division_ids"></select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Subdivisión</label>
          <select class="form-select" multiple id="subdivision_ids"></select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Tipo Form.</label>
          <select class="form-select" multiple id="tipos"></select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label">Campañas</label>
          <select class="form-select" multiple id="campaign_ids"></select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Desde</label>
          <input type="date" class="form-control" id="fecha_desde">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Hasta</label>
          <input type="date" class="form-control" id="fecha_hasta">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Distrito</label>
          <select class="form-select" multiple id="distrito_ids"></select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Jefe Venta</label>
          <select class="form-select" multiple id="jefe_venta_ids"></select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Usuario</label>
          <select class="form-select" multiple id="usuario_ids"></select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Cod. Local</label>
          <input type="text" class="form-control" id="local_codigo" placeholder="Ej: L-00123">
        </div>

        <div class="col-12"><hr></div>

        <div class="col-12 col-md-4">
          <label class="form-label">
            Preguntas <span class="badge bg-secondary" id="badgeModo">Global</span>
          </label>
          <div class="d-flex gap-2">
            <input class="form-control" id="search_q" placeholder="Buscar pregunta...">
            <button type="button" class="btn btn-outline-secondary" id="btnLoadQuestions">Cargar</button>
          </div>
          <div class="form-text">En Global se listan Sets; en Campaña se listan preguntas de las campañas seleccionadas.</div>

          <div class="mt-2">
            <label class="form-label small">Tipos de pregunta</label>
            <div id="qtypes" class="d-flex flex-wrap gap-2"></div>
          </div>

          <div class="form-check mt-2" id="adhocWrap">
            <input class="form-check-input" type="checkbox" id="include_adhoc">
            <label class="form-check-label" for="include_adhoc">Incluir ad-hoc (id_set NULL / por firma)</label>
          </div>
        </div>

        <div class="col-12 col-md-8">
          <label class="form-label">Seleccionadas</label>
          <select class="form-select select-multi" multiple id="questions_selected"></select>

          <div class="mt-2">
            <label class="form-label">Agrupar por</label>
            <div class="d-flex flex-wrap gap-3" id="group_by">
              <label class="form-check"><input class="form-check-input" type="checkbox" value="division" checked> <span class="ms-1">División</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" value="subdivision"> <span class="ms-1">Subdivisión</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" value="campania" checked> <span class="ms-1">Campaña</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" value="distrito"> <span class="ms-1">Distrito</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" value="jefe_venta"> <span class="ms-1">Jefe Venta</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" value="usuario"> <span class="ms-1">Usuario</span></label>
              <label class="form-check"><input class="form-check-input" type="checkbox" value="local"> <span class="ms-1">Local</span></label>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary" type="button" id="btnRun">Ejecutar</button>
            <button class="btn btn-outline-success" type="button" id="btnExcel">Exportar Excel</button>
            <button class="btn btn-outline-danger" type="button" id="btnPDF">Exportar PDF</button>
          </div>
        </div>

      </form>
    </div>
  </div>

  <div class="card p-3">
    <div class="small mb-2" id="hint"></div>
    <table id="tbl" class="table table-striped table-bordered w-100"></table>
  </div>
</div>

<script src="/assets/bootstrap.bundle.min.js"></script>
<script src="/assets/datatables.min.js"></script>
<script>
const API = '/panel/api';
let DATASET_CACHE = null;

function valSel(el){ return Array.from(el.selectedOptions).map(o=>o.value).filter(Boolean); }
function valChk(containerId){ return Array.from(document.querySelectorAll('#'+containerId+' input:checked')).map(i=>i.value); }

async function postJSON(url, payload){
  const r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
  if (!r.ok) throw new Error('HTTP '+r.status);
  return await r.json();
}

async function loadFilters(){
  const payload = {
    division_ids: valSel(document.getElementById('division_ids')),
    subdivision_ids: valSel(document.getElementById('subdivision_ids')),
    tipos: valSel(document.getElementById('tipos')),
    fecha_desde: document.getElementById('fecha_desde').value || null,
    fecha_hasta: document.getElementById('fecha_hasta').value || null
  };
  const j = await postJSON(API+'/filters.php', payload);

  const fill = (sel, rows, value='id', text='nombre')=>{
    const cur = new Set(valSel(sel));
    sel.innerHTML = '';
    rows.forEach(r=>{
      const opt = document.createElement('option');
      opt.value = r[value];
      opt.textContent = r[text] ?? (r[value]+'');
      if (cur.has(opt.value)) opt.selected = true;
      sel.appendChild(opt);
    });
  };

  fill(document.getElementById('division_ids'), j.divisiones);
  fill(document.getElementById('subdivision_ids'), j.subdivisiones);
  fill(document.getElementById('tipos'), j.tipos_form.map(x=>({id:x,nombre:x})));
  fill(document.getElementById('campaign_ids'), j.campanias, 'id', 'nombre');
  fill(document.getElementById('distrito_ids'), j.distritos);
  fill(document.getElementById('jefe_venta_ids'), j.jefes_venta);
  fill(document.getElementById('usuario_ids'), j.usuarios);

  const qtypesDiv = document.getElementById('qtypes'); qtypesDiv.innerHTML='';
  j.question_types.forEach(q=>{
    const lab = document.createElement('label'); lab.className='form-check form-check-inline';
    lab.innerHTML = `<input class="form-check-input" type="checkbox" value="${q.id}"> <span class="ms-1">${q.id} - ${q.name}</span>`;
    qtypesDiv.appendChild(lab);
  });
}

function commonFilters(){
  return {
    mode: document.getElementById('mode').value,
    division_ids: valSel(document.getElementById('division_ids')),
    subdivision_ids: valSel(document.getElementById('subdivision_ids')),
    tipos: valSel(document.getElementById('tipos')),
    campaign_ids: valSel(document.getElementById('campaign_ids')),
    fecha_desde: document.getElementById('fecha_desde').value || null,
    fecha_hasta: document.getElementById('fecha_hasta').value || null,
    distrito_ids: valSel(document.getElementById('distrito_ids')),
    jefe_venta_ids: valSel(document.getElementById('jefe_venta_ids')),
    usuario_ids: valSel(document.getElementById('usuario_ids')),
    local_codigo: document.getElementById('local_codigo').value || null,
    group_by: valChk('group_by'),
    include_adhoc: document.getElementById('include_adhoc').checked ? 1 : 0
  };
}

async function loadQuestions(){
  const mode = document.getElementById('mode').value;
  document.getElementById('badgeModo').textContent = (mode==='global'?'Global':'Campaña');
  document.getElementById('adhocWrap').style.display = (mode==='global'?'block':'none');

  const payload = commonFilters();
  payload.qtypes = valChk('qtypes');
  payload.search = document.getElementById('search_q').value || '';

  const j = await postJSON(API+'/questions.php', payload);
  const sel = document.getElementById('questions_selected');
  const prev = new Set(valSel(sel));
  sel.innerHTML = '';

  if (mode==='global'){
    (j.canonicos||[]).forEach(q=>{
      const opt = document.createElement('option');
      opt.value = 'set:'+q.set_qid;
      opt.textContent = `[QS-${q.set_qid}] ${q.sample_text} (T${q.qtype}) [${q.n_campanias} campañas]`;
      if (prev.has(opt.value)) opt.selected = true;
      sel.appendChild(opt);
    });
    if (document.getElementById('include_adhoc').checked){
      (j.adhoc||[]).forEach(q=>{
        const opt = document.createElement('option');
        opt.value = 'sig:'+q.signature;
        opt.textContent = `⚠ Ad-hoc: ${q.sample_text} (T${q.qtype}) [${q.n_campanias}]`;
        if (prev.has(opt.value)) opt.selected = true;
        sel.appendChild(opt);
      });
    }
  } else {
    (j.items||[]).forEach(q=>{
      const opt = document.createElement('option');
      opt.value = 'q:'+q.form_question_id;
      const tag = q.set_qid ? `[QS-${q.set_qid}]` : `⚠`;
      opt.textContent = `${tag} ${q.label} (T${q.qtype})`;
      if (prev.has(opt.value)) opt.selected = true;
      sel.appendChild(opt);
    });
  }
}

let table = null;

async function runQuery(){
  const payload = commonFilters();
  const sel = Array.from(document.getElementById('questions_selected').selectedOptions).map(o=>o.value);

  payload.set_qids = [];
  payload.form_question_ids = [];
  payload.signatures = [];

  sel.forEach(v=>{
     if (v.startsWith('set:')) payload.set_qids.push(parseInt(v.slice(4),10));
     else if (v.startsWith('q:')) payload.form_question_ids.push(parseInt(v.slice(2),10));
     else if (v.startsWith('sig:')) payload.signatures.push(v.slice(4));
  });

  const data = await postJSON(API+'/data.php', payload);
  DATASET_CACHE = data;

  const optRows = data.option_counts || [];
  const numRows = data.numeric_stats || [];
  const optRowsAd = data.adhoc_option_counts || [];
  const numRowsAd = data.adhoc_numeric_stats || [];

  // columnas auto
  const cols = new Set();
  [...optRows, ...numRows, ...optRowsAd, ...numRowsAd].forEach(r=>Object.keys(r).forEach(k=>cols.add(k)));
  const columns = Array.from(cols).map(k=>({title:k, data:k}));

  if (table) { table.destroy(); document.getElementById('tbl').innerHTML=''; }
  table = new DataTable('#tbl', {
    data: [...optRows, ...numRows, ...optRowsAd, ...numRowsAd],
    columns,
    pageLength: 25,
    responsive: true,
    order: []
  });

  document.getElementById('hint').textContent =
    `Filas: ${optRows.length + numRows.length + optRowsAd.length + numRowsAd.length}. Modo: ${data.meta?.mode}.`;
}

async function exportExcel(){
  if (!DATASET_CACHE) return;
  const r = await fetch(API+'/export_excel.php', {
    method:'POST',
    body: new URLSearchParams({ dataset: JSON.stringify(DATASET_CACHE) })
  });
  const blob = await r.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'reporte_encuestas.xlsx';
  a.click();
  URL.revokeObjectURL(a.href);
}

async function exportPDF(){
  if (!DATASET_CACHE) return;
  const r = await fetch(API+'/export_pdf.php', {
    method:'POST',
    body: new URLSearchParams({ dataset: JSON.stringify(DATASET_CACHE) })
  });
  const blob = await r.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'reporte_encuestas.pdf';
  a.click();
  URL.revokeObjectURL(a.href);
}

// Eventos
document.getElementById('btnLoadQuestions').addEventListener('click', loadQuestions);
document.getElementById('btnRun').addEventListener('click', runQuery);
document.getElementById('btnExcel').addEventListener('click', exportExcel);
document.getElementById('btnPDF').addEventListener('click', exportPDF);
document.getElementById('mode').addEventListener('change', loadQuestions);

// Inicial
loadFilters().then(loadQuestions);
</script>
</body>
</html>
