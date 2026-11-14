import assert from 'node:assert/strict'
import test from 'node:test'
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { changeNode, createDesignerState, designerHistory, labelBox, labelEntries, moveNode, moveNodeInto, newTemplateNode, nodeAt, parseDefinition, sampleFields, scopeForNode, setDesignerDraft, starterTemplate, templateFormValue, transformLabel } from '../../business/admin/config/components/printer-template-designer.mjs'
import { designerMessages } from '../../business/admin/i18n/printer-designer.js'

const label = { kind: 'label', widthMm: 40, heightMm: 30, custom: { keep: true }, nodes: [{ type: 'labelText', value: 'Tea', x: 8, y: 16, font: 9, custom: 'preserve' }] }

test('raw drafts, formatting, invalid input and visual edits share lossless history', () => {
  const raw = JSON.stringify(label)
  let state = createDesignerState(raw)
  state = setDesignerDraft(state, raw + ' ')
  assert.equal(state.raw, raw + ' ')
  state = setDesignerDraft(state, '{')
  assert.ok(state.error)
  assert.deepEqual(state.definition, label)
  state = setDesignerDraft(state, JSON.stringify(changeNode(state.definition, [0], node => { node.x = 32 })))
  assert.equal(state.definition.nodes[0].x, 32)
  state = designerHistory(state, 'undo')
  assert.equal(state.raw, '{')
  assert.equal(state.definition.nodes[0].x, 8)
  state = designerHistory(state, 'undo')
  assert.equal(state.raw, raw + ' ')
  state = designerHistory(state, 'redo')
  assert.equal(state.raw, '{')
  state = setDesignerDraft(state, raw)
  assert.equal(state.future.length, 0)
  assert.deepEqual(state.definition.custom, { keep: true })
})

test('bounded structural parsing retains unsupported nodes and unknown properties', () => {
  assert.deepEqual(parseDefinition(JSON.stringify({ ...label, nodes: [{ type: 'future', settings: { a: 1 }, children: [] }] })).nodes[0].settings, { a: 1 })
  for (const value of [null, [], {}, { kind: 'html', nodes: [] }, { kind: 'label', nodes: [null] }, { kind: 'label', nodes: [{ type: 'if', children: {} }] }]) {
    assert.throws(() => parseDefinition(JSON.stringify(value)))
  }
  assert.throws(() => parseDefinition(' '.repeat(100001)))
  assert.equal(nodeAt(label, []), undefined)
  const original = JSON.stringify(label)
  const changed = changeNode(label, [0], node => { node.x = 64 })
  assert.equal(changed.nodes[0].custom, 'preserve')
  assert.equal(JSON.stringify(label), original)
})

test('nested reorder preserves subtrees, prevents cycles and supports keyboard-equivalent moves', () => {
  const document = { kind: 'receipt', nodes: [{ type: 'each', path: 'items', children: [{ type: 'text', value: 'a' }, { type: 'field', path: 'item.name' }] }, { type: 'line' }] }
  assert.equal(moveNode(document, [0], [0, 1]), document)
  const next = moveNode(document, [0, 1], [0, 0])
  assert.equal(next.nodes[0].children[0].type, 'field')
  const cross = moveNode(document, [0, 1], [1])
  assert.equal(cross.nodes[1].type, 'field')
  assert.equal(cross.nodes[0].children.length, 1)
  assert.equal(document.nodes[0].children.length, 2)
})

test('label geometry accounts for native pitch, dynamic centering, rotation and overflow', () => {
  const centered = { type: 'labelText', value: 'Tea', align: 'center', font: 9, x: 100, y: 8 }
  assert.equal(labelBox(centered, 'Tea', label).x, 142)
  assert.equal(labelBox({ ...centered, w: 2 }, 'Tea', label).x, 124)
  assert.equal(labelBox({ ...centered, x: 0, align: 'left', r: 90 }, 'Tea', label).overflow, true)
  assert.equal(labelBox({ ...centered, y: 235 }, 'Tea', label).overflow, true)
  assert.equal(labelBox({ ...centered, widthMode: 'legacy' }, 'é', label).x, 148)
})

