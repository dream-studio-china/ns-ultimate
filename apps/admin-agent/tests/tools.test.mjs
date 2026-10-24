import assert from 'node:assert/strict'
import test from 'node:test'
import { buildCapabilityCatalog } from '../src/capabilities.js'
import { createAssistantTools, executeAssistantTool } from '../src/tools.js'

const OPERATION_ID = 'a'.repeat(64)
const STORE_UUID = '550e8400-e29b-41d4-a716-446655440001'

const batchCapabilities = [
  {
    entity: 'Tag',
    actions: ['create'],
    actionFields: {
      create: {
        name: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
        slug: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
        color: { type: 'color', nullable: true }
      }
    }
  },
  {
    entity: 'Counter',
    actions: ['create'],
    actionFields: {
      create: {
        storeUuid: { type: 'uuid', requiredOnCreate: true },
        code: { type: 'string', maxLength: 50, requiredOnCreate: true, nonEmpty: true, trimForValidation: true },
        name: { type: 'string', maxLength: 120, requiredOnCreate: true, nonEmpty: true, trimForValidation: true }
      }
    }
  }
]

function batchPreview(input) {
  const rows = input.rows.map((row, index) => ({
    row: index + 1,
    title: String(row.name ?? `Row ${index + 1}`),
    identifier: null,
    differences: Object.entries(row).map(([property, after]) => ({ property, before: null, after }))
  }))
  return {
    operationId: OPERATION_ID,
    entity: input.entity,
    action: 'create',
    title: `Create ${rows.length} ${input.entity} records`,
    affectedRecords: rows.length,
    expiresAt: new Date(Date.now() + 300000).toISOString(),
    rows,
    saved: false
  }
}

function batchCall(args) {
  return { type: 'function', function: { name: 'prepare_record_batch', arguments: JSON.stringify(args) } }
}

const catalog = buildCapabilityCatalog({
  Counter: {
    entity: { name: 'Counter', plural: 'counters' },
    list: { list_display: ['id', 'name'], list_filter: { name: 'Name' } }
  }
}, [{ method: 'GET', path: '/api/v1/manage/counters' }], { parameterize: value => `${value.toLowerCase()}s` })

test('exposes documentation-free entity discovery and only a read-only query tool', () => {
  const names = createAssistantTools(catalog).map(tool => tool.function.name)
  assert.deepEqual(names, ['list_entities', 'query_records'])
  assert.equal(names.some(name => /create|update|delete|http|fetch/i.test(name)), false)
})

test('declares fixed record reads only for host policy entities', () => {
  const policy = { Counter: { fields: ['name'] } }
  const tools = createAssistantTools(catalog, [], {}, [], {}, [], [], policy)
  const tool = tools.find(item => item.function.name === 'read_record').function
  assert.deepEqual(tool.parameters.properties.entity.enum, ['Counter'])
  assert.deepEqual(Object.keys(tool.parameters.properties).sort(), ['entity', 'identifier'])
  assert.match(tool.description, /untrusted content/)
  assert.match(tool.description, /never send field names/)
  const withoutPolicy = createAssistantTools(catalog).map(item => item.function.name)
  assert.equal(withoutPolicy.includes('read_record'), false)
})

test('normalizes numeric target IDs and surfaces bounded preparation errors', async() => {
  const calls = []
  const host = {
    prepareRecordChange: async(input) => {
      calls.push(input)
      if (input.changes?.title === 'invalid') {
        throw Object.assign(new Error('Request failed with status code 400'), {
          response: { status: 400, data: { message: '<b>Invalid Content field: body.</b>' } }
        })
      }
      if (input.changes?.title === 'forbidden') {
        throw Object.assign(new Error('Request failed with status code 403'), {
          response: { status: 403, data: { message: 'Content management requires ROLE_ADMIN.' } }
        })
      }
      return { saved: false, differences: [], entity: 'Content', action: 'update', identifier: '12' }
    }
  }
  const args = { catalog, host, operations: [], writeCapabilities: [{ entity: 'Content', actions: ['update'], actionFields: { update: { title: { type: 'string' } } } }] }
  const prepared = await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'prepare_record_change', arguments: JSON.stringify({ entity: 'Content', action: 'update', identifier: 12, changes: { title: 'ok' } }) } }
  })
  assert.equal(prepared.prepared, true)
  assert.equal(calls[0].identifier, '12')

  const invalid = await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'prepare_record_change', arguments: JSON.stringify({ entity: 'Content', action: 'update', identifier: '12', changes: { title: 'invalid' } }) } }
  })
  assert.deepEqual(invalid, { error: 'Invalid Content field: body.', failureType: 'validation', status: 400 })

  const forbidden = await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'prepare_record_change', arguments: JSON.stringify({ entity: 'Content', action: 'update', identifier: '12', changes: { title: 'forbidden' } }) } }
  })
  assert.deepEqual(forbidden, { error: 'Content management requires ROLE_ADMIN.', failureType: 'authorization', status: 403 })
})

