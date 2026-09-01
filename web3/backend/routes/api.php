<?php

/** routes/api.php — bổ sung route nhận đơn đồng bộ offline */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CryptoPaymentController;
use App\Http\Controllers\OfflineOrderController;
use App\Http\Controllers\TableOperationsController;

Route::prefix('crypto')->group(function () {
    Route::get('config', [CryptoPaymentController::class, 'config']);
    Route::get('rate', [CryptoPaymentController::class, 'rate'])->middleware('throttle:30,1');
    Route::post('verify', [CryptoPaymentController::class, 'verify'])->middleware('throttle:10,1');
});

// Đồng bộ đơn hàng tạo lúc mất mạng (POS gửi tuần tự khi có mạng lại)
Route::post('orders/offline-sync', [OfflineOrderController::class, 'store'])->middleware('throttle:60,1');
Route::post('orders/offline-sync/batch', [OfflineOrderController::class, 'store'])->middleware('throttle:60,1');

// ================= BÀN BIDA: TIMELINE / PAUSE / GỘP - CHUYỂN BÀN =================
Route::prefix('tables')->middleware('throttle:120,1')->group(function () {
    Route::get('{tableId}/timeline', [TableOperationsController::class, 'timeline']);
    Route::post('{tableId}/open', [TableOperationsController::class, 'open']);
    Route::post('{tableId}/events', [TableOperationsController::class, 'recordEvent']);
    Route::post('{tableId}/pause', [TableOperationsController::class, 'pause']);
    Route::post('{tableId}/resume', [TableOperationsController::class, 'resume']);
    Route::post('{tableId}/close', [TableOperationsController::class, 'close']);
    Route::post('operations/transfer-merge', [TableOperationsController::class, 'transferOrMerge']);
    Route::post('operations/offline-sync', [TableOperationsController::class, 'offlineSync'])->middleware('throttle:60,1');
});

// ================= SỔ QUỸ (THU / CHI) =================
use App\Http\Controllers\CashTransactionController;

Route::prefix('cash-transactions')->group(function () {
    Route::get('/', [CashTransactionController::class, 'index']);
    Route::post('/', [CashTransactionController::class, 'store']);
    Route::post('/offline-sync', [CashTransactionController::class, 'offlineSync'])->middleware('throttle:60,1');
});
