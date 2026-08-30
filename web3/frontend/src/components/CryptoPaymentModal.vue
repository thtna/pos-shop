<script setup>
/**
 * CryptoPaymentModal.vue — Modal thanh toán USDT (BEP20) cho hóa đơn POS
 *
 * Luồng:
 *  1. Mở modal → gọi /crypto/config (ví nhận, contract) + /crypto/rate (tỷ giá VND/USDT)
 *  2. Tính số USDT = totalVnd / rate, làm tròn LÊN 2 số thập phân, refresh tỷ giá 30s
 *  3. Khách chọn: (a) Trả bằng ví đã kết nối  (b) Quét QR EIP-681 bằng app ví trên điện thoại
 *  4. Sau khi có tx_hash → polling RPC cho tới khi đủ N confirmations
 *  5. Emit 'confirmed' (kèm txHash) → component cha đóng modal + in hóa đơn
 *     và gọi POST /crypto/verify để backend xác minh độc lập & cập nhật đơn "paid"
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import QRCode from 'qrcode'
import { useWeb3Wallet } from '../composables/useWeb3Wallet'

const props = defineProps({
  /** Tổng tiền hóa đơn tính VNĐ (số) */
  totalVnd: { type: Number, required: true },
  /** Mã đơn trên hệ thống POS */
  orderId: { type: [Number, String], required: true },
})
const emit = defineEmits(['close', 'confirmed', 'failed'])

const API = import.meta.env.VITE_API_BASE_URL

const { account, connect, ensureChain, transferToken } = useWeb3Wallet()

/* ---------- trạng thái ---------- */
const step = ref('quote')            // quote | pay | waiting | confirmed | error
const rate = ref(null)               // VND per 1 USDT
const rateUpdatedAt = ref(null)
const config = ref(null)             // {receiver, usdtContract, chainIdHex, decimals, confirmations}
const amountUsdt = ref('0')
const txHash = ref('')
const confirmations = ref(0)
const errorMsg = ref('')
const copied = ref(false)
let rateTimer = null, pollTimer = null

/* ---------- helpers ---------- */
const shortHex = h => (h ? h.slice(0, 10) + '…' + h.slice(-8) : '')

async function api(path, opts) {
  const res = await fetch(`${API}${path}`, {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    ...opts,
  })
  if (!res.ok) throw new Error(`API ${path} lỗi ${res.status}`)
  return res.json()
}

/** Lấy tỷ giá VNĐ/USDT: backend proxy Binance API (tránh CORS, có cache + fallback) */
async function fetchRate() {
  try {
    const data = await api('/crypto/rate')
    rate.value = data.vndPerUsdt
  } catch {
    rate.value = 25000 // fallback khớp CRYPTO_VND_USD_FALLBACK backend
  }
  rateUpdatedAt.value = new Date()
  recalcAmount()
}

function recalcAmount() {
  if (!rate.value) return
  // làm tròn LÊN 2 số thập phân để không bị thiếu tiền khi tỷ giá đổi
  const exact = props.totalVnd / rate.value
  amountUsdt.value = (Math.ceil(exact * 100) / 100).toFixed(2)
}

/** Chuẩn EIP-681: ethereum:<contract>@<chainId>/transfer?address=<to>&value=<wei> */
function eip681Uri() {
  if (!config.value) return ''
  const decimals = Number(config.value.decimals || 18)
  const wei = BigInt(Math.round(parseFloat(amountUsdt.value) * 10 ** decimals))
  const c = config.value.usdtContract.replace(/^0x/i, '').toLowerCase()
  return `ethereum:0x${c}@${config.value.chainIdDecimal}/transfer?address=${config.value.receiver}&value=${wei.toString()}`
}
const qrDataUrl = ref('')
async function buildQr() {
  if (!config.value) return
  qrDataUrl.value = await QRCode.toDataURL(eip681Uri(), { width: 280, margin: 1 })
}