test('reads one exact record through the host and audits metadata only', async() => {
  const calls = []
  const audits = []
  const host = {
    readRecord: async(...args) => {
      calls.push(args)
      return {
        entity: 'Counter',
        identifier: '12',
        fields: { name: 'Counter twelve', body: 'Record content' },
        truncatedFields: [],
        omittedFields: [],
        truncated: false,
        query: { entity: 'Counter', identifier: '12', durationMs: 4, completedAt: new Date().toISOString() }
      }
    }
  }
  const args = {
    catalog,
    host,
    operations: [],
    recordReadPolicy: { Counter: { fields: ['name', 'body'] } },
    onReadAudit: item => audits.push(item)
  }
  const result = await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'read_record', arguments: '{"entity":"Counter","identifier":"12"}' } }
  })
  assert.equal(result.fields.body, 'Record content')
  assert.deepEqual(calls[0][0], { entity: 'Counter', identifier: '12' })
  assert.equal(audits.length, 1)
  assert.equal(audits[0].identifier, '12')
  assert.equal(audits[0].outcome, 'success')
  assert.equal(JSON.stringify(audits[0]).includes('Record content'), false)

  assert.deepEqual(await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'read_record', arguments: '{"entity":"Counter","identifier":"12","fields":["body"]}' } }
  }), { error: 'Record read arguments contain unsupported parameters.' })
  assert.deepEqual(await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'read_record', arguments: '{"entity":"User","identifier":"12"}' } }
  }), { error: 'This entity has no approved record read.' })
  const failed = await executeAssistantTool({
    ...args,
    host: { readRecord: async() => { throw new Error('backend detail must not leak') } },
    call: { type: 'function', function: { name: 'read_record', arguments: '{"entity":"Counter","identifier":"12"}' } }
  })
  assert.deepEqual(failed, { error: 'The record could not be read.', failureType: 'request-failed' })
  assert.equal(calls.length, 1)
})

test('runs bounded read-only queries without confirmation and validates arguments before calling the host', async() => {
  let queryCount = 0
  const host = { queryRecords: async args => { queryCount += 1; return { records: [{ name: args.filters?.[0]?.value ?? 'sorted' }] } } }
  const call = { type: 'function', function: { name: 'query_records', arguments: JSON.stringify({ entity: 'Counter', filters: [{ field: 'name', value: 'West' }] }) } }

  const result = await executeAssistantTool({ call, catalog, host, operations: [] })
  assert.deepEqual(result.records, [{ name: 'West' }])
  assert.deepEqual(result.query.filters, [{ field: 'name', operator: 'contains', value: 'West' }])
  assert.equal(result.query.entity, 'Counter')
  const sorted = await executeAssistantTool({
    call: { type: 'function', function: { name: 'query_records', arguments: JSON.stringify({ entity: 'Counter', sort: { field: 'name', direction: 'ASC' } }) } },
    catalog,
    host,
    operations: []
  })
  assert.deepEqual(sorted.query.sort, { field: 'name', direction: 'ASC' })
  assert.equal(sorted.query.filters.length, 0)
  const listed = await executeAssistantTool({
    call: { type: 'function', function: { name: 'list_entities', arguments: '{}' } },
    catalog,
    host,
    operations: []
  })
  assert.deepEqual(listed.entities[0].sortFields, ['id', 'name'])
  assert.equal(result.query.page, 1)
  assert.equal(result.query.limit, 10)
  assert.ok(Number.isFinite(result.query.durationMs))
  assert.ok(Number.isFinite(Date.parse(result.query.completedAt)))
  assert.equal(queryCount, 2)
})

