import assert from 'node:assert/strict'
import test from 'node:test'
import { buildCapabilityCatalog } from '../src/capabilities.js'
import {
  assertDetailIdentity,
  fetchInspectedRecord,
  prepareRecordInspection,
  projectInspectedConfiguration,
  unwrapDetailPayload,
  validateInspectionIdentifier
} from '../src/inspect.js'

const STORE_UUID = '123e4567-e89b-4123-a456-426614174000'
const DEVICE_UUID = '123e4567-e89b-4123-a456-426614174001'

const catalog = buildCapabilityCatalog({
  Store: {
    entity: { name: 'Store', plural: 'stores' },
    list: { list_display: ['id', 'code'], list_filter: { code: 'Code' } }
  },
  Product: {
    list: { list_display: ['id', 'name'], list_filter: { name: 'Name' } }
  },
  Counter: {
    entity: { name: 'Counter', plural: 'counters' },
    list: { list_display: ['id', 'name'], list_filter: { name: 'Name' } }
  }
}, [
  { method: 'GET', path: '/api/v1/manage/stores' },
  { method: 'GET', path: '/api/v1/manage/stores/{id}' },
  { method: 'GET', path: '/api/v1/manage/products' },
  { method: 'GET', path: '/api/v1/manage/products/{id}' },
  { method: 'GET', path: '/api/v1/manage/counters' }
], { parameterize: value => `${value.toLowerCase()}s` })

const policy = {
  Store: { paths: ['code', 'settings.printing.order.enabled', 'settings.printing.order.deviceUuid'] },
  Product: { paths: ['name', 'settings.kitchenPrinterEnable'] }
}

test('accepts exact safe numeric IDs and UUIDs, rejects everything else', () => {
  assert.equal(validateInspectionIdentifier('12'), '12')
  assert.equal(validateInspectionIdentifier(7), '7')
  assert.equal(validateInspectionIdentifier(STORE_UUID), STORE_UUID)
  for (const bad of ['', '0', '-3', '1.5', '12abc', '../../etc', 'https://example.test/1', 'x'.repeat(40), null, undefined, {}, ['1'], 1.5, NaN]) {
    assert.throws(() => validateInspectionIdentifier(bad), /positive numeric ID or UUID/)
  }
})

test('resolves the fixed GET detail endpoint and rejects unknown entities, ids, and extra arguments', () => {
  assert.deepEqual(prepareRecordInspection(
    { entity: 'Store', identifier: STORE_UUID },
    { catalog, policy, operations: [{ method: 'GET', path: '/api/v1/manage/stores' }, { method: 'GET', path: '/api/v1/manage/stores/{id}' }] }
  ), { key: 'Store', name: 'Store', path: `/api/v1/manage/stores/${STORE_UUID}`, identifier: STORE_UUID })
  const numeric = prepareRecordInspection({ entity: 'Product', identifier: '7' }, {
    catalog,
    policy,
    operations: [{ method: 'GET', path: '/api/v1/manage/products' }, { method: 'GET', path: '/api/v1/manage/products/{id}' }]
  })
  assert.equal(numeric.path, '/api/v1/manage/products/7')

  assert.throws(() => prepareRecordInspection({ entity: 'Printer', identifier: '1' }, { catalog, policy, operations: [] }), /no approved configuration inspection/)
  assert.throws(() => prepareRecordInspection({ entity: '__proto__', identifier: '1' }, { catalog, policy, operations: [] }), /no approved configuration inspection/)
  assert.throws(() => prepareRecordInspection({ entity: 'Store', identifier: '../1' }, { catalog, policy, operations: [] }), /positive numeric ID or UUID/)
  assert.throws(() => prepareRecordInspection({ entity: 'Store', identifier: '1', url: 'https://example.test' }, { catalog, policy, operations: [] }), /unsupported parameters/)
  assert.throws(() => prepareRecordInspection({ entity: 'Store', identifier: '1', fields: ['code'] }, { catalog, policy, operations: [] }), /unsupported parameters/)
  assert.throws(() => prepareRecordInspection({ entity: 'Store', identifier: '1', paths: ['settings'] }, { catalog, policy, operations: [] }), /unsupported parameters/)
})