async function copyPayload() {
  await navigator.clipboard.writeText(eip681Uri())
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}

/* ---------- thanh toán bằng ví trình duyệt ---------- */
async function payWithWallet() {
  errorMsg.value = ''
  try {
    if (!account.value) await connect()
    await ensureChain(config.value.chainIdHex)
    step.value = 'waiting'
    txHash.value = await transferToken({
      contractAddress: config.value.usdtContract,
      to: config.value.receiver,
      humanAmount: amountUsdt.value,
      decimals: config.value.decimals,
    })
    watchConfirmations()
  } catch (e) {
    errorMsg.value = e.shortMessage || e.message || 'Giao dịch bị từ chối'
    step.value = 'error'
    emit('failed', errorMsg.value)
  }
}

/* ---------- lắng nghe confirm qua RPC polling ---------- */
async function watchConfirmations() {
  step.value = 'waiting'
  confirmations.value = 0
  const providerRpc = config.value.rpcUrl
  const needed = Number(config.value.confirmations || 3)
  const getWithEndpoint = async () => {
    const res = await fetch(providerRpc, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'eth_getTransactionReceipt', params: [txHash.value] }),
    })
    return (await res.json()).result
  }
  pollTimer = setInterval(async () => {
    try {
      const receipt = await getWithEndpoint()
      if (!receipt) return // chưa được đưa vào block
      if (receipt.status !== '0x1') {
        clearInterval(pollTimer)
        errorMsg.value = 'Giao dịch thất bại trên blockchain (reverted)'
        step.value = 'error'
        emit('failed', errorMsg.value)
        return
      }
      // số block xác nhận = current - blockNumber + 1
      const curHex = await (await fetch(providerRpc, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'eth_blockNumber', params: [] }),
      })).json()
      confirmations.value = BigInt(curHex.result).valueOf() - BigInt(receipt.blockNumber).valueOf() + 1n
      if (Number(confirmations.value) >= needed) {
        clearInterval(pollTimer)
        step.value = 'confirmed'
        // Báo backend xác minh độc lập + cập nhật đơn
        await api('/crypto/verify', {
          method: 'POST',
          body: JSON.stringify({ order_id: props.orderId, tx_hash: txHash.value }),
        })
        emit('confirmed', { txHash: txHash.value, amountUsdt: amountUsdt.value })
      }
    } catch { /* RPC hiccup — thử lại chu kỳ sau */ }
  }, 3000)
}

/* ---------- vòng đời ---------- */
onMounted(async () => {
  step.value = 'quote'
  const [cfg] = await Promise.all([api('/crypto/config'), fetchRate()])
  config.value = cfg
  await buildQr()
  rateTimer = setInterval(fetchRate, 30_000) // refresh tỷ giá mỗi 30s
})
onUnmounted(() => { clearInterval(rateTimer); clearInterval(pollTimer) })
</script>

