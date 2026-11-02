import { describe, expect, it } from 'vitest'
import { confirmedOrderCanCollect, invoiceReferenceUuid, parseStaffPaymentResult, paymentStatusIsPaid, paymentStatusStartsAttempt } from '../../src/staff/order-payment'

const invoiceUuid = '10000000-0000-4000-8000-000000000001'

describe('staff order collection', () => {
  it('allows only confirmed orders without a settled payment state', () => {
    expect(confirmedOrderCanCollect({ status: 'confirmed', paymentStatus: null })).toBe(true)
    expect(confirmedOrderCanCollect({ status: 'confirmed', paymentStatus: 'paying' })).toBe(true)
    expect(confirmedOrderCanCollect({ status: 'pending', paymentStatus: null })).toBe(false)
    expect(confirmedOrderCanCollect({ status: 'confirmed', paymentStatus: 'paid' })).toBe(false)
    expect(confirmedOrderCanCollect({ status: 'confirmed', paymentStatus: 'refunded' })).toBe(false)
  })

  it('extracts an invoice UUID from order, metadata or expanded invoice payloads', () => {
    expect(invoiceReferenceUuid({ invoiceUuid })).toBe(invoiceUuid)
    expect(invoiceReferenceUuid({ metadata: { invoice_id: invoiceUuid } })).toBe(invoiceUuid)
    expect(invoiceReferenceUuid({ invoice: { id: invoiceUuid } })).toBe(invoiceUuid)
    expect(invoiceReferenceUuid({ invoice: { id: 42 } })).toBeNull()
  })

  it('normalizes the Store Huifu payment response used by the customer payment entry', () => {
    const parsed = parseStaffPaymentResult({
      invoice: { uuid: invoiceUuid, status: 'paying' },
      status: 'paying',
      payUrl: 'https://pay.example.test/order',
      payload: { payInfo: '{"appId":"wx"}' },
    })
    expect(parsed).toEqual({
      status: 'paying',
      invoiceUuid,
      payUrl: 'https://pay.example.test/order',
      qrCode: null,
      payInfo: '{"appId":"wx"}',
      message: null,
    })
    expect(parseStaffPaymentResult({ data: { payment: { qr_code: 'weixin://wxpay/example', status: 'pending' } } }).qrCode).toBe('weixin://wxpay/example')
  })

  it('recognizes only confirmed server payment states as settled', () => {
    expect(paymentStatusIsPaid('paid')).toBe(true)
    expect(paymentStatusIsPaid('PAID')).toBe(true)
    expect(paymentStatusIsPaid('paying')).toBe(false)
    expect(paymentStatusIsPaid(null)).toBe(false)
  })

  it('allows a pending invoice but blocks duplicate provider attempts', () => {
    expect(paymentStatusStartsAttempt('pending')).toBe(false)
    expect(paymentStatusStartsAttempt(null)).toBe(false)
    expect(paymentStatusStartsAttempt('paying')).toBe(true)
    expect(paymentStatusStartsAttempt('UNKNOWN')).toBe(true)
  })
})
