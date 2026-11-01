import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { diningModeFromOrderCounterBindings } from '../../business/admin/config/components/order-dining-mode.mjs'

function loadComponent(loadBindings, loadTradeOrderRecord = async() => null) {
  const source = readFileSync(new URL(
    '../../business/admin/config/components/OrderDiningMode.js', import.meta.url
  ), 'utf8').replace(/^import .*$/gm, '')
    .replace('export function createOrderDiningModeField', 'function createOrderDiningModeField')
    .replace('export default OrderDiningMode', 'globalThis.component = OrderDiningMode')
    .replace('export { createOrderDiningModeField }', '')
  const context = {
    defineComponent: config => config,
    h: (tag, props, children) => ({ tag, props: props || {}, children }),
    ref: value => ({ value }),
    t: key => key,
    watch: (_getter, callback) => { context.callback = callback },
    loadOrderCounterBindings: loadBindings,
    loadTradeOrderRecord,
    diningModeFromOrderCounterBindings
  }
  runInNewContext(source, context)
  return context
}

test('reads the frozen dining mode from an expanded counter session', () => {
  const bindings = [{
    counterSession: {
      id: 21,
      uuid: 'session-uuid',
      __metadata: {
        diningModeUuid: '11111111-1111-4111-8111-111111111111',
        diningModeSnapshot: JSON.stringify({ uuid: '11111111-1111-4111-8111-111111111111', name: 'Hotpot' })
      }
    }
  }]

  assert.deepEqual(diningModeFromOrderCounterBindings(bindings), {
    uuid: '11111111-1111-4111-8111-111111111111',
    name: 'Hotpot'
  })
  assert.equal(diningModeFromOrderCounterBindings([{ counterSession: null }]), null)
  assert.equal(diningModeFromOrderCounterBindings(null), null)
})

test('renders the mode name and an explicit legacy fallback', async() => {
  const uuid = '11111111-1111-4111-8111-111111111111'
  const configured = loadComponent(async() => [{
    counterSession: { __metadata: { diningModeSnapshot: { uuid, name: 'Breakfast' } } }
  }])
  const configuredRender = configured.component.setup({ data: uuid, record: {} })
  await configured.callback(uuid, '', () => {})
  assert.equal(configuredRender().children, 'Breakfast')

  const legacy = loadComponent(async() => [])
  const legacyRender = legacy.component.setup({ data: uuid, record: {} })
  await legacy.callback(uuid, '', () => {})
  assert.equal(legacyRender().children, 'Dining mode not recorded')
})

test('shows a StoreOrder mode by loading metadata from its Trade order', async() => {
  const uuid = '11111111-1111-4111-8111-111111111111'
  let requestedUuid = null
  const context = loadComponent(async() => [], async(orderUuid) => {
    requestedUuid = orderUuid
    return {
      uuid,
      metadata: { diningModeUuid: 'mode-breakfast', diningModeName: 'Breakfast' }
    }
  })
  const render = context.component.setup({
    data: uuid,
    record: { uuid: 'store-order-uuid', tradeOrderUuid: uuid, orderSnapshot: { items: [] } }
  })
  await context.callback(uuid, '', () => {})

  assert.equal(requestedUuid, uuid)
  assert.equal(render().children, 'Breakfast')
})
