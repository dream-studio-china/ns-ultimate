import assert from 'node:assert/strict'
import test from 'node:test'
import { buildCapabilityCatalog } from '../src/capabilities.js'
import { createAssistantTools, executeAssistantTool } from '../src/tools.js'
import recordInspectionPolicy from '../adapters/ns-ultimate/admin/inspection-policy.mjs'
import { createRecordInspection } from '../adapters/ns-ultimate/admin/inspection.mjs'

const STORE_UUID = '123e4567-e89b-4123-a456-426614174000'
const DEVICE_UUID = '123e4567-e89b-4123-a456-426614174001'
const TEMPLATE_UUID = '123e4567-e89b-4123-a456-426614174002'

const catalog = buildCapabilityCatalog({
  Store: {
    entity: { name: 'Store', plural: 'stores' },
    list: { list_display: ['id', 'code'], list_filter: { code: 'Code' } }
  },
  Product: {
    list: { list_display: ['id', 'name'], list_filter: { name: 'Name' } }
  }
}, [
  { method: 'GET', path: '/api/v1/manage/stores' },
  { method: 'GET', path: '/api/v1/manage/stores/{id}' },
  { method: 'GET', path: '/api/v1/manage/products' },
  { method: 'GET', path: '/api/v1/manage/products/{id}' }
], { parameterize: value => `${value.toLowerCase()}s` })

const operations = [
  { method: 'GET', path: '/api/v1/manage/stores' },
  { method: 'GET', path: '/api/v1/manage/stores/{id}' },
  { method: 'GET', path: '/api/v1/manage/products' },
  { method: 'GET', path: '/api/v1/manage/products/{id}' }
]

const SENSITIVE_PATH = /password|secret|token|credential|private.?key|certificate|api.?key|openid|authorization|email|phone|mobile|address|contact|metadata|payment|pay/i

test('host policy covers exactly Store printing configuration and the Product kitchen-print flag', () => {
  assert.deepEqual(Object.keys(recordInspectionPolicy), ['Store', 'Product'])
  const cases = ['order', 'perItem', 'filteredList', 'fullList', 'settlement']
  const expectedStore = ['code', 'name', 'status', 'settings.order.requireAcceptance',
    ...cases.flatMap(caseKey => ['enabled', 'deviceUuid', 'templateUuid', 'copies'].map(field => `settings.printing.${caseKey}.${field}`))]
  assert.deepEqual(recordInspectionPolicy.Store.paths, expectedStore)
  assert.deepEqual(recordInspectionPolicy.Product.paths, ['name', 'status', 'settings.kitchenPrinterEnable'])
  for (const [entity, entry] of Object.entries(recordInspectionPolicy)) {
    assert.ok(Array.isArray(entry.paths) && entry.paths.length > 0, entity)
    for (const path of entry.paths) {
      assert.match(path, /^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)*$/)
      assert.ok(!SENSITIVE_PATH.test(path), `sensitive path in policy: ${path}`)
      assert.ok(!/(?:^|\.)(?:__proto__|prototype|constructor)(?:\.|$)/.test(path), `unsafe path in policy: ${path}`)
    }
  }
})

function storeRecord() {
  return {
    id: 3,
    uuid: STORE_UUID,
    code: 'S-3',
    name: 'Flagship',
    status: 'activate',
    contact: { phone: '+86-138-0000-0000' },
    settings: {
      paymentRate: 0.006,
      order: { requireAcceptance: true },
      printing: {
        order: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 2 },
        perItem: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 },
        filteredList: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 1 },
        fullList: { enabled: false },
        settlement: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 1 }
      },
      credentials: { apiKey: 's3cr3t' }
    },
    metadata: { internal: 'snapshot' }
  }
}

function makeHost(record, { available = true } = {}) {
  const calls = []
  const request = {
    get: async(path, options) => {
      calls.push([path, options])
      return { data: { data: record, code: 0, message: 'SUCCESS' } }
    }
  }
  const inspect = createRecordInspection({
    request,
    getCatalog: () => catalog,
    isRecordPageAvailable: () => available
  })
  return { inspect, calls }
}

