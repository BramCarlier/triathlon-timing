// Service worker: keeps timing pages usable when the connection drops.
// - Static build assets: network first, cached copy when offline.
// - Timing pages (race-day link, timing station): network first; the last good
//   copy opens offline so saved taps can still be recorded and uploaded later.
// - Inertia JSON requests and all writes always go to the network.
const VERSION = 'v2';
const ASSETS = `triathlon-timing-assets-${VERSION}`;
const PAGES = `triathlon-timing-pages-${VERSION}`;
const CORE = ['/manifest.webmanifest', '/icon.svg'];
const MAX_ASSETS = 120;
const TIMING_PAGE = /^\/(race\/[^/]+|races\/\d+\/station)$/;

const OFFLINE_HTML = `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Offline · Triathlon Timing</title><body style="font-family:system-ui,sans-serif;background:#e9eff0;color:#0b2a3c;padding:2rem;max-width:32rem;margin:auto">
<h1>You are offline</h1><p>This page was not opened on this device while it was online, so it cannot be shown now.</p>
<p>Taps already saved on this device are kept. Reconnect and reload to continue.</p><button onclick="location.reload()" style="font:inherit;padding:.75rem 1rem;border-radius:.4rem;border:0;background:#ff5b14;color:#0b2a3c;font-weight:600">Try again</button></body></html>`;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(ASSETS).then((cache) => cache.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== ASSETS && key !== PAGES).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

async function trim(cacheName, max) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  await Promise.all(keys.slice(0, Math.max(0, keys.length - max)).map((key) => cache.delete(key)));
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin) return;
  if (request.headers.get('X-Inertia')) return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).then((response) => {
      if (response.ok && TIMING_PAGE.test(url.pathname)) {
        const copy = response.clone();
        event.waitUntil(caches.open(PAGES).then((cache) => cache.put(url.pathname, copy)));
      }
      return response;
    }).catch(async () => (await caches.match(url.pathname, { cacheName: PAGES }))
      ?? new Response(OFFLINE_HTML, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
    return;
  }

  const cacheable = ['script', 'style', 'image', 'font'].includes(request.destination) || url.pathname.startsWith('/build/');
  if (!cacheable) return;
  event.respondWith(fetch(request).then((response) => {
    if (response.ok) {
      // Clone before the page reads the body.
      const copy = response.clone();
      event.waitUntil(caches.open(ASSETS).then((cache) => cache.put(request, copy)).then(() => trim(ASSETS, MAX_ASSETS)));
    }
    return response;
  }).catch(async () => (await caches.match(request)) ?? Response.error()));
});
