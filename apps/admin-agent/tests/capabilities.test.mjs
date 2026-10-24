import assert from 'node:assert/strict'
import test from 'node:test'
import { buildCapabilityCatalog, formatAdminReadError, prepareRecordQuery, sanitizeRecordResults } from '../src/capabilities.js'

const parameterize = name => `${name.toLowerCase()}s`
const entities = {
  Counter: {
    entity: { name: 'Counter', plural: 'counters' },
    form: { fields: ['name', { property: 'enabled', type: 'boolean', required: true }, { property: 'apiToken' }, { property: 'bankAccountNumber' }, { property: 'metadata', type: 'json' }] },
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
  assert.equal(counter.fields.some(field => ['apiToken', 'bankAccountNumber', 'metadata'].includes(field.property)), false)
  assert.equal(secretSetting.readable, true)
  assert.equal(secretSetting.select.includes('value'), false)
  assert.equal(unsupported.readable, false)
  assert.equal(JSON.stringify(catalog).includes('expression'), false)
})

test('excludes personal identifiers and financial account identifiers from the query allowlist', () => {
  const catalog = buildCapabilityCatalog({
    Customer: {
      entity: { name: 'Customer', plural: 'customers' },
      list: { list_display: ['id', 'legalName', 'nickname', 'bankAccountNumber', 'name'] }
    }
  }, [{ method: 'GET', path: '/api/v1/manage/customers' }], { parameterize })
  assert.deepEqual(catalog[0].select, ['id', 'uuid', 'name'])
})

test('validates query bounds and compiles only configured scalar filters', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const query = prepareRecordQuery({ entity: 'Counter', page: 2, limit: 5, filters: [{ field: 'name', value: 'West "counter"' }, { field: 'enabled', value: true }] }, catalog)
  assert.equal(query.path, '/api/v1/manage/counters')
  assert.equal('@select' in query.params, false)
  assert.equal(query.params['@filter'], 'entity.getName() matches "West \\"counter\\"" && entity.getEnabled() == true')
  assert.equal(query.params['@order'], 'entity.id|DESC')
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', filters: [{ field: 'status', value: 'active' }] }, catalog), /not allowed/)
  assert.equal(prepareRecordQuery({ entity: 'Counter', limit: 100 }, catalog).limit, 100)
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', limit: 101 }, catalog), /Limit/)
  assert.match(
    String((() => { try { prepareRecordQuery({ entity: 'Counter', filters: [{ field: 'status', value: 'active' }] }, catalog) } catch (error) { return error.message } })()),
    /Allowed filter fields: name, enabled/
  )
  assert.equal(prepareRecordQuery({ entity: 'Counter', page: 100 }, catalog).page, 100)
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', page: 101 }, catalog), /Page/)
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
        { property: 'storeUuid', relation: { entity: 'Store', valueKey: 'uuid' } },
        { property: 'regionUuid', relation: { entity: 'Region', valueKey: 'uuid' } }
      ] },
      list: { list_display: ['id', 'amount', 'createdAt'], list_filter: {
        amount: 'Amount', createdAt: 'Created at', storeUuid: relationLoader,
        regionUuid: { '550e8400-e29b-41d4-a716-446655440001': 'North' }
      } }
    }
  }, [{ method: 'GET', path: '/api/v1/manage/invoices' }], { parameterize })
  const invoice = typedCatalog.find(item => item.key === 'Invoice')
  assert.equal(invoice.filters.some(filter => filter.property === 'storeUuid'), false)
  const query = prepareRecordQuery({ entity: 'Invoice', filters: [
    { field: 'amount', operator: 'gte', value: 500 },
    { field: 'createdAt', operator: 'gte', value: '2026-01-01' },
    { field: 'createdAt', operator: 'lte', value: '2026-12-31' },
    { field: 'regionUuid', value: '550e8400-e29b-41d4-a716-446655440001' }
  ] }, typedCatalog)
  assert.equal(query.params['@filter'], 'entity.getAmount() >= 500 && entity.getCreatedAt() >= "2026-01-01" && entity.getCreatedAt() <= "2026-12-31" && entity.getRegionUuid() == "550e8400-e29b-41d4-a716-446655440001"')
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'regionUuid', value: '550e8400-e29b-41d4-a716-446655440000' }] }, typedCatalog), /allowed option/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'storeUuid', value: '550e8400-e29b-41d4-a716-446655440000' }] }, typedCatalog), /not allowed/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'amount', operator: 'gte', value: '500' }] }, typedCatalog), /finite number/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'amount', operator: 'gte', value: 1.5 }] }, typedCatalog), /integer/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'createdAt', operator: 'gte', value: 'yesterday' }] }, typedCatalog), /ISO date/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'createdAt', operator: 'gte', value: '2026-02-31' }] }, typedCatalog), /ISO date/)
  assert.throws(() => prepareRecordQuery({ entity: 'Invoice', filters: [{ field: 'amount', operator: 'contains', value: '5' }] }, typedCatalog), /operator is not allowed/)
})

