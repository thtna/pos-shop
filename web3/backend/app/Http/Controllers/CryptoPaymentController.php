<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CryptoPaymentController — Thanh toán USDT (BEP20) cho POS Shop
 *
 * Bảo mật cốt lõi: KHÔNG BAO GIỜ tin trạng thái từ frontend.
 * Mọi giao dịch đều được xác minh lại qua RPC node với 3 điều kiện:
 *   1. receipt.status == 0x1 (thành công)
 *   2. Log Transfer gửi tới ĐÚNG địa chỉ ví nhận (từ .env)
 *   3. Giá trị transfer >= số tiền đơn hàng quy ra wei
 */
class CryptoPaymentController extends Controller
{
    /** GET /api/crypto/config — cấu hình public cho frontend */
    public function config()
    {
        return response()->json([
            'receiver'       => config('crypto.receiver'),
            'usdtContract'   => config('crypto.usdt_contract'),
            'decimals'       => (int) config('crypto.usdt_decimals', 18),
            'chainIdDecimal' => (int) config('crypto.chain_id', 56),
            'chainIdHex'     => '0x' . dechex(config('crypto.chain_id', 56)),
            'rpcUrl'         => config('crypto.rpc_url'),
            'confirmations'  => (int) config('crypto.confirmations', 3),
            'explorer'       => 'https://bscscan.com',
        ]);
    }

    /** GET /api/crypto/rate — proxy tỷ giá VND/USDT (cache 60s, tránh CORS + rate-limit Binance) */
    public function rate()
    {
        $rate = Cache::remember('crypto_usdt_vnd', 60, function () {
            try {
                // USDTVND không có trên Binance → lấy USDT/USD * USD/VND (CoinGecko + exchange rate fallback)
                $binance = Http::timeout(5)->get('https://api.binance.com/api/v3/ticker/price', [
                    'symbol' => 'USDTUSDT', // placeholder giữ cấu trúc; Binance không có cặp này
                ]);
                $usdUsdt = 1.0; // USDT ~ 1 USD (stablecoin)
                try {
                    $usdVnd = Http::timeout(5)->get('https://open.er-api.com/v6/latest/USD')->json('rates.VND');
                } catch (\Throwable) {
                    $usdVnd = null;
                }
                if (!$usdVnd) {
                    try {
                        // CoinGecko: giá VND per USDT trực tiếp
                        $cg = Http::timeout(5)->get('https://api.coingecko.com/api/v3/simple/price', [
                            'ids' => 'tether', 'vs_currencies' => 'vnd',
                        ])->json('tether.vnd');
                        $usdVnd = $cg;
                    } catch (\Throwable) { $usdVnd = null; }
                }
                return $usdVnd ?: (float) config('crypto.vnd_usd_fallback', 25000);
            } catch (\Throwable $e) {
                Log::warning('crypto rate fail: ' . $e->getMessage());
                return (float) config('crypto.vnd_usd_fallback', 25000);
            }
        });

        return response()->json(['vndPerUsdt' => (float) $rate]);
    }

    /** POST /api/crypto/verify — xác minh giao dịch độc lập rồi mới "paid" đơn */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'tx_hash'  => 'required|string|regex:/^0x[0-9a-fA-F]{64}$/',
        ]);

        $order = \App\Models\Order::findOrFail($data['order_id']);
        if ($order->payment_status === 'paid') {
            return response()->json(['ok' => true, 'already' => true]); // idempotent
        }
        if ($order->payment_method !== 'crypto') {
            return response()->json(['ok' => false, 'error' => 'Đơn không phải thanh toán crypto'], 422);
        }
        if (Cache::has("tx_used_{$data['tx_hash']}")) {
            return response()->json(['ok' => false, 'error' => 'TX đã dùng cho đơn khác'], 422); // chống replay
        }

        $receipt = $this->rpc('eth_getTransactionReceipt', [$data['tx_hash']]);
        if (!$receipt) {
            return response()->json(['ok' => false, 'error' => 'Giao dịch chưa được xác nhận trên blockchain'], 409);
        }
        if (($receipt['status'] ?? '') !== '0x1') {
            return response()->json(['ok' => false, 'error' => 'Giao dịch thất bại (reverted)'], 422);
        }

        // Đủ số block xác nhận?
        $current  = hexdec($this->rpc('eth_blockNumber', [])['result'] ?? '0x0');
        $txBlock  = hexdec($receipt['blockNumber']);
        $confirms = $current - $txBlock + 1;
        if ($confirms < (int) config('crypto.confirmations', 3)) {
            return response()->json(['ok' => false, 'error' => "Chưa đủ xác nhận ($confirms)"], 409);
        }

        // Quét logs tìm event Transfer(topic0) tới đúng ví nhận với số tiền >= đơn
        $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        $receiverLower = strtolower(config('crypto.receiver'));
        $decimals      = (int) config('crypto.usdt_decimals', 18);
        $expectedWei   = gmp_init(bcmul((string) $order->total_usdt, bcpow('10', (string) $decimals)));

        $paidWei = gmp_init('0');
        foreach ($receipt['logs'] ?? [] as $log) {
            if (strtolower($log['address'] ?? '') !== strtolower(config('crypto.usdt_contract'))) continue;
            $topics = $log['topics'] ?? [];
            if (count($topics) < 3 || strcasecmp($topics[0], $transferTopic) !== 0) continue;
            if (strtolower($topics[2]) !== $receiverLower) continue; // người nhận sai
            $paidWei = gmp_add($paidWei, gmp_init($log['data'], 16));
        }

        if (gmp_cmp($paidWei, $expectedWei) < 0) {
            return response()->json(['ok' => false, 'error' => 'Số tiền nhận được ít hơn đơn hàng'], 422);
        }

        // OK — cập nhật đơn
        $order->update([
            'payment_status'    => 'paid',
            'paid_tx_hash'      => $data['tx_hash'],
            'paid_amount_wei'   => gmp_strval($paidWei),
            'paid_confirmations' => $confirms,
            'paid_at'           => now(),
        ]);
        Cache::put("tx_used_{$data['tx_hash']}", $order->id, now()->addDays(30));

        return response()->json(['ok' => true, 'order_id' => $order->id, 'confirmations' => $confirms]);
    }

    /** Gọi JSON-RPC tới node */
    private function rpc(string $method, array $params)
    {
        try {
            $res = Http::timeout(10)->post(config('crypto.rpc_url'), [
                'jsonrpc' => '2.0', 'id' => uniqid(), 'method' => $method, 'params' => $params,
            ])->json();
            return $res['result'] ?? null;
        } catch (\Throwable $e) {
            Log::warning("RPC $method fail: " . $e->getMessage());
            return null;
        }
    }
}
