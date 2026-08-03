/* ============================================================
   PFOffline — registra el service worker y reporta si el juego
   ya quedó descargado completo en el tótem.

   Sirve sólo cuando el juego se carga por HTTP(S) desde el
   servidor. Si el index.html se abre como archivo local
   (file://) no hay service worker posible y tampoco hace falta:
   los archivos ya están en el disco del tótem. En ese caso todo
   falla en silencio y el juego funciona igual.
   ============================================================ */
window.PFOffline = (function () {
  'use strict';

  var estado = {
    soportado: false,
    activo: false,
    listo: false,        // el precache terminó: ya aguanta sin red
    cacheados: 0,
    total: 0,
    version: null,
    hayActualizacion: false,
    motivo: '',
  };

  function emit() {
    try { window.dispatchEvent(new CustomEvent('pf:offline', { detail: get() })); } catch (e) {}
  }

  function get() { return JSON.parse(JSON.stringify(estado)); }

  // pregunta al SW cuántos archivos alcanzó a guardar
  function preguntarEstado() {
    return new Promise(function (resolve) {
      var sw = navigator.serviceWorker && navigator.serviceWorker.controller;
      if (!sw) { resolve(null); return; }
      var ch = new MessageChannel();
      var listo = false;
      ch.port1.onmessage = function (ev) { listo = true; resolve(ev.data); };
      try { sw.postMessage({ tipo: 'estado' }, [ch.port2]); } catch (e) { resolve(null); return; }
      setTimeout(function () { if (!listo) resolve(null); }, 3000);
    }).then(function (r) {
      if (r) {
        estado.activo = true;
        estado.listo = !!r.listo;
        estado.cacheados = r.cacheados;
        estado.total = r.total;
        estado.version = r.version;
      }
      emit();
      return get();
    });
  }

  function init() {
    if (location.protocol === 'file:') {
      estado.motivo = 'Archivos locales en el tótem (no necesita caché)';
      emit();
      return Promise.resolve(get());
    }
    if (!('serviceWorker' in navigator)) {
      estado.motivo = 'El navegador no soporta service workers';
      emit();
      return Promise.resolve(get());
    }
    estado.soportado = true;

    return navigator.serviceWorker.register('sw.js').then(function (reg) {
      // hay una versión nueva esperando para el próximo arranque
      if (reg.waiting) { estado.hayActualizacion = true; emit(); }
      reg.addEventListener('updatefound', function () {
        var nuevo = reg.installing;
        if (!nuevo) return;
        nuevo.addEventListener('statechange', function () {
          if (nuevo.state === 'installed' && navigator.serviceWorker.controller) {
            estado.hayActualizacion = true;
            emit();
          }
        });
      });

      /* En la PRIMERA visita el SW aún no controla la página, así que no
         se le puede preguntar nada. Se espera a que tome el control para
         confirmar que el juego ya quedó descargado completo. */
      if (!navigator.serviceWorker.controller) {
        navigator.serviceWorker.addEventListener('controllerchange', function () {
          preguntarEstado();
        });
        estado.motivo = 'Descargando el juego para uso sin red…';
        emit();
        return navigator.serviceWorker.ready.then(function () {
          return preguntarEstado();
        });
      }
      return preguntarEstado();
    }).catch(function (e) {
      estado.motivo = 'No se pudo registrar: ' + String(e && e.message || e);
      emit();
      return get();
    });
  }

  // fuerza la versión nueva y recarga (botón del panel admin)
  function actualizarAhora() {
    if (!('serviceWorker' in navigator)) return Promise.resolve(false);
    return navigator.serviceWorker.getRegistration().then(function (reg) {
      if (!reg) return false;
      return reg.update().then(function () {
        var esperando = reg.waiting;
        if (!esperando) return false;
        return new Promise(function (resolve) {
          var ch = new MessageChannel();
          ch.port1.onmessage = function () { resolve(true); };
          esperando.postMessage({ tipo: 'activar_ya' }, [ch.port2]);
          setTimeout(function () { resolve(true); }, 1500);
        }).then(function () {
          location.reload();
          return true;
        });
      });
    }).catch(function () { return false; });
  }

  function buscarActualizacion() {
    if (!('serviceWorker' in navigator)) return Promise.resolve(false);
    return navigator.serviceWorker.getRegistration().then(function (reg) {
      if (!reg) return false;
      return reg.update().then(function () { return !!reg.waiting; });
    }).catch(function () { return false; });
  }

  return {
    init: init,
    getStatus: get,
    refrescar: preguntarEstado,
    actualizarAhora: actualizarAhora,
    buscarActualizacion: buscarActualizacion,
  };
})();
