/* MINERVOX - Service Worker Offline */
const CACHE_NAME = 'minervox-v1';
const FILES_TO_CACHE = [
  '/minervox-pos/',
  '/minervox-pos/index.html',
  '/minervox-pos/assets/styles.css',
  '/minervox-pos/assets/vue/vue.global.js',
  '/minervox-pos/assets/fontawesome/all.min.css',
  '/minervox-pos/assets/html2pdf/html2pdf.bundle.min.js',
  '/minervox-pos/assets/sw.js'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(FILES_TO_CACHE))
  );
});

self.addEventListener('fetch', event => {
  // Para peticiones a la API (php), no cacheamos para asegurar datos frescos
  if (event.request.url.includes('/minervox-pos/api/')) {
    event.respondWith(fetch(event.request));
    return;
  }

  event.respondWith(
    caches.match(event.request)
      .then(response => response || fetch(event.request))
  );
});