test('requires both the documented collection and the GET detail route', () => {
  const collectionOnly = [{ method: 'GET', path: '/api/v1/manage/stores' }]
  assert.throws(() => prepareRecordInspection({ entity: 'Store', identifier: '1' }, { catalog, policy, operations: collectionOnly }), /no approved, documented detail endpoint/)
  const postOnly = [
    { method: 'GET', path: '/api/v1/manage/stores' },
    { method: 'POST', path: '/api/v1/manage/stores/{id}' }
  ]
  assert.throws(() => prepareRecordInspection({ entity: 'Store', identifier: '1' }, { catalog, policy, operations: postOnly }), /no approved, documented detail endpoint/)
  // Counter is readable but has no detail route and no policy entry either way.
  assert.throws(() => prepareRecordInspection({ entity: 'Counter', identifier: '1' }, { catalog, policy, operations: postOnly }), /no approved configuration inspection/)
  assert.throws(() => prepareRecordInspection({ entity: 'Store', identifier: '1' }, { catalog, policy, operations: null }), /no approved, documented detail endpoint/)
})

test('projects only allowlisted scalar leaves and counts omissions', () => {
  const record = {
    id: 3,
    code: 'S-3',
    settings: { printing: { order: { enabled: true, deviceUuid: DEVICE_UUID, copies: 99 } } },
    credentials: 's3cr3t'
  }
  const { configuration, omitted } = projectInspectedConfiguration(record, policy.Store.paths)
  assert.deepEqual(configuration, {
    code: 'S-3',
    settings: { printing: { order: { enabled: true, deviceUuid: DEVICE_UUID } } }
  })
  // copies is not in this policy and must stay out; the assertion above proves it.
  assert.equal(omitted, 0)
  assert.ok(!JSON.stringify(configuration).includes('s3cr3t'))
})

test('omits missing, null-container, and non-scalar leaves without failing the record', () => {
  const record = { code: null, settings: null, name: ['not', 'a', 'scalar'] }
  const { configuration, omitted } = projectInspectedConfiguration(record, ['code', 'settings.printing.order.enabled', 'name'])
  assert.deepEqual(configuration, { code: null })
  assert.equal(omitted, 2)

  const blob = { settings: { printing: { order: { enabled: { nested: true } } } } }
  const projected = projectInspectedConfiguration(blob, ['settings.printing.order.enabled'])
  assert.deepEqual(projected, { configuration: {}, omitted: 1 })
})

test('rejects prototype-polluting policy paths and never pollutes from record data', () => {
  for (const path of ['__proto__.x', 'a.prototype.b', 'constructor.x', 'a..b', 'a'.repeat(0)]) {
    assert.throws(() => projectInspectedConfiguration({}, [path]), /allowlisted|bounds/)
  }
  assert.throws(() => projectInspectedConfiguration({}, []), /no readable configuration paths/)
  assert.throws(() => projectInspectedConfiguration(null, ['code']), /unsupported record response/)
  assert.throws(() => projectInspectedConfiguration([1], ['code']), /unsupported record response/)

  const hostile = JSON.parse('{"code":"ok","settings":{"__proto__":{"polluted":true},"printing":{"order":{"enabled":true}}}}')
  const { configuration } = projectInspectedConfiguration(hostile, ['code', 'settings.printing.order.enabled'])
  assert.deepEqual(configuration, { code: 'ok', settings: { printing: { order: { enabled: true } } } })
  assert.equal({}.polluted, undefined)
})

test('omits oversized strings instead of silently truncating them', () => {
  const oversized = 'x'.repeat(121)
  const atLimit = 'y'.repeat(120)
  const { configuration, omitted } = projectInspectedConfiguration(
    { id: 1, code: oversized, name: atLimit },
    ['code', 'name']
  )
  assert.deepEqual(configuration, { name: atLimit })
  assert.equal(omitted, 1)
  assert.ok(!JSON.stringify(configuration).includes('x'))
})

