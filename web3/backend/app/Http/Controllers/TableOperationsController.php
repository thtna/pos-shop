<?php

namespace App\Http\Controllers;

use App\Models\BillingSession;
use App\Models\BillingSessionPause;
use App\Models\TableLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TableOperationsController extends Controller
{
    /**
     * Lấy toàn bộ timeline/nhật ký của bàn, hỗ trợ lọc theo phiên hiện tại hoặc mọi phiên.
     */
    public function timeline(Request $request, int $tableId)
    {
        $session = BillingSession::query()
            ->where('table_id', $tableId)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        $query = TableLog::query()->where('table_id', $tableId);
        if ($request->boolean('active_session_only') && $session) {
            $query->where('billing_session_id', $session->id);
        }

        $logs = $query->orderByDesc('occurred_at')->limit(300)->get();

        return response()->json([
            'ok' => true,
            'table_id' => $tableId,
            'session' => $session ? $session->load('pauses') : null,
            'billable_seconds' => $session ? $session->billableSeconds() : 0,
            'logs' => $logs,
        ]);
    }

    /**
     * Mở phiên phục vụ tại bàn (bắt đầu tính giờ cho dịch vụ tính giờ/bida).
     */
    public function open(Request $request, int $tableId)
    {
        $data = $request->validate([
            'actor_name' => 'nullable|string|max:100',
            'started_at' => 'nullable|date',
            'order_id' => 'nullable|integer',
            'meta' => 'nullable|array',
            'client_event_id' => 'nullable|string|max:64',
        ]);

        return DB::transaction(function () use ($data, $tableId, $request) {
            $actor = $this->resolveActor($request, $data['actor_name'] ?? null);
            $session = $this->activeSessionForUpdate($tableId);
            $at = isset($data['started_at']) ? Carbon::parse($data['started_at']) : now();

            if (!$session) {
                $session = BillingSession::create([
                    'table_id' => $tableId,
                    'order_id' => $data['order_id'] ?? null,
                    'status' => 'open',
                    'opened_by' => $actor,
                    'started_at' => $at,
                    'meta' => $data['meta'] ?? null,
                ]);

                $this->writeLog(
                    $tableId, $session, 'open_table', 'Mở bàn',
                    'Bắt đầu phiên phục vụ tại bàn', $actor,
                    null, [], $at, $data['client_event_id'] ?? null
                );
            }

            return response()->json([
                'ok' => true,
                'data' => $session->fresh('pauses'),
            ], 200);
        }, 3);
    }

    /**
     * Ghi nhận sự kiện nghiệp vụ (thêm món, hủy món có lý do, in tạm tính...).
     */
    public function recordEvent(Request $request, int $tableId)
    {
        $data = $request->validate([
            'event_type' => 'required|string|max:60',
            'title' => 'required|string|max:160',
            'details' => 'nullable|string|max:1000',
            'reason' => 'nullable|string|max:500',
            'actor_name' => 'nullable|string|max:100',
            'payload' => 'nullable|array',
            'occurred_at' => 'nullable|date',
            'client_event_id' => 'nullable|string|max:64',
        ]);

        $actor = $this->resolveActor($request, $data['actor_name'] ?? null);

        // Kiểm tra phân quyền đối với thao tác nhạy cảm: Hủy món
        if ($data['event_type'] === 'cancel_item') {
            $this->authorizePrivilegedAction($request, 'Hủy món yêu cầu quyền Quản lý / Admin');
            if (empty($data['reason'])) {
                throw ValidationException::withMessages(['reason' => 'Hủy món bắt buộc phải có lý do.']);
            }
        }

        $session = BillingSession::query()->where('table_id', $tableId)->whereNull('ended_at')->latest('id')->first();
        $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        $log = $this->writeLog(
            $tableId, $session, $data['event_type'], $data['title'],
            $data['details'] ?? null, $actor,
            $data['reason'] ?? null, $data['payload'] ?? [], $at,
            $data['client_event_id'] ?? null
        );

        return response()->json(['ok' => true, 'data' => $log]);
    }

    /**
     * Tạm dừng tính giờ cho toàn bộ dịch vụ tính giờ tại bàn.
     */
    public function pause(Request $request, int $tableId)
    {
        $data = $request->validate([
            'actor_name' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:300',
            'occurred_at' => 'nullable|date',
            'client_event_id' => 'nullable|string|max:64',
        ]);

        return DB::transaction(function () use ($data, $tableId, $request) {
            $actor = $this->resolveActor($request, $data['actor_name'] ?? null);
            $session = $this->activeSessionForUpdate($tableId);
            $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

            if (!$session) {
                $session = BillingSession::create([
                    'table_id' => $tableId, 'status' => 'open', 'opened_by' => $actor, 'started_at' => $at,
                ]);
            }

            if ($session->active_pause_started_at) {
                return response()->json(['ok' => true, 'message' => 'Đang trong trạng thái tạm dừng', 'data' => $session->fresh('pauses')]);
            }

            $session->update([
                'status' => 'paused',
                'active_pause_started_at' => $at,
            ]);

            BillingSessionPause::create([
                'billing_session_id' => $session->id,
                'started_at' => $at,
                'paused_by' => $actor,
                'reason' => $data['reason'] ?? 'Tạm dừng tính giờ',
            ]);

            $this->writeLog(
                $tableId, $session, 'pause_service', 'Tạm dừng tính giờ',
                $data['reason'] ?? 'Tạm dừng giờ chơi theo yêu cầu', $actor,
                null, [], $at, $data['client_event_id'] ?? null
            );

            return response()->json(['ok' => true, 'data' => $session->fresh('pauses')]);
        }, 3);
    }

    /**
     * Tiếp tục tính giờ sau khi tạm dừng.
     */
    public function resume(Request $request, int $tableId)
    {
        $data = $request->validate([
            'actor_name' => 'nullable|string|max:100',
            'occurred_at' => 'nullable|date',
            'client_event_id' => 'nullable|string|max:64',
        ]);

        return DB::transaction(function () use ($data, $tableId, $request) {
            $actor = $this->resolveActor($request, $data['actor_name'] ?? null);
            $session = $this->activeSessionForUpdate($tableId);
            $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

            if (!$session || !$session->active_pause_started_at) {
                return response()->json(['ok' => true, 'message' => 'Giờ chơi đang chạy bình thường', 'data' => $session?->fresh('pauses')]);
            }

            $pauseStart = $session->active_pause_started_at;
            $seconds = max(0, $pauseStart->diffInSeconds($at));

            $pause = BillingSessionPause::query()
                ->where('billing_session_id', $session->id)
                ->whereNull('ended_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($pause) {
                $pause->update([
                    'ended_at' => $at,
                    'duration_seconds' => $seconds,
                    'resumed_by' => $actor,
                ]);
            }

            $session->update([
                'status' => 'open',
                'active_pause_started_at' => null,
                'paused_seconds' => (int) $session->paused_seconds + $seconds,
            ]);

            $this->writeLog(
                $tableId, $session, 'resume_service', 'Tiếp tục tính giờ',
                "Kết thúc Pause: {$seconds} giây", $actor,
                null, [], $at, $data['client_event_id'] ?? null
            );

            return response()->json(['ok' => true, 'data' => $session->fresh('pauses')]);
        }, 3);
    }

    /**
     * Đóng phiên sau thanh toán hoặc hủy bàn.
     */
    public function close(Request $request, int $tableId)
    {
        $data = $request->validate([
            'actor_name' => 'nullable|string|max:100',
            'status' => 'nullable|in:paid,cancelled,closed',
            'occurred_at' => 'nullable|date',
            'detail' => 'nullable|string|max:500',
            'rate_per_hour' => 'nullable|numeric|min:0',
            'rounding_mode' => 'nullable|string|in:exact_seconds,minute_round,block_15m,block_30m',
            'client_event_id' => 'nullable|string|max:64',
        ]);

        return DB::transaction(function () use ($data, $tableId, $request) {
            $actor = $this->resolveActor($request, $data['actor_name'] ?? null);
            $session = $this->activeSessionForUpdate($tableId);
            if (!$session) return response()->json(['ok' => true, 'already_closed' => true]);

            $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
            $this->closeOpenPause($session, $at, $actor);
            $status = $data['status'] ?? 'closed';

            $session->update(['status' => $status, 'ended_at' => $at]);

            $this->writeLog(
                $tableId, $session, 'close_table',
                $status === 'cancelled' ? 'Hủy phiên bàn' : 'Kết thúc phục vụ',
                $data['detail'] ?? 'Kết thúc phiên phục vụ', $actor,
                null, [], $at, $data['client_event_id'] ?? null
            );

            $billableSec = $session->fresh()->billableSeconds();
            $serviceFee = null;
            if (!empty($data['rate_per_hour'])) {
                $serviceFee = $session->fresh()->calculateServiceFee(
                    (float) $data['rate_per_hour'],
                    $data['rounding_mode'] ?? 'exact_seconds'
                );
            }

            return response()->json([
                'ok' => true,
                'billable_seconds' => $billableSec,
                'service_fee' => $serviceFee,
                'data' => $session->fresh('pauses'),
            ]);
        }, 3);
    }

    /**
     * Chuyển món hoặc gộp bàn với toàn vẹn dữ liệu và rollback khi có lỗi.
     */
    public function transferOrMerge(Request $request)
    {
        $data = $request->validate([
            'source_table_id' => 'required|integer|different:target_table_id',
            'target_table_id' => 'required|integer',
            'mode' => 'required|in:transfer,merge',
            'actor_name' => 'nullable|string|max:100',
            'occurred_at' => 'nullable|date',
            'source_order_id' => 'nullable|integer',
            'target_order_id' => 'nullable|integer|required_if:mode,merge',
            'client_event_id' => 'nullable|string|max:64',
        ]);

        // Phân quyền cho thao tác Chuyển / Gộp bàn
        $this->authorizePrivilegedAction($request, 'Chuyển / Gộp bàn yêu cầu quyền Quản lý / Admin');

        return DB::transaction(function () use ($data, $request) {
            $actor = $this->resolveActor($request, $data['actor_name'] ?? null);
            $sourceId = (int) $data['source_table_id'];
            $targetId = (int) $data['target_table_id'];
            $at = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

            $source = $this->activeSessionForUpdate($sourceId);
            if (!$source) {
                throw ValidationException::withMessages(['source_table_id' => 'Bàn nguồn không có phiên đang mở.']);
            }

            $target = $this->activeSessionForUpdate($targetId);
            if ($data['mode'] === 'transfer' && $target) {
                throw ValidationException::withMessages(['target_table_id' => 'Bàn đích đang có bill; hãy dùng chế độ Gộp bàn (Merge).']);
            }

            $orderMoved = $this->moveOrderIfRequested($data);

            if ($data['mode'] === 'transfer') {
                $this->writeLog(
                    $sourceId, $source, 'table_transfer', 'Chuyển món sang bàn khác',
                    "Chuyển sang bàn #{$targetId}", $actor,
                    null, ['target_table_id' => $targetId], $at,
                    $data['client_event_id'] ?? null
                );

                $source->update(['table_id' => $targetId]);
                $target = $source->fresh();

                $this->writeLog(
                    $targetId, $target, 'table_transfer', 'Nhận món chuyển bàn',
                    "Nhận từ bàn #{$sourceId}", $actor,
                    null, ['source_table_id' => $sourceId], $at
                );
            } else {
                $target ??= BillingSession::create([
                    'table_id' => $targetId, 'status' => 'open', 'opened_by' => $actor, 'started_at' => $at,
                ]);

                $this->writeLog(
                    $sourceId, $source, 'table_merge', 'Gộp thanh toán sang bàn khác',
                    "Gộp vào bàn #{$targetId}", $actor,
                    null, ['target_table_id' => $targetId], $at,
                    $data['client_event_id'] ?? null
                );

                $this->closeOpenPause($source, $at, $actor);
                $source->update([
                    'status' => 'merged',
                    'ended_at' => $at,
                    'meta' => array_merge($source->meta ?? [], ['merged_into_table_id' => $targetId]),
                ]);

                $this->writeLog(
                    $targetId, $target, 'table_merge', 'Nhận bill gộp',
                    "Nhận bill từ bàn #{$sourceId}", $actor,
                    null, ['source_table_id' => $sourceId], $at
                );
            }

            return response()->json([
                'ok' => true,
                'mode' => $data['mode'],
                'order_reassigned' => $orderMoved,
                'source_session_id' => $source->id,
                'target_session_id' => $target->id,
            ]);
        }, 3);
    }

    /**
     * Đồng bộ hàng loạt thao tác bàn tạo lúc offline lên server (Idempotent theo client_event_id).
     */
    public function offlineSync(Request $request)
    {
        $rawRecords = $request->has('operations') ? $request->input('operations') : [$request->all()];
        $records = $request->validate([
            'operations' => 'required|array|min:1|max:300',
            'operations.*.client_event_id' => 'required|string|max:64',
            'operations.*.table_id' => 'required|integer',
            'operations.*.event_type' => 'required|string|max:60',
            'operations.*.title' => 'required|string|max:160',
            'operations.*.details' => 'nullable|string|max:1000',
            'operations.*.reason' => 'nullable|string|max:500',
            'operations.*.actor_name' => 'nullable|string|max:100',
            'operations.*.payload' => 'nullable|array',
            'operations.*.occurred_at' => 'nullable|date',
        ])['operations'];

        $synced = 0;
        foreach ($records as $op) {
            $at = !empty($op['occurred_at']) ? Carbon::parse($op['occurred_at']) : now();
            TableLog::updateOrCreate(
                ['client_event_id' => $op['client_event_id']],
                [
                    'table_id' => (int) $op['table_id'],
                    'event_type' => $op['event_type'],
                    'title' => $op['title'],
                    'details' => $op['details'] ?? null,
                    'reason' => $op['reason'] ?? null,
                    'actor_name' => $op['actor_name'] ?? 'Admin',
                    'payload' => $op['payload'] ?? null,
                    'occurred_at' => $at,
                ]
            );
            $synced++;
        }

        return response()->json(['ok' => true, 'synced' => $synced], 200);
    }

    private function resolveActor(Request $request, ?string $fallback): string
    {
        if ($request->user()) {
            return $request->user()->name ?? 'User #' . $request->user()->id;
        }
        $headerActor = $request->header('X-POS-Staff');
        if ($headerActor) return trim($headerActor);
        return $fallback ?: 'Admin';
    }

    private function authorizePrivilegedAction(Request $request, string $message): void
    {
        // Khi chạy với Sanctum: kiểm tra quyền của User
        if ($request->user()) {
            $role = $request->user()->role ?? 'staff';
            if (!in_array($role, ['admin', 'manager', 'owner'])) {
                throw ValidationException::withMessages(['actor_name' => $message]);
            }
            return;
        }

        // Khi gọi từ POS local qua Header/PIN role
        $roleHeader = $request->header('X-POS-Role');
        if ($roleHeader && !in_array(strtolower($roleHeader), ['admin', 'manager'])) {
            throw ValidationException::withMessages(['actor_name' => $message]);
        }
    }

    private function activeSessionForUpdate(int $tableId): ?BillingSession
    {
        return BillingSession::query()
            ->where('table_id', $tableId)
            ->whereNull('ended_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function closeOpenPause(BillingSession $session, Carbon $at, ?string $actor): void
    {
        if (!$session->active_pause_started_at) return;
        $seconds = max(0, $session->active_pause_started_at->diffInSeconds($at));
        $pause = BillingSessionPause::query()
            ->where('billing_session_id', $session->id)
            ->whereNull('ended_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($pause) {
            $pause->update([
                'ended_at' => $at,
                'duration_seconds' => $seconds,
                'resumed_by' => $actor,
            ]);
        }

        $session->update([
            'active_pause_started_at' => null,
            'paused_seconds' => (int) $session->paused_seconds + $seconds,
        ]);
    }

    private function writeLog(
        int $tableId, ?BillingSession $session, string $event, string $title,
        ?string $details, ?string $actor, ?string $reason = null,
        array $payload = [], ?Carbon $occurredAt = null,
        ?string $clientEventId = null
    ): TableLog {
        if ($clientEventId) {
            return TableLog::updateOrCreate(
                ['client_event_id' => $clientEventId],
                [
                    'table_id' => $tableId,
                    'billing_session_id' => $session?->id,
                    'event_type' => $event,
                    'title' => $title,
                    'details' => $details,
                    'reason' => $reason,
                    'actor_name' => $actor,
                    'payload' => $payload ?: null,
                    'occurred_at' => $occurredAt ?? now(),
                ]
            );
        }

        return TableLog::create([
            'client_event_id' => 'TLOG-' . time() . '-' . bin2hex(random_bytes(3)),
            'table_id' => $tableId,
            'billing_session_id' => $session?->id,
            'event_type' => $event,
            'title' => $title,
            'details' => $details,
            'reason' => $reason,
            'actor_name' => $actor,
            'payload' => $payload ?: null,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function moveOrderIfRequested(array $data): bool
    {
        if (empty($data['source_order_id'])) return false;
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'table_id')) {
            throw ValidationException::withMessages(['source_order_id' => 'Backend cần cột orders.table_id trước khi gán bill thật.']);
        }

        $source = DB::table('orders')->where('id', $data['source_order_id'])->lockForUpdate()->first();
        if (!$source) throw ValidationException::withMessages(['source_order_id' => 'Không tìm thấy bill nguồn.']);

        if ($data['mode'] === 'transfer') {
            DB::table('orders')->where('id', $source->id)->update([
                'table_id' => $data['target_table_id'],
                'updated_at' => now(),
            ]);
            return true;
        }

        $targetId = (int) ($data['target_order_id'] ?? 0);
        $target = DB::table('orders')->where('id', $targetId)->lockForUpdate()->first();
        if (!$target) throw ValidationException::withMessages(['target_order_id' => 'Không tìm thấy bill đích để gộp.']);

        if (Schema::hasTable('order_items')) {
            DB::table('order_items')->where('order_id', $source->id)->update(['order_id' => $target->id]);

            // Tính toán lại tổng tiền của bill đích sau khi nhận thêm món
            if (Schema::hasColumn('order_items', 'line_total')) {
                $newSub = (float) DB::table('order_items')->where('order_id', $target->id)->sum('line_total');
                $disc = (float) ($target->disc ?? 0);
                $tax = (float) ($target->tax ?? 0);
                $newTotal = max(0, $newSub - $disc + $tax);

                DB::table('orders')->where('id', $target->id)->update([
                    'sub' => $newSub,
                    'total' => $newTotal,
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasColumn('orders', 'status')) {
            DB::table('orders')->where('id', $source->id)->update([
                'status' => 'merged',
                'updated_at' => now(),
            ]);
        }

        return true;
    }
}
