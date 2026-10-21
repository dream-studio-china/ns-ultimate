import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import test from 'node:test'

const printerSampleData = JSON.parse(readFileSync(new URL('../../business/admin/config/components/printer-sample-data.json', import.meta.url), 'utf8'))

function mount(name, request, confirm = () => true, record = {}) {
  const source = readFileSync(new URL(`../../business/admin/config/components/${name}.js`, import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
  const context = {
    defineComponent: value => value, ref: value => ({ value }), resolveComponent: name => name,
    h: (type, props, children) => Array.isArray(props) || typeof props === 'string'
      ? ({ type, props: {}, children: props }) : ({ type, props: props || {}, children }),
    t: key => key, request, printerSampleData, PrinterPaper: 'PrinterPaper', window: { confirm }, globalThis: { crypto: { randomUUID: () => 'test-key' } }
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
  assert.deepEqual(JSON.parse(JSON.stringify(posts[0][1].data)), printerSampleData)
  assert.ok(ui.nodes().some(node => node.type === 'PrinterPaper' && node.props.document.content === 'Rendered receipt'))
  await ui.button('Submit print job').props.onClick()
  assert.equal(posts[1][1].templateUuid, 't')
  assert.ok(ui.nodes().some(node => node.type === 'ElAlert' && node.props.title === 'Job status: submitted'))
})

test('status refresh skips disabled demo devices and displays errors outside the dialog', async() => {
  const posts = []
  const ui = mount('PrinterDeviceStatusAction', {
    get: async() => ({ data: [{ uuid: 'demo', enabled: false }, { uuid: 'live', enabled: true }] }),
    post: async url => { posts.push(url); throw new Error('Status unavailable') }
  })
  await ui.button('Refresh device status').props.onClick()
  assert.deepEqual(posts, ['/api/v1/manage/printer/devices/live/status'])
  assert.ok(ui.nodes().some(node => node.type === 'ElAlert' && node.props.title === 'Status unavailable'))
})

test('printer device creation uses the standard CRUD form and server registration hook', () => {
  const source = readFileSync(new URL('../../business/admin/config/collections/printer/PrinterDevice.js', import.meta.url), 'utf8')
  const moduleSource = source.replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const context = { t: key => key, PrinterDeviceStatusAction: 'status', globalThis: {} }
  runInNewContext(moduleSource, context)
  const config = context.globalThis.config.PrinterDevice
  assert.ok(!config.list.disabled_actions.includes('new'))
  assert.ok(config.form.fields.some(field => field.property === 'serialNumber'))
  assert.ok(config.form.fields.some(field => field.property === 'account'))
  assert.ok(!source.includes('printer-device-register'))
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

test('reprint requires confirmation and posts the source job UUID as a new print request', async() => {
  const calls = []
  const ui = mount('PrinterJobReprintAction', {
    post: async(url, body) => { calls.push([url, body]); return { data: { uuid: 'new-job', status: 'submitted' } } }
  }, () => true, { uuid: 'original-job' })
  const button = ui.nodes().find(node => node.type === 'ElButton' && node.children() === 'Reprint')
  assert.equal(button.props.type, 'warning')
  await button.props.onClick()
  assert.deepEqual(JSON.parse(JSON.stringify(calls)), [['/api/v1/manage/printer/jobs/original-job/reprint', {}]])
})

test('cancelling reprint confirmation does not submit another live job', async() => {
  let submitted = false
  const ui = mount('PrinterJobReprintAction', { post: async() => { submitted = true } }, () => false, { uuid: 'original-job' })
  await ui.nodes().find(node => node.type === 'ElButton' && node.children() === 'Reprint').props.onClick()
  assert.equal(submitted, false)
})

test('printer job admin exposes detail and single delete but preserves immutable snapshots', () => {
  const source = readFileSync(new URL('../../business/admin/config/collections/printer/PrinterJob.js', import.meta.url), 'utf8')
  const moduleSource = source.replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const context = { t: key => key, PrinterJobAction: 'create', PrinterPaperAction: 'preview', PrinterJobReprintAction: 'reprint', globalThis: {} }
  runInNewContext(moduleSource, context)
  const config = context.globalThis.config.PrinterJob
  assert.deepEqual(JSON.parse(JSON.stringify(config.list.disabled_actions)), ['new', 'edit', 'batch_edit', 'batch_delete'])
  assert.ok(!config.list.disabled_actions.includes('detail'))
  assert.ok(!config.list.disabled_actions.includes('delete'))
  assert.deepEqual(JSON.parse(JSON.stringify(config.detail.disabled_actions)), ['edit'])
  assert.ok(config.detail.detail_display.some(field => typeof field === 'object' && field.property === 'dataSnapshot'))
  assert.ok(config.detail.detail_display.some(field => typeof field === 'object' && field.property === 'renderedContent'))
})

test('each template previews its definition with editable sample data', async() => {
  const posts = []
  const ui = mount('PrinterPaperAction', {
    post: async(url, data) => { posts.push([url, data]); return { data: { document: { kind: 'label', pages: ['Label'] } } } }
  }, () => true, { id: 3, definition: { kind: 'label', nodes: [] } })
  await ui.button('Paper preview').props.onClick()
  assert.equal(posts[0][0], '/api/v1/manage/printer/preview')
  assert.equal(posts[0][1].template.kind, 'label')
  assert.deepEqual(JSON.parse(JSON.stringify(posts[0][1].data)), printerSampleData)
  assert.equal(posts[0][1].data.store.name, 'Demo tea shop')
  assert.equal(posts[0][1].data.order.items[0].product, 'Jasmine tea')
  assert.equal(posts[0][1].data.order.amountDue, '\uffe543.00')
  assert.equal(new URL(posts[0][1].data.order.paymentUrl).hostname, 'example.invalid')
  assert.ok(ui.nodes().some(node => node.type === 'PrinterPaper'))
})

test('editing template demo data sends the edited values rather than replacing them with defaults', async() => {
  const posts = []
  const ui = mount('PrinterPaperAction', {
    post: async(url, body) => { posts.push(body); return { data: { document: { kind: 'receipt', pages: ['Receipt'] } } } }
  }, () => true, { definition: { kind: 'receipt', nodes: [] } })
  await ui.button('Paper preview').props.onClick()
  const input = ui.nodes().find(node => node.type === 'ElInput')
  const edited = JSON.parse(input.props.modelValue)
  edited.store.name = 'Edited store'
  input.props['onUpdate:modelValue'](JSON.stringify(edited))
  await ui.button('Update preview').props.onClick()
  assert.equal(posts[1].data.store.name, 'Edited store')
  assert.equal(printerSampleData.store.name, 'Demo tea shop')
})
