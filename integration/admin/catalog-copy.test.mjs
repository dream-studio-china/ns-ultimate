import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import {
  categoryCopyPayload,
  categoryStoreUuid,
  fetchAllPages,
  findCategoryByReference,
  mappedCategory,
  orderedSourceCategories,
  productCopyPayload,
  productStoreUuid,
  referenceKeys,
  rememberCategoryMapping,
  specificationCopyPayload,
  targetCategoriesBySlug
} from '../../business/admin/config/components/catalog-copy.mjs'

function loadCategoryConfig() {
  const source = readFileSync(new URL(
    '../../business/admin/config/overrides/trade/product-category.js', import.meta.url
  ), 'utf8')
    .replace(/^import coreConfig.*$/m, 'const coreConfig = { ProductCategory: { form: { fields: [] }, list: { list_display: ["id"] } } }')
    .replace(/^import \{ CopyProductCategoriesAction \}.*$/m, 'const CopyProductCategoriesAction = {}')
  const context = {}
  runInNewContext(source.replace('export default', 'globalThis.config ='), context)
  return context.config
}

const store = uuid => ({ id: 1, uuid })
const category = (uuid, slug, overrides = {}) => ({ id: uuid.length, uuid, name: slug, slug, parent: null, enabled: true, sortOrder: 0, ...overrides })

test('category copy order places parents before children and rejects cycles', () => {
  const parent = category('parent-uuid', 'food', { sortOrder: 1 })
  const child = category('child-uuid', 'hotpot', { parent, sortOrder: 0 })
  const grandchild = category('grandchild-uuid', 'spicy', { parent: child, sortOrder: 2 })

  assert.deepEqual(orderedSourceCategories([grandchild, child, parent]).map(item => item.uuid), [
    'parent-uuid',
    'child-uuid',
    'grandchild-uuid'
  ])

  parent.parent = grandchild
  assert.throws(() => orderedSourceCategories([parent, child, grandchild]), /catalog_category_cycle/)
})

test('category copy reuses matching target slugs and maps source identifiers', () => {
  const source = category('source-uuid', 'drinks')
  const target = category('target-uuid', 'drinks')
  const bySlug = targetCategoriesBySlug([target])
  const mapping = rememberCategoryMapping(new Map(), source, bySlug.get(source.slug))

  assert.equal(bySlug.get('drinks'), target)
  assert.deepEqual(referenceKeys(source), ['source-uuid', String(source.id)])
  assert.equal(mappedCategory(mapping, source.id), target)
  assert.equal(mappedCategory(mapping, source), target)
})

test('copy payloads preserve product display data, settings and specifications', () => {
  const sourceCategory = category('category-source', 'food')
  const payload = productCopyPayload({
    name: '  Hotpot  ',
    description: 'House special',
    status: 'inactive',
    metadata: { cover: 'cover.jpg', subtitle: 'Spicy' },
    settings: { kitchenPrinterEnable: true },
    sortOrder: 4,
    recommendationLevel: 2,
    isSoldout: true
  }, 'target-store', 'target-category')

  assert.deepEqual(payload, {
    name: 'Hotpot',
    description: 'House special',
    status: 'inactive',
    metadata: { cover: 'cover.jpg', subtitle: 'Spicy' },
    settings: { kitchenPrinterEnable: true },
    store: 'target-store',
    category: 'target-category',
    sortOrder: 4,
    recommendationLevel: 2,
    isSoldout: true
  })
  assert.deepEqual(specificationCopyPayload({ name: 'Large', price: 1890, status: 'active', sort: 2 }), {
    name: 'Large',
    price: 1890,
    status: 'active',
    sort: 2
  })
  assert.deepEqual(categoryCopyPayload(sourceCategory, 'target-store'), {
    name: 'food',
    slug: 'food',
    description: null,
    parent: null,
    sortOrder: 0,
    enabled: true,
    store: 'target-store'
  })
})

test('category references can use either UUID or numeric association IDs', () => {
  const source = category('550e8400-e29b-41d4-a716-446655440000', 'food')
  assert.equal(findCategoryByReference([source], { id: source.id }), source)
  assert.equal(findCategoryByReference([source], source.uuid), source)
})

test('fetchAllPages follows backend paginator metadata', async () => {
  const calls = []
  const get = async (_path, { params }) => {
    calls.push(params)
    return {
      data: params.page === 1 ? [{ id: 1 }, { id: 2 }] : [{ id: 3 }],
      paginator: { has_next: params.page === 1 }
    }
  }

  const items = await fetchAllPages(get, '/categories', { '@order': 'entity.id|ASC' }, 2)
  assert.deepEqual(items.map(item => item.id), [1, 2, 3])
  assert.deepEqual(calls, [
    { '@order': 'entity.id|ASC', page: 1, limit: 2 },
    { '@order': 'entity.id|ASC', page: 2, limit: 2 }
  ])
})

test('source and target store helpers are based on UUIDs', () => {
  assert.equal(categoryStoreUuid({ store: store('category-store') }), 'category-store')
  assert.equal(productStoreUuid({ store: store('product-store') }), 'product-store')
  assert.equal(categoryStoreUuid({ store: 42 }), '')
})

test('product category override is an unwrapped entity config with the copy action', () => {
  const config = loadCategoryConfig()

  assert.equal(config.ProductCategory, undefined)
  assert.equal(config.list.list_display.length, 1)
  assert.equal(config.list.list_display[0], 'id')
  assert.equal(config.list.actions[0].name, 'copy-product-categories-from-store')
  assert.equal(config.list.actions[0].position, 'top')
})
