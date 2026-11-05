const amountPattern = /^(?:0|[1-9]\d*)(?:\.(\d{1,2}))?$/
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

const record = (value: unknown): Record<string, unknown> => value && typeof value === 'object' && !Array.isArray(value)
  ? value as Record<string, unknown>
  : {}

const text = (value: unknown): string | null => typeof value === 'string' && value.trim() ? value.trim() : null

export interface RefundPreview {
  invoiceUuid: string
  amount: number
  refundedAmount: number
  refundableAmount: number
  currency: string
  payment: string
  status: string
  canRefund: boolean
}

export function amountToMinorUnits(value: string | number): number | null {
  const raw = typeof value === 'number' ? String(value) : value.trim()
  if (!amountPattern.test(raw)) return null
  const [whole, fraction = ''] = raw.split('.')
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  return Number.isSafeInteger(cents) ? cents : null
}

export function minorUnitsToInput(cents: number): string {
  if (!Number.isSafeInteger(cents) || cents < 0) return ''
  return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`
}

export function validateRefundAmount(value: string, refundableAmount: number): { cents: number | null; error: 'refundAmountPositive' | 'refundAmountExceeds' | null } {
  const cents = amountToMinorUnits(value)
  if (cents === null || cents <= 0) return { cents: null, error: 'refundAmountPositive' }
  if (!Number.isSafeInteger(refundableAmount) || refundableAmount <= 0 || cents > refundableAmount) {
    return { cents: null, error: 'refundAmountExceeds' }
  }
  return { cents, error: null }
}

export function paidOrderCanRefund(order: { status: string; paymentStatus?: string | null }): boolean {
  return order.status.toLowerCase() === 'paid'
}

export function refundReasonBytes(value: string): number {
  return new TextEncoder().encode(value).length
}

/** Read the payment-page projection without trusting totals computed by the cashier UI. */
export function parseRefundPreview(value: unknown, expectedOrderUuid: string): RefundPreview | null {
  const data = record(value)
  const invoice = record(data.invoice)
  const orderUuid = text(data.orderUuid)
  const invoiceUuid = text(invoice.uuid)
  const amount = data.amount
  const payment = text(invoice.payment)
  const status = text(invoice.status)
  const currency = text(invoice.currency) ?? text(data.currency)
  const refundedAmount = invoice.refundedAmount ?? 0

  if (orderUuid !== expectedOrderUuid || !uuidPattern.test(expectedOrderUuid)
    || !invoiceUuid || !uuidPattern.test(invoiceUuid)
    || !Number.isSafeInteger(amount) || (amount as number) < 0
    || !Number.isSafeInteger(refundedAmount) || (refundedAmount as number) < 0
    || (refundedAmount as number) > (amount as number)
    || !payment || !status || !currency) return null

  const refundableAmount = (amount as number) - (refundedAmount as number)
  return {
    invoiceUuid,
    amount: amount as number,
    refundedAmount: refundedAmount as number,
    refundableAmount,
    currency,
    payment,
    status,
    canRefund: ['paid', 'partial_refunded'].includes(status.toLowerCase()) && refundableAmount > 0,
  }
}

export type RefundResultKind = 'processing' | 'unknown' | 'succeeded' | 'failed'

export function paymentStatusMessage(status: string | null | undefined) {
  const labels = { paid: 'diningPaid', partial_refunded: 'refundPartiallyRefunded', partially_refunded: 'refundPartiallyRefunded',
    refunded: 'diningRefunded', pending: 'diningPending', paying: 'refundProcessing', failed: 'refundResultFailed', cancelled: 'stateCancelled' } as const
  return labels[status?.toLowerCase() as keyof typeof labels] ?? null
}

export function refundResultKind(status: string | null | undefined): RefundResultKind {
  const normalized = status?.toLowerCase() ?? ''
  if (['created', 'submitted', 'processing'].includes(normalized)) return 'processing'
  if (normalized === 'unknown') return 'unknown'
  if (normalized === 'succeeded') return 'succeeded'
  return 'failed'
}
