import assert from 'node:assert/strict'
import test from 'node:test'
import { buildCapabilityCatalog } from '../src/capabilities.js'
import { createAssistantTools, executeAssistantTool } from '../src/tools.js'

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

test('runs bounded read-only queries without confirmation and validates arguments before calling the host', async() => {
  let queryCount = 0
  const host = { queryRecords: async args => { queryCount += 1; return { records: [{ name: args.filters[0].value }] } } }
  const budget = { count: 0 }
  const call = { type: 'function', function: { name: 'query_records', arguments: JSON.stringify({ entity: 'Counter', filters: [{ field: 'name', value: 'West' }] }) } }

  const result = await executeAssistantTool({ call, catalog, host, operations: [], budget })
  assert.deepEqual(result.records, [{ name: 'West' }])
  assert.deepEqual(result.query.filters, [{ field: 'name', operator: 'contains', value: 'West' }])
  assert.equal(result.query.entity, 'Counter')
  assert.equal(result.query.page, 1)
  assert.equal(result.query.limit, 10)
  assert.ok(Number.isFinite(result.query.durationMs))
  assert.ok(Number.isFinite(Date.parse(result.query.completedAt)))
  assert.equal(queryCount, 1)
  assert.equal(budget.count, 1)
})

test('enforces a rolling per-session query and row budget and records only safe audit metadata', async() => {
  const sessionBudget = { events: [] }
  const audits = []
  const host = { queryRecords: async() => ({ records: [{ name: 'A' }, { name: 'B' }] }) }
  const call = { type: 'function', function: { name: 'query_records', arguments: JSON.stringify({ entity: 'Counter', limit: 2 }) } }
  const common = { call, catalog, host, operations: [], budget: { count: 0 }, sessionBudget, onReadAudit: item => audits.push(item) }
  const first = await executeAssistantTool(common)
  assert.equal(first.records.length, 2)
  assert.equal(sessionBudget.events[0].rows, 2)
  assert.equal(audits[0].outcome, 'success')
  assert.deepEqual(Object.keys(audits[0]).sort(), ['at', 'durationMs', 'entity', 'failureType', 'outcome', 'page', 'rows'].sort())

  sessionBudget.events = Array.from({ length: 100 }, (_, index) => ({ at: Date.now() - index, rows: 0 }))
  const limited = await executeAssistantTool({ ...common, budget: { count: 0 } })
  assert.match(limited.error, /session read budget/)
  assert.equal(audits.length, 1)
})

test('reduces the last page to the remaining session row allowance', async() => {
  const sessionBudget = { events: [{ at: Date.now(), rows: 999 }] }
  const host = { queryRecords: async input => ({ records: Array.from({ length: input.limit }, (_, index) => ({ name: `Row ${index}` })) }) }
  const result = await executeAssistantTool({
    call: { type: 'function', function: { name: 'query_records', arguments: JSON.stringify({ entity: 'Counter', limit: 10 }) } },
    catalog, host, operations: [], budget: { count: 0 }, sessionBudget
  })
  assert.equal(result.records.length, 1)
  assert.equal(result.query.limit, 1)
  assert.equal(result.query.budgetLimited, true)
  assert.equal(sessionBudget.events.reduce((total, event) => total + event.rows, 0), 1000)
})

test('rejects unapproved tool names and malformed entity lists', async() => {
  const args = { catalog, host: {}, operations: [], budget: { count: 0 } }
  assert.deepEqual(await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'delete_record', arguments: '{}' } } }), { error: 'This tool is not available.' })
  assert.deepEqual(await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'list_entities', arguments: '{"url":"https://example.test"}' } } }), { error: 'This tool takes no arguments.' })
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
