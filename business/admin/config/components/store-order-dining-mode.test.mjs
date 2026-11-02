import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

function loadComponent(retrieve) {
  const source = readFileSync(new URL('./StoreOrderDiningMode.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace('export default', 'globalThis.component =')
    .replace('export function createStoreOrderDiningModeField', 'function createStoreOrderDiningModeField')
    .replace('export default StoreOrderDiningMode', 'globalThis.component = StoreOrderDiningMode')
    .replace('export { loadStoreOrderDiningMode }', 'globalThis.createField = createStoreOrderDiningModeField; globalThis.load = loadStoreOrderDiningMode')
  const context = {
    defineComponent: config => config,
    h: (tag, props) => ({ tag, props: props || {} }),
    ref: value => ({ value }),
    t: key => key,
    watch: (_getter, callback) => { context.callback = callback },
    CrudSkeletonAdapter: class { retrieve(uuid) { return retrieve(uuid) } },
    OrderDiningMode: 'OrderDiningMode',
    createStoreOrderDiningModeField: null
  }
  runInNewContext(source, context)
  return context
}

test('loads the Trade order metadata and renders the dining mode in a Store order row', async () => {
  const context = loadComponent(async uuid => ({
    data: { uuid, metadata: { diningModeUuid: 'mode-hotpot', diningModeName: 'Hotpot' } }
  }))
  const render = context.component.setup({ data: '', record: {} })
  const uuid = '11111111-1111-4111-8111-111111111111'
  await context.callback(uuid, '', () => {})

  const rendered = render()
  assert.equal(rendered.tag, 'OrderDiningMode')
  assert.equal(rendered.props.record.metadata.diningModeName, 'Hotpot')

  const field = context.createField()
  assert.equal(field.property, 'tradeOrderUuid')
  assert.equal(field.label, 'Dining mode')
  assert.equal(field.component, context.component)
})

test('ignores stale Store order lookups and reports failures without losing the row', async () => {
  const pending = []
  const context = loadComponent(uuid => new Promise((resolve, reject) => pending.push({ uuid, resolve, reject })))
  const render = context.component.setup({ data: '', record: {} })
  let cleanup
  const first = context.callback('11111111-1111-4111-8111-111111111111', '', fn => { cleanup = fn })
  cleanup()
  const second = context.callback('22222222-2222-4222-8222-222222222222', '', () => {})
  pending[1].resolve({ data: { metadata: { diningModeName: 'Breakfast' } } })
  await second
  assert.equal(render().props.record.metadata.diningModeName, 'Breakfast')

  pending[0].resolve({ data: { metadata: { diningModeName: 'Hotpot' } } })
  await first
  assert.equal(render().props.record.metadata.diningModeName, 'Breakfast')

  const failed = context.callback('33333333-3333-4333-8333-333333333333', '', () => {})
  pending[2].reject(new Error('Unavailable'))
  await failed
  assert.equal(render().props, 'Dining mode failed to load')
})
