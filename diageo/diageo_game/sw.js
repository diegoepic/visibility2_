const CACHE = 'diageo-quiz-v2';
const ASSETS = [
  './',
  './index.html',
  // Imágenes que usas en pantallas
  './assets/1.png', // portada
  './assets/3.png', // pregunta TQ
  './assets/4.png', // pregunta JW
  './assets/6.png', // estás participando JW
  './assets/7.png', // estás participando TQ
  './assets/9.png', // sigue participando
  // agrega aquí cualquier otra (8,11,12) si las llamas desde index.html
];

self.addEventListener('install', e=>{
  e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)));
  self.skipWaiting();
});
self.addEventListener('activate', e=>{
  e.waitUntil(
    caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k))))
  );
  self.clients.claim();
});
self.addEventListener('fetch', e=>{
  e.respondWith(
    caches.match(e.request).then(res=>{
      if(res) return res;
      return fetch(e.request).then(r=>{
        const copy = r.clone();
        caches.open(CACHE).then(c=>c.put(e.request, copy));
        return r;
      }).catch(()=>caches.match('./index.html'));
    })
  );
});
