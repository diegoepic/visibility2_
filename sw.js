self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      for (const key of keys) {
        if (key && key.toLowerCase().includes('visibility2')) {
          await caches.delete(key);
        }
      }
    } catch (e) {
      // ignore
    }
    try { await self.registration.unregister(); } catch(_) {}
    try { self.clients.claim(); } catch(_) {}
    try {
      const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (const client of all) {
        client.postMessage({ type: 'SW_LEGACY_UNREGISTERED' });
      }
    } catch (_) {}
  })());
});

self.addEventListener('fetch', (event) => {
  // legacy shim: no-op
});
