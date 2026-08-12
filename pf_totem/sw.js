/* ============================================================
   Service worker del tótem "Brindemos lo real".
   Permite servir el juego por HTTP desde el servidor y que igual
   funcione sin red: en el primer arranque se descarga TODO y
   después el tótem ya no depende de la señal.

   Reglas de caché:
   - POST y demás métodos que no sean GET pasan derecho a la red.
     Así el sync (que es POST) nunca se toca desde acá.
   - js/config.js va primero a la red: es el archivo que se edita
     en el servidor (token, URL de sync, matriz de vinos) y quedaría
     congelado si se sirviera del caché. Sin red usa la copia local.
   - Todo lo demás es cache-first: sin red y sin latencia.

   El SW nuevo NO se activa solo: espera al siguiente arranque para
   no recargar la página en medio de una partida. Desde el panel
   admin se puede forzar la actualización ("Buscar actualización").
   ============================================================ */
'use strict';

var VERSION = 'pf-totem-v9';
var CACHE = VERSION;

/* Todo lo que el juego necesita para arrancar sin red. Si se agrega un
   archivo nuevo al proyecto hay que sumarlo acá Y subir VERSION. */
var PRECACHE = [
  './',
  'index.html',
  'css/style.css',
  'js/vendor/qrcode.min.js',
  'js/config.js',
  'js/db.js',
  'js/audio.js',
  'js/keyboard.js',
  'js/sync.js',
  'js/offline.js',
  'js/game.js',
  'assets/fonts/Cinzel.ttf',
  'assets/fonts/Jost.ttf',
  // fotos reales de "Tu vino ideal" (ver matriz en config.js). Sólo las que
  // el juego usa de verdad, no las 29 del catálogo completo en
  // assets/img/catalogo-puntiferrer/ — esa carpeta es material de referencia.
  'assets/img/catalogo-puntiferrer/reserva-carmenere.png',
  'assets/img/catalogo-puntiferrer/signature-merlot.png',
  'assets/img/catalogo-puntiferrer/granreserva-cabernet.png',
  'assets/img/catalogo-puntiferrer/reserva-pinotnoir.png',
  'assets/img/catalogo-puntiferrer/signature-sauvignonblanc.png',
  'assets/img/catalogo-puntiferrer/signature-chardonnay.png',
  'assets/img/catalogo-puntiferrer/granreserva-chardonnay.png',
  'assets/img/catalogo-puntiferrer/innovation-huevosdelloco.png',
  'assets/img/catalogo-puntiferrer/espumantes-pais.png',
  'assets/img/catalogo-puntiferrer/espumantes-xtrabrut.png',
  // sumadas al pasar la matriz de 1 vino fijo a un pool de 2 por celda
  'assets/img/catalogo-puntiferrer/reserva-cabernet.png',
  'assets/img/catalogo-puntiferrer/signature-malbec.png',
  'assets/img/catalogo-puntiferrer/premium-carmenere.png',
  'assets/img/catalogo-puntiferrer/innovation-tinajas-malbec.png',
  'assets/img/catalogo-puntiferrer/granreserva-sauvignonblanc.png',
  'assets/img/catalogo-puntiferrer/reserva-chardonnay.png',
  'assets/img/catalogo-puntiferrer/reserva-sauvignonblanc.png',
];

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(CACHE).then(function (c) {
      // addAll es todo-o-nada a propósito: un tótem a medio cachear es peor
      // que uno que reintenta en la siguiente carga.
      return c.addAll(PRECACHE);
    })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (nombres) {
      return Promise.all(nombres.map(function (n) {
        if (n !== CACHE) return caches.delete(n);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

function esConfig(url) { return /\/js\/config\.js(\?|$)/.test(url); }

self.addEventListener('fetch', function (e) {
  var req = e.request;

  // el sync es POST: no lo tocamos, va directo a la red
  if (req.method !== 'GET') return;

  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // config.js: red primero, caché como respaldo
  if (esConfig(url.pathname)) {
    e.respondWith(
      fetch(req).then(function (resp) {
        if (resp && resp.ok) {
          var copia = resp.clone();
          caches.open(CACHE).then(function (c) { c.put(req, copia); });
        }
        return resp;
      }).catch(function () {
        return caches.match(req).then(function (r) {
          return r || caches.match('js/config.js');
        });
      })
    );
    return;
  }

  // navegación: si no hay red, se sirve el index cacheado
  if (req.mode === 'navigate') {
    e.respondWith(
      caches.match(req).then(function (r) {
        return r || fetch(req).catch(function () {
          return caches.match('index.html') || caches.match('./');
        });
      })
    );
    return;
  }

  // resto: caché primero, y lo que llegue de red se guarda para la próxima
  e.respondWith(
    caches.match(req).then(function (r) {
      if (r) return r;
      return fetch(req).then(function (resp) {
        if (resp && resp.ok && resp.type === 'basic') {
          var copia = resp.clone();
          caches.open(CACHE).then(function (c) { c.put(req, copia); });
        }
        return resp;
      });
    })
  );
});

/* Mensajes desde la página (panel admin) */
self.addEventListener('message', function (e) {
  var msg = e.data || {};
  var responder = function (payload) {
    if (e.ports && e.ports[0]) e.ports[0].postMessage(payload);
  };

  if (msg.tipo === 'estado') {
    caches.open(CACHE).then(function (c) {
      return c.keys();
    }).then(function (keys) {
      responder({
        version: VERSION,
        cacheados: keys.length,
        total: PRECACHE.length,
        listo: keys.length >= PRECACHE.length,
      });
    }).catch(function () {
      responder({ version: VERSION, cacheados: 0, total: PRECACHE.length, listo: false });
    });
    return;
  }

  // el operador pidió actualizar ahora desde el panel
  if (msg.tipo === 'activar_ya') {
    self.skipWaiting();
    responder({ ok: true });
  }
});