test('label moves snap to dots and supported resize steps without losing custom properties', () => {
  const node = { ...label.nodes[0], align: 'center' }, box = labelBox(node, 'Tea', label)
  const moved = transformLabel(node, box, 10, -100, 'move')
  assert.equal(moved.x % 8, 0)
  assert.equal(moved.y, 0)
  assert.equal(moved.align, undefined)
  assert.equal(moved.custom, 'preserve')
  assert.deepEqual([transformLabel(node, box, box.width, box.height, 'resize').w, transformLabel(node, box, box.width, box.height, 'resize').h], [2, 2])
  assert.equal(transformLabel({ type: 'qr', size: 2 }, { width: 50 }, 10000, 10000, 'resize').size, 10)
  assert.equal(transformLabel({ type: 'barcode', height: 64 }, {}, 0, -1000, 'resize').height, 1)
})

test('representative loop entries retain editable paths and use first-item sample scope', () => {
  const document = { kind: 'label', nodes: [newTemplateNode('each', 'label')] }
  const entries = labelEntries(document, { order: { items: [{ name: 'Tea' }, { name: 'Coffee' }] } })
  assert.equal(entries.length, 1)
  assert.equal(entries[0].text, 'Tea')
  assert.deepEqual(entries[0].path, [0, 0])
  assert.equal(nodeAt(document, entries[0].path).type, 'labelText')
})

const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
const { JSDOM } = require('jsdom')
const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'http://localhost/' })
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) globalThis[key] = dom.window[key]
const Vue = require('vue')
const { parse, compileScript, compileTemplate } = require('@vue/compiler-sfc')
const helpers = await import('../../business/admin/config/components/printer-template-designer.mjs')
const paper = await import('../../business/admin/config/components/printer-paper.mjs')
const sampleData = JSON.parse(readFileSync(new URL('../../business/admin/config/components/printer-sample-data.json', import.meta.url)))

