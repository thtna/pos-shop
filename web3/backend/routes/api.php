<?php

/** routes/api.php — bổ sung route nhận đơn đồng bộ offline */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CryptoPaymentController;
use App\Http\Controllers\OfflineOrderController;

Route::prefix('crypto')->group(function () {
    Route::get('config', [CryptoPaymentController::class, 'config']);
    Route::get('rate', [CryptoPaymentController::class, 'rate'])->middleware('throttle:30,1');
    Route::post('verify', [CryptoPaymentController::class, 'verify'])->middleware('throttle:10,1');
});

// Đồng bộ đơn hàng tạo lúc mất mạng (POS gửi tuần tự khi có mạng lại)
Route::post('orders/offline-sync', [OfflineOrderController::class, 'store'])->middleware('throttle:60,1');