test('pages the full configured entity catalog below the tool-result budget', async() => {
  const large = Array.from({ length: 40 }, (_, index) => ({
    key: `Entity${index}`,
    name: `Entity${index}`,
    readable: true,
    fields: Array.from({ length: 20 }, (_, field) => ({ property: `field${field}`, label: `Field ${field}`, type: 'string', required: false })),
    sortFields: Array.from({ length: 30 }, (_, field) => `field${field}`),
    filters: Array.from({ length: 10 }, (_, field) => ({ property: `field${field}`, label: `Filter ${field}`, type: 'text', operator: 'contains', operators: ['contains', 'equals'], options: [], dynamicOptions: false }))
  }))
  const args = { catalog: large, host: {}, operations: [] }
  let offset = 0
  const seen = []
  for (let page = 0; page < 20; page += 1) {
    const result = await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'list_entities', arguments: JSON.stringify(offset ? { offset } : {}) } } })
    assert.ok(JSON.stringify(result).length <= 18000)
    seen.push(...result.entities.map(item => item.name))
    if (!result.hasMore) break
    assert.ok(result.nextOffset > offset)
    offset = result.nextOffset
  }
  assert.equal(seen.length, 40)
  assert.equal(new Set(seen).size, 40)
  const single = await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'list_entities', arguments: '{"entity":"Entity39"}' } } })
  assert.deepEqual(single.entities.map(item => item.name), ['Entity39'])
})

test('allows repeated read queries and records only safe audit metadata', async() => {
  const audits = []
  const host = { queryRecords: async() => ({ records: [{ name: 'A' }, { name: 'B' }] }) }
  const call = { type: 'function', function: { name: 'query_records', arguments: JSON.stringify({ entity: 'Counter', limit: 2 }) } }
  for (let index = 0; index < 125; index += 1) {
    const result = await executeAssistantTool({ call, catalog, host, operations: [], onReadAudit: item => audits.push(item) })
    assert.equal(result.records.length, 2)
  }
  assert.equal(audits.length, 125)
  assert.equal(audits.at(-1).outcome, 'success')
  assert.deepEqual(Object.keys(audits[0]).sort(), ['at', 'durationMs', 'entity', 'failureType', 'outcome', 'page', 'rows'].sort())
})

test('rejects unapproved tool names and malformed entity lists', async() => {
  const args = { catalog, host: {}, operations: [], budget: { count: 0 } }
  assert.deepEqual(await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'delete_record', arguments: '{}' } } }), { error: 'This tool is not available.' })
  assert.deepEqual(await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'list_entities', arguments: '{"url":"https://example.test"}' } } }), { error: 'This tool accepts only an optional entity name and offset.' })
  assert.deepEqual(await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'list_entities', arguments: '{"offset":-1}' } } }), { error: 'The offset must be a non-negative integer.' })
  assert.deepEqual(await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'list_entities', arguments: '{"entity":"Missing"}' } } }), { error: 'No configured admin entity has that exact name.' })
})

test('lists registered pages and opens only allowlisted pages or safe record details', async() => {
  const navigation = [{ key: 'CounterList', title: 'Counters', path: '/counter/list' }]
  const calls = []
  const host = { navigateAdminPage: async(...args) => { calls.push(args); return { title: 'Counters', identifier: args[1] || null } } }
  const tools = createAssistantTools(catalog, navigation)
  assert.deepEqual(tools.map(tool => tool.function.name), ['list_entities', 'list_admin_pages', 'navigate_admin_page', 'query_records'])
  const args = { catalog, navigation, host, operations: [], budget: { count: 0 } }
  const listed = await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'list_admin_pages', arguments: '{}' } }
  })
  assert.deepEqual(listed.pages, navigation)

  const opened = await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'navigate_admin_page', arguments: '{"page":"CounterList","identifier":"12"}' } }
  })
  assert.deepEqual(opened, { navigated: true, title: 'Counters', identifier: '12' })
  assert.deepEqual(calls, [['CounterList', '12']])

  const rejected = await executeAssistantTool({
    ...args,
    call: { type: 'function', function: { name: 'navigate_admin_page', arguments: '{"page":"CounterList","identifier":"../../login"}' } }
  })
  assert.match(rejected.error, /positive numeric ID or UUID/)
  assert.equal(calls.length, 1)
})