test('maps static configuration expressions without evaluating or exposing them', () => {
  const expressionCatalog = buildCapabilityCatalog({
    Article: {
      entity: { name: 'Article', plural: 'articles' },
      form: { fields: [
        { property: 'enabled', type: 'boolean' },
        { property: 'createdAt', type: 'datetime' },
        { property: 'role', relation: { entity: 'Role' } }
      ] },
      list: { list_display: ['id', 'name', 'enabled', 'createdAt'], list_filter: {
        enabled: { label: 'Enabled', type: 'boolean', expression: 'entity.getEnabled() == :value' },
        createdAt: { label: 'Created', type: 'datetime', expression: 'entity.getCreatedAt() >= datetime.get(":value")' },
        roleId: { label: 'Role', type: 'input', expression: 'entity.getRole().getId() == :value' },
        revokedAt: { label: 'Revoked', type: 'boolean', expression: 'entity.getRevokedAt()' }
      } }
    }
  }, [{ method: 'GET', path: '/api/v1/manage/articles' }], { parameterize })
  const article = expressionCatalog.find(item => item.key === 'Article')
  assert.deepEqual(article.filters.map(filter => filter.property), ['enabled', 'createdAt', 'roleId'])
  assert.deepEqual(article.filters.find(filter => filter.property === 'createdAt').operators, ['gte'])
  assert.equal(JSON.stringify(article).includes('expression'), false)

  const query = prepareRecordQuery({ entity: 'Article', filters: [
    { field: 'enabled', value: true },
    { field: 'createdAt', value: '2026-01-01' },
    { field: 'roleId', value: 7 }
  ] }, expressionCatalog)
  assert.equal(query.params['@filter'],
    'entity.getEnabled() == true && entity.getCreatedAt() >= "2026-01-01" && entity.getRole().getId() == 7')
  assert.throws(() => prepareRecordQuery({ entity: 'Article', filters: [{ field: 'roleId', value: '7' }] }, expressionCatalog), /positive numeric ID/)
  assert.throws(() => prepareRecordQuery({ entity: 'Article', filters: [{ field: 'revokedAt', value: true }] }, expressionCatalog), /not allowed/)
})

test('supports static relation identifier filters without running option callbacks', () => {
  const loader = () => { throw new Error('Configuration callbacks must not execute.') }
  const relationCatalog = buildCapabilityCatalog({
    Entry: {
      entity: { name: 'Entry', plural: 'entries' },
      form: { fields: [{ property: 'category', relation: { entity: 'Category' } }] },
      list: { list_display: ['id', 'name'], list_filter: { 'category.id': loader } }
    }
  }, [{ method: 'GET', path: '/api/v1/manage/entries' }], { parameterize })
  const entry = relationCatalog.find(item => item.key === 'Entry')
  assert.deepEqual(entry.filters.map(filter => filter.property), ['category.id'])
  const query = prepareRecordQuery({ entity: 'Entry', filters: [{ field: 'category.id', value: 12 }] }, relationCatalog)
  assert.equal(query.params['@filter'], 'entity.getCategory().getId() == 12')
  assert.throws(() => prepareRecordQuery({ entity: 'Entry', filters: [{ field: 'category.id', value: '12' }] }, relationCatalog), /positive numeric ID/)
})

