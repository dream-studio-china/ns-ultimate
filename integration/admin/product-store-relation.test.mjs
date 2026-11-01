import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

function loadProductConfig() {
  const source = readFileSync(new URL(
    '../../business/admin/config/overrides/product/product.jsx', import.meta.url
  ), 'utf8')
  // Evaluate the actual field configuration without mounting its JSX components.
  const context = {
    t: key => key,
    orderByIdDesc: {},
    statusFilterLabel: () => ({}),
    ProductMetadataSchema: {},
    ProductSettingsSchema: {},
    ProductNameWithCover: {},
    SpecificationManager: {}
  }
  runInNewContext(source.slice(source.indexOf('export default')).replace('export default', 'globalThis.config ='), context)
  return context.config
}

function loadRelationHelpers() {
  const source = readFileSync(new URL(
    '../../core/crud-admin/src/utils/relation-descriptor.js', import.meta.url
  ), 'utf8').replace(/^export /gm, '')
  const context = {}
  runInNewContext(source + '\nglobalThis.helpers = { resolveRelation, relationValue }', context)
  return context.helpers
}

test('Product store options and edit values use UUIDs even with numeric association IDs', () => {
  const field = loadProductConfig().form.fields.find(field => field.property === 'store')
  const { resolveRelation, relationValue } = loadRelationHelpers()
  const relation = resolveRelation(field, {
    metadata: { type: 'ManyToOne', targetEntity: 'App\\Store\\Entity\\Store' }
  }, { Store: {} })
  const store = { id: 42, uuid: '550e8400-e29b-41d4-a716-446655440000', __toString: 'Main store' }

  assert.equal(relation.name, 'Store')
  assert.equal(relation.valueKey, 'uuid')
  assert.equal(relation.multiple, false)
  // The select and FormAdmin's edit hydration share this value resolver.
  assert.equal(relationValue(store, relation), store.uuid)
  assert.notEqual(relationValue(store, relation), store.id)
})
