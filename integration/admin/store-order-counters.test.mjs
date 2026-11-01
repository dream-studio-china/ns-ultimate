import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

function loadComponent(list) {
  const source = readFileSync(new URL(
    '../../business/admin/config/components/StoreOrderCounterNumbers.js', import.meta.url
  ), 'utf8').replace(/^import .*$/gm, '')
    .replace('export default', 'globalThis.component =')
    .replace('export { loadCounterNumbers }', 'globalThis.load = loadCounterNumbers')
  const context = {
    defineComponent: config => config,
    h: (tag, props, children) => ({
      tag,
      props: children !== undefined || (props && typeof props === 'object' && !Array.isArray(props)) ? props : null,
      children: children === undefined && (!props || typeof props !== 'object' || Array.isArray(props)) ? props : children
    }),
    ref: value => ({ value }),
    t: key => key,
    watch: (_getter, callback) => { context.callback = callback },
    CrudSkeletonAdapter: class { list(params) { return list(params) } },
    RelationToOne: 'RelationToOne'
  }
  runInNewContext(source, context)
  return context
}

test('renders expanded Counter relations with the UUID relation plugin and ignores stale responses', async () => {
  const pending = []
  const context = loadComponent(params => new Promise(resolve => pending.push({ params, resolve })))
  const render = context.component.setup({ data: '' })
  let cleanup
  const first = context.callback('11111111-1111-4111-8111-111111111111', '', fn => { cleanup = fn })
  cleanup()
  const second = context.callback('22222222-2222-4222-8222-222222222222', '', () => {})
  pending[1].resolve({ data: [
    { counterCodeSnapshot: 'A02', counter: { id: 12, uuid: 'counter-uuid-02', code: 'A02' } },
    { counterCodeSnapshot: 'A03', counter: { id: 13, uuid: 'counter-uuid-03', code: 'A03' } }
  ] })
  await second
  const rendered = render()
  assert.equal(rendered.tag, 'span')
  assert.equal(rendered.children[0].tag, 'RelationToOne')
  assert.equal(rendered.children[0].props.value.uuid, 'counter-uuid-02')
  assert.equal(rendered.children[0].props.field.relation.valueKey, 'uuid')
  assert.equal(rendered.children[2].props.value.uuid, 'counter-uuid-03')
  assert.equal(pending[1].params['@expands'], 'counter')
  pending[0].resolve({ data: [{ counterCodeSnapshot: 'A01' }] })
  await first
  assert.equal(render().children[0].props.value.uuid, 'counter-uuid-02')
  assert.match(pending[1].params['@filter'], /getTradeOrderUuid\(\)/)
})

test('shows failed loads separately from unbound orders and allows retry', async () => {
  let calls = 0
  const context = loadComponent(() => ++calls === 1 ? Promise.reject(new Error('Unavailable')) : Promise.resolve({ data: [] }))
  const render = context.component.setup({ data: '' })
  const uuid = '11111111-1111-4111-8111-111111111111'
  await context.callback(uuid, '', () => {})
  assert.equal(render().children, 'Counter numbers failed to load')
  await context.callback(uuid, '', () => {})
  assert.equal(render().children, '—')
  assert.equal(calls, 2)
})
