/* POS Shop — Service Worker (Offline-First PWA)
 * Chiến lược cache:
 *  - Navigation (F5/mở web): network-first → nếu mất mạng, trả bản cache index.html
 *  - Tài nguyên tĩnh & thư viện CDN (SheetJS, ethers, qrcode): cache-first, tự cập nhật nền
 *  - Chỉ xử lý GET; API/đồng bộ (POST) không bao giờ cache
 */
const CACHE_NAME = 'posshop-v8-billiards-ops';
const PRECACHE = [
  './',
  './index.html',
  './manifest.json',
  './pwa-offline.js?v=20260901-billiards-ops',
  'https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js',
  'https://cdn.jsdelivr.net/npm/ethers@6.13.2/dist/ethers.umd.min.js',
];

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    // addAll fail toàn bộ nếu 1 URL chết → thêm từng cái, lỗi URL nào bỏ qua URL đó
    await Promise.all(PRECACHE.map(async url => {
      try { await cache.add(url); } catch (e) { console.warn('[SW] precache skip:', url, e.message); }
    }));
    self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    // dọn cache phiên bản cũ
    const keys = await caches.keys();
    await Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;            // POST sync/API không cache
  const url = new URL(req.url);

  // Trang web (mở lại tab, F5)
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try { return await fetch(req); }
      catch { return (await caches.match('./index.html')) || Response.error(); }
    })());
    return;
  }

  // Tài nguyên: cache-first + cập nhật nền
  event.respondWith((async () => {
    const cached = await caches.match(req);
    if (cached) {
      // refresh nền để lần sau có bản mới
      fetch(req).then(res => {
        if (res && res.ok) caches.open(CACHE_NAME).then(c => c.put(req, res.clone()));
      }).catch(()=>{});
      return cached;
    }
    try {
      const res = await fetch(req);
      if (res && res.ok && (url.origin === location.origin || url.hostname.includes('cdn.'))) {
        const cache = await caches.open(CACHE_NAME);
        cache.put(req, res.clone());
      }
      return res;
    } catch { return Response.error(); }
  })());
});

// Nhận lệnh từ trang: ép đồng bộ ngay khi có mạng lại
self.addEventListener('message', e => {
  if (e.data === 'POS_SYNC_NOW') self.clients.matchAll().then(cs => cs.forEach(c => c.postMessage('SYNC_NOW')));
});
