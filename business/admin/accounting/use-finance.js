import { computed, onMounted, ref } from 'vue'
import request from '@/utils/request'
import store from '@/store'
import { commandIntent, minorToDecimal, newCommandId } from './ledger-ui.mjs'

export const FINANCE_API = '/api/v1/finance'
const MANAGE_ACCOUNTING_API = '/api/v1/manage/accounting'
export const sides = ['debit', 'credit']
export const classes = ['asset', 'liability', 'equity', 'revenue', 'expense']
const retriable = new Set(['create-draft', 'void-draft', 'post', 'reverse', 'approve-zero-opening', 'lock-period', 'reopen-period'])

export const financeGet = async(path, params = {}) => (await request.get(`${FINANCE_API}${path}`, { params })).data
export const available = row => Number(row.active) === 1 && Number(row.postable) === 1
export const fields = (...columns) => columns.map(([property, label, extra]) => ({ property, label, ...extra }))

export function useFinance() {
  const workspace = ref(null)
  const busy = ref(false)
  const error = ref('')
  const pending = ref(null)
  const book = computed(() => workspace.value?.book)
  const accounts = computed(() => workspace.value?.accounts ?? [])
  const scale = computed(() => Number(book.value?.scale ?? 0))
  const can = capability => Boolean(workspace.value?.capabilities.includes(capability))
  const resourcePrefix = computed(() => store.getters.roles.includes('ROLE_ADMIN') ? MANAGE_ACCOUNTING_API : FINANCE_API)
  const money = value => minorToDecimal(value, scale.value)

  async function run(operation) {
    if (busy.value) return false
    busy.value = true
    error.value = ''
    try {
      await operation()
      return true
    } catch (failure) {
      error.value = failure.response?.data?.message || failure.message || 'Finance request failed'
      return false
    } finally {
      busy.value = false
    }
  }

  const loadWorkspace = async() => { workspace.value = await financeGet('/workspace') }
  const reload = () => run(loadWorkspace)
  onMounted(reload)

  async function send(action, body, onSuccess) {
    let result
    try {
      result = (await request.post(`${FINANCE_API}/commands/${action}`, body)).data
    } catch (failure) {
      if (failure.response?.status >= 400 && failure.response.status < 500) pending.value = null
      throw failure
    }
    pending.value = null
    await onSuccess?.(result)
  }

  function act(action, input, onSuccess = loadWorkspace) {
    return run(async() => {
      if (pending.value) throw new Error('finance_pending_intent')
      if (retriable.has(action)) {
        pending.value = { ...commandIntent(null, action, input, newCommandId), onSuccess }
      }
      await send(action, pending.value?.body ?? input, onSuccess)
    })
  }

  const retry = () => pending.value && run(() => send(pending.value.action, pending.value.body, pending.value.onSuccess))
  const discard = () => { pending.value = null }

  return { workspace, book, accounts, scale, busy, error, pending, can, resourcePrefix, money, run, reload, loadWorkspace, act, retry, discard }
}
