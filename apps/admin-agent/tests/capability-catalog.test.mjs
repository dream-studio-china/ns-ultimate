import assert from 'node:assert/strict'
import test from 'node:test'
import { Worker } from 'node:worker_threads'
import {
  CapabilityCatalogError,
  compileCapabilityCatalogResponse,
  createCapabilityCatalogRuntime,
  validateCapabilityCatalogResponse
} from '../src/capability-catalog.js'
import { assertSafePattern } from '../src/capability-validation.js'
import { createMutationHost } from '../src/mutation-host.js'
import { createBatchMutationHost } from '../src/batch-mutation-host.js'
import { createAssistantTools } from '../src/tools.js'
import {
  changeSemantics,
  compileMutationCapabilities,
  previewSemantics
} from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import { catalogResponse, compiledCatalog } from './support/capability-fixture.mjs'

const REVISION = 'a'.repeat(64)
const UUID = 'a2c6b792-cab7-4fae-8a25-86f5d59e0210'
const clone = value => structuredClone(value)

function responseWith(mutator) {
  const copy = clone(catalogResponse)
  mutator(copy)
  return copy
}

function compileError(code, candidate) {
  assert.throws(() => compileMutationCapabilities(candidate), error => {
    assert.ok(error instanceof CapabilityCatalogError)
    assert.equal(error.code, code)
    return true
  })
}

const validationModuleUrl = new URL('../src/capability-validation.js', import.meta.url).href

function runPatternInWorker(pattern, timeoutMs = 1500) {
  return new Promise((resolve, reject) => {
    const worker = new Worker(`
      const { parentPort, workerData } = require('node:worker_threads')
      import(workerData.moduleUrl).then(({ assertSafePattern }) => {
        try {
          const expression = assertSafePattern(workerData.pattern)
          expression.test('a'.repeat(200) + 'b')
          parentPort.postMessage({ ok: true })
        } catch (error) {
          parentPort.postMessage({ ok: false, code: error.code || error.name })
        }
      }).catch(error => parentPort.postMessage({ ok: false, code: 'import', message: error.message }))
    `, { eval: true, workerData: { pattern, moduleUrl: validationModuleUrl } })
    let settled = false
    const finish = (callback, value) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      worker.terminate()
      callback(value)
    }
    const timer = setTimeout(() => finish(reject, new Error(`Pattern validation timed out: ${pattern}`)), timeoutMs)
    worker.on('message', message => finish(resolve, message))
    worker.on('error', error => finish(reject, error))
  })
}

test('the canonical server export compiles into reusable validators without serializing functions', () => {
  assert.equal(compiledCatalog.revision, catalogResponse.revision)
  assert.deepEqual(compiledCatalog.capabilities.map(item => item.entity),
    ['Content', 'Counter', 'Store', 'Category', 'ProductCategory', 'Tag', 'Product', 'Specification', 'StorePrinting', 'ProductPrinting'])
  assert.deepEqual(compiledCatalog.batchCapabilities.map(item => item.entity), ['Counter', 'Tag'])
  const tag = compiledCatalog.capabilities.find(item => item.entity === 'Tag')
  assert.equal(typeof tag.validateChanges, 'function')
  assert.equal(typeof tag.validatePreview, 'function')
  assert.equal(tag.validateChanges({ name: 'Vegan', slug: 'vegan' }, 'create'), true)
  assert.equal(tag.validateChanges({ name: 'Vegan' }, 'create'), false)
  const serialized = JSON.stringify(tag)
  assert.equal(serialized.includes('validateChanges'), false)
  assert.equal(serialized.includes('validatePreview'), false)
  assert.equal(Object.keys(JSON.parse(serialized)).includes('schemaVersion'), false)
})

const DELETE_PREVIEW_FIELDS = Object.freeze({
  name: { type: 'string', maxLength: 255, nonEmpty: true },
  slug: { type: 'string', maxLength: 255, nonEmpty: true },
  color: { type: 'color', nullable: true }
})

