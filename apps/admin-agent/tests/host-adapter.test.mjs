import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { mutationCapabilities } from './support/capability-fixture.mjs'
import { createMutationHost } from '../src/mutation-host.js'
import { createAssistantTools } from '../src/tools.js'

const uuid = 'a2c6b792-cab7-4fae-8a25-86f5d59e0210'
const calls = () => {
  const requests = []
  const host = createMutationHost({ post: async(...args) => { requests.push(args); return { data: { saved: false } } } }, '/operations', mutationCapabilities)
  return { requests, host }
}

test('approved business groups expose only scoped actions and fields', () => {
  assert.deepEqual(mutationCapabilities.map(item => item.entity), ['Content', 'Counter', 'Store', 'Category', 'ProductCategory', 'Tag', 'Product', 'Specification', 'StorePrinting', 'ProductPrinting'])
  const policy = Object.fromEntries(mutationCapabilities.map(item => [item.entity, item]))
  assert.deepEqual(policy.Counter.actions, ['create', 'update'])
  assert.deepEqual(policy.Store.actions, ['update'])
  assert.deepEqual(policy.Product.actions, ['create', 'update'])
  assert.deepEqual(policy.Specification.actions, ['create', 'update'])
  assert.deepEqual(policy.StorePrinting.actions, ['update'])
  assert.deepEqual(policy.ProductPrinting.actions, ['update'])
  assert.deepEqual(Object.keys(policy.Content.fields), ['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'])
  assert.equal(Object.hasOwn(policy.Store.fields, 'settings'), false)
  assert.equal(Object.hasOwn(policy.Product.fields, 'settings'), false)
})

test('composed host no longer declares the Counter name-only local preview pilot', () => {
  const source = readFileSync(new URL('../../../integration/admin/agent-host.js', import.meta.url), 'utf8')
  assert.equal(source.includes('preview-policy'), false)
  assert.equal(source.includes('getWritePreviewPolicy'), false)
})

test('composed host exposes the admin main-area bounds and bounded record reads', () => {
  const source = readFileSync(new URL('../../../integration/admin/agent-host.js', import.meta.url), 'utf8')
  assert.match(source, /getCatalog,\s*\n\s*getMainAreaBounds\(\) \{[\s\S]*?querySelector\('\.app-main'\)[\s\S]*?getBoundingClientRect\(\)/)
  assert.match(source, /getRecordReadPolicy\(\) \{ return recordReadPolicy \}/)
  assert.match(source, /createRecordRead\(\{[\s\S]*?isRecordPageAvailable/)
  assert.match(source, /readRecord,/)
})

test('composed host refreshes policy before commit and uses epoch-aware effective identity', () => {
  const source = readFileSync(new URL('../../../integration/admin/agent-host.js', import.meta.url), 'utf8')
  assert.match(source, /async commitRecordChange\(operationId, signal\)[\s\S]*?refreshForCommit\(signal\)/)
  assert.match(source, /async commitRecordBatch\(operationId, signal\)[\s\S]*?refreshForCommit\(signal\)/)
  assert.match(source, /ensureFreshCommitCapability/)
  assert.match(source, /refreshCatalog: requestSignal => capabilityRuntime\.refresh\(\{ signal: requestSignal \}\)/)
  assert.match(source, /getRuntimeEpoch: \(\) => capabilityRuntime\.epoch/)
  assert.match(source, /getProjectionEpoch: \(\) => projectionGeneration/)
  assert.match(source, /componentBindings: scalarComponentBindings/)
  assert.match(source, /schemaFormResolver: createSchemaForm/)
  assert.match(source, /routeAvailability: registeredRoutes\(\)/)
})

test('valid create/update/delete proposals transport only after per-action validation', async() => {
  const { host, requests } = calls()
  const entries = [
    ['Counter', 'create', { storeUuid: uuid, code: 'C-1', name: 'Counter', capacity: null, sort: 0, enabled: true }],
    ['Counter', 'create', { storeUuid: uuid.toUpperCase(), code: 'C-2', name: 'Upper UUID', capacity: 1 }],
    ['Counter', 'update', { name: 'Renamed', capacity: 8, enabled: false }],
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
  for (const entity of ['Category', 'ProductCategory', 'Tag']) {
    await assert.rejects(host.prepareRecordChange({ entity, action: 'delete', identifier: '12', changes: { productId: 1 } }), /Changes contain unsupported/)
  }
  for (const entity of ['Content', 'Counter', 'User', 'Wallet']) {
    await assert.rejects(host.prepareRecordChange({ entity, action: 'delete', identifier: '12' }), /not enabled/)
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
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'delete', identifier: '12' }), /not enabled/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'create', changes: { body: 'Missing title' } }), /Changes contain unsupported/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'update', identifier: '12', changes: { unexpected: true } }), /Changes contain unsupported/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'create', changes: { title: 'Draft', metadata: { arbitrary: true } } }), /Changes contain unsupported/)
  assert.equal(requests.length, 1)
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

