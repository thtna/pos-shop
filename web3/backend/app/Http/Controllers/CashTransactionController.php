<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;

class CashTransactionController extends Controller
{
    /**
     * Danh sách phiếu thu/chi kèm bộ lọc và tóm tắt tổng số tiền.
     */
    public function index(Request $request)
    {
        $query = CashTransaction::query();

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('from')));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('to')));
        }
        if ($request->filled('type') && in_array($request->input('type'), ['thu', 'chi'])) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }
        if ($request->filled('category')) {
            $query->where('category_name', 'like', '%' . $request->input('category') . '%');
        }

        $transactions = $query->orderBy('created_at', 'desc')->get();

        $totalThu = (clone $query)->where('type', 'thu')->sum('amount');
        $totalChi = (clone $query)->where('type', 'chi')->sum('amount');
        $cashThu = (clone $query)->where('type', 'thu')->where('payment_method', 'cash')->sum('amount');
        $cashChi = (clone $query)->where('type', 'chi')->where('payment_method', 'cash')->sum('amount');

        return response()->json([
            'ok' => true,
            'summary' => [
                'total_thu' => (float)$totalThu,
                'total_chi' => (float)$totalChi,
                'cash_thu' => (float)$cashThu,
                'cash_chi' => (float)$cashChi,
                'net_cash_flow' => (float)($cashThu - $cashChi),
            ],
            'data' => $transactions,
        ]);
    }

    /**
     * Tạo mới 1 phiếu thu hoặc phiếu chi
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:thu,chi',
            'amount' => 'required|numeric|min:1',
            'category_name' => 'required|string|max:150',
            'payment_method' => 'nullable|string|in:cash,transfer',
            'note' => 'nullable|string|max:1000',
            'user_name' => 'nullable|string|max:100',
            'offline_id' => 'nullable|string|max:64',
            'code' => 'nullable|string|max:32',
            'created_at' => 'nullable|date',
        ]);

        if (empty($validated['code'])) {
            $prefix = $validated['type'] === 'thu' ? 'PT' : 'PC';
            $count = CashTransaction::where('type', $validated['type'])->count() + 1;
            $validated['code'] = $prefix . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
        }

        if (empty($validated['payment_method'])) {
            $validated['payment_method'] = 'cash';
        }

        $transaction = CashTransaction::create($validated);

        return response()->json([
            'ok' => true,
            'message' => 'Tạo phiếu ' . ($transaction->type === 'thu' ? 'thu' : 'chi') . ' thành công',
            'data' => $transaction,
        ], 201);
    }

    /**
     * Nhận đồng bộ danh sách phiếu Thu/Chi offline từ POS.
     * Sử dụng updateOrCreate theo offline_id để đảm bảo tính Idempotent (không bị trùng lặp).
     */
    public function offlineSync(Request $request)
    {
        $rawRecords = $request->has('transactions') ? $request->input('transactions') : [$request->all()];
        $validated = Validator::make(['transactions' => $rawRecords], [
            'transactions' => 'required|array|min:1|max:200',
            'transactions.*.offline_id' => 'required|string|max:64',
            'transactions.*.code' => 'nullable|string|max:32',
            'transactions.*.type' => 'required|string|in:thu,chi',
            'transactions.*.amount' => 'required|numeric|min:0',
            'transactions.*.category_name' => 'required|string|max:150',
            'transactions.*.payment_method' => 'nullable|string|in:cash,transfer',
            'transactions.*.note' => 'nullable|string|max:1000',
            'transactions.*.user_name' => 'nullable|string|max:100',
            'transactions.*.created_at' => 'nullable|date',
        ])->validate()['transactions'];

        $saved = [];
        foreach ($validated as $item) {
            $tx = CashTransaction::updateOrCreate(
                ['offline_id' => $item['offline_id']],
                [
                    'code' => $item['code'] ?? ($item['type'] === 'thu' ? 'PT-OFF' : 'PC-OFF'),
                    'type' => $item['type'],
                    'amount' => $item['amount'],
                    'category_name' => $item['category_name'],
                    'payment_method' => $item['payment_method'] ?? 'cash',
                    'note' => $item['note'] ?? null,
                    'user_name' => $item['user_name'] ?? null,
                    'created_at' => !empty($item['created_at']) ? Carbon::parse($item['created_at']) : Carbon::now(),
                ]
            );
            $saved[] = $tx->id;
        }

        return response()->json([
            'ok' => true,
            'synced' => count($saved),
            'ids' => $saved,
        ], 200);
    }
}