function deleteOnlyCatalog({ previewFields = DELETE_PREVIEW_FIELDS, omitPreviewFields = false } = {}) {
  return responseWith(copy => {
    const tag = copy.capabilities.find(item => item.entity === 'Tag')
    tag.actions = ['delete']
    tag.actionFields = {}
    tag.fields = {}
    if (omitPreviewFields) delete tag.deletePreviewFields
    else tag.deletePreviewFields = clone(previewFields)
    copy.batchCapabilities = copy.batchCapabilities.filter(item => item.entity !== 'Tag')
  })
}

function canonicalTagDeletePreview() {
  return {
    operationId: 'a'.repeat(64),
    entity: 'Tag',
    action: 'delete',
    identifier: '12',
    title: 'Delete Tag Vegan',
    affectedRecords: 1,
    expiresAt: '2026-10-23T12:05:00+00:00',
    differences: [
      { property: 'name', before: 'Vegan', after: null },
      { property: 'slug', before: 'vegan', after: null },
      { property: 'color', before: null, after: null }
    ],
    saved: false
  }
}

test('delete-only capabilities validate previews only through explicit deletePreviewFields', () => {
  const tag = compileMutationCapabilities(deleteOnlyCatalog()).capabilities.find(item => item.entity === 'Tag')
  assert.deepEqual(tag.actions, ['delete'])
  assert.deepEqual(tag.actionFields, {})
  assert.deepEqual(tag.fields, {})
  assert.equal(tag.validateChanges(undefined, 'delete'), true)
  assert.equal(tag.validateChanges({ name: 'Vegan' }, 'delete'), false)
  assert.equal(tag.validatePreview(canonicalTagDeletePreview()), true)
  const unknownField = canonicalTagDeletePreview()
  unknownField.differences.push({ property: 'secret', before: 'hidden', after: null })
  assert.equal(tag.validatePreview(unknownField), false)
  const invalidValue = canonicalTagDeletePreview()
  invalidValue.differences = [{ property: 'name', before: 123, after: null }]
  assert.equal(tag.validatePreview(invalidValue), false)

  // Preview metadata is not writable and must not leak into the model policy.
  assert.equal(JSON.stringify(tag).includes('deletePreviewFields'), false)
  const tool = createAssistantTools([], [], {}, [tag]).find(item => item.function.name === 'prepare_record_change').function
  assert.ok(tool.parameters.properties.entity.enum.includes('Tag'))
  assert.ok(tool.parameters.properties.action.enum.includes('delete'))
  assert.match(tool.description, /"fieldsByAction":\{\}/)
  assert.equal(tool.description.includes('deletePreviewFields'), false)

  const updateOnly = responseWith(copy => {
    const product = copy.capabilities.find(item => item.entity === 'Product')
    product.actions = ['update']
    product.actionFields = { update: product.actionFields.update }
  })
  const product = compileMutationCapabilities(updateOnly).capabilities.find(item => item.entity === 'Product')
  assert.equal(product.validateChanges({ name: 'Tea' }, 'update'), true)
  assert.equal(product.validateChanges({ name: 'Tea', store: UUID }, 'update'), false)
})

