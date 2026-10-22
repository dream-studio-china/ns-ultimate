import assert from 'node:assert/strict'
import test from 'node:test'
import previewPolicy from '../adapters/ns-ultimate/admin/preview-policy.mjs'
import mutationCapabilities from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import { createMutationHost } from '../src/mutation-host.js'
import { createAssistantTools } from '../src/tools.js'

const uuid = 'a2c6b792-cab7-4fae-8a25-86f5d59e0210'
const calls = () => {
  const requests = []
  const host = createMutationHost({ post: async(...args) => { requests.push(args); return { data: { saved: false } } } }, '/operations', mutationCapabilities)
  return { requests, host }
}

test('seven approved business groups expose only scoped actions and fields', () => {
  assert.deepEqual(Object.keys(previewPolicy), ['Counter'])
  assert.deepEqual(mutationCapabilities.map(item => item.entity), ['Content', 'Counter', 'Store', 'Category', 'ProductCategory', 'Tag', 'Product', 'Specification'])
  const policy = Object.fromEntries(mutationCapabilities.map(item => [item.entity, item]))
  assert.deepEqual(policy.Counter.actions, ['create', 'update', 'delete'])
  assert.deepEqual(policy.Store.actions, ['update'])
  assert.deepEqual(policy.Product.actions, ['create', 'update'])
  assert.deepEqual(policy.Specification.actions, ['create', 'update'])
  assert.deepEqual(Object.keys(policy.Content.fields), ['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'])
})

test('valid create/update/delete proposals transport only after per-action validation', async() => {
  const { host, requests } = calls()
  const entries = [
    ['Counter', 'create', { storeUuid: uuid, code: 'C-1', name: 'Counter', capacity: null, sort: 0, enabled: true }],
    ['Counter', 'create', { storeUuid: uuid.toUpperCase(), code: 'C-2', name: 'Upper UUID', capacity: 1 }],
    ['Counter', 'update', { name: 'Renamed', capacity: 8, enabled: false }],
    ['Counter', 'delete', undefined],
    ['Store', 'update', { name: 'Shop', contact: { managerName: 'Front desk', phone: '+33123456789' }, address: { city: 'Paris' } }],
    ['Category', 'create', { name: 'Food', slug: 'food', description: 'é'.repeat(32767), parent: null, sortOrder: 0, enabled: true }],
    ['Category', 'update', { name: 'Meals' }],
    ['Category', 'delete', undefined],
    ['ProductCategory', 'create', { name: 'Food', slug: 'food', parent: null, store: 2 }],
    ['ProductCategory', 'update', { name: 'Meals', parent: uuid }],
    ['ProductCategory', 'delete', undefined],
    ['Tag', 'create', { name: 'Vegan', slug: 'vegan', color: '#12aB34' }],
    ['Tag', 'update', { color: null }],
    ['Tag', 'delete', undefined],
    ['Product', 'create', { name: 'Tea', description: null, status: 'active', store: uuid, category: null, sortOrder: 0, recommendationLevel: 0, isSoldout: false }],
    ['Product', 'create', { name: 'Global tea', store: null }],
    ['Product', 'create', { name: 'Default global tea' }],
    ['Product', 'create', { name: '🍵'.repeat(255), store: null }],
    ['Product', 'update', { name: 'Tea', isSoldout: true }],
    ['Specification', 'create', { productId: 4, name: 'Large', price: 0, status: 'active', sort: 0 }],
    ['Specification', 'update', { productId: 4, price: 1200 }],
    ['Content', 'create', { title: 'Draft' }],
    ['Content', 'update', { body: null }],
    ['Content', 'delete', undefined]
  ]
  for (const [entity, action, changes] of entries) await host.prepareRecordChange({ entity, action, ...(action === 'create' ? {} : { identifier: '12' }), ...(changes === undefined ? {} : { changes }) })
  assert.equal(requests.length, entries.length)
})

