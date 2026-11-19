/// <reference lib="webworker" />

declare const PUBLIC_ASSETS: string[];
declare const CACHE_VERSION: string;

const worker = globalThis as unknown as ServiceWorkerGlobalScope;
const cachePrefix = 'flow-balance-';
const cacheName = cachePrefix + 'public-' + CACHE_VERSION;
const publicAssets = new Set(PUBLIC_ASSETS);

worker.addEventListener('install', (event) => {
  event.waitUntil(worker.skipWaiting());
});

worker.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const name of await caches.keys()) {
      if (name.startsWith(cachePrefix) && name !== cacheName) await caches.delete(name);
    }
    await worker.clients.claim();
  })());
});

worker.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== worker.location.origin ) return;
  if (url.pathname === '/api' || url.pathname.startsWith('/api/')) return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request, { cache: 'no-store' }).catch(() => new Response(
      '<!doctype html><html lang="es-MX"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Sin conexión · Flow Balance</title><h1>Sin conexión</h1><p>Conéctate a internet y vuelve a cargar esta página.</p></html>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } },
    )));
    return;
  }

  if (url.search || !publicAssets.has(url.pathname) || !['image', 'font', 'script', 'style', 'manifest'].includes(request.destination)) return;
  event.respondWith((async () => {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    if (cached) return cached;
    const response = await fetch(request, { credentials: 'omit', redirect: 'error' });
    if (response.ok && !response.headers.get('Content-Type')?.includes('text/html')
        && !/private|no-store/i.test(response.headers.get('Cache-Control') ?? '')) {
      await cache.put(request, response.clone());
    }
    return response;
  })());
});