<template>
  <div class="cpm-overlay" @click.self="emit('close')">
    <div class="cpm-modal">
      <button class="cpm-close" @click="emit('close')">✕</button>
      <h3>🪙 Thanh toán bằng USDT <small>(BEP20 · Binance Smart Chain)</small></h3>

      <!-- BƯỚC 1: Báo giá -->
      <div v-if="step === 'quote'" class="cpm-body">
        <div class="cpm-amount">
          <div class="cpm-vnd">{{ totalVnd.toLocaleString('vi-VN') }}đ</div>
          <div class="cpm-arrow">↓</div>
          <div class="cpm-usdt">{{ amountUsdt }} <b>USDT</b></div>
          <small>Tỷ giá: 1 USDT = {{ rate?.toLocaleString('vi-VN') }}đ
            <span class="cpm-rate-time">(cập nhật {{ rateUpdatedAt?.toLocaleTimeString('vi-VN') }})</span></small>
        </div>
        <button class="cpm-btn cpm-primary" :disabled="!account" @click="payWithWallet">
          {{ account ? `💸 Trả ${amountUsdt} USDT bằng ví` : '🦊 Connect Wallet trước' }}
        </button>
        <div class="cpm-divider">hoặc quét bằng điện thoại</div>
        <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR EIP-681" class="cpm-qr">
        <p class="cpm-hint">Mở app MetaMask / Trust Wallet → Scan → quét mã trên để chuyển đúng số tiền</p>
        <button class="cpm-btn cpm-ghost" @click="copyPayload">{{ copied ? '✅ Đã sao chép' : '📋 Sao chép mã thanh toán' }}</button>
      </div>

      <!-- BƯỚC 2/3: Chờ xác nhận -->
      <div v-else-if="step === 'waiting'" class="cpm-body cpm-center">
        <div class="cpm-spinner" />
        <p><b>Đang chờ blockchain xác nhận…</b></p>
        <p class="cpm-mono">{{ shortHex(txHash) }}</p>
        <p>Đã xác nhận: <b>{{ confirmations }}</b> / {{ config?.confirmations }} block</p>
        <a :href="`${config?.explorer}/tx/${txHash}`" target="_blank" class="cpm-link">Xem trên BscScan ↗</a>
      </div>

      <!-- Thành công -->
      <div v-else-if="step === 'confirmed'" class="cpm-body cpm-center">
        <div class="cpm-big">✅</div>
        <p><b>Đã nhận {{ amountUsdt }} USDT — Đơn #{{ orderId }} hoàn tất!</b></p>
        <p class="cpm-mono">{{ txHash }}</p>
        <button class="cpm-btn cpm-primary" @click="emit('close')">Đóng & in hóa đơn</button>
      </div>

      <!-- Lỗi -->
      <div v-else class="cpm-body cpm-center">
        <div class="cpm-big">⚠️</div>
        <p class="cpm-error">{{ errorMsg }}</p>
        <button class="cpm-btn cpm-ghost" @click="step = 'quote'">← Thử lại</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cpm-overlay { position:fixed; inset:0; background:rgba(8,20,45,.55); display:flex;
  align-items:center; justify-content:center; z-index:100; backdrop-filter:blur(3px); }
.cpm-modal { position:relative; width:min(420px,94vw); max-height:92vh; overflow:auto;
  background:#fff; border-radius:16px; padding:22px; }
.cpm-close { position:absolute; top:12px; right:14px; border:none; background:none;
  font-size:16px; cursor:pointer; color:#888; }
.cpm-body { display:flex; flex-direction:column; gap:12px; margin-top:12px; }
.cpm-center { align-items:center; text-align:center; }
.cpm-amount { text-align:center; background:#f4f7fc; border-radius:12px; padding:16px; }
.cpm-vnd { font-size:20px; font-weight:700; color:#475569; }
.cpm-usdt { font-size:30px; font-weight:800; color:#0f766e; }
.cpm-rate-time { color:#94a3b8; font-size:11px; }
.cpm-btn { border:none; border-radius:10px; padding:13px; font-size:15px; font-weight:700; cursor:pointer; }
.cpm-primary { background:#0f766e; color:#fff; }
.cpm-primary:disabled { opacity:.5; cursor:not-allowed; }
.cpm-ghost { background:#eef2f7; }
.cpm-divider { text-align:center; color:#94a3b8; font-size:13px; }
.cpm-qr { width:220px; height:220px; align-self:center; border-radius:8px; }
.cpm-hint { font-size:12px; color:#64748b; text-align:center; margin:0; }
.cpm-mono { font-family:monospace; font-size:12px; word-break:break-all; color:#475569; }
.cpm-spinner { width:42px; height:42px; border-radius:50%; border:4px solid #e2e8f0;
  border-top-color:#0f766e; animation:spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.cpm-error { color:#dc2626; font-weight:600; }
.cpm-big { font-size:52px; }
.cpm-link { color:#1a73e8; font-size:13px; }
</style>
