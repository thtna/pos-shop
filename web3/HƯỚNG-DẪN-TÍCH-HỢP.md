# Tích hợp Web3 Payment vào POS Shop bản HTML hiện tại

Bản POS Shop của bạn đang là 1 file HTML (vanilla JS). Có 2 cách áp dụng:

---

## Cách A — Dùng ngay trên bản HTML hiện tại (nhanh, ~15 phút)

### 1. Nhúng thư viện (thêm trước `</body>`)
```html
<script src="https://cdn.jsdelivr.net/npm/ethers@6.13.2/dist/ethers.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
```

### 2. Thêm nút "Thanh toán Crypto" vào hộp thanh toán
Tìm block `.pay-methods` trong `index.html`, thêm 1 ô:
```html
<div class="pay-m" data-m="crypto"><span class="big">🪙</span>Crypto USDT</div>
```

### 3. Thêm modal crypto + logic (thêm trước `</body>`)
```html
<div class="overlay" id="cryptoOverlay">
  <div class="modal" style="width:min(420px,94vw)">
    <h3>🪙 Thanh toán USDT (BEP20)</h3>
    <div class="pay-summary">
      <div class="row"><span>Tổng đơn</span><b id="cyVnd">0đ</b></div>
      <div class="row big"><span>Cần trả</span><span id="cyAmt" style="color:var(--green)">— USDT</span></div>
      <div class="row" style="font-size:11px;color:var(--muted)"><span id="cyRate">Đang lấy tỷ giá…</span></div>
    </div>
    <button class="btn btn-green" style="width:100%" id="cyWalletBtn" onclick="cryptoPayWallet()">🦊 Connect Wallet & Trả</button>
    <div style="text-align:center;margin:10px 0;color:var(--muted);font-size:13px">— hoặc quét bằng app ví điện thoại —</div>
    <img id="cyQr" style="width:210px;display:block;margin:0 auto;border-radius:8px">
    <div id="cyStatus" style="text-align:center;font-size:13px;margin-top:10px;color:var(--muted)"></div>
    <button class="btn btn-ghost" style="width:100%;margin-top:10px" onclick="document.getElementById('cryptoOverlay').classList.remove('show')">Đóng</button>
  </div>
</div>
<script>
const CRYPTO = {
  receiver:  '0xĐỊA_CHỈ_VÍ_CỬA_HÀNG',              // ← điền ví nhận của bạn
  usdt:      '0x55d398326f99059fF775485246999027B3197955', // USDT BEP20
  chainIdHex:'0x38', rpc:'https://bsc-dataseed.binance.org', decimals:18, confirmations:3,
  erc20:['function transfer(address to,uint256 amount) returns (bool)','function decimals() view returns (uint8)'],
};
let cyRate=null, cyHash='';
async function openCryptoPay(totalVnd){
  cyRate = await (await fetch('https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=vnd')).json();
  cyRate = cyRate.tether.vnd;
  const amt = (Math.ceil(totalVnd/cyRate*100)/100).toFixed(2); // làm tròn LÊN
  document.getElementById('cyVnd').textContent = VND(totalVnd);
  document.getElementById('cyAmt').textContent = amt+' USDT';
  document.getElementById('cyRate').textContent = '1 USDT ≈ '+cyRate.toLocaleString('vi-VN')+'đ (CoinGecko)';
  // QR EIP-681
  const wei = BigInt(Math.round(amt*10**CRYPTO.decimals));
  const uri = `ethereum:${CRYPTO.usdt}@56/transfer?address=${CRYPTO.receiver}&value=${wei}`;
  QRCode.toDataURL(uri,{width:260,margin:1},(e,u)=>document.getElementById('cyQr').src=u);
  document.getElementById('cryptoOverlay').classList.add('show');
}
async function cryptoPayWallet(){
  try{
    const eth = window.ethereum||window.trustwallet;
    const acc = (await eth.request({method:'eth_requestAccounts'}))[0];
    if(await eth.request({method:'eth_chainId'})!==CRYPTO.chainIdHex)
      await eth.request({method:'wallet_switchEthereumChain',params:[{chainId:CRYPTO.chainIdHex}]});
    const prov = new ethers.BrowserProvider(eth);
    const token = new ethers.Contract(CRYPTO.usdt, CRYPTO.erc20, await prov.getSigner());
    const dec = await token.decimals(); // đọc decimals THẬT từ contract
    const tx = await token.transfer(CRYPTO.receiver, BigInt(Math.round(+document.getElementById('cyAmt').textContent*10**Number(dec))));
    document.getElementById('cyStatus').textContent = '⏳ TX: '+tx.hash.slice(0,14)+'… đang chờ xác nhận';
    watchTx(tx.hash, totalVndTemp());
  }catch(err){ document.getElementById('cyStatus').textContent='⚠️ '+err.message; }
}
function totalVndTemp(){ const t=payTotals(); return t?t.total:0; }
function watchTx(hash,vnd){
  cyHash=hash;
  const iv=setInterval(async()=>{
    const r=await (await fetch(CRYPTO.rpc,{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({jsonrpc:'2.0',id:1,method:'eth_getTransactionReceipt',params:[hash]})})).json();
    if(!r.result) return;
    clearInterval(iv);
    if(r.result.status!=='0x1'){ document.getElementById('cyStatus').textContent='❌ Giao dịch thất bại'; return; }
    document.getElementById('cyStatus').textContent='✅ Đã xác nhận trên blockchain!';
    // TODO backend: POST /api/crypto/verify {order_id, tx_hash} — xác minh độc lập rồi mới paid
    completeCryptoPayment(vnd, hash);
  },3000);
}
function completeCryptoPayment(vnd,hash){
  const tb=tables.find(x=>x.id===currentTable);
  orders.push({id:10001+orders.length,table:tb.name,zone:tb.zone,
    time:new Date().toLocaleString('vi-VN',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'2-digit'}),
    items:JSON.parse(JSON.stringify(tableOrders[currentTable])),sub:vnd,disc:0,total:vnd,method:'crypto',txHash:hash});
  delete tableOrders[currentTable]; persist(); closePay();
  document.getElementById('cryptoOverlay').classList.remove('show');
  showInvoice(orders[orders.length-1]);
  toast('🪙 Thanh toán crypto thành công!');
}
</script>
```
Và trong `openPay()` thêm: khi payMethod==='crypto' → gọi `openCryptoPay(payTotals().total)`.

