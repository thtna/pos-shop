<?php

/** config/crypto.php — đăng ký trong Laravel: bỏ file này vào config/ và chạy `php artisan config:clear` */

return [
    'receiver'       => env('CRYPTO_RECEIVER_ADDRESS'),
    'chain_id'       => env('CRYPTO_CHAIN_ID', 56),
    'rpc_url'        => env('CRYPTO_RPC_URL', 'https://bsc-dataseed.binance.org'),
    'usdt_contract'  => env('CRYPTO_USDT_CONTRACT', '0x55d398326f99059fF775485246999027B3197955'),
    'usdt_decimals'  => env('CRYPTO_USDT_DECIMALS', 18), // USDT trên BSC là 18 (Ethereum là 6 — đọc động ở frontend)
    'confirmations'  => env('CRYPTO_CONFIRMATIONS_REQUIRED', 3),
    'vnd_usd_fallback' => env('CRYPTO_VND_USD_FALLBACK', 25000),
];