test('all seven groups reject invalid bounds, nested unknown/prototype values, and cross-scope fields before transport', async() => {
  const { host, requests } = calls()
  const invalid = [
    ['Counter', 'create', { storeUuid: uuid, code: 'C', name: 'N', capacity: -1 }],
    ['Counter', 'create', { code: 'C', name: 'N' }],
    ['Counter', 'update', { name: 'N', storeUuid: uuid }],
    ['Store', 'update', { name: 'N', currency: 'USD' }],
    ['Store', 'update', { name: 'N', contact: { name: 'X', arbitrary: 'blob' } }],
    ['Store', 'update', { contact: { phone: 'not a phone' } }],
    ['Category', 'create', { name: 'N', slug: 'n', enabled: 'yes' }],
    ['Category', 'create', { name: 'N' }],
    ['Category', 'update', { enabled: 'true' }],
    ['Category', 'update', { description: 'é'.repeat(32768) }],
    ['ProductCategory', 'create', { name: 'N' }],
    ['ProductCategory', 'update', { name: 'N', slug: 'n', store: 1 }],
    ['Tag', 'create', { name: 'N', slug: 'n', color: '#fff' }],
    ['Tag', 'create', { slug: 'n' }],
    ['Tag', 'update', { name: 'N', slug: 'n', color: 'red' }],
    ['Product', 'create', { name: 'N', store: 1, status: 'active', metadata: { x: true } }],
    ['Product', 'create', { status: 'active' }],
    ['Product', 'create', { name: 'N', store: 1 }],
    ['Product', 'create', { name: ' Tea ' }],
    ['Product', 'create', { name: 'N', description: 'A <b>bold</b> product' }],
    ['Product', 'update', { name: 'N', store: 1 }],
    ['Product', 'update', { recommendationLevel: 1.5 }],
    ['Specification', 'create', { productId: 1, name: 'N', price: 2147483648 }],
    ['Specification', 'create', { productId: 2147483648, name: 'N', price: 1 }],
    ['Specification', 'create', { productId: 1, name: 'N' }],
    ['Specification', 'update', { name: 'N' }],
    ['Specification', 'update', { productId: 1, name: '' }],
    ['Specification', 'update', { productId: 2, status: 'published' }],
    ['Category', 'create', JSON.parse('{"name":"N","slug":"n","__proto__":{"polluted":true}}')],
    ['ProductCategory', 'create', { name: 'N', slug: 'n', store: 'not-a-store' }],
    ['Store', 'update', Object.assign(Object.create({ inherited: true }), { name: 'N' })]
  ]
  for (const [entity, action, changes] of invalid) await assert.rejects(host.prepareRecordChange({ entity, action, ...(action === 'create' ? {} : { identifier: '12' }), changes }), /Changes contain unsupported/)
  for (const [entity, action] of [['Store', 'create'], ['Product', 'delete'], ['Specification', 'delete'], ['Unknown', 'update']]) {
    await assert.rejects(host.prepareRecordChange({ entity, action, identifier: '12', changes: { name: 'N' } }), /not enabled/)
  }
  await assert.rejects(host.prepareRecordChange({ entity: 'Counter', action: 'update', identifier: '../12', changes: { name: 'N' } }), /identifier/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Counter', action: 'create', identifier: '12', changes: { storeUuid: uuid, code: 'C', name: 'N' } }), /identifier/)
  const symbolInput = { entity: 'Content', action: 'delete', identifier: '12' }
  symbolInput[Symbol('unexpected')] = true
  await assert.rejects(host.prepareRecordChange(symbolInput), /not enabled/)
  for (const entity of ['Content', 'Counter', 'Category', 'ProductCategory', 'Tag']) {
    await assert.rejects(host.prepareRecordChange({ entity, action: 'delete', identifier: '12', changes: { productId: 1 } }), /Changes contain unsupported/)
  }
  await assert.rejects(host.prepareRecordChange({ entity: 'Store', action: 'update', identifier: '12', changes: JSON.parse('{"contact":{"__proto__":{"polluted":true}}}') }), /Changes contain unsupported/)
  assert.equal(requests.length, 0)
})

test('unknown and disabled restored previews never reach the UI; allowed diffs are bounded', async() => {
  for (const preview of [
    { entity: 'Unknown', action: 'update', differences: [] },
    { entity: 'Product', action: 'delete', differences: [] },
    { entity: 'Store', action: 'create', differences: [] },
    { entity: 'Tag', action: 'update', differences: [{ property: 'metadata', before: {}, after: {} }] },
    { entity: 'Tag', action: 'update', differences: [{ property: 'color', before: null, after: '#123456', extra: true }] }
  ]) {
    const host = createMutationHost({ get: async() => ({ data: preview }) }, '/operations', mutationCapabilities)
    await assert.rejects(host.restoreRecordChange('a'.repeat(64)), /enabled|Invalid restored/)
  }
  const host = createMutationHost({ get: async() => ({ data: { entity: 'Tag', action: 'update', differences: [{ property: 'color', before: null, after: '#123456' }] } }) }, '/operations', mutationCapabilities)
  assert.equal((await host.restoreRecordChange('a'.repeat(64))).entity, 'Tag')
})

test('Content mutation compatibility and unknown nested fields remain rejected', async() => {
  const { host, requests } = calls()
  await host.prepareRecordChange({ entity: 'Content', action: 'create', changes: { title: 'Draft', metadata: { featured: true, layout: 'article' } } })
  await host.prepareRecordChange({ entity: 'Content', action: 'delete', identifier: '12' })
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'create', changes: { body: 'Missing title' } }), /Changes contain unsupported/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'update', identifier: '12', changes: { unexpected: true } }), /Changes contain unsupported/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'create', changes: { title: 'Draft', metadata: { arbitrary: true } } }), /Changes contain unsupported/)
  assert.equal(requests.length, 2)
})

