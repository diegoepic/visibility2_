/* ============================================================
   PFSync — autosincronización con el servidor visibility.
   Diseñado para señal mala e intermitente (Espacio Riesco):
   - Nada se pierde: todo vive en IndexedDB hasta que el server
     confirma (ok:true) y recién ahí se marca synced=1.
   - Reintenta solo: cada intervaloSeg, al volver la conexión
     ('online') y al terminar cada partida.
   - Idempotente: el server hace upsert por uuid, así que si la
     respuesta se pierde y reenviamos, no se duplica nada.
   - URL vacía en config = desactivado (modo 100% offline).
   ============================================================ */
window.PFSync = (function () {
  'use strict';

  var cfg = null;
  var deviceId = '';
  var running = false;
  var timer = null;
  var status = {
    habilitado: false,
    ultimoIntento: null,
    ultimoExito: null,
    ultimoError: null,
    pendientes: { sesiones: 0, eventos: 0, ganadores: 0 },
  };

  function refreshPendientes() {
    return Promise.all([
      PFDB.countUnsynced('sesiones'),
      PFDB.countUnsynced('eventos'),
      PFDB.countUnsynced('ganadores'),
    ]).then(function (r) {
      status.pendientes = { sesiones: r[0], eventos: r[1], ganadores: r[2] };
      emit();
      return status.pendientes;
    });
  }

  function emit() {
    try { window.dispatchEvent(new CustomEvent('pf:sync', { detail: getStatus() })); } catch (e) {}
  }

  function getStatus() { return JSON.parse(JSON.stringify(status)); }

  function fetchTimeout(url, opts, ms) {
    var ctrl = new AbortController();
    var t = setTimeout(function () { ctrl.abort(); }, ms);
    opts.signal = ctrl.signal;
    return fetch(url, opts).finally(function () { clearTimeout(t); });
  }

  function tick(profundidad) {
    if (!status.habilitado || running) return Promise.resolve(false);
    running = true;
    status.ultimoIntento = new Date().toISOString();
    profundidad = profundidad || 0;

    /* Cierre único del intento: el contador de pendientes se refresca SIEMPRE,
       haya funcionado o no. Si solo se actualizara al subir bien, el panel del
       stand mostraría "0 pendientes" durante toda una jornada sin señal y quien
       esté a cargo cerraría el día creyendo que ya subió todo. */
    var cerrar = function (ok, err) {
      if (err) status.ultimoError = String(err && err.message || err);
      running = false;
      return refreshPendientes().then(function (p) {
        // quedó más backlog que un lote: se sigue subiendo de inmediato
        if (ok && profundidad < 20 && p.sesiones + p.eventos + p.ganadores > 0) {
          return tick(profundidad + 1);
        }
        return ok;
      });
    };

    var lote = {};
    return Promise.all([
      PFDB.unsynced('sesiones', cfg.maxLote),
      PFDB.unsynced('eventos', cfg.maxLote),
      PFDB.unsynced('ganadores', cfg.maxLote),
    ]).then(function (r) {
      lote.sesiones = r[0]; lote.eventos = r[1]; lote.ganadores = r[2];
      if (!lote.sesiones.length && !lote.eventos.length && !lote.ganadores.length) {
        running = false;
        status.ultimoError = null;
        return refreshPendientes().then(function () { return true; });
      }
      return fetchTimeout(cfg.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-PF-Token': cfg.token },
        body: JSON.stringify({
          device_id: deviceId,
          sesiones: lote.sesiones,
          eventos: lote.eventos,
          ganadores: lote.ganadores,
        }),
      }, 12000).then(function (resp) {
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return resp.json();
      }).then(function (json) {
        if (!json || json.ok !== true) throw new Error((json && json.error_code) || 'RESPUESTA_INVALIDA');
        var ids = function (arr) { return arr.map(function (x) { return x.uuid; }); };
        return Promise.all([
          lote.sesiones.length ? PFDB.markSynced('sesiones', ids(lote.sesiones)) : null,
          lote.eventos.length ? PFDB.markSynced('eventos', ids(lote.eventos)) : null,
          lote.ganadores.length ? PFDB.markSynced('ganadores', ids(lote.ganadores)) : null,
        ]);
      }).then(function () {
        status.ultimoExito = new Date().toISOString();
        status.ultimoError = null;
        return cerrar(true);
      }).catch(function (err) {
        return cerrar(false, err);
      });
    }).catch(function (err) {
      return cerrar(false, 'DB: ' + String(err && err.message || err));
    });
  }

  function init(syncCfg, devId) {
    cfg = syncCfg;
    deviceId = devId;
    status.habilitado = !!(cfg && cfg.url);
    refreshPendientes();
    if (!status.habilitado) return;
    if (timer) clearInterval(timer);
    timer = setInterval(tick, Math.max(30, cfg.intervaloSeg) * 1000);
    window.addEventListener('online', function () { setTimeout(tick, 1500); });
    setTimeout(tick, 4000); // primer intento poco después del arranque
  }

  // Prueba manual de conexión desde el panel admin
  function probar() {
    if (!cfg || !cfg.url) return Promise.resolve({ ok: false, msg: 'Sync desactivado (URL vacía en config.js)' });
    return fetchTimeout(cfg.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-PF-Token': cfg.token },
      body: JSON.stringify({ device_id: deviceId, ping: true, sesiones: [], eventos: [], ganadores: [] }),
    }, 8000).then(function (r) { return r.json(); }).then(function (j) {
      return { ok: j && j.ok === true, msg: j && j.ok ? 'Conexión OK (' + (j.server_time || '') + ')' : 'Respuesta inválida del servidor' };
    }).catch(function (e) {
      return { ok: false, msg: 'Sin conexión: ' + String(e && e.message || e) };
    });
  }

  return { init: init, tick: tick, probar: probar, getStatus: getStatus, refreshPendientes: refreshPendientes };
})();