function compile(name, bindings, directory = '../../business/admin/config/components/') {
  const filename = `${name}.vue`
  const source = readFileSync(new URL(`${directory}${filename}`, import.meta.url), 'utf8')
  const { descriptor, errors } = parse(source, { filename })
  assert.deepEqual(errors, [])
  let code = compileScript(descriptor, { id: name, inlineTemplate: !!descriptor.scriptSetup }).content
  if (!descriptor.scriptSetup) {
    const template = compileTemplate({ source: descriptor.template.content, filename, id: name })
    assert.deepEqual(template.errors, [])
    code = code.replace('export default', 'const component =') + '\n' + template.code.replace('export function render', 'function render') + '\ncomponent.render = render; return component'
  } else code = code.replace('export default', 'return')
  code = code.replace(/import\s+\{([^}]+)\}\s+from\s+['"]vue['"];?/g, (_match, names) => `const {${names.replace(/\bas\b/g, ':')}} = Vue;`)
    .replace(/^import .*$/gm, '')
  let runtime = Vue
  if (name === 'PrinterTemplateDesigner') {
    const stub = bindings.PrinterTemplateJson
    runtime = { ...Vue, defineAsyncComponent: () => stub }
    bindings = { ...bindings }; delete bindings.PrinterTemplateJson
  }
  return new Function('Vue', ...Object.keys(bindings), code)(runtime, ...Object.values(bindings))
}

const PrinterTemplateNode = compile('PrinterTemplateNode', { knownTypes: helpers.knownTypes })
function mount(initial = label, post = async() => ({ data: { document: { kind: 'label', pages: ['preview'] } } })) {
  const requests = []
  const component = compile('PrinterTemplateDesigner', {
    ...helpers, ...paper, PrinterTemplateNode, sampleData,
    PrinterTemplateJson: { props: ['modelValue'], emits: ['update:modelValue'], render() { return Vue.h('textarea', { class: 'definition-json', value: this.modelValue, onInput: event => this.$emit('update:modelValue', event.target.value) }) } },
    t: key => key,
    request: { post: (url, body) => { requests.push({ url, body }); return post(url, body) } },
    PrinterPaper: { props: ['document'], render() { return Vue.h('output', { class: 'rendered-preview' }, this.document.pages?.join('')) } }
  })
  const form = Vue.reactive({ kind: initial.kind, definition: structuredClone(initial) })
  const validators = [], released = []
  const root = document.createElement('div'); document.body.append(root)
  const app = Vue.createApp({ render: () => Vue.h(component, { form, field: { property: 'definition' } }) })
  app.provide('registerFieldValidator', (property, validator) => { validators.push(validator); return () => released.push(property) })
  app.mount(root)
  const button = text => [...root.querySelectorAll('button')].find(element => element.textContent.trim() === text)
  const field = text => [...root.querySelectorAll('label')].find(element => element.firstChild.textContent.trim() === text)?.querySelector('input, textarea, select')
  const input = async(element, value, event = 'input') => { assert.ok(element); element.value = value; element.dispatchEvent(new dom.window.Event(event, { bubbles: true })); await Vue.nextTick() }
  const click = async element => { assert.ok(element); element.click(); await Vue.nextTick() }
  const pointer = async(element, type, x, y) => {
    element.setPointerCapture = () => {}; element.hasPointerCapture = () => false
    const event = new dom.window.Event(type, { bubbles: true })
    Object.assign(event, { clientX: x, clientY: y, pointerId: 1, button: 0 })
    element.dispatchEvent(event); await Vue.nextTick()
  }
  return { root, form, button, field, input, click, pointer, requests, validators, released, unmount() { app.unmount(); root.remove() } }
}

test('mounted designer synchronizes JSON, keeps invalid drafts and blocks saving until repaired', async() => {
  const ui = mount()
  try {
    const textarea = ui.root.querySelector('.definition-json')
    const changed = { ...label, nodes: [{ ...label.nodes[0], value: 'Coffee', x: 32 }] }
    const raw = JSON.stringify(changed) + '  '
    await ui.input(textarea, raw)
    assert.equal(ui.form.definition.nodes[0].x, 32)
    assert.equal(textarea.value, raw)
    assert.equal(ui.root.querySelector('.label-glyphs').textContent, 'Coffee')
    await ui.input(textarea, '{')
    assert.equal(ui.form.definition.nodes[0].value, 'Coffee')
    assert.equal(ui.root.querySelector('.label-glyphs').textContent, 'Coffee')
    assert.equal(textarea.value, '{')
    let error
    ui.validators[0](null, null, value => { error = value })
    assert.ok(error)
    await ui.click(ui.button('Undo'))
    assert.equal(textarea.value, raw)
    ui.validators[0](null, null, value => { error = value })
    assert.equal(error, undefined)
    await ui.click(ui.button('Undo'))
    assert.deepEqual(JSON.parse(JSON.stringify(ui.form.definition)), label)
    await ui.click(ui.button('Redo'))
    assert.equal(ui.form.definition.nodes[0].value, 'Coffee')
  } finally { ui.unmount() }
  assert.deepEqual(ui.released, ['definition'])
})

test('mounted visual properties preserve JSON extensions, support centering and unified undo', async() => {
  const ui = mount()
  try {
    await ui.click(ui.root.querySelector('.template-node-row button'))
    await ui.input(ui.field('X (mm)'), '5', 'change')
    assert.equal(ui.form.definition.nodes[0].x, 40)
    assert.equal(JSON.parse(ui.root.querySelector('.definition-json').value).nodes[0].x, 40)
    assert.equal(ui.form.definition.nodes[0].custom, 'preserve')
    assert.deepEqual(JSON.parse(JSON.stringify(ui.form.definition.custom)), { keep: true })
    await ui.click(ui.button('Center horizontally'))
    assert.equal(ui.form.definition.nodes[0].align, 'center')
    await ui.click(ui.button('Center vertically'))
    assert.equal(ui.form.definition.nodes[0].y, 108)
    await ui.click(ui.button('Duplicate'))
    assert.equal(ui.form.definition.nodes.length, 2)
    await ui.click(ui.button('Undo'))
    assert.equal(ui.form.definition.nodes.length, 1)
  } finally { ui.unmount() }
})

test('pointer dragging and resizing commit one undo step; clicking and cancelling do not rewrite alignment', async() => {
  const ui = mount({ ...label, nodes: [{ ...label.nodes[0], align: 'center' }] })
  try {
    const element = ui.root.querySelector('.label-element')
    await ui.pointer(element, 'pointerdown', 0, 0)
    await ui.pointer(element, 'pointerup', 0, 0)
    assert.equal(ui.form.definition.nodes[0].align, 'center')
    const scale = 96 / 25.4 / 8 * 1.5
    await ui.pointer(element, 'pointerdown', 0, 0)
    await ui.pointer(element, 'pointermove', 16 * scale, 8 * scale)
    await ui.pointer(element, 'pointerup', 16 * scale, 8 * scale)
    assert.equal(ui.form.definition.nodes[0].align, undefined)
    assert.equal(ui.form.definition.nodes[0].y, 24)
    await ui.click(ui.button('Undo'))
    assert.equal(ui.form.definition.nodes[0].align, 'center')
    assert.equal(ui.form.definition.nodes[0].y, 16)
    await ui.pointer(element, 'pointerdown', 0, 0)
    await ui.pointer(element, 'pointermove', 16 * scale, 8 * scale)
    await ui.pointer(element, 'pointercancel', 16 * scale, 8 * scale)
    assert.equal(ui.form.definition.nodes[0].align, 'center')
    await ui.click(ui.root.querySelector('.template-node-row button'))
    const handle = ui.root.querySelector('.resize-handle')
    await ui.pointer(handle, 'pointerdown', 0, 0)
    await ui.pointer(handle, 'pointerup', 36 * scale, 24 * scale)
    assert.equal(ui.form.definition.nodes[0].w, 2)
    assert.equal(ui.form.definition.nodes[0].h, 2)
  } finally { ui.unmount() }
})

test('receipt blocks support reorder, nested additions, emphasis and columns without normalizing legacy commands', async() => {
  const initial = { kind: 'receipt', nodes: [{ type: 'receiptCommand', value: 'B' }, { type: 'style', children: [{ type: 'text', value: 'Title' }] }, newTemplateNode('columns', 'receipt')] }
  const ui = mount(initial)
  try {
    await ui.click(ui.root.querySelectorAll('.template-node-row button')[1])
    await ui.click(ui.button('Move down'))
    assert.equal(ui.form.definition.nodes[2].type, 'style')
    await ui.input(ui.field('Width multiplier'), '2', 'change')
    assert.equal(ui.form.definition.nodes[2].width, 2)
    const inside = ui.root.querySelector('.designer-library input')
    inside.checked = true; inside.dispatchEvent(new dom.window.Event('change', { bubbles: true })); await Vue.nextTick()
    await ui.click(ui.button('Data field'))
    assert.equal(ui.form.definition.nodes[2].children.at(-1).type, 'field')
    assert.deepEqual(JSON.parse(JSON.stringify(ui.form.definition.nodes[0])), initial.nodes[0])
    await ui.click(ui.root.querySelectorAll('.template-node-row button')[1])
    await ui.input(ui.field('Column width cells'), '18', 'change')
    assert.equal(ui.form.definition.nodes[1].columns[0].width, 18)
  } finally { ui.unmount() }
})

test('preview uses unsaved JSON, rejects stale responses and never sends a live job', async() => {
  const pending = []
  const ui = mount(label, () => new Promise(resolve => pending.push(resolve)))
  try {
    await ui.click(ui.button('Update preview'))
    await ui.input(ui.root.querySelector('.definition-json'), JSON.stringify({ ...label, widthMm: 50 }))
    await ui.click(ui.button('Update preview'))
    assert.equal(ui.requests[1].body.template.widthMm, 50)
    pending[1]({ data: { document: { pages: ['latest'] } } }); await Promise.resolve(); await Vue.nextTick()
    pending[0]({ data: { document: { pages: ['stale'] } } }); await Promise.resolve(); await Vue.nextTick()
    assert.equal(ui.root.querySelector('.rendered-preview').textContent, 'latest')
    assert.ok(ui.requests.every(call => call.url === '/api/v1/manage/printer/preview'))
    await ui.input(ui.root.querySelector('.definition-json'), '{')
    assert.equal(ui.root.querySelector('.rendered-preview'), null)
  } finally { ui.unmount() }
})

test('explicit position, font and rotation edits replace incompatible automatic alignment', async() => {
  const ui = mount({ ...label, nodes: [{ ...label.nodes[0], align: 'center' }] })
  try {
    await ui.click(ui.root.querySelector('.template-node-row button'))
    await ui.input(ui.field('Printer font'), '1', 'change')
    assert.equal(ui.form.definition.nodes[0].align, undefined)
    assert.equal(ui.form.definition.nodes[0].x, 142)
    await ui.click(ui.button('Undo'))
    await ui.click(ui.root.querySelector('.template-node-row button'))
    await ui.input(ui.field('Rotation'), '90', 'change')
    assert.equal(ui.form.definition.nodes[0].align, undefined)
    assert.equal(ui.form.definition.nodes[0].r, 90)
    await ui.click(ui.button('Undo'))
    await ui.click(ui.root.querySelector('.template-node-row button'))
    await ui.input(ui.field('X (mm)'), '2', 'change')
    assert.equal(ui.form.definition.nodes[0].align, undefined)
    assert.equal(ui.form.definition.nodes[0].x, 16)
  } finally { ui.unmount() }
})

test('unsupported definitions survive neighboring edits and content source changes remove only competing keys', async() => {
  const unknown = { type: 'futureNode', config: { nested: [1, null, false] } }
  const ui = mount({ ...label, labelEnvelope: 'legacy', nodes: [label.nodes[0], unknown] })
  try {
    await ui.click(ui.root.querySelector('.template-node-row button'))
    await ui.input(ui.field('Content source'), 'template', 'change')
    await ui.input(ui.field('Content'), '{order.number}')
    const stored = typeof ui.form.definition === 'string' ? JSON.parse(ui.form.definition) : ui.form.definition
    assert.equal(stored.nodes[0].value, undefined)
    assert.equal(stored.nodes[0].template, '{order.number}')
    assert.equal(stored.nodes[0].custom, 'preserve')
    assert.deepEqual(JSON.parse(JSON.stringify(stored.nodes[1])), unknown)
    assert.equal(stored.labelEnvelope, 'legacy')
    await ui.click(ui.root.querySelectorAll('.template-node-row button')[1])
    assert.ok(ui.root.textContent.includes('Unsupported node preserved'))
  } finally { ui.unmount() }
})

test('invalid sample prevents preview and stale replies after unmount do not republish', async() => {
  let resolve
  const ui = mount(label, () => new Promise(done => { resolve = done }))
  await ui.click(ui.button('Update preview'))
  await ui.input(ui.root.querySelector('.designer-sample textarea'), '[]')
  assert.equal(ui.button('Update preview').disabled, true)
  assert.deepEqual(JSON.parse(JSON.stringify(ui.form.definition)), label)
  ui.unmount()
  resolve({ data: { document: { pages: ['late'] } } })
  await Promise.resolve(); await Vue.nextTick()
  assert.equal(ui.root.innerHTML, '')
  assert.deepEqual(ui.released, ['definition'])
})

test('external form replacement resets history and kind mismatches block save without conversion', async() => {
  const ui = mount()
  try {
    await ui.input(ui.root.querySelector('.definition-json'), JSON.stringify({ ...label, widthMm: 50 }))
    ui.form.definition = { kind: 'receipt', nodes: [{ type: 'text', value: 'External' }] }
    await Vue.nextTick()
    assert.equal(ui.button('Undo').disabled, true)
    assert.equal(ui.root.querySelector('.label-sheet'), null)
    assert.equal(JSON.parse(ui.root.querySelector('.definition-json').value).kind, 'receipt')
    let error
    ui.validators[0](null, null, value => { error = value })
    assert.ok(error)
    ui.form.kind = 'receipt'; await Vue.nextTick()
    ui.validators[0](null, null, value => { error = value })
    assert.equal(error, undefined)
  } finally { ui.unmount() }
})

test('all designer controls compile and business locales include every designer message', () => {
  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const messages = designerMessages(locale)
    for (const [key, value] of Object.entries(messages)) assert.ok(typeof value === 'string' && value, `${locale}: ${key}`)
  }
})

