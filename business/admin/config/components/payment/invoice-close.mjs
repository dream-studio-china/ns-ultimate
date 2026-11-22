const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const CLOSEABLE_STATUSES = new Set(['pending', 'paying', 'failed'])

export function invoiceDetailPath(id) {
  if (!(typeof id === 'number' || typeof id === 'string') || String(id).trim() === '') {
    throw new Error('Invoice id is required')
  }
  return `/api/v1/manage/invoices/${encodeURIComponent(String(id).trim())}`
}

export function orderPaymentClosePath(tradeOrderUuid) {
  if (typeof tradeOrderUuid !== 'string' || !UUID_PATTERN.test(tradeOrderUuid)) {
    throw new Error('Trade order UUID is required')
  }
  return `/api/v1/manage/orders/${tradeOrderUuid}/payment/close`
}

export function invoiceCloseContext(invoice) {
  if (!invoice || typeof invoice !== 'object') return { error: 'invoiceNotFound', canClose: false }
  if (typeof invoice.uuid !== 'string' || !UUID_PATTERN.test(invoice.uuid)) {
    return { error: 'invoiceNotFound', canClose: false }
  }
  if (invoice.sourceType !== 'trade_order') return { error: 'invoiceCloseNotTradeOrder', canClose: false }
  if (typeof invoice.sourceId !== 'string' || !UUID_PATTERN.test(invoice.sourceId)) {
    return { error: 'invoiceCloseOrderMissing', canClose: false }
  }
  if (invoice.status === 'cancelled') return { error: 'invoiceCloseAlreadyClosed', canClose: false }
  if (['paid', 'partial_refunded', 'refunded'].includes(invoice.status)) {
    return { error: 'invoiceCloseSettled', canClose: false }
  }
  if (!CLOSEABLE_STATUSES.has(invoice.status)) return { error: 'invoiceCloseUnavailable', canClose: false }
  return { error: '', canClose: true }
}

export function preliminaryInvoiceCloseable(invoice) {
  if (!invoice || !CLOSEABLE_STATUSES.has(invoice.status)) return false
  return !invoice.sourceType || invoice.sourceType === 'trade_order'
}

export function invoiceCloseReasonError(reason) {
  const value = String(reason ?? '').trim()
  if (!value) return 'invoiceCloseReasonRequired'
  if (new TextEncoder().encode(value).length > 1000) return 'invoiceCloseReasonTooLong'
  return ''
}

export function buildInvoiceCloseRequest(invoice, commandUuid, reason) {
  const context = invoiceCloseContext(invoice)
  if (!context.canClose) throw new Error(context.error)
  if (typeof commandUuid !== 'string' || !UUID_PATTERN.test(commandUuid)) throw new Error('Command UUID is required')
  const normalizedReason = String(reason ?? '').trim()
  const reasonError = invoiceCloseReasonError(normalizedReason)
  if (reasonError) throw new Error(reasonError)
  return {
    commandUuid,
    invoiceUuid: invoice.uuid,
    reason: normalizedReason
  }
}

export function invoiceCloseSucceeded(response) {
  const result = response?.data
  return result?.status === 'closed' && result?.released === true
}

export function invoiceCloseFailureKind(cause) {
  const status = cause?.response?.status
  const code = String(cause?.response?.data?.message || cause?.response?.data?.error || '')
  if (status === 409 && code === 'payment_already_paid') {
    return cause?.response?.data?.data?.requiresReview === true ? 'paymentRequiresReview' : 'paymentAlreadyPaid'
  }
  if (status === 409) return 'unconfirmed'
  if (status === 502) return 'channelFailed'
  if (!cause?.response) return 'unknown'
  return 'failed'
}

export function createInvoiceCloseCommandUuid(crypto = globalThis.crypto) {
  if (typeof crypto?.randomUUID === 'function') return crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
    const random = Math.floor(Math.random() * 16)
    const value = character === 'x' ? random : (random & 0x3) | 0x8
    return value.toString(16)
  })
}
