import assert from 'node:assert/strict'
import test from 'node:test'
import {
  ORDER_REFUND_ORDERS_ENDPOINT,
  ORDER_REFUND_STORES_ENDPOINT,
  formatRefundRecordDate,
  normalizeOrderRefundOrdersResponse,
  normalizeOrderRefundStoresResponse,
  normalizeStoreOrderRefundHistoryResponse,
  orderDetailPath,
  orderRefundContexts,
  orderRefundOrdersParams,
  orderRefundStoresParams,
  pageOrderRefundRecords,
  refundStatusTagType,
  storeFilterOptions,
  storeOrderRefundHistoryPath
} from './order-refund-records.mjs'

const storeUuid = '00000000-0000-4000-8000-000000000002'
const tradeOrderUuid = '00000000-0000-4000-8000-000000000001'

test('reads admin order pages before store refund histories', () => {
  assert.equal(ORDER_REFUND_ORDERS_ENDPOINT, '/api/v1/manage/orders')
  assert.deepEqual(orderRefundOrdersParams(2, 500), { page: 2, limit: 100 })
  assert.deepEqual(normalizeOrderRefundOrdersResponse({
    data: [{ uuid: tradeOrderUuid }],
    paginator: { page: 2, pages: 3, has_next: true }
  }), {
    items: [{ uuid: tradeOrderUuid }],
    page: 2,
    pages: 3,
    hasNext: true
  })
  assert.throws(() => normalizeOrderRefundOrdersResponse({ data: null }), /Unexpected/)
  assert.throws(() => normalizeOrderRefundOrdersResponse({ data: [], paginator: { page: 0, pages: 1 } }), /Invalid/)
})

test('reads bounded Store pages for the refund filter', () => {
  assert.equal(ORDER_REFUND_STORES_ENDPOINT, '/api/v1/manage/stores')
  assert.deepEqual(orderRefundStoresParams(3, 500), { page: 3, limit: 100, '@display': 'reduce' })
  assert.deepEqual(normalizeOrderRefundStoresResponse({
    data: [{ uuid: storeUuid }],
    paginator: { page: 1, pages: 2, has_next: true }
  }), {
    items: [{ uuid: storeUuid }],
    page: 1,
    pages: 2,
    hasNext: true
  })
  assert.throws(() => normalizeOrderRefundStoresResponse({ data: [], paginator: { page: 0, pages: 1 } }), /Invalid/)
})

test('selects only store orders carrying refund receipts', () => {
  assert.deepEqual(orderRefundContexts([
    { uuid: tradeOrderUuid, metadata: { _store: { uuid: storeUuid } } },
    {
      id: 9,
      uuid: tradeOrderUuid,
      currency: 'CNY',
      metadata: { _store: { uuid: storeUuid }, storeRefundRequests: { hash: { uuid: 'refund-1' } } }
    }
  ]), [{ storeUuid, tradeOrderUuid, orderId: 9, currency: 'CNY' }])
  assert.throws(() => orderRefundContexts([{
    uuid: tradeOrderUuid,
    metadata: { storeRefundRequests: { hash: { uuid: 'refund-1' } } }
  }]), /Store context is missing/)
})

test('normalizes store refund history against the requested order and store', () => {
  const context = { storeUuid, tradeOrderUuid, orderId: 9, currency: 'CNY' }
  assert.equal(
    storeOrderRefundHistoryPath(storeUuid, tradeOrderUuid),
    `/api/v1/store/${storeUuid}/trade-orders/${tradeOrderUuid}/refunds`
  )
  assert.deepEqual(normalizeStoreOrderRefundHistoryResponse({
    data: [{ uuid: 'refund-1', amount: 500, createdAt: '2026-11-05T10:00:00+08:00' }]
  }, context), [{
    uuid: 'refund-1',
    amount: 500,
    createdAt: '2026-11-05T10:00:00+08:00',
    tradeOrderUuid,
    storeUuid,
    orderId: 9,
    currency: 'CNY'
  }])
  assert.throws(() => normalizeStoreOrderRefundHistoryResponse({
    data: [{ uuid: 'refund-1', tradeOrderUuid: '00000000-0000-4000-8000-000000000099' }]
  }, context), /different Trade order/)
})

test('filters, sorts and pages aggregated refund records in the browser', () => {
  const records = [
    { uuid: 'b', status: 'failed', storeUuid, createdAt: '2026-11-05T10:00:00+08:00' },
    { uuid: 'a', status: 'succeeded', storeUuid, createdAt: '2026-11-05T11:00:00+08:00' },
    { uuid: 'c', status: 'succeeded', storeUuid, createdAt: '2026-11-05T12:00:00+08:00' }
  ]
  assert.deepEqual(pageOrderRefundRecords(records, 1, 1, 'succeeded', storeUuid), {
    items: [records[2]],
    total: 2,
    page: 1,
    limit: 1
  })
  assert.equal(pageOrderRefundRecords(records, 2, 1, 'succeeded', storeUuid).items[0].uuid, 'a')
  assert.equal(pageOrderRefundRecords(records, 1, 20, '', '00000000-0000-4000-8000-000000000003').total, 0)
  assert.equal(pageOrderRefundRecords(records, 99, 20, '').page, 1)
})

test('builds named Store filter options and falls back to refund record identifiers', () => {
  assert.deepEqual(storeFilterOptions([
    { uuid: storeUuid, name: 'Main Store', code: 'MAIN' },
    { uuid: '00000000-0000-4000-8000-000000000003', __toString: 'SECOND' },
    { uuid: 'invalid', name: 'Ignored' }
  ], [
    { storeUuid: '00000000-0000-4000-8000-000000000004' },
    { storeUuid: storeUuid.toUpperCase() }
  ]), [
    { uuid: storeUuid, label: 'Main Store (MAIN)' },
    { uuid: '00000000-0000-4000-8000-000000000003', label: 'SECOND' },
    { uuid: '00000000-0000-4000-8000-000000000004', label: '00000000-0000-4000-8000-000000000004' }
  ])
})

test('links valid order refunds back to their order detail', () => {
  assert.equal(orderDetailPath(tradeOrderUuid), `/order/${tradeOrderUuid}/detail`)
  assert.equal(orderDetailPath('not-an-order'), '')
  assert.equal(formatRefundRecordDate('not-a-date'), '')
  assert.equal(refundStatusTagType('succeeded'), 'success')
  assert.equal(refundStatusTagType('failed'), 'danger')
})
