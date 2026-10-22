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
  assert.equal(query.params['@filter'], 'entity.getName() matches "West \\"counter\\"" && entity.getEnabled() == true')
  assert.equal(query.params['@order'], 'entity.id|DESC')
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', filters: [{ field: 'status', value: 'active' }] }, catalog), /not allowed/)
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', limit: 21 }, catalog), /Limit/)
  assert.throws(() => prepareRecordQuery({ entity: 'Unsupported' }, catalog), /no approved/)
})

test('compiles text filters as substring matches and option filters as typed getter equality', () => {
  const mixedCatalog = buildCapabilityCatalog({
    Item: {
      entity: { name: 'Item', plural: 'items' },
      list: { list_display: ['id', 'name', 'enabled'], list_filter: { name: 'Name', enabled: { __label: 'Enabled', true: 'Yes', false: 'No' } } }
    }
  }, [{ method: 'GET', path: '/api/v1/manage/items' }], { parameterize })
  const query = prepareRecordQuery({ entity: 'Item', filters: [
    { field: 'name', value: 'tea' }, { field: 'enabled', value: false }
  ] }, mixedCatalog)
  assert.equal(query.params['@filter'], 'entity.getName() matches "tea" && entity.getEnabled() == false')
  assert.throws(() => prepareRecordQuery({ entity: 'Item', filters: [{ field: 'name', value: '' }] }, mixedCatalog), /non-empty string/)
  assert.throws(() => prepareRecordQuery({ entity: 'Item', filters: [{ field: 'enabled', value: 'maybe' }] }, mixedCatalog), /allowed option/)
})

test('supports bounded numeric/date comparisons and configured relation equality without running dynamic filters', () => {
  const relationLoader = () => { throw new Error('Configuration callbacks must not execute.') }
  const typedCatalog = buildCapabilityCatalog({
    Invoice: {
      entity: { name: 'Invoice', plural: 'invoices' },
      form: { fields: [
        { property: 'amount', type: 'integer' },
        { property: 'createdAt', type: 'datetime' },
        { property: 'storeUuid', relation: { entity: 'Store', valueKey: 'uuid' } }
      ] },
      list: { list_display: ['id', 'amount', 'createdAt'], list_filter: {
        amount: 'Amount', createdAt: 'Created at', storeUuid: relationLoader
      } }
    }
  }, [{ method: 'GET', path: '/api/v1/manage/invoices' }], { parameterize })
  const invoice = typedCatalog.find(item => item.key === 'Invoice')
  assert.equal(invoice.filters.find(filter => filter.property === 'storeUuid').dynamicOptions, true)
  const query = prepareRecordQuery({ entity: 'Invoice', filters: [
    { field: 'amount', operator: 'gte', value: 500 },
    { field: 'createdAt', operator: 'gte', value: '2026-01-01' },
    { field: 'createdAt', operator: 'lte', value: '2026-12-31' },
    { field: 'storeUuid', value: 'store-uuid' }
  ] }, typedCatalog)
  assert.equal(query.params['@filter'], 'entity.getAmount() >= 500 && entity.getCreatedAt() >= "2026-01-01" && entity.getCreatedAt() <= "2026-12-31" && entity.getStoreUuid() == "store-uuid"')
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'amount', operator: 'gte', value: '500' }] }, typedCatalog), /finite number/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'amount', operator: 'gte', value: 1.5 }] }, typedCatalog), /integer/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'createdAt', operator: 'gte', value: 'yesterday' }] }, typedCatalog), /ISO date/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'createdAt', operator: 'gte', value: '2026-02-31' }] }, typedCatalog), /ISO date/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'amount', operator: 'contains', value: '5' }] }, typedCatalog), /operator is not allowed/)
})

test('sanitizes query results to configured scalar display fields and bounds values', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const query = prepareRecordQuery({ entity: 'Counter' }, catalog)
  const result = sanitizeRecordResults([{ id: 1, uuid: 'counter-1', name: 'West', enabled: true, storeUuid: 'store-secret', apiToken: 'never', extra: 'never', nested: { token: 'never' } }], query, catalog)
  assert.deepEqual(result.records, [{ id: 1, uuid: 'counter-1', name: 'West', enabled: true }])
})

test('unwraps documented collection envelopes and reports safe pagination metadata', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const query = prepareRecordQuery({ entity: 'Counter', page: 2, limit: 1 }, catalog)
  const rows = [{ id: 2, name: 'East', apiToken: 'hidden' }]
  const documented = sanitizeRecordResults({ data: rows, paginator: { total: 2, page: 2 } }, query, catalog)
  assert.deepEqual(documented, {
    entity: 'Counter', page: 2, count: 1, total: 2, hasMore: false, truncated: false,
    records: [{ id: 2, name: 'East' }]
  })
  const doubleWrapped = sanitizeRecordResults({ data: { data: rows, paginator: { total: 4 } } }, query, catalog)
  assert.equal(doubleWrapped.total, 4)
  assert.equal(doubleWrapped.hasMore, true)
  assert.throws(() => sanitizeRecordResults({ data: { unexpected: rows } }, query, catalog), /unsupported collection response/)
})

test('keeps bounded server error details so failed queries are diagnosable', () => {
  assert.equal(formatAdminReadError({
    response: { status: 500, data: { detail: '<b>Unknown field</b> uuid' } },
    message: 'Request failed with status code 500'
  }), 'Admin read query failed (500): Unknown field uuid')
  assert.ok(formatAdminReadError({ response: { data: 'x'.repeat(500) } }).length < 450)
})
