import assert from 'node:assert/strict'
import test from 'node:test'
import { buildCapabilityCatalog, formatAdminReadError, prepareRecordQuery, sanitizeRecordResults } from '../src/capabilities.js'

const parameterize = name => `${name.toLowerCase()}s`
const entities = {
  Counter: {
    entity: { name: 'Counter', plural: 'counters' },
    form: { fields: ['name', { property: 'enabled', type: 'boolean', required: true }, { property: 'apiToken' }, { property: 'metadata', type: 'json' }] },
    list: {
      list_display: ['id', 'uuid', 'name', { property: 'storeUuid', relation: { entity: 'Store' } }, 'enabled'],
      list_filter: { name: 'Name', enabled: { label: 'Enabled', type: 'boolean' }, status: { expression: 'entity.status == :value' } },
      query: { '@order': 'entity.id|DESC' }
    }
  },
  SecretSetting: { entity: { name: 'SecretSetting', plural: 'settings' }, list: { list_display: ['id', 'key', 'value'], list_filter: { key: 'Key' } } },
  Unsupported: { entity: { name: 'Unsupported', plural: 'unsupported' }, form: { fields: ['name'] } }
}
const operations = [
  { method: 'GET', path: '/api/v1/manage/counters' },
  { method: 'DELETE', path: '/api/v1/manage/counters' },
  { method: 'GET', path: '/api/v1/manage/settings' }
]

test('builds a serializable config-first catalog and only enables documented GET collections', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const counter = catalog.find(item => item.key === 'Counter')
  const secretSetting = catalog.find(item => item.key === 'SecretSetting')
  const unsupported = catalog.find(item => item.key === 'Unsupported')
  assert.equal(counter.readable, true)
  assert.deepEqual(counter.select, ['id', 'uuid', 'name', 'enabled'])
  assert.deepEqual(counter.filters.map(filter => filter.property), ['name', 'enabled'])
  assert.equal(counter.fields.some(field => field.property === 'apiToken' || field.property === 'metadata'), false)
  assert.equal(secretSetting.readable, true)
  assert.equal(secretSetting.select.includes('value'), false)
  assert.equal(unsupported.readable, false)
  assert.equal(JSON.stringify(catalog).includes('expression'), false)
})

test('validates query bounds and compiles only configured scalar filters', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const query = prepareRecordQuery({ entity: 'Counter', page: 2, limit: 5, filters: [{ field: 'name', value: 'West "counter"' }, { field: 'enabled', value: true }] }, catalog)
  assert.equal(query.path, '/api/v1/manage/counters')
  assert.equal('@select' in query.params, false)
  assert.equal(query.params['@filter'], 'entity.name=="West \\"counter\\"" && entity.enabled==true')
  assert.equal(query.params['@order'], 'entity.id|DESC')
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', filters: [{ field: 'status', value: 'active' }] }, catalog), /not allowed/)
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', limit: 21 }, catalog), /Limit/)
  assert.throws(() => prepareRecordQuery({ entity: 'Unsupported' }, catalog), /no approved/)
})

test('sanitizes query results to configured scalar display fields and bounds values', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const query = prepareRecordQuery({ entity: 'Counter' }, catalog)
  const result = sanitizeRecordResults([{ id: 1, uuid: 'counter-1', name: 'West', enabled: true, storeUuid: 'store-secret', apiToken: 'never', extra: 'never', nested: { token: 'never' } }], query, catalog)
  assert.deepEqual(result.records, [{ id: 1, uuid: 'counter-1', name: 'West', enabled: true }])
})

test('keeps bounded server error details so failed queries are diagnosable', () => {
  assert.equal(formatAdminReadError({
    response: { status: 500, data: { detail: '<b>Unknown field</b> uuid' } },
    message: 'Request failed with status code 500'
  }), 'Admin read query failed (500): Unknown field uuid')
  assert.ok(formatAdminReadError({ response: { data: 'x'.repeat(500) } }).length < 450)
})
