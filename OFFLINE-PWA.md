# POS Shop — Offline-First (PWA)

Web bán hàng hoạt động **bình thường khi mất internet**, tự đồng bộ khi có mạng lại.

## Các file

| File | Vai trò |
|---|---|
| `sw.js` | Service Worker: cache UI + tài nguyên tĩnh. Navigation = network-first (mất mạng trả bản cache); tài nguyên = cache-first + cập nhật nền. POST không bao giờ cache |
| `manifest.json` | PWA manifest — cài được lên desktop/mobile như app |
| `pwa-offline.js` | Engine: IndexedDB (`catalog` gồm cả Nhật ký bàn/Pause, `materials`, `product_bom`, `offline_inventory_reserves`, `offline_orders_queue`), banner trạng thái, hàng chờ & auto-sync tuần tự |

## Cách hoạt động

1. **Khi có mạng hoặc offline**: mỗi lần `persist()` → bản sao danh mục, nguyên liệu và công thức BOM lưu vào IndexedDB.
2. **Khi gọi món**: BOM được quy đổi về đơn vị kho và ghi vào `offline_inventory_reserves`. Những bàn khác chỉ thấy phần tồn còn khả dụng; dịch vụ tính giờ không bao giờ tạo reserve.
3. **Khi xóa/hủy đơn**: reserve của bàn được hoàn trả. Khi thanh toán, POS trừ tồn thật ở local rồi xóa reserve và gửi kèm `bom_snapshots` lên backend.
4. **Khi mất mạng**: mọi thao tác (gọi món, giỏ hàng, thanh toán, in) chạy bình thường từ localStorage + IndexedDB. Đơn hoàn tất được gắn:
   - `is_offline: true`
   - `offline_id: "OFF-<timestamp>-<random>"`
   - `created_at: <ISO thời điểm khách mua>`
   rồi xếp vào `offline_orders_queue`.
5. **Banner trạng thái** (góc phải trên, chỉ hiện khi POS đang mất mạng hoặc vừa có mạng lại sau khi mất mạng):
   - 📴 *"Chế độ ngoại tuyến — Các đơn hàng sẽ được lưu tạm trên máy"*
   - 📦 *"Có X đơn chờ đồng bộ"* khi chưa cấu hình máy chủ (bấm vào để cấu hình)
   - 🌐 *"Đang đồng bộ X đơn hàng lên hệ thống..."*
   - ✅ *"Đã đồng bộ X đơn hàng"*
6. **Khi có mạng lại**: bắt sự kiện `window.online` → gửi tuần tự (sequential) từng đơn theo thứ tự `created_at` → backend trả 200 thì xóa khỏi hàng chờ (idempotent, không trùng lặp). Mất mạng giữa chừng thì dừng, lần sau tiếp tục. Khi POS mở và bán hàng trong trạng thái online bình thường, banner này không xuất hiện; các đơn cũ chỉ đồng bộ nền để giao diện luôn gọn.

## Cấu hình máy chủ đồng bộ

Bấm vào banner trạng thái → nhập địa chỉ backend, VD:
```
http://192.168.1.10:8000/api/orders/offline-sync
```
Để trống / bấm "Tắt đồng bộ" → chỉ lưu local (vẫn bán được, không gửi đi đâu).

## Backend Laravel

Xem `web3/backend/app/Http/Controllers/OfflineOrderController.php` + route `POST /api/orders/offline-sync` (hỗ trợ một đơn hoặc `{ orders: [...] }` tối đa 100 đơn):
- Lưu đơn theo **`created_at` (thời điểm khách mua)** → báo cáo doanh thu & lịch sử bán hàng đúng ca làm việc, không phải thời gian đồng bộ
- **Idempotent** theo `offline_id` — POS gửi lại bao nhiêu lần cũng không nhân đôi đơn
- API xếp Queue và trả 200 ngay → POS tự xóa khỏi hàng chờ; worker `ProcessOfflineOrderBatch` lock nguyên liệu, trừ kho thật và chốt bảng `order_item_bom_snapshots`.
- Trên Laravel thật, đặt `QUEUE_CONNECTION=database` hoặc `redis`, chạy migration rồi chạy `php artisan queue:work`. Không dùng `sync` nếu cần phản hồi tức thì.

## Lưu ý

- Service Worker chỉ hoạt động qua **http/https** (không chạy khi mở file index.html trực tiếp) — luôn chạy web qua `start-posshop.bat`
- Cập nhật UI mới: đổi `CACHE_NAME` trong `sw.js` (hiện là `posshop-v8-billiards-ops`) rồi F5 2 lần
- Hàng chờ tự dọn đơn cũ hơn 30 ngày