test('delete previews fail closed without metadata and invalid delete-only policy is rejected', () => {
  const noPreview = compileMutationCapabilities(deleteOnlyCatalog({ omitPreviewFields: true })).capabilities.find(item => item.entity === 'Tag')
  assert.equal(noPreview.validatePreview(canonicalTagDeletePreview()), false)

  // Do not fall back to write fields when deletePreviewFields is omitted.
  const withWrites = responseWith(copy => {
    const tag = copy.capabilities.find(item => item.entity === 'Tag')
    delete tag.deletePreviewFields
  })
  const writeTag = compileMutationCapabilities(withWrites).capabilities.find(item => item.entity === 'Tag')
  assert.ok(writeTag.actions.includes('delete'))
  assert.equal(writeTag.validatePreview(canonicalTagDeletePreview()), false)

  const invalid = [
    ['malformed', copy => {
      const product = copy.capabilities.find(item => item.entity === 'Product')
      product.deletePreviewFields = { name: { type: 'string', maxLength: 255 } }
    }],
    ['unsupported_validation', copy => {
      copy.batchCapabilities[0].deletePreviewFields = { name: { type: 'string', maxLength: 255 } }
    }],
    ['malformed', copy => {
      copy.capabilities.find(item => item.entity === 'Tag').deletePreviewFields = {}
    }],
    ['unsupported_validation', copy => {
      copy.capabilities.find(item => item.entity === 'Tag').deletePreviewFields = { name: { type: 'expression' } }
    }],
    ['unsupported_validation', copy => {
      copy.capabilities.find(item => item.entity === 'Tag').deletePreviewFields = { name: { type: 'string', pattern: '(a+)+$' } }
    }],
    ['unsupported_validation', copy => {
      copy.capabilities.find(item => item.entity === 'Tag').deletePreviewField = { name: { type: 'string', maxLength: 255 } }
    }]
  ]
  for (const [code, mutate] of invalid) compileError(code, responseWith(mutate))

  const invalidDeleteOnly = [
    copy => {
      const tag = copy.capabilities.find(item => item.entity === 'Tag')
      tag.actions = ['delete']
      tag.actionFields = {}
    },
    copy => {
      const tag = copy.capabilities.find(item => item.entity === 'Tag')
      tag.actions = ['delete']
      tag.actionFields = { create: {} }
      tag.fields = {}
    },
    copy => {
      const tag = copy.capabilities.find(item => item.entity === 'Tag')
      tag.actions = ['create']
      tag.actionFields = { create: {} }
      tag.fields = {}
      delete tag.deletePreviewFields
    },
    copy => {
      const tag = copy.capabilities.find(item => item.entity === 'Tag')
      tag.actions = ['update', 'delete']
      tag.actionFields = { update: {} }
      tag.fields = {}
    },
    copy => {
      const tag = copy.capabilities.find(item => item.entity === 'Tag')
      tag.actions = ['delete', 'archive']
      tag.actionFields = {}
      tag.fields = {}
    }
  ]
  for (const mutate of invalidDeleteOnly) compileError('malformed', responseWith(mutate))
})

test('runtime exposes delete-only capability while withholding batch and rejecting writable delete changes', async() => {
  const response = deleteOnlyCatalog()
  response.batchCapabilities = []
  const deletePreview = canonicalTagDeletePreview()
  const calls = []
  const request = {
    async get(path) {
      calls.push(['get', path])
      if (path.endsWith('/capabilities')) return { data: response }
      return { data: deletePreview }
    },
    async post(path, body) {
      calls.push(['post', path, body])
      return { data: deletePreview }
    }
  }
  const runtime = createCapabilityCatalogRuntime({ request, compile: compileMutationCapabilities })
  const singleSource = {
    getCapabilities: () => runtime.current?.capabilities ?? [],
    getRevision: () => runtime.revision
  }
  const batchSource = {
    getCapabilities: () => runtime.current?.batchCapabilities ?? [],
    getRevision: () => runtime.revision
  }
  const single = createMutationHost(request, '/operations', singleSource)
  const batch = createBatchMutationHost(request, '/batch-operations', batchSource)
  await runtime.ensure()
  const capabilities = single.getWriteCapabilities()
  assert.equal(capabilities, runtime.current.capabilities)
  const tag = capabilities.find(item => item.entity === 'Tag')
  assert.deepEqual(tag.actions, ['delete'])
  assert.deepEqual(batch.getBatchCreateCapabilities(), [])
  const tools = createAssistantTools([], [], {}, capabilities, {}, batch.getBatchCreateCapabilities())
  assert.equal(tools.some(item => item.function.name === 'prepare_record_batch'), false)
  assert.ok(tools.find(item => item.function.name === 'prepare_record_change').function.parameters.properties.entity.enum.includes('Tag'))

  await single.prepareRecordChange({ entity: 'Tag', action: 'delete', identifier: '12' })
  assert.deepEqual(calls.find(([method]) => method === 'post')[2], { entity: 'Tag', action: 'delete', identifier: '12' })
  await assert.rejects(single.prepareRecordChange({ entity: 'Tag', action: 'delete', identifier: '12', changes: { name: 'Vegan' } }), /Changes contain unsupported/)
  const restored = await single.restoreRecordChange('a'.repeat(64))
  assert.equal(restored.action, 'delete')
  runtime.dispose()
})

