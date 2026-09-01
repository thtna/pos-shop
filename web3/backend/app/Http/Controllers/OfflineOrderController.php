<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOfflineOrderBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Nhận một đơn hoặc batch đơn offline, xếp queue và trả 200 ngay.
 * Worker queue sau đó mới khóa nguyên liệu, trừ kho thật và chốt BOM snapshot.
 */
class OfflineOrderController extends Controller
{
    public function store(Request $request)
    {
        $rawRecords = $request->has('orders') ? $request->input('orders') : [$request->all()];
        $records = Validator::make(['orders' => $rawRecords], [
            'orders' => 'required|array|min:1|max:100',
            'orders.*.offline_id' => 'required|string|max:64',
            'orders.*.created_at' => 'nullable|date',
            'orders.*.order' => 'required|array',
            'orders.*.order.id' => 'required|integer',
            'orders.*.order.table' => 'required|string|max:100',
            'orders.*.order.total' => 'required|numeric|min:0',
            'orders.*.order.sub' => 'nullable|numeric|min:0',
            'orders.*.order.disc' => 'nullable|numeric|min:0',
            'orders.*.order.method' => 'required|string|in:cash,card,transfer,crypto',
            'orders.*.order.items' => 'required|array|min:1',
            'orders.*.order.items.*.pid' => 'required|integer',
            'orders.*.order.items.*.name' => 'required|string|max:255',
            'orders.*.order.items.*.qty' => 'required|integer|min:1',
            'orders.*.order.items.*.bom_snapshots' => 'nullable|array',
            'orders.*.order.items.*.bom_snapshots.*.quantity_used' => 'nullable|numeric|min:0',
            'orders.*.order.items.*.bom_snapshots.*.cost_price' => 'nullable|numeric|min:0',
            'orders.*.order.txHash' => 'nullable|string|max:100',
        ])->validate()['orders'];

        // Cần cấu hình QUEUE_CONNECTION database/redis và chạy: php artisan queue:work
        ProcessOfflineOrderBatch::dispatch($records);

        return response()->json(['ok' => true, 'queued' => count($records)], 200);
    }
}
