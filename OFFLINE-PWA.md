# POS Shop — Offline-First (PWA)

Web bán hàng hoạt động **bình thường khi mất internet**, tự đồng bộ khi có mạng lại.

## Các file

| File | Vai trò |
|---|---|
| `sw.js` | Service Worker: cache UI + tài nguyên tĩnh. Navigation = network-first (mất mạng trả bản cache); tài nguyên = cache-first + cập nhật nền. POST không bao giờ cache |
| `manifest.json` | PWA manifest — cài được lên desktop/mobile như app |
| `pwa-offline.js` | Engine: IndexedDB (`posshop_offline`: store `catalog` + `offline_orders_queue`), banner trạng thái, hàng chờ & auto-sync tuần tự |

## Cách hoạt động

1. **Khi có mạng**: mỗi lần `persist()` → bản sao danh mục (sản phẩm, bàn, khu vực, cấu hình) lưu vào IndexedDB.
2. **Khi mất mạng**: mọi thao tác (gọi món, giỏ hàng, thanh toán, in) chạy bình thường từ localStorage + IndexedDB. Đơn hoàn tất được gắn:
   - `is_offline: true`
   - `offline_id: "OFF-<timestamp>-<random>"`
   - `created_at: <ISO thời điểm khách mua>`
   rồi xếp vào `offline_orders_queue`.
3. **Banner trạng thái** (góc phải trên):
   - 📴 *"Chế độ ngoại tuyến — Các đơn hàng sẽ được lưu tạm trên máy"*
   - 📦 *"Có X đơn ngoại tuyến chờ đồng bộ"* (bấm vào để cấu hình máy chủ)
   - 🌐 *"Đang đồng bộ X đơn hàng lên hệ thống..."*
   - ✅ *"Đã đồng bộ X đơn hàng"*
4. **Khi có mạng lại**: bắt sự kiện `window.online` → gửi tuần tự (sequential) từng đơn theo thứ tự `created_at` → backend trả 200 thì xóa khỏi hàng chờ (idempotent, không trùng lặp). Mất mạng giữa chừng thì dừng, lần sau tiếp tục.

## Cấu hình máy chủ đồng bộ

Bấm vào banner trạng thái → nhập địa chỉ backend, VD:
```
http://192.168.1.10:8000/api/orders/offline-sync
```
Để trống / bấm "Tắt đồng bộ" → chỉ lưu local (vẫn bán được, không gửi đi đâu).

## Backend Laravel

Xem `web3/backend/app/Http/Controllers/OfflineOrderController.php` + route `POST /api/orders/offline-sync`:
- Lưu đơn theo **`created_at` (thời điểm khách mua)** → báo cáo doanh thu & lịch sử bán hàng đúng ca làm việc, không phải thời gian đồng bộ
- **Idempotent** theo `offline_id` — POS gửi lại bao nhiêu lần cũng không nhân đôi đơn
- Trả 200 khi lưu xong → POS tự xóa khỏi hàng chờ

## Lưu ý

- Service Worker chỉ hoạt động qua **http/https** (không chạy khi mở file index.html trực tiếp) — luôn chạy web qua `start-posshop.bat`
- Cập nhật UI mới: đổi `CACHE_NAME` trong `sw.js` (VD `posshop-v3`) rồi F5 2 lần
- Hàng chờ tự dọn đơn cũ hơn 30 ngày