test('write tool advertises per-entity supported actions and per-action input scope', () => {
  const tool = createAssistantTools([], [], {}, mutationCapabilities).find(item => item.function.name === 'prepare_record_change').function
  assert.deepEqual(tool.parameters.properties.action.enum, ['create', 'update', 'delete'])
  assert.match(tool.description, /never substitute fields between actions or assume an omitted action is allowed/)
  assert.match(tool.description, /fieldsByAction/)
  assert.deepEqual(mutationCapabilities.find(item => item.entity === 'Product').actions, ['create', 'update'])
  assert.deepEqual(Object.keys(mutationCapabilities.find(item => item.entity === 'Specification').actionFields), ['create', 'update'])
  const policies = Object.fromEntries(mutationCapabilities.map(item => [item.entity, item]))
  assert.deepEqual(policies.Store.actionFields.update.contact.properties.managerName, {
    type: 'string', maxLength: 40, requiredOnCreate: false, nonEmpty: true, minLength: 1
  })
  assert.equal(policies.Store.actionFields.update.contact.additionalProperties, false)
  assert.equal(policies.ProductCategory.actionFields.create.parent.mustMatchStoreScope, true)
  assert.deepEqual(policies.ProductCategory.actionFields.create.parent.anyOf.map(rule => rule.type), ['integer', 'numeric-id-string', 'string'])
  assert.deepEqual(policies.Store.actionFields.update.address.properties.latitude, { type: 'number', minimum: -90, maximum: 90 })
  assert.equal(policies.Specification.actionFields.update.productId.immutableScope, true)
  assert.equal(policies.Specification.actionFields.create.price.maximum, 2147483647)
  assert.equal(policies.Product.actionFields.create.store.nullable, true)
  assert.equal(policies.Product.actionFields.create.store.requiredOnCreate, undefined)
  assert.equal(policies.Counter.actionFields.create.storeUuid.requiredOnCreate, true)
})

