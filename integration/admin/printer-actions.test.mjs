import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import test from 'node:test'

function mount(name, request, confirm = () => true, record = {}) {
  const source = readFileSync(new URL(`../../business/admin/config/components/${name}.js`, import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
  const context = {
    defineComponent: value => value, ref: value => ({ value }), resolveComponent: name => name,
    h: (type, props, children) => Array.isArray(props) || typeof props === 'string'
      ? ({ type, props: {}, children: props }) : ({ type, props: props || {}, children }),
    t: key => key, request, PrinterPaper: 'PrinterPaper', window: { confirm }, globalThis: { crypto: { randomUUID: () => 'test-key' } }
  }
  runInNewContext(source, context)
  const render = context.globalThis.component.setup({ refresh() {}, record })
  const nodes = () => {
    const all = []
    const visit = node => {
      if (!node || typeof node !== 'object') return
      all.push(node)
      const children = typeof node.children === 'function' ? node.children() : node.children
      if (Array.isArray(children)) children.forEach(visit)
      else if (children && typeof children === 'object') Object.values(children).forEach(slot => {
        if (typeof slot === 'function') { const value = slot(); (Array.isArray(value) ? value : [value]).forEach(visit) }
      })
    }
    visit(render())
    return all
  }
  const button = label => nodes().find(node => node.type === 'ElButton' && node.children() === label)
  return { nodes, button }
}

test('print dialog unwraps preview and submission responses and matches template types', async() => {
  const posts = []
  const ui = mount('PrinterJobAction', {
    get: async url => ({ data: url.endsWith('devices') ? [{ uuid: 'd', name: 'Printer', kind: 'receipt', enabled: true }]
      : url.endsWith('templates') ? [{ uuid: 'wrong', name: 'Label', kind: 'label', enabled: true }, { uuid: 't', name: 'Receipt', kind: 'receipt', enabled: true }] : [] }),
    post: async(url, body) => {
      posts.push([url, body])
      return { code: 0, data: url.endsWith('preview') ? { document: { content: 'Rendered receipt' } } : { status: 'submitted' } }
    }
  })
  await ui.button('Create print job').props.onClick()
  assert.equal(ui.nodes().find(node => node.type === 'ElDialog').props.alignCenter, true)
  await ui.button('Update preview').props.onClick()
  assert.ok(ui.nodes().some(node => node.type === 'PrinterPaper' && node.props.document.content === 'Rendered receipt'))
  await ui.button('Submit print job').props.onClick()
  assert.equal(posts[1][1].templateUuid, 't')
  assert.ok(ui.nodes().some(node => node.type === 'ElAlert' && node.props.title === 'Job status: submitted'))
})

test('loading dialog blocks submission and modal dismissal', async() => {
  let finish
  const response = new Promise(resolve => { finish = resolve })
  const ui = mount('PrinterDeviceAction', { get: () => response })
  const opening = ui.button('Register printer').props.onClick()
  const dialog = ui.nodes().find(node => node.type === 'ElDialog')
  assert.equal(dialog.props.alignCenter, true)
  assert.equal(dialog.props.closeOnClickModal, false)
  assert.equal(dialog.props.showClose, false)
  assert.equal(ui.button('Close').props.disabled, true)
  finish({ data: [] })
  await opening
  assert.ok(ui.nodes().some(node => node.props.title === 'Create an enabled printer account first.'))
})

test('status refresh skips disabled demo devices and displays errors outside the dialog', async() => {
  const posts = []
  const ui = mount('PrinterDeviceAction', {
    get: async() => ({ data: [{ uuid: 'demo', enabled: false }, { uuid: 'live', enabled: true }] }),
    post: async url => { posts.push(url); throw new Error('Status unavailable') }
  })
  await ui.button('Refresh device status').props.onClick()
  assert.deepEqual(posts, ['/api/v1/manage/printer/devices/live/status'])
  assert.ok(ui.nodes().some(node => node.type === 'ElAlert' && node.props.title === 'Status unavailable'))
})

test('cancelling print confirmation never submits a job', async() => {
  let posted = false
  const ui = mount('PrinterJobAction', {
    get: async url => ({ data: url.endsWith('devices') ? [{ uuid: 'd', enabled: true, kind: 'receipt' }]
      : url.endsWith('templates') ? [{ uuid: 't', enabled: true, kind: 'receipt' }] : [] }),
    post: async() => { posted = true }
  }, () => false)
  await ui.button('Create print job').props.onClick()
  await ui.button('Submit print job').props.onClick()
  assert.equal(posted, false)
})

test('each task opens its stored snapshot paper preview without a print submission', async() => {
  const urls = []
  const ui = mount('PrinterPaperAction', {
    get: async url => { urls.push(url); return { data: { document: { kind: 'receipt', pages: ['Saved receipt'] } } } },
    post: async() => { assert.fail('Task preview must never submit or re-render a job') }
  }, () => true, { id: 12, uuid: 'job-12' })
  await ui.button('Paper preview').props.onClick()
  assert.deepEqual(urls, ['/api/v1/manage/printer/jobs/12/preview'])
  assert.ok(ui.nodes().some(node => node.type === 'PrinterPaper' && node.props.document.pages[0] === 'Saved receipt'))
})

test('each template previews its definition with editable sample data', async() => {
  const posts = []
  const ui = mount('PrinterPaperAction', {
    post: async(url, data) => { posts.push([url, data]); return { data: { document: { kind: 'label', pages: ['Label'] } } } }
  }, () => true, { id: 3, definition: { kind: 'label', nodes: [] } })
  await ui.button('Paper preview').props.onClick()
  assert.equal(posts[0][0], '/api/v1/manage/printer/preview')
  assert.equal(posts[0][1].template.kind, 'label')
  assert.equal(posts[0][1].data.order.number, 'DEMO-001')
  assert.ok(ui.nodes().some(node => node.type === 'PrinterPaper'))
})