test('the generic engine enforces action fields, bounds, nested schemas and paired values', () => {
  const capability = entity => compiledCatalog.capabilities.find(item => item.entity === entity)
  const counter = capability('Counter')
  assert.equal(counter.validateChanges({ storeUuid: UUID, code: 'C-1', name: 'Counter', capacity: null, enabled: true }, 'create'), true)
  assert.equal(counter.validateChanges({ storeUuid: UUID, code: 'C-1', name: 'Counter', capacity: -1 }, 'create'), false)
  assert.equal(counter.validateChanges({ name: 'Renamed' }, 'update'), true)
  assert.equal(counter.validateChanges({ name: 'Renamed', storeUuid: UUID }, 'update'), false)
  assert.equal(counter.validateChanges(undefined, 'delete'), true)
  assert.equal(counter.validateChanges({}, 'delete'), false)

  const store = capability('Store')
  assert.equal(store.validateChanges({ address: { latitude: 12, longitude: 34 } }, 'update'), true)
  assert.equal(store.validateChanges({ address: { latitude: 12 } }, 'update'), false)
  assert.equal(store.validateChanges({ address: { latitude: 12.5, longitude: 34, extra: true } }, 'update'), false)
  assert.equal(store.validateChanges({ contact: { managerName: 'A', tags: ['one', 'one'] } }, 'update'), false)
  assert.equal(store.validateChanges({ settings: { order: { requireAcceptance: false } } }, 'update'), false)

  const product = capability('Product')
  assert.equal(product.validateChanges({ name: ' Tea ' }, 'create'), false)
  assert.equal(product.validateChanges({ name: 'Tea', description: '<b>bold</b>' }, 'create'), false)
  assert.equal(product.validateChanges({ name: 'Tea', store: UUID }, 'create'), true)
  assert.equal(product.validateChanges({ name: 'Tea', store: 1 }, 'create'), false)
})

test('generic preview validation preserves required, immutable and redacted-before rules', () => {
  const tag = compiledCatalog.capabilities.find(item => item.entity === 'Tag')
  assert.equal(tag.validatePreview({
    entity: 'Tag', action: 'create', title: 'Vegan',
    differences: [{ property: 'name', before: null, after: 'Vegan' }, { property: 'slug', before: null, after: 'vegan' }]
  }), true)
  assert.equal(tag.validatePreview({
    entity: 'Tag', action: 'create', title: 'Vegan',
    differences: [{ property: 'name', before: null, after: 'Vegan' }]
  }), false)
  assert.equal(tag.validatePreview({
    entity: 'Tag', action: 'update', differences: [{ property: 'color', before: null, after: '#123456' }]
  }), true)
  assert.equal(tag.validatePreview({
    entity: 'Tag', action: 'delete', title: 'Vegan',
    differences: [{ property: 'name', before: 'Vegan', after: null }]
  }), true)

  const store = compiledCatalog.capabilities.find(item => item.entity === 'Store')
  assert.equal(store.validatePreview({
    entity: 'Store', action: 'update', title: 'Shop',
    differences: [{ property: 'contact', before: null, after: { managerName: 'Manager' } }]
  }), true)

  const specification = compiledCatalog.capabilities.find(item => item.entity === 'Specification')
  const valid = {
    entity: 'Specification', action: 'update', identifier: '4',
    title: '[Product #4; Specification #4; Store global] Large; Product #5 size',
    differences: [{ property: 'productId', before: 4, after: 4 }, { property: 'price', before: 100, after: 120 }]
  }
  assert.equal(specification.validatePreview(valid), true)
  assert.equal(specification.validatePreview({
    ...valid, differences: [{ property: 'productId', before: 4, after: 5 }, { property: 'price', before: 100, after: 120 }]
  }), false)
})

