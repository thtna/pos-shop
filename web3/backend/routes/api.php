<?php

/** routes/api.php — thêm các route thanh toán crypto */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CryptoPaymentController;

Route::prefix('crypto')->group(function () {
    // Public config cho frontend (ví nhận, contract, chain) — KHÔNG chứa private key
    Route::get('config', [CryptoPaymentController::class, 'config']);

    // Proxy tỷ giá (cache 60s)
    Route::get('rate', [CryptoPaymentController::class, 'rate'])->middleware('throttle:30,1');

    // Xác minh giao dịch + cập nhật đơn "paid" (rate-limit chống spam)
    Route::post('verify', [CryptoPaymentController::class, 'verify'])->middleware('throttle:10,1');
});