### 4. `.env` tương ứng bản HTML (dán vào đầu `<script>`)
```js
// Trong bản HTML: cấu hình nằm trong object CRYPTO ở trên.
// LƯU Ý: địa chỉ ví nhận KHÔNG phải thông tin bí mật (chỉ private key mới là bí mật).
```

---

## Cách B — Nâng cấp thật sự sang Vue 3 + Laravel

1. Copy thư mục `web3/frontend/src` vào project Vue, `web3/backend` vào Laravel
2. Chạy: `npm i ethers qrcode` (frontend) — backend không cần package thêm
3. Điền `.env` theo `README.md`
4. Chèn vào màn thanh toán:
```vue
<CryptoPaymentModal
  v-if="showCrypto"
  :total-vnd="currentOrder.total"
  :order-id="currentOrder.id"
  @confirmed="onCryptoPaid($event)"   <!-- đóng modal + in hóa đơn -->
  @failed="showError"
/>
```
5. Backend tự verify tx qua RPC rồi mới chuyển đơn `paid` — an toàn tuyệt đối.

---

## LƯU Ý QUAN TRỌNG
- ⚠️ **Test trên BSC Testnet trước** (chainId 97, faucet test-USDT) — đừng test bằng tiền thật
- 🔒 Private key của cửa hàng KHÔNG BAO GIỜ đặt ở frontend hay commit lên Git
- 💡 Phí gas BSC rất thấp (~$0.1–0.3/chuyển) — khách chịu phí, bạn nhận đủ USDT