test('original JSON component adapter keeps raw drafts, tree updates and teardown', async() => {
  const instances = []
  class FakeJSONEditor {
    constructor(container, options) { this.options = options; this.mode = options.mode; this.text = '{}'; this.destroyed = false; instances.push(this) }
    getText() { return this.text }
    updateText(text) { this.text = text }
    getMode() { return this.mode }
    setMode(mode) { this.mode = mode }
    destroy() { this.destroyed = true }
  }
  const JsonField = compile('json', { JSONEditor: FakeJSONEditor }, '../../core/crud-admin/src/easyadmin/ui/vue/plugins/form/')
  const source = readFileSync(new URL('../../business/admin/config/components/PrinterTemplateJson.js', import.meta.url), 'utf8').replace(/^import .*$/gm, '').replace('export default', 'return')
  const Adapter = new Function('JsonField', 'JSONEditor', source)(JsonField, FakeJSONEditor)
  const raw = Vue.ref(JSON.stringify(label)), events = []
  const host = document.createElement('div'); document.body.append(host)
  const app = Vue.createApp({ render: () => Vue.h(Adapter, { modelValue: raw.value, 'onUpdate:modelValue': text => { raw.value = text; events.push(text) } }) })
  app.mount(host)
  await Vue.nextTick()
  try {
    const editor = instances[0]
    assert.ok(host.querySelector('.jsoneditor-container'))
    assert.deepEqual(editor.options.modes, ['tree', 'code', 'form', 'text', 'view'])
    assert.equal(editor.text, raw.value)
    editor.text = '{'; editor.options.onChangeText('{'); await Vue.nextTick()
    assert.equal(raw.value, '{')
    assert.equal(editor.text, '{')
    editor.text = JSON.stringify(label); editor.mode = 'tree'
    editor.options.onChangeText(editor.text); await Vue.nextTick()
    raw.value = JSON.stringify({ ...label, widthMm: 50 }); await Vue.nextTick()
    assert.equal(editor.mode, 'tree')
    assert.equal(JSON.parse(editor.text).widthMm, 50)
    const count = events.length
    raw.value = '{'; await Vue.nextTick()
    assert.equal(editor.mode, 'code')
    assert.equal(editor.text, '{')
    assert.equal(events.length, count)
  } finally { app.unmount(); host.remove() }
  assert.equal(instances[0].destroyed, true)
})

