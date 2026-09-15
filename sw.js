const CACHE_NAME = 'printkiosk-v1';
const SHELL_CACHE = 'printkiosk-shell-v1';

self.addEventListener('install', function(e) {
  self.skipWaiting();
  e.waitUntil(
    caches.open(SHELL_CACHE).then(function(cache) {
      return cache.addAll([
        '/manifest.webmanifest',
        '/assets/apple-touch-icon.png',
        '/assets/icon-192.png',
        '/assets/icon-512.png',
        '/styles.css',
        '/frontend_config.js',
        '/website/splash.html',
        '/website/login.html'
      ]);
    }).catch(function() {})
  );
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(k) { return k !== SHELL_CACHE && k !== CACHE_NAME; })
            .map(function(k) { return caches.delete(k); })
      );
    }).then(function() { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function(e) {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET') return;
  if (url.origin !== self.location.origin) return;

  if (url.pathname.startsWith('/assets/') || url.pathname === '/manifest.webmanifest') {
    e.respondWith(
      caches.match(e.request).then(function(cached) {
        return cached || fetch(e.request).then(function(res) {
          const copy = res.clone();
          caches.open(SHELL_CACHE).then(function(cache) { cache.put(e.request, copy); });
          return res;
        });
      })
    );
    return;
  }

  e.respondWith(
    fetch(e.request).then(function(res) {
      if (res && res.status === 200 && res.type === 'basic') {
        const copy = res.clone();
        caches.open(CACHE_NAME).then(function(cache) { cache.put(e.request, copy); });
      }
      return res;
    }).catch(function() {
      return caches.match(e.request).then(function(cached) {
        return cached || caches.match('/website/login.html');
      });
    })
  );
});