test('offers only host-resolved standard-admin links for prohibited assistant deletions', async() => {
  const navigation = [{ key: 'UserList', title: 'Users', path: '/user/list', entity: 'User' }]
  const manualDeletePages = [{ key: 'UserList', title: 'Users', entity: 'User' }]
  const offered = []
  const host = { getAdminNavigationLink: (key, identifier) => ({ href: identifier ? `/user/${identifier}/detail` : '/user/list' }) }
  const tools = createAssistantTools(catalog, navigation, {}, [], {}, [], manualDeletePages)
  assert.ok(tools.some(tool => tool.function.name === 'offer_admin_navigation'))
  const result = await executeAssistantTool({
    call: { type: 'function', function: { name: 'offer_admin_navigation', arguments: '{"page":"UserList","identifier":"12"}' } },
    catalog, navigation, manualDeletePages, host, operations: [],
    onNavigationLink: link => offered.push(link)
  })
  assert.deepEqual(result, { offered: true, page: 'Users' })
  assert.deepEqual(offered, [{ title: 'Users', href: '/user/12/detail', identifier: '12' }])

  const rejected = await executeAssistantTool({
    call: { type: 'function', function: { name: 'offer_admin_navigation', arguments: '{"page":"UserList","identifier":"../../evil"}' } },
    catalog, navigation, manualDeletePages, host, operations: []
  })
  assert.match(rejected.error, /positive numeric ID or UUID/)
})

test('declares a create-only batch tool only for explicit host batch capabilities', () => {
  const withoutBatch = createAssistantTools(catalog).map(tool => tool.function.name)
  assert.equal(withoutBatch.includes('prepare_record_batch'), false)
  const withWriteOnly = createAssistantTools(catalog, [], {}, [{ entity: 'Tag', actions: ['create'], actionFields: { create: { name: { type: 'string' } } } }])
  assert.equal(withWriteOnly.some(tool => tool.function.name === 'prepare_record_batch'), false)

  const tools = createAssistantTools(catalog, [], {}, [], {}, batchCapabilities)
  const tool = tools.find(item => item.function.name === 'prepare_record_batch').function
  assert.deepEqual(tool.parameters.properties.entity.enum, ['Tag', 'Counter'])
  assert.deepEqual(tool.parameters.properties.action.enum, ['create'])
  assert.equal(tool.parameters.properties.rows.maxItems, 20)
  assert.equal(tool.parameters.properties.rows.minItems, 1)
  assert.match(tool.description, /explicitly requests these records/)
  assert.match(tool.description, /untrusted data/)
  assert.match(tool.description, /not atomically/)
  assert.equal(tools.some(item => /commit|execute|submit/i.test(item.function.name)), false)
})

