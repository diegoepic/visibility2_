//version del service  worker (subir en 0.0.1 para refrescar cache de los archivos estaticos, tambien subir a la misma version el sw en assets/js/v2_cache.js)
const VERSION        = 'v7.6.5';
const APP_SCOPE      = '/visibility2/app';
const STATIC_CACHE   = `static-${VERSION}`;
const RUNTIME_CACHE  = `runtime-${VERSION}`;
const RUNTIME_MAX_ITEMS = 80;

//achivos guardados en la cache
const STATIC_ASSETS = [
  `${APP_SCOPE}/index_pruebas.php`,
  // El centro de recuperación se consulta justamente cuando algo falló, que suele ser sin
  // red. Va precacheado y networkFirst lo busca también en STATIC_CACHE (ver más abajo).
  `${APP_SCOPE}/recuperacion.php`,
  `${APP_SCOPE}/assets/plugins/bootstrap/css/bootstrap.min.css`,
  `${APP_SCOPE}/assets/plugins/jquery/jquery-3.6.0.min.js`,
  `${APP_SCOPE}/assets/plugins/font-awesome/css/font-awesome.min.css`,
  `${APP_SCOPE}/assets/css/main.css`,
  `${APP_SCOPE}/assets/css/main-responsive.css`,
  `${APP_SCOPE}/assets/css/offline.css`,
  `${APP_SCOPE}/assets/js/db.js`,
  `${APP_SCOPE}/assets/js/gestion_draft.js`,
  `${APP_SCOPE}/assets/js/v2_cache.js`,
  `${APP_SCOPE}/assets/js/offline-queue.js`,
  `${APP_SCOPE}/assets/js/bootstrap_index_cache.js`,
  `${APP_SCOPE}/assets/js/journal_db.js`,
  `${APP_SCOPE}/assets/js/journal_ui.js`,
  // Modales de la ruta extraidos del inline de index_pruebas.php (T6-6). Van aca y se
  // piden SIN ?v= porque cacheFirst hace match sin ignoreSearch: con query string no
  // encontrarian esta entrada y, sin red, los botones del modal no harian nada.
  // El nucleo de la ruta (mapa, filtros, planificador). Es el bloque mas pesado que salio
  // del inline: 66 KB que antes viajaban en cada carga de index_pruebas.php.
  `${APP_SCOPE}/assets/js/ruta_mapa.js`,
  // La pagina de gestion, que es donde el ejecutor pasa la jornada. Salieron del inline
  // de gestionarPruebas.php: 121 KB que antes viajaban en CADA gestion que abria.
  `${APP_SCOPE}/assets/js/gestion_geo.js`,
  `${APP_SCOPE}/assets/js/gestion_offline.js`,
  `${APP_SCOPE}/assets/js/gestion_form.js`,
  `${APP_SCOPE}/assets/js/modal_reportar_direccion.js`,
  `${APP_SCOPE}/assets/js/modal_cambiar_vendedor.js`,
  // Librerias de terceros servidas desde el propio servidor. Sin esto, en modo
  // avion no existen imageCompression/heic2any/exifr y las fotos se encolan sin comprimir
  // y sin EXIF. Se piden SIN ?v=: la clave de cache incluye el query string, y el
  // cache-busting ya lo da el nombre del cache (static-${VERSION}).
  // heic2any pesa ~1,3 MB (lleva libheif dentro); es el precio de convertir HEIC offline.
  `${APP_SCOPE}/assets/vendor/browser-image-compression.js`,
  `${APP_SCOPE}/assets/vendor/heic2any.min.js`,
  `${APP_SCOPE}/assets/vendor/exifr.umd.js`,
];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(STATIC_CACHE);
    const adds = STATIC_ASSETS.map(async (u) => {
      try {
        const req = new Request(u, { method: 'GET', credentials: 'include' });
        const res = await fetch(req);
        if (res && res.ok && res.type !== 'opaque') {
          await cache.put(req, res.clone());
        }
      } catch (_) { }
    });
    await Promise.all(adds);
    self.skipWaiting();
  })());
});

function claimClientsWhenActive() {
  const { active, waiting, installing } = self.registration;

  if (active && active.state === 'activated') {
    return self.clients.claim();
  }

  const pending = waiting || installing;
  if (!pending) return Promise.resolve();

  return new Promise((resolve) => {
    const onStateChange = () => {
      if (pending.state === 'activated') {
        pending.removeEventListener('statechange', onStateChange);
        resolve(self.clients.claim());
      }
    };
    pending.addEventListener('statechange', onStateChange);
  });
}

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map(k => {
      if (k !== STATIC_CACHE && k !== RUNTIME_CACHE) return caches.delete(k);
    }));
    await claimClientsWhenActive();
  })());
});

