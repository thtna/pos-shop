# Web3 Payment Gateway cho POS Shop (Vue 3 + Laravel)

Thanh toán tiền mã hóa (USDT BEP20 trên Binance Smart Chain) cho POS Shop.

## Cài đặt packages

### Frontend (Vue 3)
```bash
npm install ethers qrcode vue-qrcode   # ethers v6 + tạo QR EIP-681
# Tùy chọn: WalletConnect (hỗ trợ 400+ ví qua Web3Modal)
npm install @web3modal/ethers ethers
```

### Backend (Laravel)
```bash
composer require illuminate/http   # có sẵn trong Laravel
# Không cần package web3 nào: verify giao dịch qua RPC bằng HTTP thuần
```

## Cấu hình biến môi trường

### Backend — Laravel `.env`
```env
# Ví NHẬN tiền của chủ cửa hàng (giữ bí mật ở server, không hardcode ở frontend)
CRYPTO_RECEIVER_ADDRESS=0xYourShopWalletAddressHere
CRYPTO_RECEIVER_PRIVATE_KEY=          # ĐỂ TRỐNG nếu chỉ nhận (không sign) - KHÔNG BAO GIỜ gửi cho frontend

# Mạng & token
CRYPTO_CHAIN_ID=56                    # BSC Mainnet (97 = BSC Testnet)
CRYPTO_RPC_URL=https://bsc-dataseed.binance.org
CRYPTO_USDT_CONTRACT=0x55d398326f99059fF775485246999027B3197955   # USDT BEP20 (18 decimals trên BSC)
CRYPTO_USDT_DECIMALS=18
CRYPTO_CONFIRMATIONS_REQUIRED=3       # số block confirm để tính "Thành công"
CRYPTO_VND_USD_FALLBACK=25000         # tỷ giá dự phòng khi API sập

# Hoặc dùng riêng cho thận trọng:转 sang mạng testnet để thử
# CRYPTO_CHAIN_ID=97
# CRYPTO_RPC_URL=https://data-seed-prebsc-1-s1.binance.org:8545
# CRYPTO_USDT_CONTRACT=0x7ef9520a877E5d87593c22A6d78a53083f65C28e  (test USDT)
```

### Frontend — Vite `.env`
```env
VITE_API_BASE_URL=http://posshop.local/api
VITE_CRYPTO_CHAIN_ID=56
# Địa chỉ ví nhận hiển thị trên frontend (lấy từ API /crypto/config an toàn hơn)
```

## Luồng hoạt động

```
[Khách bấm Thanh toán Crypto]
   1. Frontend gọi GET /api/crypto/config      → Laravel trả: địa chỉ ví nhận, contract USDT, chainId
   2. Frontend gọi GET /api/crypto/rate        → Laravel proxy Binance API (USDT/VND) — tránh CORS
   3. Modal hiện: tổng bill VNĐ → quy ra USDT (kèm tỷ giá, đếm ngược refresh 30s)
   4a. Desktop có MetaMask/Trust: bấm "Thanh toán bằng ví" → ethers.js BrowserProvider
       → contract.transfer(receiver, amount) → hash
   4b. Điện thoại / ví khác: quét QR chuẩn EIP-681, chuyển bằng app ví
   5. Frontend polling RPC `getTransactionReceipt` đủ N confirmations
       (hoặc backend lắng nghe) → POST /api/crypto/verify {order_id, tx_hash}
   6. Backend verify ĐỘC LẬP trên RPC: đúng người nhận, đúng số tiền → cập nhật đơn "paid"
   7. Frontend đóng modal → in hóa đơn
```

## Tệp tin
- `frontend/src/composables/useWeb3Wallet.js` — kết nối ví, chain switch, detect MetaMask/Trust
- `frontend/src/components/ConnectWalletButton.vue`
- `frontend/src/components/CryptoPaymentModal.vue` — toàn bộ luồng thanh toán
- `backend/app/Http/Controllers/CryptoPaymentController.php` — config, tỷ giá, verify giao dịch
- `backend/routes/api.php` — route đăng ký

## Bảo mật — bắt buộc đọc
1. **Không bao giờ tin xác nhận "đã trả tiền" từ frontend.** Luôn verify lại `tx_hash` ở backend qua RPC (đúng `to`, đúng `from` không quan trọng, đúng giá trị ≥ số tiền đơn).
2. Đơn chỉ chuyển "paid" khi: receipt.status === 'success' AND confirmations >= N AND log transfer tới đúng địa chỉ nhận với amount >= expected.
3. Giá tính bằng USDT làm tròn LÊN 2 số thập phân để tránh thiếu tiền do tỷ giá dao động.
4. Rate-limit endpoint /crypto/rate và /crypto/verify.
