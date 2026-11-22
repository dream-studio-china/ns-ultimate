import assert from 'node:assert/strict'
import test from 'node:test'
import {
  categoryBelongsToProductScope,
  productCategoryOptions
} from '../../business/admin/config/components/catalog/product-category-scope.mjs'
import { productCategoryFilterOptions } from '../../business/admin/config/components/catalog/product-filter-scope.mjs'

const storeUuid = '00000000-0000-4000-8000-000000000001'
const otherStoreUuid = '00000000-0000-4000-8000-000000000002'

test('shows global and selected-store categories without other stores', () => {
  const categories = [
    { uuid: 'global', name: 'Global category', store: null },
    { uuid: 'local', name: 'Local category', store: { uuid: storeUuid } },
    { uuid: 'other', name: 'Other category', store: { uuid: otherStoreUuid } }
  ]

  assert.deepEqual(productCategoryOptions(categories, storeUuid).map(option => option.value), ['global', 'local'])
  assert.deepEqual(productCategoryOptions(categories, '').map(option => option.value), ['global'])
})

test('uses product store scope for global, local and mismatched categories', () => {
  assert.equal(categoryBelongsToProductScope({ store: null }, ''), true)
  assert.equal(categoryBelongsToProductScope({ store: { uuid: storeUuid } }, storeUuid.toUpperCase()), true)
  assert.equal(categoryBelongsToProductScope({ store: { uuid: otherStoreUuid } }, storeUuid), false)
  assert.equal(categoryBelongsToProductScope({ store: { uuid: otherStoreUuid } }, ''), false)
})

test('filters categories without a UUID and marks global options', () => {
  const options = productCategoryOptions([
    { name: 'Missing identity' },
    { uuid: 'global', name: 'Seasonal' },
    { uuid: 'local', __toString: 'Lunch', store: { uuid: storeUuid } }
  ], storeUuid)

  assert.deepEqual(options, [
    { value: 'local', label: 'Lunch', name: 'Lunch', isGlobal: false },
    { value: 'global', label: 'Seasonal', name: 'Seasonal', isGlobal: true }
  ])
})

test('product list category filter is empty until a store is selected', () => {
  const categories = [
    { id: 1, name: 'Global category', store: null },
    { id: 2, name: 'Local category', store: { uuid: storeUuid } },
    { id: 3, name: 'Other category', store: { uuid: otherStoreUuid } }
  ]

  assert.deepEqual(productCategoryFilterOptions(categories, ''), [])
  assert.deepEqual(productCategoryFilterOptions(categories, storeUuid).map(option => option.value), ['1', '2'])
  assert.equal(productCategoryFilterOptions(categories, storeUuid)[1].isGlobal, false)
})