test('StorePrinting and ProductPrinting descriptors expose fixed update-only cases and future-print boundaries', () => {
  const policy = Object.fromEntries(mutationCapabilities.map(item => [item.entity, item]))
  assert.deepEqual(Object.keys(policy.StorePrinting.fields), ['order', 'perItem', 'filteredList', 'fullList', 'settlement'])
  assert.deepEqual(Object.keys(policy.StorePrinting.fields.order.properties), ['enabled', 'deviceUuid', 'templateUuid', 'copies'])
  assert.equal(policy.StorePrinting.fields.order.additionalProperties, false)
  assert.equal(policy.StorePrinting.fields.order.properties.copies.minimum, 1)
  assert.equal(policy.StorePrinting.fields.order.properties.copies.maximum, 100)
  assert.equal(policy.StorePrinting.fields.order.properties.deviceUuid.nullable, true)
  assert.deepEqual(Object.keys(policy.ProductPrinting.fields), ['kitchenPrinterEnable'])
  assert.equal(policy.ProductPrinting.fields.kitchenPrinterEnable.type, 'boolean')
  assert.deepEqual(policy.StorePrinting.actions, ['update'])
  assert.deepEqual(policy.ProductPrinting.actions, ['update'])

  const tools = createAssistantTools([], [], {}, mutationCapabilities)
  const tool = tools.find(item => item.function.name === 'prepare_record_change').function
  assert.match(tool.description, /StorePrinting/)
  assert.match(tool.description, /ProductPrinting/)
  assert.ok(tool.parameters.properties.entity.enum.includes('StorePrinting'))
  assert.ok(tool.parameters.properties.entity.enum.includes('ProductPrinting'))
  assert.ok(tool.parameters.properties.action.enum.includes('update'))
  assert.match(tool.description, /FUTURE automatic order printing configuration only/)
  assert.match(tool.description, /FUTURE automatic kitchen-list printing/)
  assert.match(tool.description, /never prints now/)
  assert.match(tool.description, /never creates a print job/)
  assert.match(tool.description, /exact stored Store/)
  assert.match(tool.description, /exact stored Product/)
  assert.match(policy.StorePrinting.description, /settings roots and unrelated settings fields are not accepted/)
  assert.match(policy.ProductPrinting.description, /arbitrary settings, metadata, and other fields are not accepted/)
  assert.equal(tools.some(item => /commit|execute/.test(item.function.name)), false)
})