test('rejects obviously sensitive paths even when erroneously configured', () => {
  const record = { id: 1, password: 's3cr3t', settings: { token: 'abc' }, code: 'ok' }
  for (const path of [
    'password',
    'secret',
    'settings.token',
    'credentials',
    'settings.apiKey',
    'email',
    'phone',
    'contact',
    'settings.contact',
    'metadata',
    'paymentRate',
    'settings.pay',
    'authorization'
  ]) {
    assert.throws(() => projectInspectedConfiguration(record, [path]), /not allowlisted/)
  }
  // The approved non-sensitive leaves still project.
  assert.deepEqual(
    projectInspectedConfiguration(record, ['code']),
    { configuration: { code: 'ok' }, omitted: 0 }
  )
})

test('asserts returned identity matches the requested identifier', () => {
  assertDetailIdentity({ id: 7 }, '7')
  assertDetailIdentity({ id: '7' }, 7)
  assertDetailIdentity({ id: 3, uuid: STORE_UUID }, STORE_UUID)
  assertDetailIdentity({ uuid: STORE_UUID.toUpperCase() }, STORE_UUID)
  assertDetailIdentity({ id: 3, uuid: STORE_UUID }, '3')
  for (const bad of [
    [{ id: 2 }, '1'],
    [{ id: '2' }, '1'],
    [{ uuid: DEVICE_UUID }, STORE_UUID],
    [{ id: 3, uuid: DEVICE_UUID }, STORE_UUID],
    [{ code: 'no-identity' }, '1'],
    [{ id: 0 }, '1'],
    [{ id: -5 }, '1'],
    [{ id: {} }, '1'],
    [{ uuid: 'not-a-uuid' }, STORE_UUID]
  ]) {
    assert.throws(() => assertDetailIdentity(bad[0], bad[1]), /mismatched/)
  }
})
test('fails closed on oversized projections', () => {
  const paths = Array.from({ length: 20 }, (_, index) => `field${index}`)
  const record = Object.fromEntries(paths.map(path => [path, 'x'.repeat(120)]))
  assert.throws(() => projectInspectedConfiguration(record, paths), /size limit/)
})

test('unwraps the admin detail envelope but never a collection or a record data key', () => {
  const record = { id: 9, uuid: STORE_UUID, code: 'S-9' }
  assert.deepEqual(unwrapDetailPayload({ data: record, code: 0, message: 'SUCCESS' }), record)
  assert.deepEqual(unwrapDetailPayload(record), record)
  const withDataKey = { id: 5, data: { nested: true } }
  assert.deepEqual(unwrapDetailPayload(withDataKey), withDataKey)
  for (const bad of [null, [], { data: [{ id: 1 }] }, { data: null }, 'record', { code: 0 }]) {
    assert.throws(() => unwrapDetailPayload(bad), /unsupported record response/)
  }
})

test('fetches with GET only, cancellation, and timeout', async() => {
  const calls = []
  const request = { get: async(path, options) => { calls.push([path, options]); return { data: { id: 1 } } } }
  const body = await fetchInspectedRecord(request, '/api/v1/manage/products/7', {})
  assert.deepEqual(body, { id: 1 })
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], '/api/v1/manage/products/7')
  assert.equal(typeof request.post, 'undefined')
  assert.equal(calls[0][1].timeout, 30000)

  const preAborted = new AbortController()
  preAborted.abort()
  await assert.rejects(fetchInspectedRecord(request, '/x', { signal: preAborted.signal }), /cancelled/)
  assert.equal(calls.length, 1)

  const hanging = {
    get: (path, options) => new Promise((_, reject) => {
      options.signal?.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })))
    })
  }
  await assert.rejects(fetchInspectedRecord(hanging, '/x', { timeoutMs: 20 }), /timed out after 30 seconds/)
})