test('restored create/delete previews require real bounded differences and required create fields', async() => {
  for (const preview of [
    { entity: 'Tag', action: 'create', differences: [] },
    { entity: 'Tag', action: 'create', differences: [{ property: 'name', before: null, after: 'Vegan' }] },
    { entity: 'Tag', action: 'delete', title: 'Vegan', differences: [] },
    { entity: 'Tag', action: 'delete', title: 'Vegan', differences: [{ property: 'name', before: 'Vegan', after: null, secret: 'blob' }] },
    { entity: 'Tag', action: 'create', title: 'Vegan', differences: [
      { property: 'name', before: null, after: 'Vegan' }, { property: 'slug', before: null, after: 'vegan' }
    ], rawSnapshot: { password: 'not allowed' } }
  ]) {
    const host = createMutationHost({ get: async() => ({ data: preview }) }, '/operations', mutationCapabilities)
    await assert.rejects(host.restoreRecordChange('a'.repeat(64)), /Invalid restored/)
  }
  const host = createMutationHost({ get: async() => ({ data: { entity: 'Tag', action: 'create', title: 'Vegan', differences: [
    { property: 'name', before: null, after: 'Vegan' }, { property: 'slug', before: null, after: 'vegan' }
  ] } }) }, '/operations', mutationCapabilities)
  assert.equal((await host.restoreRecordChange('a'.repeat(64))).action, 'create')
})

test('restored values use action-specific required-value, scope, duplicate, and delete rules', async() => {
  const rejected = [
    { entity: 'Tag', action: 'create', title: 'Vegan', differences: [
      { property: 'name', before: null, after: '' }, { property: 'slug', before: null, after: 'vegan' }
    ] },
    { entity: 'Specification', action: 'create', title: 'Large [Product #4]', differences: [
      { property: 'productId', before: null, after: 4 }, { property: 'name', before: null, after: '' }, { property: 'price', before: null, after: 0 }
    ] },
    { entity: 'Specification', title: '[Product #5; Specification #new; Store global] Product #4 size', action: 'create', identifier: null, differences: [
      { property: 'productId', before: null, after: 4 }, { property: 'name', before: null, after: 'Large' }, { property: 'price', before: null, after: 0 }
    ] },
    { entity: 'Specification', action: 'update', identifier: '12', title: 'Large', differences: [
      { property: 'price', before: 100, after: 120 }
    ] },
    { entity: 'Specification', action: 'create', identifier: null, title: '[Product #4; Specification #new; Store not-a-uuid] Large', differences: [
      { property: 'productId', before: null, after: 4 }, { property: 'name', before: null, after: 'Large' }, { property: 'price', before: null, after: 0 }
    ] },
    { entity: 'Specification', action: 'update', identifier: '12', title: '[Product #4; Specification #12; Store global] Large', differences: [
      { property: 'productId', before: 4, after: 5 }
    ] },
    { entity: 'Tag', action: 'update', title: 'Vegan', differences: [
      { property: 'color', before: null }
    ] },
    { entity: 'Tag', action: 'update', title: 'Vegan', differences: [
      { property: 'color', before: null, after: '#123456' }, { property: 'color', before: '#123456', after: '#654321' }
    ] },
    { entity: 'Tag', action: 'delete', title: 'Vegan', differences: [
      { property: 'name', before: 'Vegan', after: 'still here' }
    ] }
  ]
  for (const preview of rejected) {
    const host = createMutationHost({ get: async() => ({ data: preview }) }, '/operations', mutationCapabilities)
    await assert.rejects(host.restoreRecordChange('a'.repeat(64)), /Invalid restored|not enabled/)
  }
  const validUpdate = createMutationHost({ get: async() => ({ data: { entity: 'Specification', action: 'update', identifier: '4', title: '[Product #4; Specification #4; Store global] Large; Product #5 size', differences: [
    { property: 'productId', before: 4, after: 4 }, { property: 'price', before: 100, after: 120 }
  ] } }) }, '/operations', mutationCapabilities)
  assert.equal((await validUpdate.restoreRecordChange('a'.repeat(64))).entity, 'Specification')
  const validCreate = createMutationHost({ get: async() => ({ data: { entity: 'Specification', action: 'create', identifier: null, title: '[Product #4; Specification #new; Store global] Large; Product #5 size', differences: [
    { property: 'productId', before: null, after: 4 }, { property: 'name', before: null, after: 'Large' }, { property: 'price', before: null, after: 0 }
  ] } }) }, '/operations', mutationCapabilities)
  assert.equal((await validCreate.restoreRecordChange('a'.repeat(64))).title, '[Product #4; Specification #new; Store global] Large; Product #5 size')
  for (const scope of ['global', uuid]) {
    const scopedCreate = createMutationHost({ get: async() => ({ data: { entity: 'Specification', action: 'create', identifier: null, title: `[Product #4; Specification #new; Store ${scope}] Large`, differences: [
      { property: 'productId', before: null, after: 4 }, { property: 'name', before: null, after: 'Large' }, { property: 'price', before: null, after: 0 }
    ] } }) }, '/operations', mutationCapabilities)
    assert.equal((await scopedCreate.restoreRecordChange('a'.repeat(64))).entity, 'Specification')
  }
  const longProductTitle = `[Product #new; Store global] ${'🍵'.repeat(255)}`
  const validProductCreate = createMutationHost({ get: async() => ({ data: { entity: 'Product', action: 'create', identifier: null, title: longProductTitle, differences: [
    { property: 'name', before: null, after: '🍵'.repeat(255) }, { property: 'store', before: null, after: null }
  ] } }) }, '/operations', mutationCapabilities)
  assert.equal((await validProductCreate.restoreRecordChange('a'.repeat(64))).title, longProductTitle)
  for (const preview of [
    { entity: 'Product', action: 'create', identifier: null, title: '[Product #new; Store global] Product #4 tea', differences: [
      { property: 'name', before: null, after: 'Tea' }, { property: 'store', before: null, after: uuid }
    ] },
    { entity: 'Product', action: 'update', identifier: '5', title: '[Product #4; Store global] Tea', differences: [
      { property: 'name', before: 'Tea', after: 'Tea blend' }
    ] }
  ]) {
    const badProductPreview = createMutationHost({ get: async() => ({ data: preview }) }, '/operations', mutationCapabilities)
    await assert.rejects(badProductPreview.restoreRecordChange('a'.repeat(64)), /Invalid restored/)
  }
  const maxTitlePreview = createMutationHost({ get: async() => ({ data: { entity: 'Tag', action: 'update', title: '🍵'.repeat(1024), differences: [
    { property: 'name', before: 'Vegan', after: 'Plant-based' }
  ] } }) }, '/operations', mutationCapabilities)
  assert.equal([...((await maxTitlePreview.restoreRecordChange('a'.repeat(64))).title)].length, 1024)
  const oversizedTitlePreview = createMutationHost({ get: async() => ({ data: { entity: 'Tag', action: 'update', title: '🍵'.repeat(1025), differences: [
    { property: 'name', before: 'Vegan', after: 'Plant-based' }
  ] } }) }, '/operations', mutationCapabilities)
  await assert.rejects(oversizedTitlePreview.restoreRecordChange('a'.repeat(64)), /Invalid restored/)
})