test('designer theme bridge and JSON palette stay scoped and keep printed paper light', () => {
  const css = readFileSync(new URL('../../business/admin/config/components/printer-template-designer.css', import.meta.url), 'utf8')
  assert.match(css, /--el-bg-color: var\(--surface/)
  assert.match(css, /html\[data-theme='dark'\] \.printer-designer\s*\{\s*color-scheme: dark/)
  assert.match(css, /\.printer-designer \.label-sheet \{ color-scheme: light/)
  for (const selector of ['ace_string', 'ace_cursor', 'ace_selection', 'jsoneditor-contextmenu', 'jsoneditor-string']) assert.ok(css.includes(selector))
  const config = readFileSync(new URL('../../integration/admin/vite.config.ts', import.meta.url), 'utf8')
  assert.ok(config.includes("{ find: /^jsoneditor$/, replacement: coreRequire.resolve('jsoneditor') }"))
})

test('field choices use the selected loop scope and cannot expose inherited properties', () => {
  const definition = { kind: 'label', nodes: [newTemplateNode('each', 'label')] }
  const data = { order: { number: '75', items: [{ name: 'Tea', amount: 1200 }] }, store: { name: 'Store' } }
  assert.ok(!sampleFields(scopeForNode(definition, data, [0])).some(item => item.path === 'item.name'))
  assert.ok(sampleFields(scopeForNode(definition, data, [0, 0])).some(item => item.path === 'item.name'))
  assert.deepEqual(sampleFields(data, true).map(item => item.path), ['order.items'])
  assert.ok(!sampleFields(JSON.parse('{"__proto__":{"secret":1},"ok":2}')).some(item => item.path.includes('__proto__')))
})

test('move-into supports empty blocks and root append without introducing cycles', () => {
  const definition = { kind: 'receipt', nodes: [{ type: 'text', value: 'Keep', extra: { a: 1 } }, { type: 'if', path: 'order.number' }] }
  const nested = moveNodeInto(definition, [0], [1])
  assert.equal(nested.nodes.length, 1)
  assert.equal(nested.nodes[0].children[0].value, 'Keep')
  assert.equal(moveNodeInto(nested, [0], [0, 0]), nested)
  const unwrapped = moveNodeInto(nested, [0, 0], [])
  assert.equal(unwrapped.nodes[1].extra.a, 1)
  assert.equal(definition.nodes.length, 2)
})

test('field picker inserts formatted placeholders and reparent controls preserve loop children', async() => {
  const ui = mount({ kind: 'receipt', nodes: [newTemplateNode('each', 'receipt'), { type: 'style', children: [] }] })
  try {
    await ui.click(ui.root.querySelectorAll('.template-node-row button')[2])
    await ui.input(ui.field('Field format'), 'money', 'change')
    await ui.input(ui.field('Insert data field'), 'item.amount', 'change')
    assert.ok(ui.form.definition.nodes[0].children[0].children[0].value.endsWith('{item.amount|money}'))
    await ui.input(ui.field('Move into block'), '1', 'change')
    await ui.click(ui.button('Move into block'))
    assert.equal(ui.form.definition.nodes[1].children[0].type, 'string')
    await ui.click(ui.button('Undo'))
    assert.equal(ui.form.definition.nodes[0].children[0].children[0].type, 'string')
  } finally { ui.unmount() }
})

test('null comparison edits use lossless form text and initial nullable definitions are protected', async() => {
  const definition = { kind: 'receipt', custom: { keep: null }, nodes: [{ type: 'if', path: 'order.paidAt', children: [{ type: 'text', value: 'Unpaid' }] }] }
  const ui = mount(definition)
  try {
    assert.equal(typeof ui.form.definition, 'string')
    assert.equal(JSON.parse(ui.form.definition).custom.keep, null)
    await ui.click(ui.root.querySelector('.template-node-row button'))
    await ui.input(ui.field('Condition comparison'), 'null', 'change')
    assert.equal(JSON.parse(ui.form.definition).nodes[0].equals, null)
    await ui.input(ui.field('Condition comparison'), 'boolean', 'change')
    assert.equal(JSON.parse(ui.form.definition).nodes[0].equals, false)
  } finally { ui.unmount() }
})

test('new-template setup supplies renderable starter blocks without rewriting existing definitions', async() => {
  const ui = mount({ kind: 'receipt' })
  try {
    await ui.click(ui.button('Create template'))
    assert.deepEqual(JSON.parse(JSON.stringify(ui.form.definition)), starterTemplate('receipt'))
  } finally { ui.unmount() }
  assert.equal(typeof templateFormValue({ ...label, extra: null }), 'string')
  assert.equal(typeof templateFormValue(label), 'object')
})

test('per-unit preview supplies a representative item but never adds it to the saved template', async() => {
  const ui = mount(label)
  try {
    ui.form.ticketMode = 'per_unit'; await Vue.nextTick()
    await ui.click(ui.button('Update preview'))
    assert.deepEqual(JSON.parse(JSON.stringify(ui.requests[0].body.data.item)), { index: 1, total: sampleData.order.items[0].quantity, ...sampleData.order.items[0] })
    assert.equal(ui.requests[0].body.data.order.items.length, 1)
    assert.equal(ui.form.definition.item, undefined)
    assert.equal(ui.form.sampleData, undefined)
  } finally { ui.unmount() }
})

test('column field selection preserves layout and extension properties', async() => {
  const ui = mount({ kind: 'receipt', nodes: [{ type: 'each', path: 'order.items', children: [{ type: 'columns', columns: [{ value: 'Amount', width: 16, align: 'right', extra: true }] }] }] })
  try {
    await ui.click(ui.root.querySelectorAll('.template-node-row button')[1])
    await ui.input(ui.field('Field format'), 'money', 'change')
    await ui.input(ui.field('Insert data field'), 'item.amount', 'change')
    const column = ui.form.definition.nodes[0].children[0].columns[0]
    assert.equal(column.template, '{item.amount|money}')
    assert.equal(column.value, undefined)
    assert.equal(column.width, 16)
    assert.equal(column.align, 'right')
    assert.equal(column.extra, true)
  } finally { ui.unmount() }
})

test('root drops append only nodes dragged from the same designer instance', async() => {
  const definition = { kind: 'receipt', nodes: [{ type: 'text', value: 'First' }, { type: 'text', value: 'Last' }] }
  const first = mount(definition), other = mount(definition)
  try {
    let payload
    const start = new dom.window.Event('dragstart', { bubbles: true })
    Object.assign(start, { dataTransfer: { setData: (_type, data) => { payload = data } } })
    first.root.querySelector('.template-node-row').dispatchEvent(start)
    const drop = target => {
      const event = new dom.window.Event('drop', { bubbles: true, cancelable: true })
      Object.assign(event, { dataTransfer: { getData: () => payload } })
      target.dispatchEvent(event)
    }
    drop(other.root.querySelector('.root-drop')); await Vue.nextTick()
    assert.equal(other.form.definition.nodes[0].value, 'First')
    drop(first.root.querySelector('.root-drop')); await Vue.nextTick()
    assert.equal(first.form.definition.nodes.at(-1).value, 'First')
  } finally { first.unmount(); other.unmount() }
})

test('JSON editor keyboard undo is not handled again by the designer', async() => {
  const ui = mount()
  try {
    await ui.input(ui.root.querySelector('.definition-json'), JSON.stringify({ ...label, widthMm: 50 }))
    const tree = document.createElement('div'); tree.className = 'printer-template-json'
    const editable = document.createElement('div'); tree.append(editable); ui.root.querySelector('.printer-designer').append(tree)
    editable.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true }))
    await Vue.nextTick()
    assert.equal(ui.form.definition.widthMm, 50)
    await ui.click(ui.button('Undo'))
    assert.equal(ui.form.definition.widthMm, 40)
  } finally { ui.unmount() }
})
