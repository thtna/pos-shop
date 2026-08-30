/* ============================================================
 * POS Shop — Offline-First Engine (IndexedDB + Auto-Sync Queue)
 * Gắn vào index.html bằng: <script src="pwa-offline.js"></script>
 * API toàn cục: POSOffline.init() / .saveSnapshot() / .onOrderPaid() / .syncNow()
 * ============================================================ */
const POSOffline = (() => {
  const VERSION = 'v2-fix';
  const DB_NAME = 'posshop_offline';
  const DB_VERSION = 1;
  const STORE_CATALOG = 'catalog';            // bản sao danh mục: sản phẩm, bàn, khu vực, cấu hình
  const STORE_QUEUE = 'offline_orders_queue'; // hàng chờ đơn tạo lúc mất mạng
  const LS_SYNC_URL = 'posshop_syncUrl';      // endpoint backend nhận đơn (VD: http://192.168.1.10:8000/api/orders)

  let db = null, syncing = false;

  /* ---------- IndexedDB cơ bản ---------- */
  function openDB(){
    return new Promise((resolve, reject) => {
      if (db) return resolve(db);
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const d = req.result;
        if (!d.objectStoreNames.contains(STORE_CATALOG)) d.createObjectStore(STORE_CATALOG);
        if (!d.objectStoreNames.contains(STORE_QUEUE)) d.createObjectStore(STORE_QUEUE, { keyPath: 'offline_id' });
      };
      req.onsuccess = () => { db = req.result; resolve(db); };
      req.onerror = () => reject(req.error);
    });
  }
  async function idbPut(store, key, value){
    const d = await openDB();
    return new Promise((res, rej) => {
      const tx = d.transaction(store, 'readwrite');
      const os = tx.objectStore(store);
      // store có keyPath (offline_orders_queue) phải để key tự sinh từ object — truyền key ngoài sẽ DataError
      if (os.autoIncrement || os.keyPath !== null) os.put(value);
      else os.put(value, key);
      tx.oncomplete = res; tx.onerror = () => rej(tx.error);
    });
  }
  async function idbGetAll(store){
    const d = await openDB();
    return new Promise((res, rej) => {
      const tx = d.transaction(store, 'readonly');
      const rq = tx.objectStore(store).getAll();
      rq.onsuccess = () => res(rq.result || []); rq.onerror = () => rej(rq.error);
    });
  }
  async function idbDelete(store, key){
    const d = await openDB();
    return new Promise((res, rej) => {
      const tx = d.transaction(store, 'readwrite');
      tx.objectStore(store).delete(key);
      tx.oncomplete = res; tx.onerror = () => rej(tx.error);
    });
  }
  async function idbClear(store){
    const d = await openDB();
    return new Promise((res, rej) => {
      const tx = d.transaction(store, 'readwrite');
      tx.objectStore(store).clear();
      tx.oncomplete = res; tx.onerror = () => rej(tx.error);
    });
  }

  /* ---------- Banner trạng thái (góc trên giao diện POS) ---------- */
  function banner(text, kind){
    let el = document.getElementById('netBanner');
    if (!el) {
      el = document.createElement('div');
      el.id = 'netBanner';
      el.style.cssText = 'position:fixed;top:8px;right:14px;z-index:9999;padding:8px 16px;border-radius:12px;'
        + 'font-size:13px;font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,.18);max-width:360px;'
        + 'display:flex;align-items:center;gap:8px;transition:.25s';
      document.body.appendChild(el);
    }
    // nếu đang đồng bộ hoặc đã đồng bộ xong thì bấm vào banner mới mở cấu hình
    el.onclick = kind === 'offline' ? configSyncUrl : configSyncUrl;
    el.innerHTML = '<span style="flex:1;cursor:pointer" id="netBannerText">' + text + '</span>'
      + '<span id="netBannerClose" title="Đóng thông báo" style="cursor:pointer;font-weight:900;opacity:.6;padding:0 4px">✕</span>';
    el.querySelector('#netBannerText').onclick = configSyncUrl;
    el.querySelector('#netBannerClose').onclick = e => { e.stopPropagation(); hideBanner(); };
    el.style.display = 'flex';
    el.style.background = kind === 'offline' ? '#fff3ec' : kind === 'syncing' ? '#e8f0fe' : '#e6f7ed';
    el.style.color    = kind === 'offline' ? '#c2410c' : kind === 'syncing' ? '#1a73e8' : '#15803d';
    el.style.border   = '1px solid ' + (kind === 'offline' ? '#fdba74' : kind === 'syncing' ? '#93c5fd' : '#86efac');
  }
  function hideBanner(){ const el = document.getElementById('netBanner'); if (el) el.style.display = 'none'; }

  /* ---------- Cấu hình backend đồng bộ (modal riêng, không dùng prompt) ---------- */
  function syncUrl(){ return localStorage.getItem(LS_SYNC_URL) || ''; }
  function configSyncUrl(){
    let ov = document.getElementById('syncCfgOverlay');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'syncCfgOverlay';
      ov.style.cssText = 'position:fixed;inset:0;background:rgba(8,20,45,.5);z-index:10000;display:flex;align-items:center;justify-content:center';
      ov.innerHTML = `<div style="background:#fff;border-radius:16px;padding:22px;width:min(430px,92vw)">
        <h3 style="margin:0 0 6px">🌐 Máy chủ đồng bộ đơn hàng</h3>
        <p style="font-size:12px;color:#6b7280;margin:0 0 12px">Đơn tạo lúc mất mạng sẽ tự gửi lên địa chỉ này khi có mạng lại. Địa chỉ Laravel backend: <code>.../api/orders/offline-sync</code></p>
        <input id="syncCfgInput" placeholder="VD: http://192.168.1.10:8000/api/orders/offline-sync"
          style="width:100%;padding:10px 12px;border:1px solid #e5e9f0;border-radius:10px;font-size:14px;box-sizing:border-box">
        <div style="display:flex;gap:10px;margin-top:14px">
          <button id="syncCfgOff" style="flex:1;padding:10px;border:none;border-radius:10px;background:#eef2f7;cursor:pointer;font-weight:600">Tắt đồng bộ</button>
          <button id="syncCfgSave" style="flex:2;padding:10px;border:none;border-radius:10px;background:#1a73e8;color:#fff;cursor:pointer;font-weight:700">💾 Lưu & đồng bộ ngay</button>
        </div>
        <button id="syncCfgClose" style="width:100%;margin-top:8px;padding:8px;border:none;background:none;cursor:pointer;color:#888">Đóng</button>
      </div>`;
      document.body.appendChild(ov);
      ov.querySelector('#syncCfgSave').onclick = () => {
        const v = ov.querySelector('#syncCfgInput').value.trim();
        localStorage.setItem(LS_SYNC_URL, v);
        closeCfg();
        toastMsg(v ? '💾 Đã lưu máy chủ: ' + v + ' — đang đồng bộ...' : 'Đã lưu');
        if (v) syncNow();
      };
      ov.querySelector('#syncCfgOff').onclick = () => { localStorage.removeItem(LS_SYNC_URL); closeCfg(); toastMsg('Đã tắt đồng bộ — chỉ lưu local'); };
      ov.querySelector('#syncCfgClose').onclick = closeCfg;
      ov.onclick = e => { if (e.target === ov) closeCfg(); };
    }
    ov.querySelector('#syncCfgInput').value = syncUrl();
    ov.style.display = 'flex';
  }
  function closeCfg(){ const ov = document.getElementById('syncCfgOverlay'); if (ov) ov.style.display = 'none'; }
  function toastMsg(m){ const t = document.getElementById('toast'); if (t){ t.textContent = m; t.style.display = 'block'; setTimeout(()=>t.style.display='none', 2600); } }

  /* ---------- 1. Lưu bản sao danh mục vào IndexedDB (gọi mỗi khi persist) ---------- */
  async function saveSnapshot(data){
    try { await idbPut(STORE_CATALOG, 'snapshot', { savedAt: new Date().toISOString(), ...data }); }
    catch (e) { console.warn('[offline] lưu catalog lỗi:', e); }
  }
  async function loadSnapshot(){
    const d = await openDB();
    return new Promise((res) => {
      const tx = d.transaction(STORE_CATALOG, 'readonly');
      const rq = tx.objectStore(STORE_CATALOG).get('snapshot');
      rq.onsuccess = () => res(rq.result || null); rq.onerror = () => res(null);
    });
  }

  /* ---------- 2. Đơn hoàn tất thanh toán → nếu offline thì xếp hàng chờ ---------- */
  async function onOrderPaid(order){
    try {
      if (navigator.onLine && syncUrl()) {   // có mạng + có backend → đẩy thẳng, không cần chờ
        const ok = await pushOne(order);
        if (ok) return;
      }
      const offline = !navigator.onLine;
      const rec = {
        ...order,
        is_offline: offline,
        offline_id: 'OFF-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
        created_at: new Date().toISOString(), // mốc thời gian khách mua (KHÔNG phải lúc đồng bộ)
        synced: false,
      };
      await idbPut(STORE_QUEUE, rec.offline_id, rec);
      if (offline) updateOfflineBadge();
      else if (syncUrl()) syncNow();
      else updateOfflineBadge(); // có mạng nhưng chưa cấu hình máy chủ → nhắc người dùng
    } catch (e) { console.warn('[offline] xếp hàng lỗi:', e); }
  }

  /* ---------- 3. Đồng bộ tuần tự khi có mạng ---------- */
  async function pushOne(rec){
    try {
      const res = await fetch(syncUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Offline-Id': rec.offline_id || '' },
        body: JSON.stringify({ offline_id: rec.offline_id || null, created_at: rec.created_at || rec.time, order: rec }),
      });
      return res.ok; // 200 → xóa khỏi hàng chờ; lỗi khác → giữ lại thử lần sau
    } catch { return false; }
  }
  async function syncNow(){
    if (syncing || !navigator.onLine) return;
    const url = syncUrl();
    let queue = await idbGetAll(STORE_QUEUE);
    if (!queue.length) { hideBannerIfIdle(); updateOfflineBadge(); return; }
    if (!url) { banner('📦 ' + queue.length + ' đơn đang chờ đồng bộ (chưa cấu hình máy chủ — bấm để cấu hình)', 'offline'); return; }
    syncing = true;
    banner('🌐 Đã kết nối internet — Đang đồng bộ ' + queue.length + ' đơn hàng lên hệ thống...', 'syncing');
    let sent = 0;
    // gửi TUẦN TỰ để giữ đúng thứ tự thời gian created_at
    queue.sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));
    for (const rec of queue) {
      const ok = await pushOne(rec);
      if (ok) { await idbDelete(STORE_QUEUE, rec.offline_id); sent++; }
      else break; // mất mạng giữa chừng → dừng, lần sau tiếp tục
    }
    syncing = false;
    const left = (await idbGetAll(STORE_QUEUE)).length;
    if (left === 0) { banner('✅ Đã đồng bộ ' + sent + ' đơn hàng lên hệ thống', 'ok'); setTimeout(hideBannerIfIdle, 3000); }
    else banner('⚠️ Còn ' + left + ' đơn chưa đồng bộ được — sẽ thử lại khi mạng ổn định', 'offline');
    updateOfflineBadge();
  }
  function hideBannerIfIdle(){ if (navigator.onLine && !(syncing)) hideBanner(); }

  /* Số đơn chờ hiển thị góc banner khi đang online */
  async function updateOfflineBadge(){
    if (!navigator.onLine) return;
    const n = (await idbGetAll(STORE_QUEUE)).length;
    if (n > 0 && !syncing) banner('📦 Có ' + n + ' đơn ngoại tuyến chờ đồng bộ (bấm để xem cấu hình)', 'syncing');
  }

  /* ---------- 4. Khởi tạo: sự kiện online/offline + đăng ký Service Worker ---------- */
  function init(){
    window.addEventListener('offline', () =>
      banner('📴 Chế độ ngoại tuyến — Các đơn hàng sẽ được lưu tạm trên máy', 'offline'));
    window.addEventListener('online', () => syncNow());
    if (!navigator.onLine) banner('📴 Chế độ ngoại tuyến — Các đơn hàng sẽ được lưu tạm trên máy', 'offline');
    else updateOfflineBadge();

    // đăng ký Service Worker (chỉ chạy trên http/https, không chạy file://)
    if ('serviceWorker' in navigator && location.protocol.startsWith('http')) {
      navigator.serviceWorker.register('./sw.js')
        .then(() => console.log('[offline] Service Worker đã kích hoạt — web chạy được khi mất mạng'))
        .catch(e => console.warn('[offline] SW lỗi:', e));
    }
    // dọn hàng chờ lỗi cũ hơn 30 ngày tránh phình DB
    idbGetAll(STORE_QUEUE).then(all => {
      const cut = Date.now() - 30*24*3600*1000;
      all.filter(r => new Date(r.created_at).getTime() < cut).forEach(r => idbDelete(STORE_QUEUE, r.offline_id));
    }).catch(()=>{});
  }

  return { VERSION, init, saveSnapshot, loadSnapshot, onOrderPaid, syncNow, queueCount: () => idbGetAll(STORE_QUEUE).then(a => a.length), configSyncUrl };
})();

document.addEventListener('DOMContentLoaded', () => POSOffline.init());
// const cấp script không tự gắn vào window — phải gắn tường minh để index.html gọi được
window.POSOffline = POSOffline;