test('inspects Store printing configuration without leaking secrets, blobs, or snapshots', async() => {
  const { inspect, calls } = makeHost(storeRecord())
  const result = await inspect({ entity: 'Store', identifier: STORE_UUID }, operations)
  assert.equal(result.entity, 'Store')
  assert.equal(result.identifier, STORE_UUID)
  assert.deepEqual(result.configuration, {
    code: 'S-3',
    name: 'Flagship',
    status: 'activate',
    settings: {
      order: { requireAcceptance: true },
      printing: {
        order: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 2 },
        perItem: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 },
        filteredList: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 1 },
        fullList: { enabled: false },
        settlement: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 1 }
      }
    }
  })
  assert.ok(Number.isSafeInteger(result.omitted))
  assert.ok(Number.isFinite(result.query.durationMs))
  assert.ok(Number.isFinite(Date.parse(result.query.completedAt)))
  assert.deepEqual(calls.map(call => call[0]), [`/api/v1/manage/stores/${STORE_UUID}`])
  const serialized = JSON.stringify(result)
  assert.ok(!serialized.includes('s3cr3t'))
  assert.ok(!serialized.includes('+86-138-0000-0000'))
  assert.ok(!serialized.includes('paymentRate'))
  assert.ok(!serialized.includes('snapshot'))
  assert.ok(serialized.length <= 8000)
})

test('inspects only the Product kitchen-print flag, not sibling settings', async() => {
  const { inspect } = makeHost({
    id: 7,
    name: 'Noodles',
    status: 'active',
    settings: { kitchenPrinterEnable: true, startSaleTime: '09:30', endSaleTime: '21:45' }
  })
  const result = await inspect({ entity: 'Product', identifier: '7' }, operations)
  assert.deepEqual(result.configuration, {
    name: 'Noodles',
    status: 'active',
    settings: { kitchenPrinterEnable: true }
  })
  assert.ok(!JSON.stringify(result).includes('09:30'))
})

test('adapter rejects unknown entities, bad ids, unavailable pages, and malformed data before widening access', async() => {
  const { inspect, calls } = makeHost(storeRecord())
  await assert.rejects(inspect({ entity: 'PrinterDevice', identifier: '1' }, operations), /no approved configuration inspection/)
  await assert.rejects(inspect({ entity: 'Store', identifier: 'not-an-id' }, operations), /positive numeric ID or UUID/)
  await assert.rejects(inspect({ entity: 'Store', identifier: '1', url: 'https://example.test/1' }, operations), /unsupported parameters/)
  await assert.rejects(inspect({ entity: 'Store', identifier: '1' }, [{ method: 'GET', path: '/api/v1/manage/stores' }]), /documented detail endpoint/)
  assert.equal(calls.length, 0)

  const unavailable = makeHost(storeRecord(), { available: false })
  await assert.rejects(unavailable.inspect({ entity: 'Store', identifier: '1' }, operations), /not available to the current account/)
  assert.equal(unavailable.calls.length, 0)

  const malformed = makeHost({ data: [{ id: 1 }], code: 0 })
  await assert.rejects(malformed.inspect({ entity: 'Store', identifier: '1' }, operations), /unsupported record response/)

  const atLimit = 'x'.repeat(120)
  // Fill max-size leaves across the fixed policy paths so the bounded
  // projection still exceeds the size limit without any silent truncation.
  const sizeLimitRecord = {
    id: 9,
    uuid: '123e4567-e89b-4123-a456-426614174009',
    code: atLimit,
    name: atLimit,
    status: atLimit,
    settings: {
      order: { requireAcceptance: true },
      printing: Object.fromEntries(['order', 'perItem', 'filteredList', 'fullList', 'settlement'].map(caseKey => [
        caseKey,
        { enabled: true, deviceUuid: atLimit, templateUuid: atLimit, copies: 1 }
      ]))
    }
  }
  // Sanity check the fixture really exceeds the limit via max-size leaves.
  const oversized = makeHost(sizeLimitRecord)
  await assert.rejects(oversized.inspect({ entity: 'Store', identifier: '9' }, operations), /size limit/)

  const aborted = new AbortController()
  aborted.abort()
  await assert.rejects(inspect({ entity: 'Store', identifier: '1' }, operations, aborted.signal), /cancelled/)
})

