import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { uuidFilter } from '../../business/admin/config/uuid-filter.mjs'

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')

test('print jobs expose store, rule and device code filters', async () => {
  const source = read('business/admin/config/collections/printer/PrinterJob.js')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const context = {
    t: key => key,
    uuidFilter,
    loadRelationRecords: async relation => relation.name === 'Store'
      ? [{ uuid: 'store-a', name: 'Store A' }]
      : [{ uuid: 'rule-a', name: 'Kitchen rule' }],
    PrinterJobAction: {}, PrinterPaperAction: {}, PrinterJobReprintAction: {}
  }
  runInNewContext(source, context)
  const config = context.config.PrinterJob

  const stores = await config.list.list_filter.storeUuid()
  assert.equal(stores.expression, "entity.getStoreUuid() == ':value'")
  assert.deepEqual(stores.data, [{ value: 'store-a', label: 'Store A' }])

  const rules = await config.list.list_filter.ruleUuid()
  assert.equal(rules.expression, "entity.getRuleUuid() == ':value'")
  assert.deepEqual(rules.data, [{ value: 'rule-a', label: 'Kitchen rule' }])

  const serial = config.list.list_filter.serialNumber
  assert.equal(serial.type, 'input')
  assert.equal(serial.expression, "entity.getDevice().getSerialNumber() matches ':value'")

  const display = property => config.list.list_display.find(item => item.property === property)
  assert.equal(display('storeUuid').relation.prefix, '/api/v1/manage')
  assert.equal(display('ruleUuid').relation.prefix, '/api/v1/manage/printer')
})

test('print job filter translations cover every supported locale', () => {
  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const source = read(`business/admin/i18n/${locale}.js`)
    assert.match(source, /'Print rule':/)
  }
})