test('Store redacted nested before values and nullable nested updates restore without private snapshots', async() => {
  const host = createMutationHost({ get: async() => ({ data: { entity: 'Store', action: 'update', title: 'Shop', differences: [
    { property: 'contact', before: null, after: { managerName: 'Manager' } },
    { property: 'address', before: null, after: { city: 'Paris' } }
  ] } }) }, '/operations', mutationCapabilities)
  assert.equal((await host.restoreRecordChange('a'.repeat(64))).entity, 'Store')
  const { host: writer, requests } = calls()
  await writer.prepareRecordChange({ entity: 'Store', action: 'update', identifier: '12', changes: { contact: null, address: null } })
  await writer.prepareRecordChange({ entity: 'Store', action: 'update', identifier: '12', changes: { contact: Object.assign(Object.create(null), { managerName: 'Manager' }) } })
  for (const changes of [
    { contact: { managerName: 'A', tags: ['one', 'one'] } },
    { contact: { managerName: 'A\u0000B' } },
    { contact: { managerName: 'A', privateBlob: 'secret' } },
    { address: { latitude: 12 } },
    { contact: { managerName: '' } }
  ]) {
    await assert.rejects(writer.prepareRecordChange({ entity: 'Store', action: 'update', identifier: '12', changes }), /Changes contain unsupported/)
  }
  assert.equal(requests.length, 2)
})
