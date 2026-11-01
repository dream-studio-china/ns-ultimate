import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import test from 'node:test'

function mount(record, options = {}) {
  const source = readFileSync(new URL(
    '../../business/admin/config/components/OrderCancelAction.js', import.meta.url
  ), 'utf8').replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
  const calls = { posts: [], refreshes: 0, messages: [] }
  const context = {
    defineComponent: value => value,
    ref: value => ({ value }),
    resolveComponent: name => name,
    h: (type, props, children) => ({ type, props: props || {}, children }),
    t: key => key,
    createUiFeedback: () => ({ success: message => calls.messages.push(message) }),
    window: { confirm: options.confirm || (() => true) },
    request: options.request || {
      post: async(...args) => { calls.posts.push(args); return { data: {}} }
    },
    ElMessage: { success: message => calls.messages.push(message) }
  }
  runInNewContext(source, context)

  const render = context.component.setup({
    record,
    refresh: () => { calls.refreshes++ }
  })
  const nodes = result => {
    if (!result) return []
    if (Array.isArray(result)) return result.flatMap(nodes)
    if (typeof result !== 'object') return []
    return [result, ...nodes(typeof result.children === 'function' ? result.children() : result.children)]
  }
  const button = () => nodes(render()).find(node => node.type === 'ElButton')

  return { calls, button, render, nodes: () => nodes(render()) }
}

test('shows order cancellation only for cancelable workflow states', () => {
  for (const status of ['draft', 'pending', 'confirmed']) {
    assert.ok(mount({ id: 12, status }).button(), `${status} should be cancelable`)
  }
  for (const status of ['paid', 'fulfilled', 'completed', 'cancelled', 'refunded']) {
    assert.equal(mount({ id: 12, status }).button(), undefined, `${status} should not be cancelable`)
  }
})

test('cancels an order through the workflow API and refreshes the list', async() => {
  const ui = mount({ id: 42, status: 'confirmed' })
  const button = ui.button()
  assert.equal(button.props.type, 'danger')
  assert.equal(button.props.size, 'small')
  assert.equal(button.children(), 'Cancel order')

  await button.props.onClick()

  assert.deepEqual(ui.calls.posts, [['/api/v1/manage/orders/42/do/cancel']])
  assert.equal(ui.calls.refreshes, 1)
  assert.deepEqual(ui.calls.messages, ['Order cancelled'])
})

test('does not cancel an order when confirmation is dismissed', async() => {
  const ui = mount({ id: 42, status: 'pending' }, { confirm: () => false })
  await ui.button().props.onClick()
  assert.deepEqual(ui.calls.posts, [])
  assert.equal(ui.calls.refreshes, 0)
})

test('shows API cancellation errors without refreshing the list', async() => {
  const ui = mount({ id: 42, status: 'draft' }, {
    request: {
      post: async() => {
        const error = new Error('Request failed')
        error.response = { data: { error: 'Order cannot be cancelled in current status.' }}
        throw error
      }
    }
  })

  await ui.button().props.onClick()

  const alert = ui.nodes().find(node => node.type === 'ElAlert')
  assert.equal(alert.props.title, 'Order cannot be cancelled in current status.')
  assert.equal(ui.calls.refreshes, 0)
  assert.deepEqual(ui.calls.messages, [])
})

test('order admin override adds the cancel action to the core order list', () => {
  const source = readFileSync(new URL(
    '../../business/admin/config/overrides/trade/order.js', import.meta.url
  ), 'utf8').replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const base = {
    form: { fields: ['notes'] },
    list: { list_display: ['id', 'status'], actions: [{ name: 'existing' }] },
    detail: { detail_display: ['id'] }
  }
  const context = {
    Order: { Order: base },
    OrderCancelAction: 'cancel-action',
    createOrderDiningModeField: property => ({ property, component: 'dining-mode' })
  }
  runInNewContext(source, context)

  assert.equal(context.config.form, base.form)
  assert.deepEqual(Array.from(context.config.list.list_display, field => typeof field === 'string' ? field : field.property), [
    'id', 'status', 'uuid'
  ])
  assert.equal(context.config.detail.detail_display[1].property, 'uuid')
  assert.deepEqual(JSON.parse(JSON.stringify(context.config.list.actions)), [
    { name: 'existing' },
    { name: 'order-cancel', position: 'list', component: 'cancel-action' }
  ])
})
