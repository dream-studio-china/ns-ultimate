import { reactive } from 'vue'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { getToken } from '@/utils/auth'
import request from '@/utils/request'
import { commandIntent, minorToDecimal, newCommandId } from './ledger-ui.mjs'

/**
 * Shared Finance session state for the Accounting collection configs.
 *
 * The workspace supplies the book, chart, and the current user's Finance
 * capabilities. Config predicates, fields, and actions read it lazily, so the
 * same configs work from the Finance routes and the generic EasyAdmin routes.
 * The backend still authorizes every request.
 */
export const FINANCE_API = apiPath(API_PREFIX, 'finance/accounting')
const BOOK_KEY = 'accounting:selected-book'
const selection = typeof window === 'undefined' ? null : window.sessionStorage.getItem(BOOK_KEY)
export const selectedBookId = /^[1-9][0-9]{0,9}$/.test(selection ?? '') ? Number(selection) : 1
export const BOOK_API = selectedBookId === 1 ? FINANCE_API : `${FINANCE_API}/book/${selectedBookId}`

export function selectBook(id) {
  if (state.pending || state.busy || !Number.isSafeInteger(id) || id < 1) return
  window.sessionStorage.setItem(BOOK_KEY, String(id))
  // Destroy old dialogs, snapshots, and repository instances before changing scope.
  window.location.assign(`${import.meta.env.BASE_URL}finance/accounting/ledger`)
}

const RETRIABLE = new Set(['create-draft', 'void-draft', 'post', 'reverse', 'approve-zero-opening', 'lock-period', 'reopen-period'])

const state = reactive({
  workspace: null,
  token: null,
  loading: null,
  error: '',
  busy: false,
  pending: null,
  reports: {}
})

const message = failure => failure?.response?.data?.message || failure?.message || 'Finance request failed'

async function load() {
  const token = getToken()
  state.error = ''
  try {
    const response = await request.get(`${BOOK_API}/workspace`)
    state.workspace = response.data
    state.token = token
    return state.workspace
  } catch (failure) {
    state.error = message(failure)
    throw failure
  } finally {
    state.loading = null
  }
}

async function send(action, body, onSuccess) {
  let result
  try {
    result = (await request.post(`${BOOK_API}/commands/${action}`, body)).data
  } catch (failure) {
    // A definitive client error means the command was rejected; keep the
    // intent only when the outcome is unknown (network failure or 5xx).
    if (failure.response?.status >= 400 && failure.response.status < 500) state.pending = null
    throw failure
  }
  state.pending = null
  await onSuccess?.(result)
  return result
}

export const finance = {
  state,

  get workspace() { return state.workspace },
  get book() { return state.workspace?.book ?? null },
  get accounts() { return state.workspace?.accounts ?? [] },
  get scale() { return Number(state.workspace?.book?.scale ?? 0) },

  can(capability) {
    return Boolean(state.workspace?.capabilities?.includes(capability))
  },

  money(value) {
    return value === null || value === undefined || value === '' ? '' : minorToDecimal(value, finance.scale)
  },

  /** Load the workspace once per signed-in session. */
  ensure() {
    if (state.workspace && state.token === getToken()) return Promise.resolve(state.workspace)
    state.loading ??= load()
    return state.loading
  },

  reload() {
    state.loading = load()
    return state.loading
  },

  /** Run an explicit ledger command, keeping retriable intents for exact replay. */
  async act(action, input, onSuccess) {
    if (state.busy) return false
    if (state.pending) {
      state.error = 'finance_pending_intent'
      return false
    }
    state.busy = true
    state.error = ''
    try {
      const body = RETRIABLE.has(action) ? (state.pending = { ...commandIntent(null, action, input, newCommandId), onSuccess }).body : input
      await send(action, body, onSuccess)
      await finance.reload().catch(() => {})
      return true
    } catch (failure) {
      state.error = message(failure)
      return false
    } finally {
      state.busy = false
    }
  },

  async retry() {
    const intent = state.pending
    if (!intent || state.busy) return false
    state.busy = true
    state.error = ''
    try {
      await send(intent.action, intent.body, intent.onSuccess)
      await finance.reload().catch(() => {})
      return true
    } catch (failure) {
      state.error = message(failure)
      return false
    } finally {
      state.busy = false
    }
  },

  discard() {
    state.pending = null
  },

  /** Report snapshot (filters and posting cutoff) for each report kind. */
  report(kind) {
    return state.reports[kind] ?? null
  },

  rememberReport(kind, snapshot) {
    state.reports[kind] = snapshot
  }
}

export default finance
