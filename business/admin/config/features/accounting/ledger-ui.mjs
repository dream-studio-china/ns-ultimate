// Pure Finance helpers shared by the Accounting collection configs and their
// field/action components. No Vue, router, or HTTP dependencies.

export function minorToDecimal(value, scale) {
  const raw = String(value)
  const sign = raw.startsWith('-') ? '-' : ''
  const digits = raw.replace(/^-/, '').padStart(scale + 1, '0')
  return scale ? `${sign}${digits.slice(0, -scale)}.${digits.slice(-scale)}` : raw
}

export function decimalToMinor(value, scale) {
  const match = /^([0-9]{1,19})(?:\.([0-9]+))?$/.exec(value)
  if (!match || (match[2]?.length ?? 0) > scale) throw new Error('invalid_amount')
  const digits = `${match[1]}${(match[2] ?? '').padEnd(scale, '0')}`.replace(/^0+/, '')
  if (!digits || digits.length > 19 || (digits.length === 19 && digits > '9223372036854775807')) throw new Error('invalid_amount')
  return digits
}

export function draftLines(lines, scale) {
  return lines.map(line => ({
    account_code: line.account_code,
    side: line.side,
    amount: decimalToMinor(line.amount, scale),
    description: line.description ?? ''
  }))
}

export function draftTotals(lines, scale) {
  try {
    const totals = { debit: 0n, credit: 0n }
    for (const line of draftLines(lines, scale)) totals[line.side] += BigInt(line.amount)
    return Object.fromEntries(Object.entries(totals).map(([key, value]) => [key, minorToDecimal(value, scale)]))
  } catch {
    return null
  }
}

let nextLineKey = 0
export function editorLine(side, fields = {}) {
  return { key: ++nextLineKey, account_code: '', side, amount: '', description: '', ...fields }
}

// Stored lines carry exact minor units and a position; the editor uses decimals.
export function editorLines(lines, scale) {
  return (Array.isArray(lines) ? lines : []).map(line => editorLine(line.side, {
    account_code: line.account_code,
    amount: minorToDecimal(line.amount, scale),
    description: line.description ?? ''
  }))
}

// Draft lines need an account and an exact amount; balancing is a posting rule.
export function lineError(lines, scale) {
  if (!Array.isArray(lines) || lines.length === 0 || lines.length > 100) return 'invalid_lines'
  for (const line of lines) {
    if (!line.account_code) return 'Check the highlighted fields'
    try { decimalToMinor(line.amount, scale) } catch { return 'invalid_amount' }
  }
  return ''
}

// Keep one pending intent until a definitive response or explicit discard.
export function commandIntent(existing, action, input, newId) {
  const fingerprint = JSON.stringify([action, input])
  if (existing && existing.fingerprint !== fingerprint) throw new Error('finance_pending_intent')
  return existing ?? { action, fingerprint, body: { ...input, command_id: newId() } }
}

export function newCommandId(crypto = globalThis.crypto) {
  if (typeof crypto?.randomUUID === 'function') return crypto.randomUUID()
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6] & 15) | 64
  bytes[8] = (bytes[8] & 63) | 128
  const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

// Finance lists accept only whitelisted plain parameters. EasyAdmin's generic
// `@filter` expression is never forwarded.
export function listParams({ filterData = {}, pager = {}, sort = {}, query = {}, filters = [], sortable = false }) {
  const params = {}
  for (const [key, value] of Object.entries(query || {})) {
    if (key !== '@filter') params[key] = value
  }
  for (const key of filters) {
    const value = filterData[key]
    const text = typeof value === 'string' ? value.trim() : value
    if (text !== null && text !== undefined && text !== '' && text !== false) params[key] = String(text)
  }
  params.page = String(pager.page ?? 1)
  params.limit = String(pager.limit ?? 20)
  if (sortable && sort['@order']) params['@order'] = sort['@order']
  return params
}

export function reportReady(kind, params) {
  return Boolean(params.from && params.to && (kind !== 'ledger' || params.account))
}

export function reportFilters(kind, params) {
  const filters = { from: params.from, to: params.to }
  if (kind !== 'trial' && params.account) filters.account = params.account
  return filters
}

// The first page captures a new posting cutoff. Later pages of the same filters
// reuse it so every page reads one snapshot; changed filters start a new one.
export function reportParams(kind, params, previous) {
  const filters = reportFilters(kind, params)
  const key = JSON.stringify(filters)
  const request = { ...filters, page: params.page, limit: params.limit }
  if (Number(params.page) > 1 && previous?.key === key && previous.cutoff !== undefined) request.cutoff = previous.cutoff
  return { key, filters, request }
}

export function localToday(timezone, now = new Date()) {
  try {
    return new Intl.DateTimeFormat('sv-SE', { timeZone: timezone }).format(now)
  } catch {
    return now.toISOString().slice(0, 10)
  }
}
