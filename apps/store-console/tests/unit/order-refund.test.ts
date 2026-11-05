import { describe, expect, it } from 'vitest'
import {
  amountToMinorUnits,
  minorUnitsToInput,
  paidOrderCanRefund,
  parseRefundPreview,
  refundReasonBytes,
  refundResultKind,
  validateRefundAmount,
} from '../../src/staff/order-refund'

const orderUuid = '00000000-0000-4000-8000-000000000001'
const invoiceUuid = '00000000-0000-4000-8000-000000000002'

describe('cashier order refunds', () => {
  it('parses decimal refund amounts without floating point rounding', () => {
    expect(amountToMinorUnits('12.34')).toBe(1234)
    expect(amountToMinorUnits('12.3')).toBe(1230)
    expect(minorUnitsToInput(1234)).toBe('12.34')
    for (const value of ['', '0.001', '-1', '1e2', '1,000', 'abc']) expect(amountToMinorUnits(value)).toBeNull()
  })

  it('limits refunds to the positive remaining invoice balance', () => {
    expect(validateRefundAmount('5.00', 500)).toEqual({ cents: 500, error: null })
    expect(validateRefundAmount('0', 500).error).toBe('refundAmountPositive')
    expect(validateRefundAmount('5.01', 500).error).toBe('refundAmountExceeds')
  })

  it('offers refunds only for paid trade orders', () => {
    expect(paidOrderCanRefund({ status: 'paid', paymentStatus: 'paid' })).toBe(true)
    expect(paidOrderCanRefund({ status: 'fulfilled', paymentStatus: 'paid' })).toBe(false)
    expect(paidOrderCanRefund({ status: 'confirmed', paymentStatus: 'pending' })).toBe(false)
    expect(paidOrderCanRefund({ status: 'refunded', paymentStatus: 'refunded' })).toBe(false)
  })

  it('measures the refund reason in UTF-8 bytes', () => {
    expect(refundReasonBytes('customer request')).toBe(16)
    expect(refundReasonBytes('退款')).toBe(6)
  })

  it('builds refund details from the authoritative payment projection', () => {
    expect(parseRefundPreview({
      orderUuid,
      currency: 'CNY',
      amount: 2400,
      invoice: {
        uuid: invoiceUuid,
        status: 'partial_refunded',
        amount: 2400,
        refundedAmount: 500,
        currency: 'CNY',
        payment: 'huifu',
      },
    }, orderUuid)).toMatchObject({
      invoiceUuid,
      refundableAmount: 1900,
      payment: 'huifu',
      canRefund: true,
    })
  })

  it('fails closed for mismatched orders, unpaid invoices and invalid balances', () => {
    expect(parseRefundPreview({ orderUuid: invoiceUuid, invoice: null }, orderUuid)).toBeNull()
    expect(parseRefundPreview({
      orderUuid,
      amount: 1000,
      invoice: { uuid: invoiceUuid, status: 'pending', amount: 1000, refundedAmount: 0, currency: 'CNY', payment: 'huifu' },
    }, orderUuid)).toMatchObject({ canRefund: false })
    expect(parseRefundPreview({
      orderUuid,
      amount: 1000,
      invoice: { uuid: invoiceUuid, status: 'paid', amount: 1000, refundedAmount: 1001, currency: 'CNY', payment: 'huifu' },
    }, orderUuid)).toBeNull()
  })

  it('distinguishes every terminal and unresolved order refund result', () => {
    expect(refundResultKind('processing')).toBe('processing')
    expect(refundResultKind('unknown')).toBe('unknown')
    expect(refundResultKind('succeeded')).toBe('succeeded')
    expect(refundResultKind('failed')).toBe('failed')
  })
})