test('descriptor-driven scope and printing semantics reject previews that widen the exact target', () => {
  const product = compiledCatalog.capabilities.find(item => item.entity === 'Product')
  assert.equal(product.validatePreview({
    entity: 'Product', action: 'create', identifier: null, title: '[Product #new; Store global] Tea',
    differences: [{ property: 'name', before: null, after: 'Tea' }, { property: 'store', before: null, after: null }]
  }), true)
  assert.equal(product.validatePreview({
    entity: 'Product', action: 'create', identifier: null, title: '[Product #new; Store global] Tea',
    differences: [{ property: 'name', before: null, after: 'Tea' }, { property: 'store', before: null, after: UUID }]
  }), false)
  assert.equal(product.validatePreview({
    entity: 'Product', action: 'update', identifier: '5', title: '[Product #4; Store global] Tea',
    differences: [{ property: 'name', before: 'Tea', after: 'Tea blend' }]
  }), false)

  const storePrinting = compiledCatalog.capabilities.find(item => item.entity === 'StorePrinting')
  assert.equal(storePrinting.validatePreview({
    entity: 'StorePrinting', action: 'update', identifier: '12', title: 'Review future automatic printing',
    differences: [{ property: 'order', before: null, after: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 } }]
  }), true)
  assert.equal(storePrinting.validatePreview({
    entity: 'StorePrinting', action: 'update', identifier: '12', title: 'Review',
    differences: [{ property: 'order', before: null, after: { copies: 1 } }]
  }), false)
  assert.equal(storePrinting.validatePreview({
    entity: 'StorePrinting', action: 'update', identifier: '12', title: 'Review',
    differences: [{ property: 'order', before: null, after: { enabled: true, deviceUuid: null, templateUuid: UUID, copies: 1 } }]
  }), false)

  const productPrinting = compiledCatalog.capabilities.find(item => item.entity === 'ProductPrinting')
  assert.equal(productPrinting.validatePreview({
    entity: 'ProductPrinting', action: 'update', identifier: UUID, title: 'Review kitchen printing',
    differences: [{ property: 'kitchenPrinterEnable', before: null, after: true }]
  }), true)
  assert.equal(productPrinting.validatePreview({
    entity: 'ProductPrinting', action: 'update', identifier: UUID, title: 'Review',
    differences: [{ property: 'kitchenPrinterEnable', before: null, after: null }]
  }), false)
})

test('invalid envelopes, unknown keywords, unsafe patterns and prototype keys fail closed', () => {
  compileError('malformed', responseWith(copy => { delete copy.revision }))
  compileError('malformed', responseWith(copy => { copy.revision = 'abc' }))
  compileError('malformed', responseWith(copy => { copy.extra = true }))
  compileError('malformed', responseWith(copy => { copy.capabilities.push(clone(copy.capabilities[0])) }))
  compileError('malformed', responseWith(copy => { copy.batchCapabilities.push({ ...clone(copy.capabilities.find(item => item.entity === 'Tag')), entity: 'Store', actions: ['create'], actionFields: { create: {} }, fields: {} }) }))
  compileError('unsupported_schema_version', responseWith(copy => { copy.schemaVersion = 2 }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].unknown = true }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].fields.title.type = 'expression' }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].fields.title.expression = 'entity.x == 1' }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].fields.title.format = 'html' }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].fields.title.pattern = '(a+)+$' }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].fields.title.pattern = '(?<=x)y' }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].fields.title.pattern = '\\1' }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].fields.title.pattern = 'a'.repeat(201) }))
  compileError('unsupported_validation', responseWith(copy => { copy.capabilities[0].fields.title.arbitraryFlag = true }))
  compileError('malformed', responseWith(copy => {
    copy.capabilities[0].fields = JSON.parse('{"title":{"type":"string","__proto__":{"polluted":true}}}')
  }))
  compileError('malformed', responseWith(copy => {
    copy.capabilities[0].actionFields.create = JSON.parse('{"__proto__":{"type":"string"}}')
  }))
  assert.equal(Object.prototype.polluted, undefined)
})

