const STATIC_CACHE = 'keypro-static-v14-1';
const OFFLINE_PAGE = '/offline.html';
const REQUIRED_ASSETS = [
  OFFLINE_PAGE,
  '/manifest.webmanifest',
  '/icons/keypro-192.png',
  '/icons/keypro-512.png',
  '/icons/apple-touch-icon.png',
];

function isPublicResponse(response) {
  if (!response || !response.ok || response.headers.has('set-cookie')) return false;
  const cacheControl = response.headers.get('cache-control') || '';
  return !/\b(private|no-store)\b/i.test(cacheControl);
}

function isVersionedAsset(url) {
  return url.origin === self.location.origin
    && /^\/build\/assets\/.+-[A-Za-z0-9_-]{8,}\.(?:css|js|png|svg|woff2?)$/i.test(url.pathname);
}

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(STATIC_CACHE);
    await Promise.all(REQUIRED_ASSETS.map(async (path) => {
      const response = await fetch(path, { cache: 'no-store', credentials: 'omit' });
      if (!isPublicResponse(response)) {
        throw new Error(`دارایی عمومی PWA قابل ذخیره نیست: ${path}`);
      }
      await cache.put(path, response);
    }));

    try {
      const response = await fetch('/build/manifest.json', { cache: 'no-store', credentials: 'omit' });
      if (isPublicResponse(response) && (response.headers.get('content-type') || '').includes('json')) {
        const buildManifest = await response.clone().json();
        await cache.put('/build/manifest.json', response);
        const assetPaths = Object.values(buildManifest).flatMap((entry) => [
          entry.file,
          ...(entry.css || []),
          ...(entry.assets || []),
        ]).filter((file) => typeof file === 'string')
          .map((file) => new URL(`/build/${file.replace(/^\/+/, '')}`, self.location.origin))
          .filter(isVersionedAsset)
          .map((url) => url.pathname);
        await Promise.all(assetPaths.map(async (path) => {
          const assetResponse = await fetch(path, { cache: 'no-store', credentials: 'omit' });
          if (isPublicResponse(assetResponse)) await cache.put(path, assetResponse);
        }));
      }
    } catch (error) {
      console.error('ذخیرهٔ برخی منابع نسخه‌دار PWA ناموفق بود.', error);
    }

    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names
      .filter((name) => (name.startsWith('keypro-static-v') && name !== STATIC_CACHE)
        || name.startsWith('keypro-app-shell-'))
      .map((name) => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('push', (event) => {
  let message = {};
  try {
    message = event.data ? event.data.json() : {};
  } catch (error) {
    console.error('محتوای اعلان وب معتبر نیست.', error);
  }
  event.waitUntil(self.registration.showNotification(message.title || 'کلیدتو', {
    body: message.body || 'وضعیت سفارش شما به‌روزرسانی شد.',
    data: { url: message.url || '/orders?frontend=vanilla' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || '/orders?frontend=vanilla', self.location.origin);
  if (target.origin !== self.location.origin) return;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
    const existing = clients.find((client) => new URL(client.url).origin === self.location.origin);
    if (existing) {
      return existing.navigate(target.href).then(() => existing.focus());
    }
    return self.clients.openWindow(target.href);
  }));
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin || /^\/api(?:\/|$)/.test(url.pathname)) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        return await fetch(request);
      } catch {
        const cache = await caches.open(STATIC_CACHE);
        return await cache.match(OFFLINE_PAGE)
          || new Response('اتصال اینترنت برقرار نیست.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' },
          });
      }
    })());
    return;
  }

  const isManifest = url.pathname === '/manifest.webmanifest';
  if (!isManifest && !isVersionedAsset(url)) return;

  event.respondWith((async () => {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(url.pathname);
    if (cached) return cached;
    const response = await fetch(request);
    if (isPublicResponse(response)) await cache.put(url.pathname, response.clone());
    return response;
  })());
});
