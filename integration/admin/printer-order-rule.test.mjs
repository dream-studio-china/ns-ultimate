import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { uuidFilter } from '../../business/admin/config/uuid-filter.mjs'
import { eligiblePrintOutputs, loadAllPrintOutputs, PRINT_OUTPUT_MAX_PAGES, PRINT_OUTPUT_PAGE_LIMIT } from '../../business/admin/config/components/order-print-output.mjs'

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')

test('print rule configuration exposes store filtering and safe defaults', async () => {
  const source = read('business/admin/config/collections/printer/PrinterOrderRule.js')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const context = {
    t: key => key, uuidFilter, OrderPrintConditions: {}, OrderPrintEvents: {}, OrderPrintOutputSelect: {},
    loadRelationRecords: async () => [{ id: 4, uuid: 'store-uuid', name: 'Store A' }]
  }
  runInNewContext(source, context)
  const config = context.config.PrinterOrderRule
  const field = name => config.form.fields.find(item => item.property === name)
  assert.equal(field('storeUuid').hidden({}, 42), true)
  assert.equal(field('storeUuid').hidden({}, null), false)
  assert.equal(field('deviceUuid').component, context.OrderPrintOutputSelect)
  assert.equal(field('templateUuid').component, context.OrderPrintOutputSelect)
  assert.equal(field('storeUuid').relation.valueKey, 'uuid')
  assert.equal(field('enabled').default_value, false)
  assert.equal(field('copies').type_options.min, 1)
  assert.equal(field('copies').type_options.max, 100)
  const filter = await config.list.list_filter.storeUuid()
  assert.equal(filter.expression, "entity.getStoreUuid() == ':value'")
  assert.equal(filter.data[0].value, 'store-uuid')
  assert.match(read('business/admin/router.js'), /r\('PrinterOrderRule', t\('Order print rules'\)\)/)
})

test('print output choices follow the store and selected device kind without mutating values', () => {
  const devices = [
    { uuid: 'd1', storeUuid: 's1', kind: 'receipt' },
    { uuid: 'd2', storeUuid: 's1', kind: 'label' },
    { uuid: 'd3', storeUuid: 's2', kind: 'receipt' },
    { uuid: 'd4', storeUuid: null, kind: 'receipt' }
  ]
  const templates = [{ uuid: 't1', kind: 'receipt' }, { uuid: 't2', kind: 'label' }]
  const form = { storeUuid: 's1', deviceUuid: 'd1', templateUuid: 't2' }
  assert.deepEqual(eligiblePrintOutputs('deviceUuid', form, devices, templates).map(item => item.uuid), ['d1', 'd2'])
  assert.deepEqual(eligiblePrintOutputs('templateUuid', form, devices, templates).map(item => item.uuid), ['t1'])
  form.deviceUuid = 'd2'
  assert.deepEqual(eligiblePrintOutputs('templateUuid', form, devices, templates).map(item => item.uuid), ['t2'])
  form.storeUuid = 's2'
  assert.deepEqual(eligiblePrintOutputs('templateUuid', form, devices, templates), [])
  assert.equal(form.deviceUuid, 'd2')
  assert.equal(form.templateUuid, 't2')
  assert.deepEqual(eligiblePrintOutputs('deviceUuid', {}, devices, templates), [])
})

test('printer choices load with bounded paging and fail closed past the bound', async () => {
  const calls = []
  const pages = [
    { data: [{ uuid: 'a' }], paginator: { has_next: true } },
    { data: [{ uuid: 'b' }], paginator: { has_next: false } }
  ]
  const records = await loadAllPrintOutputs(params => {
    calls.push(params)
    return pages[params.page - 1]
  })
  assert.deepEqual(records.map(item => item.uuid), ['a', 'b'])
  assert.deepEqual(calls, [{ page: 1, limit: PRINT_OUTPUT_PAGE_LIMIT }, { page: 2, limit: PRINT_OUTPUT_PAGE_LIMIT }])
  let page = 0
  await assert.rejects(
    loadAllPrintOutputs(() => {
      page += 1
      return { data: [], paginator: { has_next: true } }
    }),
    /Too many printer records/
  )
  assert.equal(page, PRINT_OUTPUT_MAX_PAGES)
  await assert.rejects(loadAllPrintOutputs(() => ({ data: null })), /Unexpected printer list response/)
})