test('regexp validation rejects adversarial patterns in workers and fails the whole catalog closed', async() => {
  const adversarial = [
    '^a+a+a+a+a+a+a+a+a+a+$',
    '^a{2,3}a{2,3}a{2,3}$',
    '^a|b$',
    '^(a|aa)+$',
    '^(a+)(a+)(a+)(a+)(a+)(a+)(a+)(a+)(a+)(a+)$',
    '^((a+)|(b+))$',
    '^(ab)$',
    '^a(b|c)d$',
    '^((a+)(a+))$',
    '^.*.*$',
    '^a+?$',
    '^[a-z]+[0-9]+$',
    '^a{2,1}$'
  ]
  for (const pattern of adversarial) {
    const result = await runPatternInWorker(pattern)
    assert.equal(result.ok, false, pattern)
    assert.equal(result.code, 'unsupported_validation', pattern)
  }
  for (const pattern of [
    '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
    '^(\\+?[0-9\\- ]{7,20}|1[3-9][0-9]{9})$',
    '^[0-9A-Za-z\\- ]{3,20}$',
    '^[0-9bcdefghjkmnpqrstuvwxyz]{1,12}$',
    '^a?b{2,3}c$'
  ]) {
    assert.doesNotThrow(() => assertSafePattern(pattern), pattern)
  }
  compileError('unsupported_validation', responseWith(copy => {
    const tagName = copy.capabilities.find(item => item.entity === 'Tag').actionFields.create.name
    tagName.pattern = '^a+a+a+a+a+a+a+a+a+a+$'
  }))
})

test('type-specific rule operands are required at catalog validation', () => {
  const invalid = [
    copy => { delete copy.capabilities.find(item => item.entity === 'ProductCategory').actionFields.create.parent.anyOf },
    copy => { delete copy.capabilities.find(item => item.entity === 'Product').actionFields.create.status.values },
    copy => { delete copy.capabilities.find(item => item.entity === 'Store').actionFields.update.contact.properties },
    copy => { delete copy.capabilities.find(item => item.entity === 'Content').actionFields.create.tags.maxItems },
    copy => { delete copy.capabilities.find(item => item.entity === 'Store').actionFields.update.contact.properties.tags.minItemLength },
    copy => { delete copy.capabilities.find(item => item.entity === 'Counter').actionFields.create.sort.minimum },
    copy => { delete copy.capabilities.find(item => item.entity === 'Counter').actionFields.create.capacity.maximum },
    copy => { delete copy.capabilities.find(item => item.entity === 'Content').actionFields.create.body.maxBytes },
    copy => { delete copy.capabilities.find(item => item.entity === 'Content').actionFields.create.title.maxLength },
    copy => { delete copy.capabilities.find(item => item.entity === 'Counter').actionFields.create.storeUuid.pattern },
    copy => { copy.capabilities.find(item => item.entity === 'Counter').actionFields.create.storeUuid.patternFlags = 'g' },
    copy => { delete copy.capabilities.find(item => item.entity === 'ProductCategory').actionFields.create.parent.anyOf[1].minimum }
  ]
  for (const mutate of invalid) compileError('unsupported_validation', responseWith(mutate))
})