test('allows documented entities without a standalone page and exposes only policy-approved relation fields', () => {
  const specCatalog = buildCapabilityCatalog({
    Specification: {
      entity: { name: 'Specification', plural: 'specifications' },
      form: { fields: ['name', { property: 'price', type: 'integer' }, 'status', 'sort'] },
      list: { list_display: ['id', 'name', 'price', 'status', 'sort'] }
    }
  }, [{ method: 'GET', path: '/api/v1/manage/specifications' }], {
    parameterize,
    readPolicy: {
      Specification: {
        fields: [{ property: 'product.id', label: 'Product ID', type: 'integer' }],
        filters: [{ property: 'product.id', label: 'Product ID', type: 'integer' }]
      }
    }
  })
  const specification = specCatalog[0]
  assert.equal(specification.readable, true)
  assert.ok(specification.select.includes('product.id'))
  assert.ok(specification.filters.some(filter => filter.property === 'product.id'))
  const query = prepareRecordQuery({ entity: 'Specification', filters: [{ field: 'product.id', value: 42 }] }, specCatalog)
  assert.equal(query.params['@filter'], 'entity.getProduct().getId() == 42')
  const result = sanitizeRecordResults([{ id: 5, name: 'Large', product: { id: 42, email: 'blocked@example.test' }, secret: 'blocked' }], query, specCatalog)
  assert.deepEqual(result.records, [{ id: 5, name: 'Large', 'product.id': 42 }])
})

test('restores configured Content filters without exposing body or dynamic options', () => {
  const loader = () => { throw new Error('Category option loaders must not execute.') }
  const contentCatalog = buildCapabilityCatalog({
    Content: {
      entity: { name: 'Content', plural: 'contents' },
      form: { fields: ['title', { property: 'body', required: true }, { property: 'category' }, { property: 'tags' }] },
      list: {
        list_display: ['id', 'title', 'category', 'tags', 'createdAt', 'updatedAt'],
        list_filter: { title: 'Title', 'category.id': loader }
      }
    }
  }, [
    { method: 'GET', path: '/api/v1/manage/contents' },
    { method: 'GET', path: '/api/v1/manage/contents/{id}' }
  ], { parameterize })
  const content = contentCatalog.find(item => item.key === 'Content')
  assert.deepEqual(content.filters.map(filter => filter.property), ['title', 'category.id'])
  assert.equal(content.filters.find(filter => filter.property === 'category.id').relationValueKey, 'id')
  assert.equal(content.select.includes('body'), false)
  assert.deepEqual(content.sortFields, ['id', 'title', 'category', 'tags', 'createdAt', 'updatedAt'])
  assert.throws(() => prepareRecordQuery({ entity: 'Content', filters: [{ field: 'body', value: 'text' }] }, contentCatalog), /Allowed filter fields: title, category.id/)
})

test('accepts only configured sort fields and directions', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const counter = catalog.find(item => item.key === 'Counter')
  assert.deepEqual(counter.sortFields, ['id', 'uuid', 'name', 'enabled'])
  const sorted = prepareRecordQuery({ entity: 'Counter', sort: { field: 'name', direction: 'ASC' } }, catalog)
  assert.equal(sorted.params['@order'], 'entity.name|ASC')
  assert.deepEqual(sorted.sort, { field: 'name', direction: 'ASC' })
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', sort: { field: 'apiToken', direction: 'ASC' } }, catalog), /sort field is not allowed/)
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', sort: { field: 'name', direction: 'DOWN' } }, catalog), /sort direction/)
  assert.throws(() => prepareRecordQuery({ entity: 'Counter', sort: { field: 'name', direction: 'ASC', extra: true } }, catalog), /unsupported parameters/)
})

test('sanitizes query results to configured scalar display fields and bounds values', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const query = prepareRecordQuery({ entity: 'Counter' }, catalog)
  const result = sanitizeRecordResults([{ id: 1, uuid: 'counter-1', name: 'West', enabled: true, storeUuid: 'store-secret', apiToken: 'never', extra: 'never', nested: { token: 'never' } }], query, catalog)
  assert.deepEqual(result.records, [{ id: 1, uuid: 'counter-1', name: 'West', enabled: true }])
})

test('bounds large pages to the transport budget and marks them truncated', () => {
  const catalog = buildCapabilityCatalog(entities, operations, { parameterize })
  const query = prepareRecordQuery({ entity: 'Counter', limit: 100 }, catalog)
  const rows = Array.from({ length: 100 }, (_, index) => ({ id: index + 1, name: 'x'.repeat(300) }))
  const result = sanitizeRecordResults(rows, query, catalog)
  assert.equal(result.truncated, true)
  assert.ok(result.records.length < 100)
  assert.ok(JSON.stringify(result.records).length <= 14000)
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
