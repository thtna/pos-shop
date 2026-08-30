<script setup>
/**
 * ConnectWalletButton.vue — Nút kết nối ví MetaMask / Trust Wallet
 * Sử dụng: <ConnectWalletButton @connected="onWallet" @disconnected="onWalletLeft" />
 */
import { ref, onMounted } from 'vue'
import { useWeb3Wallet } from '../composables/useWeb3Wallet'

const emit = defineEmits(['connected', 'disconnected'])

const { account, connecting, error, connect, disconnect } = useWeb3Wallet()
const walletAvailable = ref(false)

const hasWallet = () => !!(window.ethereum || window.trustwallet)
onMounted(() => { walletAvailable.value = hasWallet() })

const shortAddr = a => (a ? a.slice(0, 6) + '...' + a.slice(-4) : '')

async function onConnect() {
  const addr = await connect()
  if (addr) emit('connected', addr)
}
async function onDisconnect() {
  await disconnect()
  emit('disconnected')
}
</script>

<template>
  <button
    v-if="!account"
    class="cw-btn"
    :disabled="connecting"
    @click="onConnect"
  >
    <span v-if="connecting">⏳ Đang kết nối…</span>
    <span v-else-if="!walletAvailable">🦊 Cài MetaMask / Trust Wallet</span>
    <span v-else>🦊 Connect Wallet</span>
  </button>

  <div v-else class="cw-wallet-chip">
    <span class="cw-dot" />
    <span class="cw-addr" :title="account">{{ shortAddr(account) }}</span>
    <button class="cw-disconnect" title="Ngắt kết nối" @click="onDisconnect">✕</button>
  </div>

  <p v-if="error" class="cw-error">{{ error }}</p>
</template>

<style scoped>
.cw-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border:none;
  border-radius:10px; background:#f6851b; color:#fff; font-weight:700; cursor:pointer; }
.cw-btn:disabled { opacity:.55; cursor:not-allowed; }
.cw-wallet-chip { display:inline-flex; align-items:center; gap:8px; padding:8px 12px;
  border-radius:99px; background:#eef3fa; border:1px solid #dde5f0; }
.cw-dot { width:8px; height:8px; border-radius:50%; background:#16a34a; }
.cw-addr { font-weight:700; font-family:monospace; }
.cw-disconnect { border:none; background:transparent; cursor:pointer; color:#888; }
.cw-error { color:#dc2626; font-size:12px; margin-top:4px; }
</style>
