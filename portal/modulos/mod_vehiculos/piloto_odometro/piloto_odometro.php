<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once __DIR__ . '/lib_piloto.php';
date_default_timezone_set('America/Santiago');

mysqli_set_charset($conn, 'utf8mb4');

$tablaOk  = piloto_tabla_existe($conn);
$modelos  = $GLOBALS['PILOTO_MODELOS'] ?? [];
$keyLista = (OPENAI_API_KEY && OPENAI_API_KEY !== 'PEGA_AQUI_TU_API_KEY');

// URL web (root-relativa) para mostrar la foto en el navegador
function piloto_url_web(?string $v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/^https?:\/\//i', $v)) return $v;
    $v = str_replace('\\', '/', $v);
    $v = ltrim($v, '/');
    $v = preg_replace('#^(visibility2/app/)+#i', '', $v);
    return '/visibility2/app/' . $v;
}

// Cargar fotos ya procesadas, agrupadas por foto (una fila por foto, N modelos)
$fotos = [];
if ($tablaOk) {
    $res = mysqli_query($conn,
        "SELECT * FROM piloto_odometro_lecturas
         ORDER BY fecha_visita DESC, resp_foto_id DESC, modelo ASC");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $fid = (int)$r['resp_foto_id'];
            if (!isset($fotos[$fid])) {
                $fotos[$fid] = [
                    'resp_foto_id'   => $fid,
                    'patente'        => $r['patente'],
                    'nombre_usuario' => $r['nombre_usuario'],
                    'fecha_visita'   => $r['fecha_visita'],
                    'km_tipeado'     => $r['km_tipeado'],
                    'km_real'        => $r['km_real'],
                    'foto_url'       => $r['foto_url'],
                    'modelos'        => [],
                ];
            }
            $fotos[$fid]['modelos'][$r['modelo']] = $r;
            if (($r['km_real'] ?? '') !== '') $fotos[$fid]['km_real'] = $r['km_real'];
        }
        mysqli_free_result($res);
    }
}
$totalProc = $tablaOk ? piloto_total_procesadas($conn) : 0;
$costoTot  = $tablaOk ? piloto_costo_total($conn) : 0.0;
$cap       = PILOTO_SAMPLE_CAP;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Piloto lectura de odómetro (IA) — Visibility</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
    body { background:#f4f6f9; font-family:'Segoe UI',sans-serif; }
    .page-header { background:linear-gradient(135deg,#1f2937,#111827); color:#fff; border-radius:20px; padding:24px; margin-bottom:20px; }
    .card-modern { border:none; border-radius:18px; box-shadow:0 8px 24px rgba(15,23,42,.08); }
    .kpi { border-radius:16px; padding:16px; background:#fff; box-shadow:0 6px 18px rgba(15,23,42,.06); }
    .kpi strong { font-size:24px; }
    .odo-thumb { width:120px; height:120px; object-fit:cover; border-radius:12px; border:1px solid #d1d5db; cursor:zoom-in; background:#f3f4f6; }
    .badge-conf-hi { background:#dcfce7; color:#166534; }
    .badge-conf-lo { background:#fef9c3; color:#854d0e; }
    .badge-ver-ok { background:#dcfce7; color:#166534; }
    .badge-ver-no { background:#fee2e2; color:#991b1b; }
    .badge-ver-pd { background:#e5e7eb; color:#374151; }
    .modelo-box { border:1px solid #e5e7eb; border-radius:12px; padding:8px 10px; margin-bottom:6px; }
    .km-big { font-size:20px; font-weight:800; letter-spacing:.5px; }
    .foto-zoom { position:fixed; inset:0; background:rgba(3,7,18,.92); z-index:9999; display:none; align-items:center; justify-content:center; padding:20px; }
    .foto-zoom.show { display:flex; }
    .foto-zoom img { max-width:96vw; max-height:92vh; object-fit:contain; border-radius:12px; }
    .txt-mono { font-variant-numeric:tabular-nums; }
    #barra { height:14px; border-radius:999px; background:#e5e7eb; overflow:hidden; }
    #barraFill { height:100%; width:0%; background:linear-gradient(90deg,#16a34a,#22c55e); transition:width .3s; }
</style>
</head>
<body>
<div class="container-fluid p-4" style="max-width:1500px;margin:0 auto">

    <div class="page-header">
        <h3 class="mb-1"><i class="fa-solid fa-gauge-high me-2"></i> Piloto — Lectura de odómetro con IA</h3>
        <p class="mb-0" style="color:#d1d5db">Encuesta 138 · compara modelos de OpenAI contra la foto del odómetro y mide precisión y costo real.</p>
    </div>

    <?php if (!$tablaOk): ?>
        <div class="alert alert-warning card-modern">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            Falta la tabla <code>piloto_odometro_lecturas</code>. Corre primero
            <code>scripts/19_piloto_odometro.sql</code> en la base de datos y recarga esta página.
        </div>
    <?php else: ?>

    <?php if (!$keyLista): ?>
        <div class="alert alert-danger card-modern">
            <i class="fa-solid fa-key me-1"></i>
            La API key de OpenAI no está configurada. Edita <code>config_piloto.php</code> en el server
            (o define la variable de entorno <code>OPENAI_API_KEY</code>) antes de procesar.
        </div>
    <?php endif; ?>

    <!-- CONTROLES DE PROCESAMIENTO -->
    <div class="card card-modern mb-4">
        <div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-md-3">
                    <div class="kpi">
                        <small class="text-muted d-block">Fotos procesadas</small>
                        <strong id="statProc"><?= (int)$totalProc ?></strong> / <?= (int)$cap ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi">
                        <small class="text-muted d-block">Costo acumulado (USD)</small>
                        <strong id="statCosto">$<?= number_format($costoTot, 4) ?></strong>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex gap-2 flex-wrap">
                        <button id="btnProcesar" class="btn btn-dark btn-lg" <?= $keyLista ? '' : 'disabled' ?>>
                            <i class="fa-solid fa-play me-1"></i> Procesar fotos
                        </button>
                        <button id="btnPausar" class="btn btn-outline-secondary btn-lg" disabled>
                            <i class="fa-solid fa-pause me-1"></i> Pausar
                        </button>
                        <button class="btn btn-outline-dark btn-lg" onclick="location.reload()">
                            <i class="fa-solid fa-rotate me-1"></i> Refrescar
                        </button>
                    </div>
                    <div class="mt-3" id="barra"><div id="barraFill"></div></div>
                    <small class="text-muted" id="procMsg">Modelos en prueba:
                        <?= h(implode(' · ', array_map(fn($m) => $m['label'].' ('.$m['id'].')', $modelos))) ?>.
                        Procesa en lotes de <?= (int)PILOTO_CHUNK ?> fotos. Freno de costo: $<?= number_format(PILOTO_MAX_COSTO_TOTAL_USD, 2) ?>.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="card card-modern mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0"><i class="fa-solid fa-chart-simple me-2"></i> Resultados (precisión y costo)</h5>
                <button class="btn btn-sm btn-outline-dark" onclick="cargarResumen()"><i class="fa-solid fa-rotate me-1"></i> Actualizar</button>
            </div>
            <div id="resumenBox" class="text-muted">Cargando KPIs…</div>
            <small class="text-muted d-block mt-2">
                * “Acierto” se calcula solo sobre fotos con verdad de terreno ya marcada abajo.
                “Coincide con tipeado” no requiere revisión humana pero solo indica acuerdo IA↔ejecutor, no que sea correcto.
            </small>
        </div>
    </div>

    <!-- ADJUDICACIÓN -->
    <div class="card card-modern">
        <div class="card-body">
            <h5 class="mb-3"><i class="fa-solid fa-user-check me-2"></i> Revisión / verdad de terreno
                <span class="text-muted fs-6">(<?= count($fotos) ?> fotos)</span></h5>

            <?php if (empty($fotos)): ?>
                <p class="text-muted">Aún no hay fotos procesadas. Presiona <b>Procesar fotos</b> para empezar.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="width:140px">Foto odómetro</th>
                            <th>Vehículo / visita</th>
                            <th style="width:110px">Km tipeado</th>
                            <?php foreach ($modelos as $m): ?>
                                <th>Lectura IA — <?= h($m['label']) ?></th>
                            <?php endforeach; ?>
                            <th style="width:230px">Verdad de terreno</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fotos as $f):
                        $urlWeb = piloto_url_web($f['foto_url']); ?>
                        <tr data-foto="<?= (int)$f['resp_foto_id'] ?>">
                            <td>
                                <?php if ($urlWeb): ?>
                                    <img src="<?= h($urlWeb) ?>" class="odo-thumb" loading="lazy"
                                         onclick="zoomFoto('<?= h($urlWeb) ?>')" alt="odómetro">
                                <?php else: ?>
                                    <span class="text-muted small">sin foto</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold"><?= h($f['patente'] ?: '—') ?></div>
                                <div class="small text-muted"><?= h($f['nombre_usuario'] ?: '') ?></div>
                                <div class="small text-muted"><?= h($f['fecha_visita'] ?: '') ?></div>
                            </td>
                            <td><span class="km-big txt-mono"><?= h($f['km_tipeado'] !== '' ? $f['km_tipeado'] : '—') ?></span></td>

                            <?php foreach ($modelos as $m):
                                $row = $f['modelos'][$m['id']] ?? null;
                                $kmIa = $row['km_ia'] ?? null;
                                $conf = $row && $row['confianza'] !== null ? (float)$row['confianza'] : null;
                                $tipo = $row['tipo_odometro'] ?? '';
                                $err  = $row['error_msg'] ?? '';
                                $ver  = $row['veredicto'] ?? 'pendiente';
                            ?>
                                <td>
                                    <div class="modelo-box">
                                        <?php if ($err): ?>
                                            <div class="text-danger small"><i class="fa-solid fa-circle-exclamation me-1"></i><?= h($err) ?></div>
                                        <?php else: ?>
                                            <div class="km-big txt-mono km-ia"><?= h($kmIa !== null && $kmIa !== '' ? $kmIa : '—') ?></div>
                                            <div class="small">
                                                <?php if ($conf !== null): ?>
                                                    <span class="badge <?= $conf >= PILOTO_UMBRAL_CONFIANZA ? 'badge-conf-hi' : 'badge-conf-lo' ?>">
                                                        conf <?= number_format($conf, 2) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span class="text-muted"><?= h($tipo) ?></span>
                                                <?php
                                                    $vc = $ver === 'correcta' ? 'badge-ver-ok' : ($ver === 'incorrecta' ? 'badge-ver-no' : 'badge-ver-pd');
                                                ?>
                                                <span class="badge <?= $vc ?> ver-badge"><?= h($ver) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>

                            <td>
                                <div class="input-group input-group-sm mb-1">
                                    <input type="text" class="form-control txt-mono km-real-input"
                                           value="<?= h($f['km_real'] ?? '') ?>" placeholder="km real">
                                    <button class="btn btn-success" onclick="guardarReal(this)"><i class="fa-solid fa-check"></i></button>
                                </div>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if ($f['km_tipeado'] !== ''): ?>
                                        <button class="btn btn-outline-secondary btn-sm py-0" onclick="setReal(this,'<?= h($f['km_tipeado']) ?>')">= tipeado</button>
                                    <?php endif; ?>
                                    <?php foreach ($modelos as $m):
                                        $kmIa = $f['modelos'][$m['id']]['km_ia'] ?? '';
                                        if ($kmIa === '' || $kmIa === null) continue; ?>
                                        <button class="btn btn-outline-secondary btn-sm py-0" onclick="setReal(this,'<?= h($kmIa) ?>')">= <?= h($m['label']) ?></button>
                                    <?php endforeach; ?>
                                    <button class="btn btn-outline-danger btn-sm py-0" onclick="marcarIlegible(this)">ilegible</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; // tablaOk ?>
</div>

<div class="foto-zoom" id="fotoZoom" onclick="this.classList.remove('show')">
    <img id="fotoZoomImg" src="" alt="">
</div>

<script>
const AJAX = 'ajax_piloto.php';
const CAP = <?= (int)$cap ?>;
let corriendo = false, pausar = false;

function zoomFoto(url){ document.getElementById('fotoZoomImg').src = url; document.getElementById('fotoZoom').classList.add('show'); }

function norm(v){ return String(v ?? '').replace(/[.\s]/g,''); }

/* ---------- PROCESAMIENTO EN LOTES ---------- */
document.getElementById('btnProcesar')?.addEventListener('click', () => { pausar = false; loopProcesar(); });
document.getElementById('btnPausar')?.addEventListener('click', () => { pausar = true; });

async function loopProcesar(){
    if (corriendo) return;
    corriendo = true;
    const btn = document.getElementById('btnProcesar');
    const btnP = document.getElementById('btnPausar');
    btn.disabled = true; btnP.disabled = false;

    while (!pausar) {
        let data;
        try {
            const resp = await fetch(AJAX + '?action=procesar_lote', { method:'POST' });
            data = await resp.json();
        } catch(e) {
            document.getElementById('procMsg').innerHTML = '<span class="text-danger">Error de red: '+e+'</span>';
            break;
        }
        if (!data.ok && data.freno) {
            document.getElementById('procMsg').innerHTML = '<span class="text-danger">'+data.msg+'</span>';
            break;
        }
        if (!data.ok) {
            document.getElementById('procMsg').innerHTML = '<span class="text-danger">'+(data.msg||'Error')+'</span>';
            break;
        }
        document.getElementById('statProc').textContent = data.total_procesadas;
        document.getElementById('statCosto').textContent = '$' + Number(data.costo_total).toFixed(4);
        document.getElementById('barraFill').style.width = Math.min(100, (data.total_procesadas / CAP) * 100) + '%';
        document.getElementById('procMsg').textContent =
            'Procesadas ' + data.total_procesadas + ' / ' + CAP + ' · costo $' + Number(data.costo_total).toFixed(4);

        if (data.rate_limited) {
            document.getElementById('procMsg').innerHTML =
                '<span class="text-warning"><b>OpenAI está limitando la tasa (rate limit)</b> — típico de gpt-4o-mini con imágenes. '
                + 'El avance quedó guardado. Espera ~1 minuto y presiona <b>Procesar</b> de nuevo, '
                + 'o quita el modelo económico de <code>config_piloto.php</code>.</span>';
            break;
        }
        if (data.completado) {
            document.getElementById('procMsg').innerHTML += ' — <b>Listo. Recargando…</b>';
            setTimeout(() => location.reload(), 1200);
            break;
        }
    }
    corriendo = false;
    btn.disabled = false; btnP.disabled = true;
    if (pausar) document.getElementById('procMsg').textContent += ' (pausado)';
}

/* ---------- ADJUDICACIÓN ---------- */
function setReal(btn, val){
    const tr = btn.closest('tr');
    tr.querySelector('.km-real-input').value = val;
}

async function guardarReal(btn){
    const tr = btn.closest('tr');
    const fid = tr.getAttribute('data-foto');
    const val = tr.querySelector('.km-real-input').value.trim();
    if (!val) { alert('Ingresa el km real o marca ilegible.'); return; }
    const fd = new FormData();
    fd.append('action','guardar_real'); fd.append('resp_foto_id', fid); fd.append('km_real', val);
    const r = await (await fetch(AJAX, {method:'POST', body:fd})).json();
    if (!r.ok) { alert(r.msg||'Error'); return; }
    pintarVeredictos(tr, val, false);
}

async function marcarIlegible(btn){
    const tr = btn.closest('tr');
    const fid = tr.getAttribute('data-foto');
    const fd = new FormData();
    fd.append('action','guardar_real'); fd.append('resp_foto_id', fid); fd.append('ilegible','1');
    const r = await (await fetch(AJAX, {method:'POST', body:fd})).json();
    if (!r.ok) { alert(r.msg||'Error'); return; }
    pintarVeredictos(tr, null, true);
}

function pintarVeredictos(tr, real, ilegible){
    tr.querySelectorAll('td').forEach(td => {
        const iaEl = td.querySelector('.km-ia');
        const badge = td.querySelector('.ver-badge');
        if (!iaEl || !badge) return;
        let ver, cls;
        if (ilegible) { ver='ilegible'; cls='badge-ver-pd'; }
        else {
            const ok = norm(iaEl.textContent) === norm(real) && norm(real) !== '';
            ver = ok ? 'correcta' : 'incorrecta';
            cls = ok ? 'badge-ver-ok' : 'badge-ver-no';
        }
        badge.className = 'badge ver-badge ' + cls;
        badge.textContent = ver;
    });
    tr.querySelector('.km-real-input').classList.add('is-valid');
}

/* ---------- KPIs ---------- */
async function cargarResumen(){
    const box = document.getElementById('resumenBox');
    try {
        const r = await (await fetch(AJAX + '?action=resumen')).json();
        if (!r.ok) { box.innerHTML = '<span class="text-danger">'+(r.msg||'Error')+'</span>'; return; }
        box.innerHTML = renderResumen(r.resumen);
    } catch(e) { box.innerHTML = '<span class="text-danger">Error: '+e+'</span>'; }
}

function pct(a,b){ b=Number(b); return b>0 ? (100*Number(a)/b).toFixed(1)+'%' : '—'; }

function renderResumen(res){
    let html = '<div class="table-responsive"><table class="table table-sm align-middle">';
    html += '<thead><tr>'
        + '<th>Modelo</th><th>Fotos</th><th>Adjudicadas</th>'
        + '<th>Acierto</th><th>Acierto digital</th><th>Acierto análogo</th>'
        + '<th>Coincide c/ tipeado</th><th>Dudosas (&lt;'+<?= json_encode(PILOTO_UMBRAL_CONFIANZA) ?>+')</th>'
        + '<th>Errores</th><th>Costo prom/img</th><th>Proyección mensual</th>'
        + '</tr></thead><tbody>';
    (res.modelos||[]).forEach(m => {
        const costoProm = Number(m.costo_prom||0);
        html += '<tr>'
            + '<td><b>'+m.label+'</b><br><small class="text-muted">'+m.modelo+'</small></td>'
            + '<td>'+ (m.n||0) +'</td>'
            + '<td>'+ (m.adjudicadas||0) +'</td>'
            + '<td><b>'+ pct(m.correctas, m.adjudicadas) +'</b><br><small class="text-muted">'+(m.correctas||0)+'/'+(m.adjudicadas||0)+'</small></td>'
            + '<td>'+ pct(m.ok_digital, m.adj_digital) +'</td>'
            + '<td>'+ pct(m.ok_analogico, m.adj_analogico) +'</td>'
            + '<td>'+ pct(m.coincide_tipeado, m.n) +'</td>'
            + '<td>'+ (m.dudosas||0) +'</td>'
            + '<td>'+ (m.errores||0) +'</td>'
            + '<td>$'+ costoProm.toFixed(5) +'</td>'
            + '<td><b>$'+ Number(m.proyeccion_mensual||0).toFixed(2) +'</b><br><small class="text-muted">'+res.proyeccion_imagenes+' img/mes</small></td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    html += '<div class="small text-muted">Costo total del piloto hasta ahora: <b>$'+Number(res.costo_total||0).toFixed(4)+'</b> · '+res.total_fotos+' fotos procesadas.</div>';
    return html;
}

<?php if ($tablaOk): ?>cargarResumen();<?php endif; ?>
</script>
</body>
</html>
