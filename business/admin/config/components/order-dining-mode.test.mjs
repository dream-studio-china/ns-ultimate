import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { diningModeFromOrderMetadata } from './order-dining-mode.mjs'

const plain = value => JSON.parse(JSON.stringify(value))

function loadComponent() {
  const source = readFileSync(new URL('./OrderDiningMode.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace('export function createOrderDiningModeField', 'function createOrderDiningModeField')
    .replace('export default OrderDiningMode', 'globalThis.component = OrderDiningMode')
    .replace('export { createOrderDiningModeField }', '')
  const context = {
    defineComponent: config => config,
    h: (tag, props, children) => ({ tag, props: props || {}, children }),
    t: key => key,
    diningModeFromOrderMetadata
  }
  runInNewContext(source, context)
  return context
}

test('reads the dining mode from Order metadata in supported API shapes', () => {
  assert.deepEqual(diningModeFromOrderMetadata({
    metadata: { diningModeUuid: 'mode-hotpot', diningModeName: 'Hotpot' }
  }), { uuid: 'mode-hotpot', name: 'Hotpot' })

  assert.deepEqual(diningModeFromOrderMetadata({
    metadata: JSON.stringify({ dining_mode_uuid: 'mode-breakfast' })
  }), { uuid: 'mode-breakfast', name: null })

  assert.deepEqual(diningModeFromOrderMetadata({
    __metadata: { metadata: { diningModeName: 'Breakfast' } }
  }), { uuid: null, name: 'Breakfast' })

  assert.equal(diningModeFromOrderMetadata({ metadata: [] }), null)
  assert.equal(diningModeFromOrderMetadata(null), null)
})

test('renders the mode name, UUID fallback and an explicit empty state', () => {
  const context = loadComponent()

  assert.equal(context.component.setup({
    record: { metadata: { diningModeUuid: '12345678-1234', diningModeName: 'Hotpot' } }
  })().children, 'Hotpot')

  assert.equal(context.component.setup({
    record: { metadata: { diningModeUuid: '12345678-1234' } }
  })().children, '12345678')

  assert.equal(context.component.setup({ record: { metadata: {} } })().children, 'Not recorded')
})

test('adds a read-only dining mode field to the Order list and detail', () => {
  const core = {
    Order: {
      list: { list_display: ['id', 'user', 'totalAmount', 'currency', 'status', 'paymentMethod', 'createdAt'] },
      detail: { detail_display: ['id', 'uuid', 'user', 'totalAmount', 'currency', 'status', 'paymentMethod', 'metadata'] }
    }
  }
  const diningModeField = { property: 'diningModeUuid', component: 'OrderDiningMode' }
  const paymentStatusField = { property: 'paymentStatus', component: 'OrderPaymentStatus' }
  const source = readFileSync(new URL('../overrides/trade/order.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const context = {
    coreOrderConfig: core,
    createOrderDiningModeField: () => diningModeField,
    createOrderPaymentStatusField: () => paymentStatusField,
    OrderRefundAction: {},
    t: key => key,
    config: null
  }
  context.Order = core
  runInNewContext(source, context)

  assert.deepEqual(plain(context.config.list.list_display), [
    'id', 'user', 'totalAmount', 'currency', 'status', diningModeField, 'paymentMethod', 'createdAt', paymentStatusField
  ])
  assert.deepEqual(plain(context.config.detail.detail_display), [
    'id', 'uuid', 'user', 'totalAmount', 'currency', 'status', diningModeField, 'paymentMethod', 'metadata', paymentStatusField,
    { property: 'refundDetails', label: 'Refund details', component: {}, full_width: true }
  ])
  assert.equal(context.config.list.actions.at(-1).name, 'order-refund')
})
