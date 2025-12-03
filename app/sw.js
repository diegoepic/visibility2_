const VERSION        = 'v2.7.2';
const APP_SCOPE      = '/visibility2/app';
const STATIC_CACHE   = `static-${VERSION}`;
const RUNTIME_CACHE  = `runtime-${VERSION}`;

//archivos guardados en cache
const STATIC_ASSETS = [
  `${APP_SCOPE}/index_pruebas.php`,
   `${APP_SCOPE}/login.php`,
    `${APP_SCOPE}/procesar_login.php`,
  `${APP_SCOPE}/gestionar_spa.html`,
  `${APP_SCOPE}/assets/plugins/bootstrap/css/bootstrap.min.css`,
  `${APP_SCOPE}/assets/plugins/jquery/jquery-3.6.0.min.js`,
  `${APP_SCOPE}/assets/plugins/font-awesome/css/font-awesome.min.css`,
  `${APP_SCOPE}/assets/css/main.css`,
  `${APP_SCOPE}/assets/css/main-responsive.css`,
  `${APP_SCOPE}/assets/css/offline.css`,
  `${APP_SCOPE}/assets/js/v2_cache.js`,
  `${APP_SCOPE}/assets/js/offline-queue.js`,
  `${APP_SCOPE}/assets/js/bootstrap_index_cache.js`,
  `${APP_SCOPE}/assets/js/gestionar_precache.js`,
  `${APP_SCOPE}/assets/js/gestionar_spa.js`,
];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(STATIC_CACHE);
    try { await cache.addAll(STATIC_ASSETS); } catch (_) { }
    self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map(k => {
      if (k !== STATIC_CACHE && k !== RUNTIME_CACHE) return caches.delete(k);
    }));
    await self.clients.claim();
  })());
});

// Mensajes desde el cliente: activar y precachear assets GET del bundle
self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  } else if (data.type === 'CLIENTS_CLAIM') {
    self.clients.claim();
  } else if (data.type === 'PRECACHE_ASSETS') {
    const urls = Array.isArray(data.assets) ? data.assets : [];
    event.waitUntil(precacheAssets(urls));
  } else if (data.type === 'PRECACHE_GESTIONAR_PAGES') {
    const urls = Array.isArray(data.urls) ? data.urls : [];
    const max  = Number(data.max || 0) || 10;
    event.waitUntil(precacheGestionarPages(urls.slice(0, max)));
  } else if (data.type === 'EVICT_GESTIONAR') {
    const urls = Array.isArray(data.urls) ? data.urls : [];
    event.waitUntil(evictGestionarPages(urls));
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

async function precacheGestionarPages(urls) {
  if (!urls.length) return;
  const cache = await caches.open(STATIC_CACHE);
  const adds = urls.map(async (u) => {
    try {
      const req = new Request(u, { method: 'GET', credentials: 'include' });
      const res = await fetch(req);
      if (isValidGestionarResponse(res, req.url)) {
        await cache.put(req, res.clone());
      } else {
        notifyGestionarPrecacheFailure(u);
      }
    } catch (_) { /* swallow */ }
  });
  await Promise.all(adds);
}

async function evictGestionarPages(urls) {
  if (!urls.length) return;
  const cache = await caches.open(STATIC_CACHE);
  await Promise.all(urls.map(async (u) => {
    try {
      const req = new Request(u, { method: 'GET', credentials: 'include' });
      await cache.delete(req, { ignoreVary: true });
    } catch (_) { /* swallow */ }
  }));
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
  const runtimeCache = await caches.open(RUNTIME_CACHE);
  try {
    const res = await fetch(request);
    if (request.method === 'GET' && res && res.ok) {
      await runtimeCache.put(request, res.clone());
    }
    return res;
  } catch (_) {
    const runtimeHit = await runtimeCache.match(request);
    if (runtimeHit) return runtimeHit;
    if (request.mode === 'navigate') {
      // Intento fallback de navegación a HTML conocido
      const staticCache = await caches.open(STATIC_CACHE);
      const staticHit = await staticCache.match(request, { ignoreVary: true });
      if (staticHit) return staticHit;
      const fallback = await staticCache.match(navFallback);
      if (fallback) return fallback;
    }
    return new Response('Offline', { status: 503, statusText: 'Offline' });
  }
}

// Navegaciones de gestionarPruebas: preferimos el caché si ya fue precargado
// para evitar redirecciones al index cuando no hay red o la sesión no responde.
async function gestionarCacheFirst(request) {
  const staticCache = await caches.open(STATIC_CACHE);
  const hit = await staticCache.match(request, { ignoreVary: true });
  if (hit) {
    if (isValidGestionarResponse(hit, request.url)) return hit;
    await staticCache.delete(request, { ignoreVary: true });
  }

  try {
    const res = await fetch(request);
    if (isValidGestionarResponse(res, request.url)) {
      await staticCache.put(request, res.clone());
    }
    return res;
  } catch (_) {
    const fallback = await staticCache.match(`${APP_SCOPE}/index_pruebas.php`);
    return fallback || new Response('Offline', { status: 503, statusText: 'Offline' });
  }
}

function isValidGestionarResponse(res, requestUrl) {
  if (!res || res.type === 'opaque' || res.redirected || !res.ok) return false;
  const resUrl = new URL(res.url);
  if (resUrl.pathname.endsWith('/login.php') || resUrl.pathname.endsWith('/index_pruebas.php')) return false;

  const expectedPath = new URL(requestUrl, self.location.origin).pathname;
  return resUrl.pathname.startsWith(expectedPath);
}

async function notifyGestionarPrecacheFailure(url) {
  try {
    const clients = await self.clients.matchAll({ includeUncontrolled: true });
    clients.forEach(c => c.postMessage({ type: 'GESTIONAR_PRECACHE_FAILED', url }));
  } catch (_) { /* ignore */ }
}

// Ruteo de fetch
self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Nunca interferimos con métodos distintos de GET (evita el error de Cache.put con POST)
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Ignora orígenes cruzados
  if (url.origin !== self.location.origin) return;

  // Navegación SPA/HTML
  if (req.mode === 'navigate') {
    // gestionPruebas precacheada -> usar cache-first para evitar redirecciones
    if (url.pathname.startsWith(`${APP_SCOPE}/gestionarPruebas.php`)) {
      event.respondWith(gestionarCacheFirst(req));
      return;
    }
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