test('StorePrinting preparation transports only fixed bounded cases for exact targets', async() => {
  const { host, requests } = calls()
  const cases = {
    order: { enabled: true, deviceUuid: uuid, templateUuid: uuid, copies: 1 },
    perItem: { enabled: false, deviceUuid: null, templateUuid: null, copies: 2 },
    filteredList: { copies: 3 },
    fullList: { enabled: false },
    settlement: { enabled: true, deviceUuid: uuid.toUpperCase(), templateUuid: uuid.toUpperCase(), copies: 100 }
  }
  for (const identifier of ['12', uuid, uuid.toUpperCase()]) {
    await host.prepareRecordChange({ entity: 'StorePrinting', action: 'update', identifier, changes: { order: cases.order } })
  }
  await host.prepareRecordChange({ entity: 'StorePrinting', action: 'update', identifier: '12', changes: cases })
  assert.equal(requests.length, 4)
  assert.deepEqual(requests[3][1], { entity: 'StorePrinting', action: 'update', identifier: '12', changes: cases })
})

test('StorePrinting partial patches leave omitted references to backend merged-state revalidation', async() => {
  const { host, requests } = calls()
  const partials = [
    { order: { enabled: true } },
    { order: { copies: 2 } },
    { order: { enabled: true, deviceUuid: uuid } },
    { order: { enabled: true, templateUuid: uuid } },
    { perItem: { enabled: false } },
    { settlement: { enabled: true, copies: 100 } }
  ]
  for (const changes of partials) {
    await host.prepareRecordChange({ entity: 'StorePrinting', action: 'update', identifier: '12', changes })
  }
  assert.equal(requests.length, partials.length)
  assert.deepEqual(requests.map(([, input]) => input.changes), partials)
  for (const changes of [
    { order: { enabled: true, deviceUuid: null } },
    { order: { enabled: true, templateUuid: null } },
    { order: { enabled: true, deviceUuid: null, templateUuid: uuid } },
    { order: { enabled: true, deviceUuid: uuid, templateUuid: null } },
    { order: { enabled: true, deviceUuid: 'not-a-uuid' } },
    { order: { enabled: true, deviceUuid: '../scope' } },
    { order: { enabled: true, storeUuid: uuid } }
  ]) {
    await assert.rejects(host.prepareRecordChange({ entity: 'StorePrinting', action: 'update', identifier: '12', changes }), /Changes contain unsupported/)
  }
  assert.equal(requests.length, partials.length)
})

test('StorePrinting rejects settings roots, arbitrary JSON, malformed cases, and unsafe values before transport', async() => {
  const { host, requests } = calls()
  const invalid = [
    {},
    [{ enabled: false }],
    { settings: { printing: { order: { enabled: false } } } },
    { 'order.requireAcceptance': true },
    { order: {} },
    { order: [] },
    { order: null },
    { order: 'enabled' },
    { order: { enabled: true, deviceUuid: null } },
    { order: { enabled: true, templateUuid: null } },
    { order: { enabled: true, deviceUuid: null, templateUuid: uuid } },
    { order: { enabled: true, deviceUuid: uuid, templateUuid: null } },
    { order: { enabled: true, deviceUuid: uuid, templateUuid: 'not-a-uuid' } },
    { order: { enabled: 'true', deviceUuid: uuid, templateUuid: uuid } },
    { order: { copies: '2' } },
    { order: { copies: 0 } },
    { order: { copies: 101 } },
    { order: { copies: 1.5 } },
    { order: { enabled: false, deviceUuid: 123 } },
    { order: { enabled: false, deviceUuid: `${uuid}\u0000` } },
    { order: { enabled: false, deviceUuid: `${uuid}\n` } },
    { order: { enabled: false, extra: true } },
    { order: { enabled: false }, unknownCase: { enabled: false } },
    { order: Object.assign(Object.create({ inherited: true }), { enabled: false }) },
    { order: { enabled: false, [Symbol('unexpected')]: true } }
  ]
  for (const changes of invalid) {
    await assert.rejects(host.prepareRecordChange({ entity: 'StorePrinting', action: 'update', identifier: '12', changes }), /Changes contain unsupported/)
  }
  await assert.rejects(host.prepareRecordChange({ entity: 'StorePrinting', action: 'update', identifier: '12', changes: JSON.parse('{"order":{"__proto__":{"polluted":true}}}') }), /Changes contain unsupported/)
  for (const [action, extra] of [['create', {}], ['delete', { identifier: '12' }]]) {
    await assert.rejects(host.prepareRecordChange({ entity: 'StorePrinting', action, ...extra, changes: { order: { enabled: false } } }), /not enabled/)
  }
  for (const identifier of [undefined, '0', '../12', '1234567890123456', 'not-a-uuid']) {
    await assert.rejects(host.prepareRecordChange({ entity: 'StorePrinting', action: 'update', ...(identifier === undefined ? {} : { identifier }), changes: { order: { enabled: false } } }), /identifier/)
  }
  for (const [entity, changes] of [
    ['Store', { settings: { order: { requireAcceptance: false } } }],
    ['Store', { settings: { printing: { order: { enabled: false } } } }],
    ['Product', { settings: { kitchenPrinterEnable: true } }]
  ]) {
    await assert.rejects(host.prepareRecordChange({ entity, action: 'update', identifier: '12', changes }), /Changes contain unsupported/)
  }
  assert.equal(requests.length, 0)
})