test('never presents silently truncated values as complete', async() => {
  const oversized = 'x'.repeat(5000)
  const { inspect } = makeHost({
    id: 3,
    uuid: STORE_UUID,
    code: oversized,
    name: 'Flagship',
    status: 'activate',
    settings: {
      order: { requireAcceptance: true },
      printing: {
        order: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 2 },
        perItem: { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 },
        filteredList: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 1 },
        fullList: { enabled: false },
        settlement: { enabled: true, deviceUuid: DEVICE_UUID, templateUuid: TEMPLATE_UUID, copies: 1 }
      }
    }
  })
  const result = await inspect({ entity: 'Store', identifier: STORE_UUID }, operations)
  assert.equal(result.truncated, false)
  assert.ok(!('code' in result.configuration))
  assert.ok(result.omitted >= 1)
  assert.ok(!JSON.stringify(result.configuration).includes('xxx'))
})

test('refuses records whose identity does not match the requested identifier', async() => {
  const record = storeRecord()
  const otherUuid = '123e4567-e89b-4123-a456-426614174099'
  const mismatchedId = makeHost({ ...record })
  await assert.rejects(mismatchedId.inspect({ entity: 'Store', identifier: '999' }, operations), /mismatched/)
  const mismatchedUuid = makeHost({ ...record })
  await assert.rejects(mismatchedUuid.inspect({ entity: 'Store', identifier: otherUuid }, operations), /mismatched/)
  const malformedIdentity = makeHost({ code: 'S-3', name: 'Flagship' })
  await assert.rejects(malformedIdentity.inspect({ entity: 'Store', identifier: '3' }, operations), /mismatched|unsupported/)
  // Numeric backend ids returned as numbers still match a numeric request.
  const numericMatch = makeHost({ id: 7, name: 'Noodles', status: 'active', settings: { kitchenPrinterEnable: true } })
  const matched = await numericMatch.inspect({ entity: 'Product', identifier: '7' }, operations)
  assert.equal(matched.identifier, '7')
})

test('generic tool exposes inspection only for catalog-readable policy entities', () => {
  assert.ok(!createAssistantTools(catalog).some(tool => tool.function.name === 'inspect_record_configuration'))
  const tools = createAssistantTools(catalog, [], {}, [], { Store: { paths: [] }, Unknown: { paths: [] } })
  const inspectTool = tools.find(tool => tool.function.name === 'inspect_record_configuration')
  assert.deepEqual(inspectTool.function.parameters.properties.entity.enum, ['Store'])
  assert.deepEqual(inspectTool.function.parameters.required, ['entity', 'identifier'])
  assert.equal(inspectTool.function.parameters.additionalProperties, false)
  assert.ok(!/create|update|delete|http|fetch/i.test(inspectTool.function.name))
})

test('generic tool execution delegates to the host and maps failures without leaking details', async() => {
  const host = { inspectRecordConfiguration: async input => ({ entity: 'Store', identifier: input.identifier, configuration: { code: 'S-1' }, query: {} }) }
  const call = { type: 'function', function: { name: 'inspect_record_configuration', arguments: JSON.stringify({ entity: 'Store', identifier: '1' }) } }
  const result = await executeAssistantTool({ call, catalog, host, operations })
  assert.deepEqual(result.configuration, { code: 'S-1' })

  assert.deepEqual(
    await executeAssistantTool({ call, catalog, host: {}, operations }),
    { error: 'Configuration inspection is unavailable.' }
  )
  assert.match((await executeAssistantTool({
    call,
    catalog,
    host: { inspectRecordConfiguration: async() => { throw new Error('Configuration inspection timed out after 30 seconds.') } },
    operations
  })).error, /timed out/)
  assert.match((await executeAssistantTool({
    call,
    catalog,
    host: { inspectRecordConfiguration: async() => { throw new Error('Configuration inspection failed (403): forbidden') } },
    operations
  })).error, /not authorized/)
  const aborted = new AbortController()
  aborted.abort()
  assert.deepEqual(
    await executeAssistantTool({ call, catalog, host, operations, signal: aborted.signal }),
    { error: 'The configuration inspection was cancelled.', failureType: 'cancelled' }
  )
  assert.deepEqual(
    await executeAssistantTool({
      call,
      catalog,
      host: { inspectRecordConfiguration: async() => ({ entity: 'Store', configuration: null }) },
      operations
    }),
    { error: 'The configuration inspection returned an unsupported response.', failureType: 'invalid-response' }
  )
})
