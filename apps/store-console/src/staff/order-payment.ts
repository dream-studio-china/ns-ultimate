const record = (value: unknown): Record<string, unknown> => value && typeof value === 'object' && !Array.isArray(value)
  ? value as Record<string, unknown>
  : {}

const text = (value: unknown): string | null => typeof value === 'string' && value.trim() ? value.trim() : null
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export interface PaymentOrderReference {
  status: string
  paymentStatus?: string | null
  hasInvoice?: boolean
  invoiceUuid?: string | null
  invoiceId?: unknown
  invoice?: unknown
  metadata?: Record<string, unknown> | null
}

export interface StaffPaymentResult {
  status: string | null
  invoiceUuid: string | null
  payUrl: string | null
  qrCode: string | null
  payInfo: unknown
  message: string | null
}

/** Payment is available only while the Trade order is confirmed and not already settled. */
export function confirmedOrderCanCollect(order: PaymentOrderReference): boolean {
  const paymentStatus = order.paymentStatus?.toLowerCase() ?? ''
  return order.status.toLowerCase() === 'confirmed'
    && !['paid', 'partial_refunded', 'partially_refunded', 'refunded', 'cancelled'].includes(paymentStatus)
}

/** A Huifu start still needs the exact invoice UUID; a boolean hasInvoice flag is not sufficient. */
export function invoiceReferenceUuid(value: unknown, depth = 0): string | null {
  if (depth > 4 || value == null) return null
  const direct = text(value)
  if (direct && uuidPattern.test(direct)) return direct
  const source = record(value)
  const metadata = record(source.metadata)
  const extraData = record(source.extraData ?? source.extra_data)
  const invoice = record(source.invoice)
  const payment = record(source.payment)
  const candidates = [
    source.invoiceUuid,
    source.invoice_uuid,
    source.invoiceId,
    source.invoice_id,
    metadata.invoiceUuid,
    metadata.invoice_uuid,
    metadata.invoiceId,
    metadata.invoice_id,
    extraData.invoiceUuid,
    extraData.invoice_uuid,
    extraData.invoiceId,
    extraData.invoice_id,
    invoice.uuid,
    invoice.id,
    invoice.invoiceUuid,
    invoice.invoice_uuid,
    invoice.invoiceId,
    invoice.invoice_id,
    payment.invoiceUuid,
    payment.invoice_uuid,
  ]
  for (const candidate of candidates) {
    const uuid = invoiceReferenceUuid(candidate, depth + 1)
    if (uuid) return uuid
  }
  return null
}

export function paymentStatusIsPaid(status: string | null | undefined): boolean {
  return ['paid', 'partial_refunded', 'partially_refunded'].includes(status?.toLowerCase() ?? '')
}

/** A pending invoice still accepts a first provider request; submitted attempts must not be duplicated. */
export function paymentStatusStartsAttempt(status: string | null | undefined): boolean {
  return ['paying', 'processing', 'pending_confirmation', 'submitted', 'unknown'].includes(status?.toLowerCase() ?? '')
}

/**
 * Normalize the same payment result shape used by the customer payment entry and the
 * Store-scoped Huifu adapter. Keep provider values opaque and only expose safe URLs.
 */
export function parseStaffPaymentResult(value: unknown): StaffPaymentResult {
  const root = record(value)
  const nested = record(root.data)
  const source = Object.keys(nested).length ? { ...root, ...nested } : root
  const payment = record(source.payment)
  const payload = record(source.payload)
  const invoice = record(source.invoice)
  const qrCode = text(source.qrCode) ?? text(source.qr_code) ?? text(payment.qrCode) ?? text(payment.qr_code)
  const payUrl = text(source.payUrl) ?? text(source.pay_url) ?? text(payment.payUrl) ?? text(payment.pay_url)
  return {
    status: text(source.status) ?? text(payment.status),
    invoiceUuid: invoiceReferenceUuid(invoice) ?? invoiceReferenceUuid(source),
    payUrl,
    qrCode,
    payInfo: source.payInfo ?? source.pay_info ?? payment.payInfo ?? payment.pay_info ?? payload.payInfo ?? payload.pay_info ?? null,
    message: text(source.message) ?? text(payment.message),
  }
}