test('runtime fetches once per session, refreshes revisions, and never shares cache between instances', async() => {
  const responses = [catalogResponse, { ...clone(catalogResponse), revision: REVISION }]
  let calls = 0
  const request = {
    async get(path, options) {
      calls += 1
      assert.equal(path, '/api/v1/manage/agent/capabilities')
      assert.equal(options.headers['Cache-Control'], 'no-cache')
      return { data: responses[Math.min(calls - 1, responses.length - 1)] }
    }
  }
  const runtime = createCapabilityCatalogRuntime({
    request,
    compile: response => compileCapabilityCatalogResponse(response, { previewSemantics, changeSemantics })
  })
  const events = []
  runtime.subscribe(event => events.push(event))
  assert.equal(runtime.current, null)
  assert.equal(runtime.status.available, false)
  const first = await runtime.ensure()
  assert.equal(calls, 1)
  assert.equal(first.revision, catalogResponse.revision)
  assert.equal(first.capabilities.length, 10)
  assert.equal(first.batchCapabilities.length, 2)
  assert.deepEqual(events.map(event => event.revision), [catalogResponse.revision])
  await runtime.ensure()
  assert.equal(calls, 1)
  const refreshed = await runtime.refresh()
  assert.equal(calls, 2)
  assert.equal(refreshed.revision, REVISION)
  assert.deepEqual(events.map(event => event.revision), [catalogResponse.revision, REVISION])
  assert.equal(events[1].previousRevision, catalogResponse.revision)

  const otherCalls = { count: 0 }
  const other = createCapabilityCatalogRuntime({
    request: { async get() { otherCalls.count += 1; return { data: catalogResponse } } },
    compile: response => compileMutationCapabilities(response)
  })
  assert.equal(other.current, null)
  await other.ensure()
  assert.equal(otherCalls.count, 1)
  runtime.dispose()
  other.dispose()
})

test('runtime failures clear the prior snapshot and late results after disposal are suppressed', async() => {
  let response = catalogResponse
  let resolveLate = null
  const request = {
    async get() {
      if (response === 'late') return new Promise(resolve => { resolveLate = resolve })
      return { data: response }
    }
  }
  const runtime = createCapabilityCatalogRuntime({
    request,
    compile: response => compileMutationCapabilities(response)
  })
  const events = []
  runtime.subscribe(event => events.push(event))
  await runtime.ensure()
  const loaded = runtime.current
  assert.ok(loaded)
  response = { ...clone(catalogResponse), schemaVersion: 2 }
  await assert.rejects(runtime.refresh(), error => error.code === 'unsupported_schema_version')
  assert.equal(runtime.current, null)
  assert.equal(runtime.status.available, false)
  assert.equal(runtime.status.error, 'unsupported_schema_version')
  assert.equal(events.at(-1).error, 'unsupported_schema_version')

  response = 'late'
  const pending = runtime.ensure({ force: true })
  runtime.dispose()
  resolveLate({ data: catalogResponse })
  await assert.rejects(pending, error => error.code === 'disposed' || error.name === 'AbortError')
  assert.equal(runtime.current, null)
  assert.equal(runtime.status.error, 'disposed')
})

test('single and batch transports only use the initialized snapshot and reject stale preparation', async() => {
  const responses = [catalogResponse, { ...clone(catalogResponse), revision: REVISION }]
  let getCalls = 0
  let resolvePost = null
  const request = {
    async get(path) {
      assert.equal(path, '/api/v1/manage/agent/capabilities')
      getCalls += 1
      return { data: responses[Math.min(getCalls - 1, responses.length - 1)] }
    },
    post() {
      return new Promise(resolve => { resolvePost = resolve })
    }
  }
  const runtime = createCapabilityCatalogRuntime({ request, compile: response => compileMutationCapabilities(response) })
  const singleSource = {
    getCapabilities: () => runtime.current?.capabilities ?? [],
    getRevision: () => runtime.revision
  }
  const batchSource = {
    getCapabilities: () => runtime.current?.batchCapabilities ?? [],
    getRevision: () => runtime.revision
  }
  const single = createMutationHost(request, '/operations', singleSource)
  const batch = createBatchMutationHost(request, '/batch-operations', batchSource)
  assert.deepEqual(single.getWriteCapabilities(), [])
  assert.deepEqual(batch.getBatchCreateCapabilities(), [])
  await assert.rejects(single.prepareRecordChange({ entity: 'Tag', action: 'create', changes: { name: 'A', slug: 'a' } }), /not enabled/)

  await runtime.ensure()
  assert.equal(single.getWriteCapabilities(), runtime.current.capabilities)
  assert.equal(batch.getBatchCreateCapabilities()[0], runtime.current.batchCapabilities.find(item => item.entity === 'Tag'))
  const prepare = single.prepareRecordChange({ entity: 'Tag', action: 'create', changes: { name: 'A', slug: 'a' } })
  await runtime.refresh()
  resolvePost({ data: { saved: false } })
  await assert.rejects(prepare, error => error.code === 'capabilities_changed')
  runtime.dispose()
})