test('prepares a batch through the host but returns only safe metadata to the model', async() => {
  const calls = { prepare: [], cancel: [] }
  const prepared = []
  const host = {
    async prepareRecordBatch(input) {
      calls.prepare.push(input)
      return batchPreview(input)
    },
    async cancelRecordBatch(operationId) { calls.cancel.push(operationId) }
  }
  const result = await executeAssistantTool({
    call: batchCall({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north', color: '#112233' }] }),
    catalog,
    host,
    operations: [],
    batchCapabilities,
    onPreparedBatch: (preview, context) => { prepared.push({ preview, context }); return true }
  })
  assert.deepEqual(calls.prepare, [{
    entity: 'Tag',
    action: 'create',
    rows: [{ name: 'North', slug: 'north', color: '#112233' }]
  }])
  assert.equal(prepared.length, 1)
  assert.equal(prepared[0].preview.operationId, OPERATION_ID)
  assert.deepEqual(prepared[0].preview.records[0].values, { name: 'North', slug: 'north', color: '#112233' })
  assert.equal(prepared[0].context.entity, 'Tag')
  assert.deepEqual(result, {
    prepared: true,
    saved: false,
    entity: 'Tag',
    action: 'create',
    affectedRecords: 1,
    message: 'Awaiting explicit UI confirmation. No target data has been changed.'
  })
  const serialized = JSON.stringify(result)
  assert.equal(serialized.includes(OPERATION_ID), false)
  assert.equal(serialized.includes('North'), false)
  assert.equal(serialized.includes('north'), false)
})

test('rejects invalid batch input before the host transport and never invents values', async() => {
  let prepareCalls = 0
  const host = {
    async prepareRecordBatch(input) { prepareCalls += 1; return batchPreview(input) },
    async cancelRecordBatch() {}
  }
  const invalid = [
    { entity: 'Tag', action: 'create', rows: [{ name: 'North' }] },
    { entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north', unknown: true }] },
    { entity: 'Tag', action: 'create', rows: [] },
    { entity: 'Counter', action: 'create', rows: [{ storeUuid: STORE_UUID, code: 'A', name: 'A' }, { storeUuid: '550e8400-e29b-41d4-a716-446655440002', code: 'B', name: 'B' }] },
    { entity: 'Product', action: 'create', rows: [{ name: 'North' }] }
  ]
  for (const args of invalid) {
    const result = await executeAssistantTool({
      call: batchCall(args),
      catalog,
      host,
      operations: [],
      batchCapabilities,
      onPreparedBatch: () => { throw new Error('must not prepare') }
    })
    assert.equal(result.failureType, 'validation')
    assert.equal(result.prepared, undefined)
  }
  assert.equal(prepareCalls, 0)

  const unavailable = await executeAssistantTool({
    call: batchCall({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] }),
    catalog,
    host,
    operations: [],
    onPreparedBatch: () => true
  })
  assert.equal(unavailable.failureType, 'unavailable')
  assert.equal(prepareCalls, 0)
})

test('cancels a prepared batch when the task is aborted or the preview is not adopted', async() => {
  const cancels = []
  let prepareCalls = 0
  const host = {
    async prepareRecordBatch(input) { prepareCalls += 1; return batchPreview(input) },
    async cancelRecordBatch(operationId) { cancels.push(operationId) }
  }
  const aborted = new AbortController()
  aborted.abort()
  const cancelled = await executeAssistantTool({
    call: batchCall({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] }),
    catalog,
    host,
    operations: [],
    batchCapabilities,
    signal: aborted.signal,
    onPreparedBatch: () => true
  })
  assert.equal(cancelled.failureType, 'cancelled')
  assert.equal(prepareCalls, 0)

  const discarded = await executeAssistantTool({
    call: batchCall({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] }),
    catalog,
    host,
    operations: [],
    batchCapabilities,
    onPreparedBatch: () => false
  })
  assert.equal(discarded.failureType, 'cancelled')
  assert.equal(prepareCalls, 1)
  assert.deepEqual(cancels, [OPERATION_ID])
})

test('hides transport failures and malformed previews from the model result', async() => {
  const cancels = []
  const failed = await executeAssistantTool({
    call: batchCall({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] }),
    catalog,
    host: {
      async prepareRecordBatch() { throw new Error('Sensitive upstream detail') },
      async cancelRecordBatch() {}
    },
    operations: [],
    batchCapabilities
  })
  assert.equal(failed.failureType, 'failed')
  assert.equal(JSON.stringify(failed).includes('Sensitive'), false)

  const malformed = await executeAssistantTool({
    call: batchCall({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] }),
    catalog,
    host: {
      async prepareRecordBatch(input) { return { ...batchPreview(input), saved: true } },
      async cancelRecordBatch(operationId) { cancels.push(operationId) }
    },
    operations: [],
    batchCapabilities
  })
  assert.equal(malformed.failureType, 'invalid-preview')
  assert.equal(JSON.stringify(malformed).includes(OPERATION_ID), false)
  assert.deepEqual(cancels, [OPERATION_ID])
})
