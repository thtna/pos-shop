/* ============================================================
 * POS Shop — Offline-First Engine (IndexedDB + Auto-Sync Queue)
 * Gắn vào index.html bằng: <script src="pwa-offline.js"></script>
 * API toàn cục: POSOffline.init() / .saveSnapshot() / .onOrderPaid() / .onCashTransactionCreated() / .queueTableOperation() / .syncNow()
 * ============================================================ */
const POSOffline = (() => {
  const VERSION = 'v5-billiards-ops';
  const DB_NAME = 'posshop_offline';
  const DB_VERSION = 4;
  const STORE_CATALOG = 'catalog';            // bản sao danh mục: sản phẩm, bàn, khu vực, cấu hình
  const STORE_QUEUE = 'offline_orders_queue'; // hàng chờ đơn tạo lúc mất mạng
  const STORE_MATERIALS = 'materials';        // nguyên liệu thô, dùng được cả khi mất mạng
  const STORE_PRODUCT_BOM = 'product_bom';    // định lượng nguyên liệu theo món
  const STORE_RESERVES = 'offline_inventory_reserves'; // kho ảo: nguyên liệu đang được các bàn giữ
  const STORE_CASH_TRANSACTIONS = 'cash_transactions'; // lưu trữ sổ quỹ cục bộ
  const STORE_CASH_QUEUE = 'offline_cash_transactions_queue'; // hàng chờ phiếu thu chi offline
  const STORE_TABLE_QUEUE = 'table_operations_queue'; // hàng chờ thao tác bàn offline (logs, pause, resume, transfer)
  const LS_SYNC_URL = 'posshop_syncUrl';      // endpoint backend nhận đơn (VD: http://192.168.1.10:8000/api/orders/offline-sync)

  let db = null, syncing = false, reserveOps = Promise.resolve();
  let offlineSession = !navigator.onLine;

  /* ---------- IndexedDB cơ bản ---------- */
  function openDB(){
    return new Promise((resolve, reject) => {
      if (db) return resolve(db);
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const d = req.result;
        if (!d.objectStoreNames.contains(STORE_CATALOG)) d.createObjectStore(STORE_CATALOG);
        if (!d.objectStoreNames.contains(STORE_QUEUE)) d.createObjectStore(STORE_QUEUE, { keyPath: 'offline_id' });
        if (!d.objectStoreNames.contains(STORE_MATERIALS)) d.createObjectStore(STORE_MATERIALS, { keyPath: 'id' });
        if (!d.objectStoreNames.contains(STORE_PRODUCT_BOM)) d.createObjectStore(STORE_PRODUCT_BOM, { keyPath: 'id' });
        if (!d.objectStoreNames.contains(STORE_RESERVES)) d.createObjectStore(STORE_RESERVES, { keyPath: 'reserve_id' });
        if (!d.objectStoreNames.contains(STORE_CASH_TRANSACTIONS)) d.createObjectStore(STORE_CASH_TRANSACTIONS, { keyPath: 'offline_id' });
        if (!d.objectStoreNames.contains(STORE_CASH_QUEUE)) d.createObjectStore(STORE_CASH_QUEUE, { keyPath: 'offline_id' });
        if (!d.objectStoreNames.contains(STORE_TABLE_QUEUE)) d.createObjectStore(STORE_TABLE_QUEUE, { keyPath: 'client_event_id' });
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
      rq.onsuccess = () => res(rq.result || []);
      rq.onerror = () => rej(rq.error);
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

  /* ---------- Banner trạng thái ---------- */
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

  /* ---------- Cấu hình backend đồng bộ ---------- */
  function syncUrl(){ return localStorage.getItem(LS_SYNC_URL) || ''; }
  function cashSyncUrl(){
    const base = syncUrl();
    if (!base) return '';
    return base.replace(/\/orders\/offline-sync(?:\/batch)?$/, '/cash-transactions/offline-sync');
  }
  function tableSyncUrl(){
    const base = syncUrl();
    if (!base) return '';
    return base.replace(/\/orders\/offline-sync(?:\/batch)?$/, '/tables/operations/offline-sync');
  }

  function configSyncUrl(){
    let ov = document.getElementById('syncCfgOverlay');
    if (!ov) {
      ov = document.createElement('div');
      ov.id = 'syncCfgOverlay';
      ov.style.cssText = 'position:fixed;inset:0;background:rgba(8,20,45,.5);z-index:10000;display:flex;align-items:center;justify-content:center';
      ov.innerHTML = `<div style="background:#fff;border-radius:16px;padding:22px;width:min(430px,92vw)">
        <h3 style="margin:0 0 6px">🌐 Máy chủ đồng bộ đơn hàng & Bida</h3>
        <p style="font-size:12px;color:#6b7280;margin:0 0 12px">Đơn hàng, Phiếu Thu/Chi và Thao tác bàn Bida tạo lúc mất mạng sẽ tự gửi lên địa chỉ này khi có mạng lại. Laravel backend: <code>.../api/orders/offline-sync</code></p>
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
        ov.remove();
        if (v && navigator.onLine) syncNow(true);
      };
      ov.querySelector('#syncCfgOff').onclick = () => {
        localStorage.removeItem(LS_SYNC_URL);
        ov.remove();
        hideBanner();
      };
      ov.querySelector('#syncCfgClose').onclick = () => ov.remove();
      ov.onclick = e => { if (e.target === ov) ov.remove(); };
    }
    ov.querySelector('#syncCfgInput').value = syncUrl();
    ov.style.display = 'flex';
  }

  /* ---------- 1. Lưu snapshot danh mục vào IndexedDB ---------- */
  async function saveSnapshot(data){
    try {
      if (data.products) await idbPut(STORE_CATALOG, 'products', data.products);
      if (data.tables) await idbPut(STORE_CATALOG, 'tables', data.tables);
      if (data.cats) await idbPut(STORE_CATALOG, 'cats', data.cats);
      if (data.shop) await idbPut(STORE_CATALOG, 'shop', data.shop);
      if (data.tableLogs) await idbPut(STORE_CATALOG, 'tableLogs', data.tableLogs);
      if (data.tableSessions) await idbPut(STORE_CATALOG, 'tableSessions', data.tableSessions);
      if (Array.isArray(data.materials)) {
        await idbClear(STORE_MATERIALS);
        for (const m of data.materials) await idbPut(STORE_MATERIALS, m.id, m);
      }
      if (Array.isArray(data.productBom)) {
        await idbClear(STORE_PRODUCT_BOM);
        for (const b of data.productBom) await idbPut(STORE_PRODUCT_BOM, b.id, b);
      }
      if (data.torders) await syncBomReserves({ tableOrders: data.torders, products: data.products, materials: data.materials, productBom: data.productBom });
    } catch (e) { console.warn('[offline] lưu snapshot lỗi:', e); }
  }
  async function loadSnapshot(){
    try {
      const products = await idbGetAll(STORE_CATALOG);
      const materials = await idbGetAll(STORE_MATERIALS);
      const productBom = await idbGetAll(STORE_PRODUCT_BOM);
      return { products, materials, productBom };
    } catch { return null; }
  }

  function queueReserveOp(op){
    reserveOps = reserveOps.then(op).catch(e => console.warn('[offline] lỗi reserve BOM:', e));
    return reserveOps;
  }
  function numeric(v){ const n = Number(v); return Number.isFinite(n) ? n : 0; }
  function productBomRows(rows, productId){
    return (rows || []).filter(r => String(r.productId ?? r.product_id) === String(productId));
  }
  function materialFor(materials, materialId){
    return (materials || []).find(m => String(m.id) === String(materialId));
  }
  async function syncBomReserves(snapshot){
    return queueReserveOp(async () => {
      const all = await idbGetAll(STORE_RESERVES);
      for (const row of all) await idbDelete(STORE_RESERVES, row.reserve_id);
      const orders = snapshot.tableOrders || {}, products = snapshot.products || [], materials = snapshot.materials || [], productBom = snapshot.productBom || [];
      for (const [tableId, items] of Object.entries(orders)) {
        for (const item of (items || [])) {
          const product = products.find(p => String(p.id) === String(item.pid));
          if (!product || product.itemType === 'service') continue;
          for (const bom of productBomRows(productBom, product.id)) {
            const material = materialFor(materials, bom.materialId ?? bom.material_id);
            if (!material) continue;
            const conversion = Math.max(0.000001, numeric(material.conversion_rate ?? material.conversionRate) || 1);
            const amount = Math.max(0, numeric(bom.quantityPerUnit ?? bom.quantity_per_unit)) * Math.max(0, numeric(item.qty)) / conversion;
            if (!amount) continue;
            const reserveId = `BOM:${tableId}:${item.cartKey || item.pid}:${material.id}`;
            await idbPut(STORE_RESERVES, reserveId, { reserve_id: reserveId, table_id: String(tableId), cart_key: item.cartKey || String(item.pid), product_id: product.id, material_id: material.id, quantity_reserved: amount, storage_unit: material.storage_unit || '', updated_at: new Date().toISOString() });
          }
        }
      }
    });
  }
  async function releaseBomReserves(tableId){
    return queueReserveOp(async () => {
      const all = await idbGetAll(STORE_RESERVES);
      for (const row of all) if (String(row.table_id) === String(tableId)) await idbDelete(STORE_RESERVES, row.reserve_id);
    });
  }
  async function finalizeBomReserves(tableId){ return releaseBomReserves(tableId); }

  /* ---------- 2. Đơn hoàn tất thanh toán ---------- */
  async function onOrderPaid(order){
    try {
      const offline = !navigator.onLine;
      const rec = {
        ...order,
        is_offline: offline,
        offline_id: order.offline_id || ('OFF-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)),
        created_at: order.created_at || new Date().toISOString(),
        synced: false,
      };
      if (navigator.onLine && syncUrl()) {
        const ok = await pushOne(rec);
        if (ok) return;
      }
      await idbPut(STORE_QUEUE, rec.offline_id, rec);
      if (offline) updateOfflineBadge();
      else if (syncUrl()) syncNow(false);
      else updateOfflineBadge();
    } catch (e) { console.warn('[offline] xếp hàng đơn lỗi:', e); }
  }

  /* ---------- 3. Phiếu Thu/Chi Sổ Quỹ ---------- */
  async function onCashTransactionCreated(tx){
    try {
      const offline = !navigator.onLine;
      const rec = {
        ...tx,
        is_offline: offline,
        offline_id: tx.offline_id || ('OFF-CASH-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)),
        created_at: tx.created_at || tx.createdAt || new Date().toISOString(),
        synced: false,
      };
      await idbPut(STORE_CASH_TRANSACTIONS, rec.offline_id, rec);
      if (navigator.onLine && syncUrl()) {
        const ok = await pushOneCash(rec);
        if (ok) return rec;
      }
      await idbPut(STORE_CASH_QUEUE, rec.offline_id, rec);
      if (offline) updateOfflineBadge();
      else if (syncUrl()) syncNow(false);
      return rec;
    } catch (e) {
      console.warn('[offline] lưu phiếu thu/chi lỗi:', e);
      return tx;
    }
  }

  async function pushCashBatch(records){
    try {
      const url = cashSyncUrl();
      if (!url) return false;
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Offline-Id': records.map(r => r.offline_id).join(',') },
        body: JSON.stringify({ transactions: records }),
      });
      return res.ok;
    } catch { return false; }
  }
  async function pushOneCash(rec){ return pushCashBatch([rec]); }

  /* ---------- 4. Thao tác bàn Bida / Timeline Events (Offline Queue) ---------- */
  async function queueTableOperation(op){
    try {
      const offline = !navigator.onLine;
      const rec = {
        client_event_id: op.client_event_id || op.id || ('TLOG-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8)),
        table_id: Number(op.table_id || op.tableId || 0),
        event_type: op.event_type || op.type || 'generic_event',
        title: op.title || 'Thao tác bàn',
        details: op.details || op.detail || '',
        reason: op.reason || '',
        actor_name: op.actor_name || op.actor || 'Admin',
        payload: op.payload || op.meta || {},
        occurred_at: op.occurred_at || op.createdAt || new Date().toISOString(),
      };
      await idbPut(STORE_TABLE_QUEUE, rec.client_event_id, rec);
      if (navigator.onLine && syncUrl()) syncNow(false);
      return rec;
    } catch (e) {
      console.warn('[offline] xếp hàng thao tác bàn lỗi:', e);
      return op;
    }
  }

  async function pushTableBatch(records){
    try {
      const url = tableSyncUrl();
      if (!url) return false;
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Offline-Events': records.map(r => r.client_event_id).join(',') },
        body: JSON.stringify({ operations: records }),
      });
      return res.ok;
    } catch { return false; }
  }

  /* ---------- 5. Đồng bộ tuần tự khi có mạng ---------- */
  function serverRecord(rec){
    return { offline_id: rec.offline_id || null, created_at: rec.created_at || rec.time, order: rec };
  }
  async function pushBatch(records){
    try {
      const res = await fetch(syncUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Offline-Id': records.map(r => r.offline_id).join(',') },
        body: JSON.stringify({ orders: records.map(serverRecord) }),
      });
      return res.ok;
    } catch { return false; }
  }
  async function pushOne(rec){ return pushBatch([rec]); }

  async function syncNow(showNotice = false){
    if (syncing || !navigator.onLine) return;
    const url = syncUrl();
    let queue = await idbGetAll(STORE_QUEUE);
    let cashQueue = await idbGetAll(STORE_CASH_QUEUE);
    let tableQueue = await idbGetAll(STORE_TABLE_QUEUE);
    const totalPending = queue.length + cashQueue.length + tableQueue.length;

    if (!totalPending) { hideBannerIfIdle(); updateOfflineBadge(); return; }

    if (!url) {
      if (showNotice) banner('📦 Có ' + totalPending + ' dữ liệu chờ đồng bộ — bấm để cấu hình máy chủ', 'offline');
      return;
    }

    syncing = true;
    if (showNotice) banner('🌐 Đã kết nối internet — Đang đồng bộ ' + totalPending + ' dữ liệu lên hệ thống...', 'syncing');

    let sentOrders = 0;
    queue.sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));
    while (queue.length) {
      const batch = queue.splice(0, 100);
      const ok = await pushBatch(batch);
      if (ok) {
        for (const rec of batch) await idbDelete(STORE_QUEUE, rec.offline_id);
        sentOrders += batch.length;
      } else break;
    }

    let sentCash = 0;
    cashQueue.sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));
    while (cashQueue.length) {
      const batch = cashQueue.splice(0, 100);
      const ok = await pushCashBatch(batch);
      if (ok) {
        for (const rec of batch) await idbDelete(STORE_CASH_QUEUE, rec.offline_id);
        sentCash += batch.length;
      } else break;
    }

    let sentTable = 0;
    tableQueue.sort((a, b) => String(a.occurred_at).localeCompare(String(b.occurred_at)));
    while (tableQueue.length) {
      const batch = tableQueue.splice(0, 100);
      const ok = await pushTableBatch(batch);
      if (ok) {
        for (const rec of batch) await idbDelete(STORE_TABLE_QUEUE, rec.client_event_id);
        sentTable += batch.length;
      } else break;
    }

    syncing = false;
    const leftOrders = (await idbGetAll(STORE_QUEUE)).length;
    const leftCash = (await idbGetAll(STORE_CASH_QUEUE)).length;
    const leftTable = (await idbGetAll(STORE_TABLE_QUEUE)).length;
    const totalLeft = leftOrders + leftCash + leftTable;
    const totalSent = sentOrders + sentCash + sentTable;

    if (totalLeft === 0) {
      if (showNotice) {
        banner('✅ Đã đồng bộ ' + totalSent + ' dữ liệu (đơn hàng, sổ quỹ, bàn Bida) lên hệ thống', 'ok');
        setTimeout(hideBannerIfIdle, 2600);
      } else hideBannerIfIdle();
    } else if (showNotice) {
      banner('⚠️ Còn ' + totalLeft + ' dữ liệu chưa đồng bộ được — sẽ thử lại khi mạng ổn định', 'offline');
    } else hideBannerIfIdle();

    updateOfflineBadge();
  }
  function hideBannerIfIdle(){ if (navigator.onLine && !(syncing)) hideBanner(); }

  async function updateOfflineBadge(){
    if (!navigator.onLine) return;
    const n1 = (await idbGetAll(STORE_QUEUE)).length;
    const n2 = (await idbGetAll(STORE_CASH_QUEUE)).length;
    const n3 = (await idbGetAll(STORE_TABLE_QUEUE)).length;
    const total = n1 + n2 + n3;
    if (total === 0 && !syncing) hideBannerIfIdle();
    return total;
  }

  /* ---------- 6. Khởi tạo ---------- */
  function init(){
    window.addEventListener('offline', () => {
      offlineSession = true;
      banner('📴 Chế độ ngoại tuyến — Dữ liệu Bida & Bán hàng sẽ được lưu tạm trên máy', 'offline');
    });
    window.addEventListener('online', () => {
      const shouldShowNotice = offlineSession;
      offlineSession = false;
      syncNow(shouldShowNotice);
    });
    if (!navigator.onLine) {
      offlineSession = true;
      banner('📴 Chế độ ngoại tuyến — Dữ liệu Bida & Bán hàng sẽ được lưu tạm trên máy', 'offline');
    } else {
      hideBanner();
      if (syncUrl()) syncNow(false);
      else updateOfflineBadge();
    }

    if ('serviceWorker' in navigator && location.protocol.startsWith('http')) {
      navigator.serviceWorker.register('./sw.js')
        .then(() => console.log('[offline] Service Worker đã kích hoạt'))
        .catch(e => console.warn('[offline] SW lỗi:', e));
    }

    idbGetAll(STORE_QUEUE).then(all => {
      const cut = Date.now() - 30*24*3600*1000;
      all.filter(r => new Date(r.created_at).getTime() < cut).forEach(r => idbDelete(STORE_QUEUE, r.offline_id));
    }).catch(()=>{});

    idbGetAll(STORE_CASH_QUEUE).then(all => {
      const cut = Date.now() - 30*24*3600*1000;
      all.filter(r => new Date(r.created_at).getTime() < cut).forEach(r => idbDelete(STORE_CASH_QUEUE, r.offline_id));
    }).catch(()=>{});

    idbGetAll(STORE_TABLE_QUEUE).then(all => {
      const cut = Date.now() - 30*24*3600*1000;
      all.filter(r => new Date(r.occurred_at).getTime() < cut).forEach(r => idbDelete(STORE_TABLE_QUEUE, r.client_event_id));
    }).catch(()=>{});
  }

  return {
    VERSION, init, saveSnapshot, loadSnapshot, syncBomReserves, releaseBomReserves, finalizeBomReserves,
    onOrderPaid, onCashTransactionCreated, queueTableOperation, syncNow,
    queueCount: () => idbGetAll(STORE_QUEUE).then(a => a.length),
    cashQueueCount: () => idbGetAll(STORE_CASH_QUEUE).then(a => a.length),
    tableQueueCount: () => idbGetAll(STORE_TABLE_QUEUE).then(a => a.length),
    configSyncUrl,
  };
})();

document.addEventListener('DOMContentLoaded', () => POSOffline.init());
window.POSOffline = POSOffline;
