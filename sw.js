self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
// fetch handler required by Chrome's PWA installability criteria
self.addEventListener('fetch', (e) => e.respondWith(fetch(e.request)));