test('ProductPrinting preparation transports only the boolean kitchen flag for exact targets', async() => {
  const { host, requests } = calls()
  for (const identifier of ['12', uuid]) {
    await host.prepareRecordChange({ entity: 'ProductPrinting', action: 'update', identifier, changes: { kitchenPrinterEnable: true } })
    await host.prepareRecordChange({ entity: 'ProductPrinting', action: 'update', identifier, changes: { kitchenPrinterEnable: false } })
  }
  assert.equal(requests.length, 4)
  for (const changes of [
    {},
    [],
    { kitchenPrinterEnable: 'true' },
    { kitchenPrinterEnable: 1 },
    { kitchenPrinterEnable: null },
    { settings: { kitchenPrinterEnable: true } },
    { metadata: { kitchenPrinterEnable: true } },
    { kitchenPrinterEnable: true, extra: false },
    Object.assign(Object.create({ inherited: true }), { kitchenPrinterEnable: true }),
    { kitchenPrinterEnable: true, [Symbol('unexpected')]: true }
  ]) {
    await assert.rejects(host.prepareRecordChange({ entity: 'ProductPrinting', action: 'update', identifier: '12', changes }), /Changes contain unsupported/)
  }
  await assert.rejects(host.prepareRecordChange({ entity: 'ProductPrinting', action: 'update', identifier: '12', changes: JSON.parse('{"kitchenPrinterEnable":true,"__proto__":{"polluted":true}}') }), /Changes contain unsupported/)
  for (const action of ['create', 'delete']) {
    await assert.rejects(host.prepareRecordChange({ entity: 'ProductPrinting', action, ...(action === 'delete' ? { identifier: '12' } : {}), changes: { kitchenPrinterEnable: true } }), /not enabled/)
  }
  for (const identifier of [undefined, '0', '../12', '1234567890123456', 'not-a-uuid']) {
    await assert.rejects(host.prepareRecordChange({ entity: 'ProductPrinting', action: 'update', ...(identifier === undefined ? {} : { identifier }), changes: { kitchenPrinterEnable: true } }), /identifier/)
  }
  assert.equal(requests.length, 4)
})

