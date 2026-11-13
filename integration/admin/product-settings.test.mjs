import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

const schema = JSON.parse(readFileSync(new URL(
  '../../business/admin/config/overrides/product/ProductSettings.json', import.meta.url
), 'utf8'))

function loadJsonSchemaHelpers() {
  const source = readFileSync(new URL(
    '../../core/crud-admin/src/utils/json-schema-form.js', import.meta.url
  ), 'utf8')
    .replace("import { t } from '@/i18n'", 'const t = value => value')
    .replace(/^export /gm, '')
  const context = { structuredClone }
  runInNewContext(source + '\nglobalThis.helpers = { applySchemaDefaults, createSchemaForm }', context)
  return context.helpers
}

const jsonValue = value => JSON.parse(JSON.stringify(value))

test('product ticket settings render both switches when settings are empty', () => {
  const helpers = loadJsonSchemaHelpers()
  const definition = helpers.createSchemaForm(schema)
  const fields = new Map(definition.fields.map(field => [field.property, field]))

  assert.equal(fields.get('kitchenPrinterEnable').type, 'boolean')
  assert.equal(fields.get('kitchenPrinterEnable').field_options.label, 'Print ticket')
  assert.equal(fields.get('kitchenTicketDisabled').type, 'boolean')
  assert.equal(fields.get('kitchenTicketDisabled').field_options.label, 'Kitchen ticket disabled')
  assert.deepEqual(jsonValue(helpers.applySchemaDefaults(schema, null)), {
    kitchenPrinterEnable: false,
    kitchenTicketDisabled: false
  })
})

test('kitchen ticket exclusion preserves an explicit true and defaults missing values to false', () => {
  const { applySchemaDefaults } = loadJsonSchemaHelpers()

  assert.deepEqual(jsonValue(applySchemaDefaults(schema, { kitchenTicketDisabled: true })), {
    kitchenPrinterEnable: false,
    kitchenTicketDisabled: true
  })
  assert.deepEqual(jsonValue(applySchemaDefaults(schema, {})), {
    kitchenPrinterEnable: false,
    kitchenTicketDisabled: false
  })
})
