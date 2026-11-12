import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'

const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
const { createRenderer, h, nextTick, reactive } = require('vue')
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
const sample = JSON.parse(read('business/admin/config/components/printer-sample-data.json'))
const source = read('business/admin/config/components/PrinterSampleDataField.vue')
  .match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/^import .*$/gm, '')
  .replace('export default', 'globalThis.component =')
const context = { JsonField: {}, printerSampleData: sample }
runInNewContext(source, context)
const component = { ...context.component, render: () => null }
const renderer = createRenderer({
  createComment: () => ({}), insert() {}, remove() {}, parentNode() {}, nextSibling() {}
})
const plain = value => JSON.parse(JSON.stringify(value))

test('sample editor initializes create and retrieved edit forms without sharing sample objects', async () => {
  const state = reactive({ form: { sampleData: null } })
  const app = renderer.createApp({
    render: () => h(component, { form: state.form, field: { property: 'sampleData' } })
  })
  app.mount({})
  try {
    assert.deepEqual(plain(state.form.sampleData), sample)
    state.form.sampleData.order.number = 'CUSTOM'
    assert.notEqual(sample.order.number, 'CUSTOM')

    // Detail responses omit sampleData and replace the entire form model.
    state.form = { name: 'Existing template' }
    await nextTick()
    assert.deepEqual(plain(state.form.sampleData), sample)

    state.form.sampleData = { custom: 'User input' }
    await nextTick()
    assert.deepEqual(plain(state.form.sampleData), { custom: 'User input' })
    state.form.sampleData = {}
    await nextTick()
    assert.deepEqual(plain(state.form.sampleData), {})
  } finally {
    app.unmount()
  }
})

test('sample editor preserves explicitly supplied data on mount', () => {
  const form = reactive({ sampleData: { custom: 'Existing input' } })
  const app = renderer.createApp({ render: () => h(component, { form, field: { property: 'sampleData' } }) })
  app.mount({})
  try {
    assert.deepEqual(plain(form.sampleData), { custom: 'Existing input' })
  } finally {
    app.unmount()
  }
})
