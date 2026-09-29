<?php
/**
 * Centro de recuperación.
 *
 * Antes, un trabajo que fallaba de forma definitiva se quedaba en IndexedDB en estado
 * 'error' y no había NADA que se pudiera hacer con él desde el teléfono: no se veía qué
 * era, no se podía reintentar y no se podía sacar. Esa era la razón de fondo por la que
 * "Limpiar la cola" daba miedo y por la que la purga al cerrar sesión era peligrosa.
 *
 * Esta pantalla es la salida. Lee la cola local (no consulta al servidor, así que funciona
 * sin red) y por cada trabajo atascado muestra qué era, cuándo fue, por qué falló —en
 * castellano, no en códigos— y qué se puede hacer con él.
 */
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/lib/asset_version.php';

$usuario_id = (int)$_SESSION['usuario_id'];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Envíos con problemas</title>
  <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
  <style>
    body{background:#f4f6f8;color:#2b2f33;font-size:15px;padding-bottom:40px}
    .rec-top{background:#fff;border-bottom:1px solid #dfe3e8;padding:12px 16px;
             display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:10}
    .rec-top h1{font-size:18px;margin:0;font-weight:600;flex:1}
    .rec-wrap{max-width:760px;margin:0 auto;padding:16px}
    .rec-vacio{background:#fff;border:1px solid #dfe3e8;border-radius:6px;padding:32px 20px;
               text-align:center;color:#5b6570}
    .rec-vacio i{font-size:38px;color:#3c9a5f;display:block;margin-bottom:10px}
    .rec-grupo{margin-bottom:22px}
    .rec-grupo h2{font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
                  color:#5b6570;margin:0 0 8px}
    .rec-job{background:#fff;border:1px solid #dfe3e8;border-left-width:4px;border-radius:5px;
             padding:12px 14px;margin-bottom:10px}
    .rec-job--conflicto{border-left-color:#d38b28}
    .rec-job--error{border-left-color:#b02a37}
    .rec-job--bloqueado{border-left-color:#5b6570}
    .rec-job h3{font-size:15px;font-weight:600;margin:0 0 4px}
    .rec-meta{font-size:13px;color:#6b7480;margin-bottom:8px}
    .rec-razon{background:#f7f8fa;border-radius:4px;padding:8px 10px;font-size:14px;margin-bottom:10px}
    .rec-razon code{font-size:11px;color:#8a929c;background:none;padding:0}
    .rec-acciones{display:flex;flex-wrap:wrap;gap:6px}
    .rec-aviso{background:#fff6e5;border:1px solid #f0d9a8;border-radius:5px;padding:10px 12px;
               font-size:14px;margin-bottom:16px}
    .rec-barra{background:#fff;border:1px solid #dfe3e8;border-radius:5px;padding:10px 12px;
               margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
    .rec-barra span{flex:1;font-size:14px;color:#5b6570}
    .rec-toast{position:fixed;left:50%;transform:translateX(-50%);bottom:20px;background:#2b2f33;
               color:#fff;padding:10px 16px;border-radius:4px;font-size:14px;z-index:100;max-width:92%}
  </style>
</head>
<body>

<div class="rec-top">
  <a href="index_pruebas.php" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i></a>
  <h1>Envíos con problemas</h1>
  <button type="button" class="btn btn-default btn-sm" id="recRefrescar" title="Volver a leer la cola">
    <i class="fa fa-refresh"></i>
  </button>
</div>

<div class="rec-wrap">
  <div class="rec-barra" id="recBarra" style="display:none">
    <span id="recResumen"></span>
    <button type="button" class="btn btn-sm btn-default" id="recExportar">
      <i class="fa fa-upload"></i> Enviar copia a soporte
    </button>
    <button type="button" class="btn btn-sm btn-default" id="recCopiar">
      <i class="fa fa-clipboard"></i> Copiar
    </button>
    <button type="button" class="btn btn-sm btn-info" id="recReintentarTodo">
      <i class="fa fa-repeat"></i> Reintentar todos
    </button>
  </div>
  <div id="recLista"></div>
</div>

<script src="assets/js/db.js"></script>
<script src="assets/js/v2_cache.js"></script>
<script src="assets/js/offline-queue.js"></script>
<script>
  window.__USUARIO_ID = <?php echo $usuario_id; ?>;
  window.CSRF_TOKEN   = "<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>";
</script>
<script>
(function(){
  'use strict';

  // Estados que dejan un trabajo atascado. 'queued' y 'running' NO están: esos avanzan solos.
  var ATASCADOS = {
    conflict:         { grupo: 'conflicto', titulo: 'Necesitan una decisión' },
    failed_permanent: { grupo: 'error',     titulo: 'No se pudieron enviar' },
    error:            { grupo: 'error',     titulo: 'No se pudieron enviar' },
    blocked_auth:     { grupo: 'bloqueado', titulo: 'Esperando que vuelvas a entrar' },
    blocked_csrf:     { grupo: 'bloqueado', titulo: 'Esperando que vuelvas a entrar' }
  };

  // El diccionario es el corazón de esta pantalla. Sin él el ejecutor lee
  // 'TRANSACTION_FAILED' y no sabe si perdió el trabajo o si sólo tiene que esperar.
  var RAZONES = {
    ASSIGNMENT_CHANGED:   'Este local o material ya no está asignado a ti. Avisa a tu supervisor antes de reintentar.',
    VALOR_EXCEDE:         'La cantidad informada supera la comprometida. Hay que corregirla y volver a gestionar.',
    VALOR_INVALIDO:       'La cantidad informada no es un número válido. Vuelve a gestionar el local.',
    MOTIVO_REQUERIDO:     'Falta el motivo de implementación parcial.',
    FOTO_REQUERIDA:       'Faltaba adjuntar una foto obligatoria.',
    FOTO_MUY_GRANDE:      'La foto pesa o mide demasiado. Vuelve a tomarla desde la app.',
    FOTO_INVALIDA:        'El archivo enviado no era una imagen válida. Vuelve a tomar la foto.',
    FOTO_FORMATO:         'El formato de la foto no está admitido. Vuelve a tomarla desde la app.',
    ETAPA_INVALIDA:       'La etapa del material ya no es válida. Vuelve a abrir la gestión.',
    PREGUNTA_NO_EXISTE:   'La campaña cambió y una pregunta ya no existe. Vuelve a abrir la gestión.',
    OPCION_INVALIDA:      'La campaña cambió y una opción de respuesta ya no existe. Vuelve a abrir la gestión.',
    RESPUESTA_INVALIDA:   'Una respuesta llegó con un formato que el servidor no entiende.',
    DUPLICATE_PHOTO:      'Esa foto ya se usó en otro local de esta campaña. Hay que tomar una nueva.',
    OWNER_MISMATCH:       'Esta gestión la creó otra persona en este teléfono. No se puede enviar con tu sesión.',
    MISSING_DEPENDENCY:   'Se perdió el vínculo con la visita. Hay que rehacer la gestión.',
    TRANSACTION_FAILED:   'El servidor tuvo un problema pasajero. Se puede reintentar.',
    IDEMPOTENCY_MISMATCH: 'Se reutilizó un identificador de envío con datos distintos. Hay que rehacer la gestión.',
    REQUEST_IN_PROGRESS:  'El servidor todavía estaba procesando el envío anterior. Se puede reintentar.',
    NO_SESSION:           'Tu sesión caducó. Vuelve a entrar y se enviará solo.',
    CSRF_INVALID:         'Tu sesión caducó. Vuelve a entrar y se enviará solo.',
    CONFLICT:             'El servidor rechazó el envío por un conflicto y no dijo cuál. Envía la copia a soporte con el botón de arriba.',
    VALIDATION:           'El servidor rechazó los datos del envío.',
    UNKNOWN:              'No se registró el motivo. Envía la copia a soporte con el botón de arriba.'
  };

  var TIPOS = {
    procesar_gestion:             'Gestión de local',
    procesar_gestion_pruebas:     'Gestión de local',
    create_visita:                'Apertura de visita',
    upload_material_foto:         'Foto de material',
    upload_material_foto_pruebas: 'Foto de material',
    pregunta_foto:                'Foto de encuesta',
    material_foto_delete:         'Borrado de foto de material',
    pregunta_foto_delete:         'Borrado de foto de encuesta',
    add_material:                 'Material agregado'
  };

  function $(sel){ return document.querySelector(sel); }

  var yaExporto = false;   // "Descartar" no se habilita sin haber descargado la copia antes

  var ESCAPES = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' };
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return ESCAPES[c]; });
  }

  function toast(msg){
    var el = document.createElement('div');
    el.className = 'rec-toast';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function(){ el.remove(); }, 3500);
  }

  function cuando(ts){
    if (!ts) return 'sin fecha';
    var d = new Date(ts);
    var hora = d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
    var mismoDia = d.toDateString() === new Date().toDateString();
    return mismoDia ? ('hoy ' + hora) : (d.toLocaleDateString('es-CL') + ' ' + hora);
  }

  // Los nombres salen del caché local, no del servidor: esta pantalla tiene que funcionar
  // sin red, que es justo cuando hace falta.
  var cacheNombres = { locals: {}, campaigns: {} };
  function nombreDe(store, id){
    if (!id) return Promise.resolve(null);
    if (cacheNombres[store][id] !== undefined) return Promise.resolve(cacheNombres[store][id]);
    if (!window.V2Cache) { cacheNombres[store][id] = null; return Promise.resolve(null); }
    return V2Cache.get(store, Number(id))
      .then(function(row){
        var n = (row && row.nombre) ? row.nombre : null;
        cacheNombres[store][id] = n;
        return n;
      })
      .catch(function(){ cacheNombres[store][id] = null; return null; });
  }

  function describir(job){
    var meta = job.meta || {};
    var tipo = TIPOS[job.type] || TIPOS[meta.kind] || 'Envío';
    return Promise.all([
      nombreDe('locals', meta.local_id),
      nombreDe('campaigns', meta.form_id)
    ]).then(function(res){
      var local = res[0], camp = res[1];
      var partes = [];
      if (local)              partes.push(local);
      else if (meta.local_id) partes.push('Local ' + meta.local_id);
      if (camp)               partes.push(camp);
      else if (meta.form_id)  partes.push('Campaña ' + meta.form_id);
      var nFotos = Array.isArray(job.files) ? job.files.length : 0;
      if (nFotos) partes.push(nFotos + (nFotos === 1 ? ' foto' : ' fotos'));
      return tipo + (partes.length ? ' · ' + partes.join(' · ') : '');
    });
  }

  function razonDe(job){
    var err = job.lastError || {};
    var cod = err.code || 'UNKNOWN';
    return { codigo: cod, texto: RAZONES[cod] || RAZONES.UNKNOWN, detalle: err.message || '' };
  }

  async function leerAtascados(){
    if (!window.AppDB) return [];
    var out = [];
    var estados = Object.keys(ATASCADOS);
    for (var i = 0; i < estados.length; i++) {
      var lote = [];
      try { lote = (await AppDB.listByStatus(estados[i])) || []; } catch(_) { lote = []; }
      for (var k = 0; k < lote.length; k++) {
        var j = (AppDB.normalizeJob ? AppDB.normalizeJob(lote[k]) : lote[k]) || {};
        j.__estado = estados[i];
        out.push(j);
      }
    }
    out.sort(function(a, b){ return (b.createdAt || 0) - (a.createdAt || 0); });
    return out;
  }

  async function pintar(){
    var lista = $('#recLista');
    lista.innerHTML = '<p class="text-muted">Leyendo la cola…</p>';

    var jobs = await leerAtascados();

    if (!jobs.length) {
      $('#recBarra').style.display = 'none';
      lista.innerHTML =
        '<div class="rec-vacio"><i class="fa fa-check-circle"></i>' +
        '<strong>No hay envíos con problemas.</strong><br>' +
        'Todo lo que gestionaste está enviado o en camino.</div>';
      return;
    }

    $('#recBarra').style.display = '';
    $('#recResumen').textContent = (jobs.length === 1)
      ? '1 envío necesita tu atención'
      : jobs.length + ' envíos necesitan tu atención';

    // Agrupar por familia, no por estado técnico.
    var grupos = {};
    jobs.forEach(function(j){
      var g = ATASCADOS[j.__estado];
      if (!grupos[g.grupo]) grupos[g.grupo] = { titulo: g.titulo, jobs: [] };
      grupos[g.grupo].jobs.push(j);
    });

    var html = '';
    if (grupos.bloqueado) {
      html += '<div class="rec-aviso"><i class="fa fa-info-circle"></i> ' +
              'Hay envíos esperando a que vuelvas a iniciar sesión. Sal y entra de nuevo: ' +
              'se mandan solos, no hay que hacer nada más.</div>';
    }

    ['bloqueado', 'conflicto', 'error'].forEach(function(clave){
      var g = grupos[clave];
      if (!g) return;
      html += '<div class="rec-grupo"><h2>' + esc(g.titulo) + ' (' + g.jobs.length + ')</h2>';
      g.jobs.forEach(function(j){
        var r = razonDe(j);
        // Los conflictos no se reintentan: reenviarlos da exactamente el mismo rechazo.
        var puedeReintentar = (clave !== 'conflicto');
        html +=
          '<div class="rec-job rec-job--' + clave + '" data-id="' + esc(j.id) + '">' +
            '<h3 data-desc>Cargando…</h3>' +
            '<div class="rec-meta">' + esc(cuando(j.createdAt)) +
              ' · ' + (j.attempts || 0) + ' intento(s)</div>' +
            '<div class="rec-razon">' + esc(r.texto) +
              '<br><code>' + esc(r.codigo) +
              (r.detalle ? ' · ' + esc(r.detalle.slice(0, 120)) : '') + '</code></div>' +
            '<div class="rec-acciones">' +
              (puedeReintentar
                ? '<button type="button" class="btn btn-xs btn-info" data-accion="reintentar">' +
                  '<i class="fa fa-repeat"></i> Reintentar</button>'
                : '') +
              '<button type="button" class="btn btn-xs btn-danger" data-accion="descartar">' +
                '<i class="fa fa-trash"></i> Descartar</button>' +
            '</div>' +
          '</div>';
      });
      html += '</div>';
    });

    lista.innerHTML = html;

    // Las descripciones piden nombres al caché (async): se rellenan después de pintar, para
    // que la lista aparezca de inmediato aunque el caché esté frío.
    jobs.forEach(function(j){
      var fila = lista.querySelector('[data-id="' + CSS.escape(String(j.id)) + '"]');
      var nodo = fila ? fila.querySelector('[data-desc]') : null;
      if (nodo) describir(j).then(function(t){ nodo.textContent = t; });
    });
  }

  async function reintentar(id){
    await AppDB.update(id, {
      status: 'queued',
      attempts: 0,
      nextTryAt: 0,
      startedAt: null,
      finishedAt: null,
      updatedAt: Date.now()
    });
    try { await Queue.flushNow(); } catch(_) {}
    toast(navigator.onLine ? 'Reintentando…' : 'Guardado: se enviará al recuperar señal.');
    await pintar();
  }

  async function descartar(id){
    if (!yaExporto) {
      toast('Primero descarga la copia: al descartar, el envío se borra del teléfono.');
      return;
    }
    if (!window.confirm('Se borra de este teléfono y no se puede recuperar. ¿Descartar?')) return;

    // Queda constancia local del descarte; se incluye en la próxima copia que se descargue.
    try {
      var previos = JSON.parse(localStorage.getItem('v2_descartes') || '[]');
      previos.push({ id: id, cuando: new Date().toISOString(), usuario: window.__USUARIO_ID || null });
      localStorage.setItem('v2_descartes', JSON.stringify(previos.slice(-200)));
    } catch(_) {}

    await AppDB.remove(id);
    toast('Envío descartado.');
    await pintar();
  }

  document.addEventListener('click', async function(ev){
    var btn = ev.target.closest ? ev.target.closest('[data-accion]') : null;
    if (!btn) return;
    var fila = btn.closest('.rec-job');
    var id = fila ? fila.dataset.id : null;
    if (!id) return;
    btn.disabled = true;
    try {
      if (btn.dataset.accion === 'reintentar') await reintentar(id);
      if (btn.dataset.accion === 'descartar')  await descartar(id);
    } finally {
      btn.disabled = false;
    }
  });

  $('#recRefrescar').addEventListener('click', pintar);

  /* El aviso dice lo que REALMENTE pasó. Antes afirmaba siempre "Copia descargada",
     incluso dentro de la APK, donde el envoltorio Android rechaza la descarga de un blob:
     con "Tipo de descarga no soportada". El ejecutor veía los dos avisos seguidos y se
     quedaba con el segundo, creyendo que tenía un archivo que nunca existió. */
  $('#recExportar').addEventListener('click', async function(){
    try {
      var r = await Queue.exportarRespaldoLocal();
      yaExporto = true;
      if (r.subido) {
        toast('Copia enviada a soporte (' + r.total + ' envío(s)).');
      } else {
        toast('No se pudo enviar la copia. Usa "Copiar" y pégala en un mensaje a soporte.');
      }
    } catch(_) {
      toast('No se pudo generar la copia.');
    }
  });

  /* Salida manual. Es la única que funciona dentro de la APK sin conexión, y el equipo de
     terreno ya vive en WhatsApp: copiar y pegar les sale más natural que un archivo. */
  $('#recCopiar').addEventListener('click', async function(){
    try {
      var r = await Queue.exportarRespaldoLocal({ subir:false, descargar:false, portapapeles:true });
      yaExporto = true;
      toast(r.copiado
        ? 'Copiado (' + r.total + ' envío(s)). Pégalo en un mensaje a soporte.'
        : 'Este teléfono no permitió copiar.');
    } catch(_) {
      toast('No se pudo generar la copia.');
    }
  });

  $('#recReintentarTodo').addEventListener('click', async function(){
    var jobs = await leerAtascados();
    // Los conflictos NO se reintentan en masa: cada uno necesita una decisión distinta.
    var reintentables = jobs.filter(function(j){ return j.__estado !== 'conflict'; });
    if (!reintentables.length) {
      toast('Sólo quedan envíos que necesitan una decisión, uno por uno.');
      return;
    }
    for (var i = 0; i < reintentables.length; i++) {
      await AppDB.update(reintentables[i].id, {
        status: 'queued', attempts: 0, nextTryAt: 0, updatedAt: Date.now()
      });
    }
    try { await Queue.flushNow(); } catch(_) {}
    toast('Reintentando ' + reintentables.length + ' envío(s)…');
    await pintar();
  });

  window.addEventListener('online', pintar);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', pintar);
  } else {
    pintar();
  }
})();
</script>
</body>
</html>