test('the runtime validates the response envelope without accepting unknown versions', () => {
  assert.equal(validateCapabilityCatalogResponse(catalogResponse).schemaVersion, 1)
  const empty = compileMutationCapabilities({ schemaVersion: 1, revision: REVISION, capabilities: [], batchCapabilities: [] })
  assert.deepEqual(empty.capabilities, [])
  assert.deepEqual(empty.batchCapabilities, [])
  assert.throws(() => validateCapabilityCatalogResponse({ foo: true }), error => error.code === 'malformed')
  assert.throws(() => validateCapabilityCatalogResponse(null), error => error.code === 'malformed')
})

test('batch outcome reads settle after the runtime catalog is revoked', async() => {
  const BATCH_PATH = '/api/v1/manage/agent/batch-operations'
  const CATALOG_PATH = '/api/v1/manage/agent/capabilities'
  const OPERATION_ID = 'a'.repeat(64)
  const createdAt = '2026-10-23T12:00:00+00:00'
  const expiresAt = '2026-10-23T12:05:00+00:00'
  const completedAt = '2026-10-23T12:01:00+00:00'
  const preview = {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    title: 'Create 1 Tag record',
    affectedRecords: 1,
    expiresAt,
    rows: [{
      row: 1,
      title: 'North',
      identifier: null,
      differences: [
        { property: 'name', before: null, after: 'North' },
        { property: 'slug', before: null, after: 'north' }
      ]
    }],
    saved: false
  }
  const succeeded = {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    status: 'succeeded',
    rowCount: 1,
    fields: ['name', 'slug'],
    results: [{ row: 1, identifier: '41' }],
    createdAt,
    expiresAt,
    completedAt
  }
  const cancelled = { ...succeeded, status: 'cancelled', results: [], completedAt }
  const catalogResponses = [
    catalogResponse,
    { schemaVersion: 1, revision: 'b'.repeat(64), capabilities: [], batchCapabilities: [] }
  ]
  let catalogCalls = 0
  const request = {
    async get(path) {
      if (path === CATALOG_PATH) return { data: catalogResponses[Math.min(catalogCalls++, 1)] }
      if (path === BATCH_PATH) return { data: { items: [succeeded], page: 1, hasMore: false } }
      if (path.endsWith('/preview')) return { data: preview }
      return { data: succeeded }
    },
    async post(path) {
      if (path === BATCH_PATH) return { data: preview }
      if (path.endsWith('/commit')) return { data: { ...succeeded, verified: true } }
      return { data: cancelled }
    }
  }
  const runtime = createCapabilityCatalogRuntime({
    request,
    path: CATALOG_PATH,
    compile: response => compileMutationCapabilities(response)
  })
  const batchSource = {
    getCapabilities: () => runtime.current?.batchCapabilities ?? [],
    getRevision: () => runtime.revision
  }
  const host = createBatchMutationHost(request, BATCH_PATH, batchSource)
  await runtime.ensure()
  await host.prepareRecordBatch({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  await runtime.refresh()
  assert.deepEqual(host.getBatchCreateCapabilities(), [])

  // Outcome/read/cancel paths remain usable after the write catalog is empty.
  assert.deepEqual(await host.getRecordBatchStatus(OPERATION_ID), succeeded)
  assert.deepEqual(await host.cancelRecordBatch(OPERATION_ID), cancelled)
  assert.deepEqual(await host.listRecordBatches(1), { items: [succeeded], page: 1, hasMore: false })

  // Preview restore and new commit preparation remain current-authority gated.
  await assert.rejects(host.restoreRecordBatch(OPERATION_ID), /not enabled/)
  await assert.rejects(host.commitRecordBatch(OPERATION_ID), /not enabled/)
  runtime.dispose()
})
