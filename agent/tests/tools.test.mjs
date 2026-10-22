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
  assert.deepEqual(result, { records: [{ name: 'West' }] })
  assert.equal(queryCount, 1)
  assert.equal(budget.count, 1)
})

test('rejects unapproved tool names and malformed entity lists', async() => {
  const args = { catalog, host: {}, operations: [], budget: { count: 0 } }
  assert.deepEqual(await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'delete_record', arguments: '{}' } } }), { error: 'This tool is not available.' })
  assert.deepEqual(await executeAssistantTool({ ...args, call: { type: 'function', function: { name: 'list_entities', arguments: '{"url":"https://example.test"}' } } }), { error: 'This tool takes no arguments.' })
})
