const AMOUNT_PATTERN = /^(?:0|[1-9]\d*)(?:\.(\d{1,2}))?$/
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export function amountToMinorUnits(value) {
  const raw = typeof value === 'number' ? String(value) : String(value ?? '').trim()
  const match = AMOUNT_PATTERN.exec(raw)
  if (!match) return null

  const [whole, fraction = ''] = raw.split('.')
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  return Number.isSafeInteger(cents) ? cents : null
}

export function minorUnitsToInput(cents) {
  if (!Number.isSafeInteger(cents) || cents < 0) return ''
  return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`
}

export function formatMoney(cents, currency = 'CNY') {
  const amount = Number(cents) / 100
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount)
  } catch {
    return `${currency} ${amount.toFixed(2)}`
  }
}

export function refundContext(order, invoice) {
  if (!order || typeof order !== 'object') return { error: 'orderNotFound' }
  if (!order.invoiceId) return { error: 'orderHasNoInvoice' }
  if (!invoice || typeof invoice !== 'object') return { error: 'invoiceNotFound' }
  if (invoice.uuid !== order.invoiceId) return { error: 'paymentContextMismatch' }
  if (invoice.sourceType !== 'trade_order' || invoice.sourceId !== order.uuid) {
    return { error: 'paymentContextMismatch' }
  }

  const amount = Number(invoice.amount)
  const refundedAmount = Number(invoice.refundedAmount ?? 0)
  if (!Number.isSafeInteger(amount) || !Number.isSafeInteger(refundedAmount) || amount < 0 || refundedAmount < 0) {
    return { error: 'invalidPaymentAmount' }
  }
  if (refundedAmount > amount) return { error: 'invalidPaymentAmount' }

  const refundableAmount = amount - refundedAmount
  const statusRefundable = ['paid', 'partial_refunded'].includes(invoice.status)
  const payment = String(invoice.payment || order.paymentMethod || '').toLowerCase()
  return {
    error: '',
    amount,
    refundedAmount,
    refundableAmount,
    currency: invoice.currency || order.currency || 'CNY',
    payment,
    canRefund: statusRefundable && refundableAmount > 0,
    statusRefundable
  }
}

export function validateRefundAmount(value, refundableAmount) {
  const cents = amountToMinorUnits(value)
  if (cents === null || cents <= 0) return { error: 'refundAmountPositive', cents: null }
  if (!Number.isSafeInteger(refundableAmount) || refundableAmount <= 0 || cents > refundableAmount) {
    return { error: 'refundAmountExceeds', cents: null }
  }
  return { error: '', cents }
}

export function refundReasonBytes(value) {
  return new TextEncoder().encode(String(value ?? '').trim()).length
}

export function orderStoreUuid(order, record = {}) {
  const storeUuid = order?.metadata?._store?.uuid || record?.metadata?._store?.uuid
  return typeof storeUuid === 'string' && UUID_PATTERN.test(storeUuid) ? storeUuid : ''
}

export function orderRefundPath(tradeOrderUuid) {
  if (typeof tradeOrderUuid !== 'string' || !UUID_PATTERN.test(tradeOrderUuid)) throw new Error('Trade order UUID is required')
  return `/api/v1/manage/orders/${tradeOrderUuid}/refunds`
}

export function orderRefundHistoryPath(storeUuid, tradeOrderUuid) {
  if (typeof storeUuid !== 'string' || !UUID_PATTERN.test(storeUuid)) throw new Error('Store UUID is required')
  if (typeof tradeOrderUuid !== 'string' || !UUID_PATTERN.test(tradeOrderUuid)) throw new Error('Trade order UUID is required')
  return `/api/v1/store/${storeUuid}/trade-orders/${tradeOrderUuid}/refunds`
}

export function paymentStatusLabel(status) {
  return ({ paid: 'Paid', partial_refunded: 'Partially refunded', partially_refunded: 'Partially refunded', refunded: 'Refunded',
    pending: 'Pending', paying: 'Processing', failed: 'Failed', cancelled: 'Cancelled' })[status] || status || 'Unknown'
}

export function refundStatusLabel(status) {
  return ({ processing: 'Refund is processing', unknown: 'Refund result is unknown', succeeded: 'Refund completed', failed: 'Refund failed' })[status] || 'Unknown'
}
