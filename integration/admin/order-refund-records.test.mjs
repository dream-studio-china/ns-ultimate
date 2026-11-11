import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import en from '../../business/admin/i18n/en.js'
import zh from '../../business/admin/i18n/zh.js'
import zhHant from '../../business/admin/i18n/zh-Hant.js'
import ja from '../../business/admin/i18n/ja.js'
import {
  ORDER_REFUND_ORDERS_ENDPOINT,
  ORDER_REFUND_STORES_ENDPOINT,
  normalizeOrderRefundOrdersResponse
} from '../../business/admin/config/components/order-refund-records.mjs'

test('order refund records discover refunds from admin order pages', () => {
  assert.equal(ORDER_REFUND_ORDERS_ENDPOINT, '/api/v1/manage/orders')
  const normalized = normalizeOrderRefundOrdersResponse({
    data: [{ uuid: 'order-record' }],
    paginator: { page: 1, pages: 2, has_next: true }
  })
  assert.equal(normalized.hasNext, true)
  assert.equal(normalized.items[0].uuid, 'order-record')
})

test('order refund records page uses Store-scoped refund history routes', () => {
  const source = readFileSync(new URL('../../business/admin/config/components/OrderRefundRecords.vue', import.meta.url), 'utf8')
  assert.match(source, /ORDER_REFUND_ORDERS_ENDPOINT/)
  assert.match(source, /ORDER_REFUND_STORES_ENDPOINT/)
  assert.match(source, /storeOrderRefundHistoryPath\(context\.storeUuid, context\.tradeOrderUuid\)/)
  assert.match(source, /pageOrderRefundRecords\(this\.allRecords, page, this\.limit, this\.status, this\.storeUuid\)/)
  assert.doesNotMatch(source, /\/api\/v1\/manage\/order-refunds/)
})

test('order refund records expose the existing Store list as a filter', () => {
  assert.equal(ORDER_REFUND_STORES_ENDPOINT, '/api/v1/manage/stores')
  const source = readFileSync(new URL('../../business/admin/config/components/OrderRefundRecords.vue', import.meta.url), 'utf8')
  assert.match(source, /v-model="storeUuid"/)
  assert.match(source, /v-for="option in storeOptions"/)
  assert.match(source, /@change="changeStore"/)
})

test('order detail refund history uses the Store-scoped history endpoint', () => {
  const source = readFileSync(new URL('../../business/admin/config/components/OrderRefundAction.vue', import.meta.url), 'utf8')
  assert.match(source, /orderRefundHistoryPath\(storeUuid, this\.order\.uuid\)/)
  assert.match(source, /orderStoreUuid\(this\.order, this\.record\)/)
})

test('order refund submission uses the Store-scoped refund endpoint', () => {
  const source = readFileSync(new URL('../../business/admin/config/components/OrderRefundAction.vue', import.meta.url), 'utf8')
  assert.match(source, /orderRefundPath\(storeUuid, tradeOrderUuid\)/)
  assert.match(source, /const storeUuid = orderStoreUuid\(this\.order, this\.record\)/)
  assert.match(source, /this\.storeUuid = storeUuid/)
  assert.doesNotMatch(source, /request\.post\(orderRefundPath\(tradeOrderUuid\)/)
})

test('order refund records menu is localized in every business locale', () => {
  for (const messages of [en, zh, zhHant, ja]) {
    for (const key of [
      'Order refund records',
      'Unified order refund records help',
      'All refund statuses',
      'All stores',
      'No order refund records',
      'Failed to load order refund records'
    ]) {
      assert.equal(typeof messages[key], 'string', key)
      assert.notEqual(messages[key], '', key)
    }
  }
})
