import assert from 'node:assert/strict'
import test from 'node:test'
import {
  amountToMinorUnits,
  minorUnitsToInput,
  orderRefundPath,
  orderStoreUuid,
  refundContext,
  refundReasonBytes,
  validateRefundAmount
} from './order-refund.mjs'

test('amountToMinorUnits converts decimal yuan amounts without losing precision', () => {
  assert.equal(amountToMinorUnits('10'), 1000)
  assert.equal(amountToMinorUnits('10.5'), 1050)
  assert.equal(amountToMinorUnits('10.05'), 1005)
  assert.equal(amountToMinorUnits(0.01), 1)
  assert.equal(minorUnitsToInput(1050), '10.50')
})

test('amountToMinorUnits rejects malformed and over-precise amounts', () => {
  for (const value of ['', '0.001', '-1', '1e2', '1,000', 'abc', null, undefined]) {
    assert.equal(amountToMinorUnits(value), null)
  }
})

test('validateRefundAmount enforces positive and remaining balance limits', () => {
  assert.deepEqual(validateRefundAmount('12.34', 1234), { error: '', cents: 1234 })
  assert.equal(validateRefundAmount('0', 1234).error, 'refundAmountPositive')
  assert.equal(validateRefundAmount('12.35', 1234).error, 'refundAmountExceeds')
})

test('refundReasonBytes measures the backend UTF-8 limit', () => {
  assert.equal(refundReasonBytes('refund'), 6)
  assert.equal(refundReasonBytes('退款'), 6)
})

test('refundContext accepts a linked paid order invoice', () => {
  const context = refundContext(
    { uuid: 'order-uuid', invoiceId: 'invoice-uuid', currency: 'CNY', paymentMethod: 'wechat' },
    {
      uuid: 'invoice-uuid',
      sourceType: 'trade_order',
      sourceId: 'order-uuid',
      amount: 2400,
      refundedAmount: 500,
      currency: 'CNY',
      payment: 'wechat',
      status: 'partial_refunded'
    }
  )

  assert.equal(context.error, '')
  assert.equal(context.refundableAmount, 1900)
  assert.equal(context.canRefund, true)
  assert.equal(context.payment, 'wechat')
})

test('refundContext fails closed for mismatched or unpaid invoices', () => {
  const order = { uuid: 'order-uuid', invoiceId: 'invoice-uuid' }
  assert.equal(refundContext(order, { uuid: 'other' }).error, 'paymentContextMismatch')
  assert.equal(refundContext(order, null).error, 'invoiceNotFound')
  assert.equal(refundContext({}, null).error, 'orderHasNoInvoice')

  const unpaid = refundContext(order, {
    uuid: 'invoice-uuid',
    sourceType: 'trade_order',
    sourceId: 'order-uuid',
    amount: 1000,
    refundedAmount: 0,
    status: 'pending'
  })
  assert.equal(unpaid.canRefund, false)
  assert.equal(unpaid.statusRefundable, false)
})

test('orderStoreUuid reads only a valid Store context from the order', () => {
  const storeUuid = '00000000-0000-4000-8000-000000000002'
  assert.equal(orderStoreUuid({ metadata: { _store: { uuid: storeUuid } } }), storeUuid)
  assert.equal(orderStoreUuid({}, { metadata: { _store: { uuid: storeUuid } } }), storeUuid)
  assert.equal(orderStoreUuid({ metadata: { _store: { uuid: 'not-a-uuid' } } }), '')
})

test('orderRefundPath always targets the unified Store Trade order API', () => {
  const storeUuid = '00000000-0000-4000-8000-000000000002'
  const tradeOrderUuid = '00000000-0000-4000-8000-000000000001'
  assert.equal(orderRefundPath(storeUuid, tradeOrderUuid), `/api/v1/store/${storeUuid}/trade-orders/${tradeOrderUuid}/refund`)
  assert.throws(() => orderRefundPath('', tradeOrderUuid), /Store UUID is required/)
  assert.throws(() => orderRefundPath(storeUuid, ''), /Trade order UUID is required/)
})
