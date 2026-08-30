/**
 * useWeb3Wallet.js — Composable quản lý kết nối ví (MetaMask / Trust Wallet / EIP-1193)
 * ethers.js v6
 */
import { ref, onMounted } from 'vue'
import { BrowserProvider, Contract, formatUnits } from 'ethers'

const ERC20_ABI = [
  'function transfer(address to, uint256 amount) returns (bool)',
  'function balanceOf(address owner) view returns (uint256)',
  'function decimals() view returns (uint8)',
  'function symbol() view returns (string)',
]

const BSC_MAINNET = {
  chainId: '0x38', chainName: 'Binance Smart Chain',
  rpcUrls: ['https://bsc-dataseed.binance.org'],
  nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 },
  blockExplorerUrls: ['https://bscscan.com'],
}

export function useWeb3Wallet() {
  const account = ref(null)          // địa chỉ ví đã kết nối
  const provider = ref(null)         // BrowserProvider
  const connecting = ref(false)
  const error = ref('')

  /** Phát hiện ví cài trong trình duyệt (MetaMask / Trust / vi định dạng EIP-1193) */
  const detectProvider = () => {
    if (window.ethereum) return window.ethereum
    if (window.trustwallet) return window.trustwallet
    return null
  }
  const hasWallet = () => !!detectProvider()

  /** Kết nối ví */
  async function connect() {
    error.value = ''
    const eth = detectProvider()
    if (!eth) {
      // Mobile app nhúng: mở link cài MetaMask
      error.value = 'Chưa tìm thấy ví. Hãy cài MetaMask hoặc Trust Wallet.'
      if (/Android|iPhone/i.test(navigator.userAgent)) window.open('https://metamask.io/download/')
      return null
    }
    try {
      connecting.value = true
      const accounts = await eth.request({ method: 'eth_requestAccounts' })
      account.value = accounts[0]
      provider.value = new BrowserProvider(eth)
      // Trust Wallet app dựng sẵn provider riêng — thêm listener
      eth.on?.('accountsChanged', accs => { account.value = accs[0] || null })
      eth.on?.('chainChanged', () => window.location.reload())
      return account.value
    } catch (e) {
      error.value = e.shortMessage || e.message || 'Người dùng từ chối kết nối'
      return null
    } finally { connecting.value = false }
  }

  async function disconnect() {
    account.value = null; provider.value = null
  }

  /** Đảm bảo đang ở đúng mạng (BSC). Tự đề cầu switch / add chain. */
  async function ensureChain(chainIdHex = BSC_MAINNET.chainId) {
    const eth = detectProvider()
    const current = await eth.request({ method: 'eth_chainId' })
    if (current === chainIdHex) return true
    try {
      await eth.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: chainIdHex }] })
    } catch (e) {
      if (e.code === 4902) { // chain chưa thêm trong ví
        await eth.request({ method: 'wallet_addEthereumChain', params: [BSC_MAINNET] })
      } else throw e
    }
    return true
  }

  /**
   * Chuyển token ERC20 (USDT) — xử lý decimals chuẩn.
   * @returns {Promise<string>} transaction hash
   */
  async function transferToken({ contractAddress, to, humanAmount, decimals = 18 }) {
    if (!provider.value) throw new Error('Chưa kết nối ví')
    const signer = await provider.value.getSigner()
    const token = new Contract(contractAddress, ERC20_ABI, signer)
    // QUAN TRỌNG: đọc decimals thực tế từ contract thay vì tin tham số truyền vào
    const onchainDecimals = await token.decimals()
    const amount = BigInt(Math.round(parseFloat(humanAmount) * 10 ** Number(onchainDecimals)))
    const tx = await token.transfer(to, amount)
    return tx.hash
  }

  async function tokenBalance(contractAddress, owner) {
    if (!provider.value) return null
    const token = new Contract(contractAddress, ERC20_ABI, provider.value)
    const raw = await token.balanceOf(owner)
    return formatUnits(raw, await token.decimals())
  }

  return { account, provider, connecting, error, hasWallet, connect, disconnect, ensureChain, transferToken, tokenBalance, BSC_MAINNET }
}
