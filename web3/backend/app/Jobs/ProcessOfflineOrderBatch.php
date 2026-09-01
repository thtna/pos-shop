<?php

namespace App\Jobs;

use App\Models\Material;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Xử lý đơn offline sau khi API đã phản hồi 200; idempotent theo offline_id. */
class ProcessOfflineOrderBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /** @param array<int, array<string, mixed>> $records */
    public function __construct(public array $records) {}

    public function handle(): void
    {
        // Không nuốt lỗi: Queue sẽ retry và cuối cùng ghi vào failed_jobs để quản lý đối soát.
        // Các bản ghi đã thành công ở lần chạy trước được bỏ qua nhờ offline_id idempotent.
        foreach ($this->records as $record) $this->storeOne($record);
    }

    /** @param array<string, mixed> $record */
    private function storeOne(array $record): void
    {
        $offlineId = (string) ($record['offline_id'] ?? '');
        $order = $record['order'] ?? [];
        if ($offlineId === '' || !is_array($order)) throw new \InvalidArgumentException('Offline payload không hợp lệ.');

        DB::transaction(function () use ($record, $offlineId, $order) {
            if (DB::table('orders')->where('offline_id', $offlineId)->exists()) return;

            $purchasedAt = !empty($record['created_at']) ? Carbon::parse($record['created_at']) : now();
            $orderId = DB::table('orders')->insertGetId([
                'pos_order_id' => $order['id'], 'offline_id' => $offlineId, 'table_name' => $order['table'], 'zone' => $order['zone'] ?? null,
                'sub_total' => $order['sub'] ?? $order['total'], 'discount' => $order['disc'] ?? 0, 'total' => $order['total'],
                'payment_method' => $order['method'], 'payment_status' => 'paid', 'tx_hash' => $order['txHash'] ?? null,
                'is_offline' => true, 'purchased_at' => $purchasedAt, 'created_at' => now(),
            ]);

            foreach (($order['items'] ?? []) as $item) {
                $orderItemId = DB::table('order_items')->insertGetId([
                    'order_id' => $orderId, 'product_id' => $item['pid'], 'product_name' => $item['name'],
                    'qty' => $item['qty'], 'amount' => $item['lineTotal'] ?? null,
                ]);
                $snapshots = $item['bom_snapshots'] ?? $item['bomSnapshots'] ?? [];
                foreach ($snapshots as $snapshot) {
                    $materialId = $snapshot['material_id'] ?? $snapshot['materialId'] ?? null;
                    $quantity = (float) ($snapshot['quantity_used'] ?? $snapshot['quantityUsed'] ?? 0);
                    if (!$materialId || $quantity <= 0) continue;
                    // POS offline có thể dùng ID local; khi đó đối chiếu mã nguyên liệu duy nhất trên server.
                    $material = Material::query()->where(function ($query) use ($materialId, $snapshot) {
                        $query->whereKey($materialId);
                        if (!empty($snapshot['material_code'])) $query->orWhere('code', $snapshot['material_code']);
                    })->lockForUpdate()->firstOrFail();
                    if ((float) $material->current_stock + 0.000001 < $quantity) throw new \RuntimeException("Nguyên liệu {$material->name} không đủ khi đồng bộ đơn {$offlineId}.");
                    $material->decrement('current_stock', $quantity);
                    DB::table('order_item_bom_snapshots')->insert([
                        'order_item_id' => $orderItemId, 'material_id' => $material->id, 'material_name' => $snapshot['material_name'] ?? $material->name,
                        'quantity_used' => $quantity, 'cost_price' => $snapshot['cost_price'] ?? $material->avg_cost,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }, 3);
    }
}
