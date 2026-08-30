<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OfflineOrderController — Nhận đơn hàng đồng bộ từ POS khi có mạng lại
 *
 * Nguyên tắc:
 *  - Đơn tạo lúc MẤT MẠNG có cờ is_offline + offline_id + created_at (mốc khách mua thật)
 *  - Báo cáo doanh thu / lịch sử bán hàng ghi theo created_at, KHÔNG phải thời gian đồng bộ
 *  - Idempotent: 1 offline_id chỉ nhận 1 lần (POS gửi lại không bị nhân đôi)
 *  - Trả 200 khi lưu thành công → POS tự xóa khỏi hàng chờ
 */
class OfflineOrderController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'offline_id'          => 'nullable|string|max:64',
            'created_at'          => 'nullable|date',
            'order'               => 'required|array',
            'order.id'            => 'required|integer',
            'order.table'         => 'required|string|max:100',
            'order.total'         => 'required|numeric|min:0',
            'order.sub'           => 'nullable|numeric|min:0',
            'order.disc'          => 'nullable|numeric|min:0',
            'order.method'        => 'required|string|in:cash,card,transfer,crypto',
            'order.items'         => 'required|array|min:1',
            'order.items.*.pid'   => 'required|integer',
            'order.items.*.name'  => 'required|string',
            'order.items.*.qty'   => 'required|integer|min:1',
            'order.txHash'        => 'nullable|string',
        ]);

        $order = $data['order'];
        $offlineId = $data['offline_id'] ?? null;

        // Idempotency: đã nhận offline_id này rồi → trả 200 để POS xóa khỏi hàng chờ
        if ($offlineId && DB::table('orders')->where('offline_id', $offlineId)->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            DB::transaction(function () use ($order, $offlineId, $data) {
                // created_at = thời điểm khách mua thật (POS gửi từ đơn offline)
                $purchasedAt = $data['created_at'] ?? now();

                $orderId = DB::table('orders')->insertGetId([
                    'pos_order_id'  => $order['id'],
                    'offline_id'    => $offlineId,
                    'table_name'    => $order['table'],
                    'zone'          => $order['zone'] ?? null,
                    'sub_total'     => $order['sub'] ?? $order['total'],
                    'discount'      => $order['disc'] ?? 0,
                    'total'         => $order['total'],
                    'payment_method'=> $order['method'],
                    'payment_status'=> 'paid',
                    'tx_hash'       => $order['txHash'] ?? null,
                    'is_offline'    => $offlineId ? true : false,
                    'purchased_at'  => $purchasedAt,   // ← BÁO CÁO DOANH THU DỰA VÀO ĐÂY
                    'created_at'    => now(),
                ]);

                foreach ($order['items'] as $item) {
                    DB::table('order_items')->insert([
                        'order_id' => $orderId,
                        'product_id' => $item['pid'],
                        'product_name' => $item['name'],
                        'qty' => $item['qty'],
                        'amount' => $item['lineTotal'] ?? null,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('offline order sync fail: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'Lưu đơn lỗi'], 500); // POS giữ lại thử sau
        }

        return response()->json(['ok' => true, 'duplicate' => false]);
    }
}