test('output selector retains unavailable values and exposes load failures', () => {
  const source = read('business/admin/config/components/OrderPrintOutputSelect.vue')
  assert.match(source, /v-if="unavailable"[^\n]*disabled/)
  assert.match(source, /Print rule output unavailable help/)
  assert.match(source, /Print rule output outputs|Print rule outputs failed to load/)
  assert.match(source, /@click="load"/)
  assert.doesNotMatch(source, /form\[this\.property\]\s*=/)
  assert.doesNotMatch(source, /1e10/)
})

test('output selector loads paged records, preserves hydrated values and can retry failures', async () => {
  const source = read('business/admin/config/components/OrderPrintOutputSelect.vue')
    .split('<script>')[1].split('</script>')[0]
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
  let fail = true
  const calls = []
  const records = {
    devices: [{ uuid: 'device', storeUuid: 'store', kind: 'receipt', name: 'Device' }],
    templates: [{ uuid: 'template', kind: 'receipt', name: 'Template' }]
  }
  const context = {
    eligiblePrintOutputs, loadAllPrintOutputs,
    CrudSkeletonAdapter: class {
      constructor(conf) { this.plural = conf.plural }
      async list(params) {
        calls.push({ plural: this.plural, params })
        if (fail) throw new Error('network')
        return { data: records[this.plural], paginator: { has_next: false } }
      }
    }
  }
  runInNewContext(source, context)
  const component = context.component
  const form = { storeUuid: 'store', deviceUuid: 'device', templateUuid: 'template' }
  const instance = { ...component.data(), form, property: 'templateUuid', $t: value => value }
  instance.load = component.methods.load.bind(instance)
  await instance.load()
  assert.equal(instance.failed, true)
  assert.equal(form.templateUuid, 'template')
  fail = false
  await instance.load()
  assert.equal(instance.failed, false)
  instance.options = component.computed.options.call(instance)
  assert.equal(instance.options[0].uuid, 'template')
  assert.equal(component.computed.unavailable.call(instance), false)
  form.deviceUuid = 'missing'
  instance.options = component.computed.options.call(instance)
  assert.equal(component.computed.unavailable.call(instance), true)
  assert.equal(form.templateUuid, 'template')
  assert.ok(calls.every(call => call.params.limit === PRINT_OUTPUT_PAGE_LIMIT && !('@display' in call.params)))
})

test('print rule translations cover every supported locale', () => {
  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const source = read(`business/admin/i18n/${locale}.js`)
    for (const key of [
      'Print rule output store help', 'Print rule outputs failed to load',
      'Print rule output unavailable help', 'Order print rules',
      'Ticket mode', 'One ticket per order', 'One ticket per item',
      'Item filter', 'All items', 'Kitchen items only'
    ]) {
      assert.ok(source.includes(`'${key}'`), `${locale} is missing ${key}`)
    }
  }
})

test('rules no longer carry a print scene; templates own ticket granularity and item scope', () => {
  const rule = read('business/admin/config/collections/printer/PrinterOrderRule.js')
  assert.doesNotMatch(rule, /property: 'scene'/)
  const template = read('business/admin/config/collections/printer/PrinterTemplate.js')
  const field = name => new RegExp(`property: '${name}'`)
  assert.match(template, field('ticketMode'))
  assert.match(template, field('itemFilter'))
  assert.match(template, /value: 'per_unit'/)
  assert.match(template, /value: 'kitchen'/)
})

test('condition editor warns about empty match modes and bounds field length', () => {
  const source = read('business/admin/config/components/OrderPrintConditions.vue')
  assert.match(source, /form\.match === 'any' \? 'Print rule any conditions help' : 'Print rule all conditions help'/)
  assert.match(source, /maxlength="128"/)
  assert.match(source, /conditions\.length >= 20/)
})