// Mensajes desde el cliente: activar y precachear assets GET del bundle
self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  } else if (data.type === 'CLIENTS_CLAIM') {
    event.waitUntil(claimClientsWhenActive());
  } else if (data.type === 'PRECACHE_ASSETS') {
    const urls = Array.isArray(data.assets) ? data.assets : [];
    event.waitUntil(precacheAssets(urls));
  } else if (data.type === 'CLEAR_USER_CACHES') {
    // Limpiar caches de datos de usuario al hacer logout.
    // Se eliminan RUNTIME_CACHE y STATIC_CACHE para que el próximo usuario
    // no vea datos del anterior en modo offline.
    event.waitUntil(
      caches.keys().then(keys =>
        Promise.all(keys.map(k => caches.delete(k)))
      )
    );
  }
});

async function precacheAssets(urls) {
  if (!urls.length) return;
  const cache = await caches.open(STATIC_CACHE);
  const adds = urls
    .filter(u => typeof u === 'string')
    .map(async (u) => {
      try {
        const req = new Request(u, { method: 'GET', credentials: 'include' });
        const res = await fetch(req);
        if (res && res.ok && res.type !== 'opaque') {
          await cache.put(req, res.clone());
        }
      } catch (_) {  }
    });
  await Promise.all(adds);
}


// Helpers de estrategia
async function cacheFirst(request) {
  const cache = await caches.open(STATIC_CACHE);
  const hit = await cache.match(request, { ignoreVary: true });
  if (hit) return hit;
  try {
    const res = await fetch(request);
    if (res && res.ok) await cache.put(request, res.clone());
    return res;
  } catch (e) {
    return hit || new Response('Offline', { status: 503, statusText: 'Offline' });
  }
}

async function networkFirst(request, navFallback = `${APP_SCOPE}/index_pruebas.php`) {
  const cache = await caches.open(RUNTIME_CACHE);
  try {
    const res = await fetch(request);
    if (request.method === 'GET' && res && res.ok) {
      await cache.put(request, res.clone());
      trimCache(RUNTIME_CACHE, RUNTIME_MAX_ITEMS);
    }
    return res;
  } catch (_) {
    const hit = await cache.match(request);
    if (hit) return hit;
    if (request.mode === 'navigate') {
      const staticCache = await caches.open(STATIC_CACHE);
      // Primero la propia página, si está precacheada: antes se caía directo al fallback y
      // recuperacion.php acababa mostrando el index sin que nadie entendiera por qué.
      const propia = await staticCache.match(request, { ignoreSearch: true });
      if (propia) return propia;
      const fallback = await staticCache.match(navFallback);
      if (fallback) return fallback;
    }
    return new Response('Offline', { status: 503, statusText: 'Offline' });
  }
}

async function trimCache(name, maxItems) {
  const cache = await caches.open(name);
  const keys = await cache.keys();
  if (keys.length <= maxItems) return;
  const toDelete = keys.slice(0, keys.length - maxItems);
  await Promise.all(toDelete.map(k => cache.delete(k)));
}

// Ruteo de fetch
self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Nunca interferimos con métodos distintos de GET (evita el error de Cache.put con POST)
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Ignora orígenes cruzados
  if (url.origin !== self.location.origin) return;

  // Bypass completo de endpoints sensibles
  const path = url.pathname;
  const sensitive = [
    `${APP_SCOPE}/ping.php`,
    `${APP_SCOPE}/csrf_refresh.php`,
    `${APP_SCOPE}/create_visita_pruebas.php`,
    `${APP_SCOPE}/procesar_gestion_pruebas.php`,
    `${APP_SCOPE}/upload_material_foto_pruebas.php`,
    `${APP_SCOPE}/procesar_pregunta_foto_pruebas.php`,
    `${APP_SCOPE}/eliminar_pregunta_foto_pruebas.php`
  ];

  if (path.startsWith(`${APP_SCOPE}/api/`) || sensitive.includes(path)) {
    event.respondWith(fetch(req));
    return;
  }

  // Navegación SPA/HTML
  if (req.mode === 'navigate') {
    event.respondWith(networkFirst(req));
    return;
  }

  // API GET -> network-first con fallback a cache
  if (url.pathname.startsWith(`${APP_SCOPE}/api/`)) {
    event.respondWith(networkFirst(req));
    return;
  }

  // Ping / CSRF siempre de red (pero sin romper offline si ya están en STATIC)
  if (url.pathname.endsWith('/ping.php') || url.pathname.endsWith('/csrf_refresh.php')) {
    event.respondWith((async () => {
      try { return await fetch(req); } catch { return cacheFirst(req); }
    })());
    return;
  }

  // Assets de la app -> cache-first
  if (url.pathname.startsWith(APP_SCOPE)) {
    event.respondWith(cacheFirst(req));
  }
});