test('restored StorePrinting previews accept actual merged cases and reject malformed effective configuration', async() => {
  const restore = preview => createMutationHost({ get: async() => ({ data: preview }) }, '/operations', mutationCapabilities)
  const valid = [
    { entity: 'StorePrinting', action: 'update', identifier: '12', title: 'Review future automatic printing', differences: [
      { property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } },
      { property: 'perItem', before: { enabled: true, deviceUuid: uuid, templateUuid: uuid, copies: 2 }, after: { enabled: true, deviceUuid: uuid, templateUuid: uuid, copies: 3 } },
      { property: 'filteredList', before: { enabled: false }, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }
    ] },
    { entity: 'StorePrinting', action: 'update', identifier: uuid.toUpperCase(), title: 'Automatic printing settings will change', differences: [
      { property: 'fullList', before: { copies: 2 }, after: { enabled: true, deviceUuid: uuid, templateUuid: uuid, copies: 1 } }
    ] },
    { entity: 'StorePrinting', action: 'update', identifier: uuid, title: 'Review', differences: [
      { property: 'settlement', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }
    ] }
  ]
  for (const preview of valid) assert.equal((await restore(preview).restoreRecordChange('a'.repeat(64))).entity, 'StorePrinting')

  const invalid = [
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', title: 'x'.repeat(1025), differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', saved: true, differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', affectedRecords: 2, differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', rawSnapshot: {}, differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: null, differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '../12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12\n', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '1234567890123456', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'unknownCase', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order.requireAcceptance', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [
      { property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }, { property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }
    ] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: {} }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: 'false', deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: true, deviceUuid: uuid } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: true, deviceUuid: uuid, templateUuid: uuid } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: true, deviceUuid: null, templateUuid: uuid, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: true, deviceUuid: 'not-a-uuid', templateUuid: uuid, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: '1' } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 0 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 101 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1, unknown: true } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: `${uuid}\u0000`, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: { templateUuid: `${uuid}\n` }, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: [], after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: { unknown: true }, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: { enabled: 'false' }, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null }] },
    { entity: 'StorePrinting', action: 'update', identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 }, raw: true }] }
  ]
  for (const preview of invalid) await assert.rejects(restore(preview).restoreRecordChange('a'.repeat(64)), /Invalid restored/)
  for (const action of ['create', 'delete']) {
    await assert.rejects(restore({ entity: 'StorePrinting', action, identifier: '12', differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }] }).restoreRecordChange('a'.repeat(64)), /not enabled/)
  }
})

test('restored ProductPrinting previews accept nullable before and boolean after values only', async() => {
  const restore = preview => createMutationHost({ get: async() => ({ data: preview }) }, '/operations', mutationCapabilities)
  for (const preview of [
    { entity: 'ProductPrinting', action: 'update', identifier: '12', title: 'Review kitchen printing', differences: [
      { property: 'kitchenPrinterEnable', before: null, after: true }
    ] },
    { entity: 'ProductPrinting', action: 'update', identifier: uuid, title: 'Kitchen printing will change for future orders', differences: [
      { property: 'kitchenPrinterEnable', before: false, after: true, changed: true, label: 'Kitchen printing' }
    ] },
    { entity: 'ProductPrinting', action: 'update', identifier: uuid.toUpperCase(), differences: [
      { property: 'kitchenPrinterEnable', before: true, after: false }
    ] }
  ]) assert.equal((await restore(preview).restoreRecordChange('a'.repeat(64))).entity, 'ProductPrinting')

  const invalid = [
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [] },
    { entity: 'ProductPrinting', action: 'update', identifier: null, differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '../12', differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12\n', differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', title: 'x'.repeat(1025), differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', saved: false, affectedRecords: 2, differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', rawSnapshot: {}, differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'settings.kitchenPrinterEnable', before: null, after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'kitchenPrinterEnable', before: null, after: null }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'kitchenPrinterEnable', before: null, after: 'true' }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'kitchenPrinterEnable', before: null, after: 1 }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'kitchenPrinterEnable', before: 'absent', after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }, { property: 'kitchenPrinterEnable', before: true, after: false }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'kitchenPrinterEnable', before: null, after: true, raw: 'x' }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'kitchenPrinterEnable', after: true }] },
    { entity: 'ProductPrinting', action: 'update', identifier: '12', differences: [{ property: 'kitchenPrinterEnable', before: null }] }
  ]
  for (const preview of invalid) await assert.rejects(restore(preview).restoreRecordChange('a'.repeat(64)), /Invalid restored/)
  for (const action of ['create', 'delete']) {
    await assert.rejects(restore({ entity: 'ProductPrinting', action, identifier: '12', differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }] }).restoreRecordChange('a'.repeat(64)), /not enabled/)
  }
})
