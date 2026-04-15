// Minimal service worker — required for PWA installability.
// No caching or offline logic; the browser needs a registered SW
// before it will offer the "Add to home screen" / install prompt.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
