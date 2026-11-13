import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import test from 'node:test'

function mount(record, request, confirm = () => true) {
  const source = readFileSync(new URL('../../business/admin/config/components/MembershipStatusAction.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
  const context = {
    defineComponent: value => value,
    ref: value => ({ value }),
    resolveComponent: name => name,
    h: (type, props, children) => Array.isArray(props) || typeof props === 'string'
      ? ({ type, props: {}, children: props }) : ({ type, props: props || {}, children }),
    t: key => key,
    request,
    window: { confirm },
    globalThis: {}
  }
  runInNewContext(source, context)
  const refresh = []
  const render = context.globalThis.component.setup({ record, refresh: () => refresh.push(true) })
  const nodes = () => {
    const all = []
    const visit = node => {
      if (!node || typeof node !== 'object') return
      all.push(node)
      const children = typeof node.children === 'function' ? node.children() : node.children
      if (Array.isArray(children)) children.forEach(visit)
    }
    visit(render())
    return all
  }
  const button = label => nodes().find(node => node.type === 'ElButton' && node.children() === label)
  return { nodes, button, refresh }
}

test('membership status actions call the scoped store endpoint', async() => {
  const calls = []
  const ui = mount({ uuid: 'member-uuid', status: 'active', store: { uuid: 'store-uuid' } }, {
    post: async(url, body) => { calls.push([url, body]); return { data: {} } }
  })
  assert.deepEqual(ui.nodes().filter(node => node.type === 'ElButton').map(node => node.children()), ['Suspend', 'Revoke'])
  assert.equal(ui.button('Suspend').props.icon, 'el-icon-lock')
  await ui.button('Suspend').props.onClick()
  assert.deepEqual(JSON.parse(JSON.stringify(calls)), [['/api/v1/manage/stores/store-uuid/members/member-uuid/suspend', {}]])
  assert.equal(ui.refresh.length, 1)
})

test('revoked memberships offer activation only and accept a store UUID string', async() => {
  const calls = []
  const ui = mount({ uuid: 'member-uuid', status: 'revoked', store: 'store-uuid' }, {
    post: async(url, body) => { calls.push([url, body]); return { data: {} } }
  })
  assert.deepEqual(ui.nodes().filter(node => node.type === 'ElButton').map(node => node.children()), ['Activate'])
  await ui.button('Activate').props.onClick()
  assert.deepEqual(JSON.parse(JSON.stringify(calls)), [['/api/v1/manage/stores/store-uuid/members/member-uuid/activate', {}]])
})

test('declining the confirmation does not change membership status', async() => {
  let submitted = false
  const ui = mount({ uuid: 'member-uuid', status: 'active', store: 'store-uuid' }, {
    post: async() => { submitted = true }
  }, () => false)
  await ui.button('Revoke').props.onClick()
  assert.equal(submitted, false)
})
