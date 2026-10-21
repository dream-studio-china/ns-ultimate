import { prepareRecordQuery } from './capabilities.js'

const TOOL_BUDGET = 4

export function createAssistantTools(catalog) {
  const readable = catalog.filter(item => item.readable)
  const tools = [{
    type: 'function',
    function: {
      name: 'list_entities',
      description: 'List admin entities and their safe, configured read fields. This does not access record data.',
      parameters: { type: 'object', properties: {}, required: [], additionalProperties: false }
    }
  }]
  if (readable.length) {
    tools.push({
      type: 'function',
      function: {
        name: 'query_records',
        description: 'Read a small page from a documented, configured admin collection. Only use simple equality filters. The application will ask the user before every record read because selected data is sent to the configured model provider.',
        parameters: {
          type: 'object',
          properties: {
            entity: { type: 'string', enum: readable.map(item => item.key) },
            page: { type: 'integer', minimum: 1, maximum: 100 },
            limit: { type: 'integer', minimum: 1, maximum: 20 },
            filters: {
              type: 'array',
              maxItems: 5,
              items: {
                type: 'object',
                properties: {
                  field: { type: 'string' },
                  value: { type: ['string', 'number', 'boolean', 'null'] }
                },
                required: ['field', 'value'],
                additionalProperties: false
              }
            }
          },
          required: ['entity'],
          additionalProperties: false
        }
      }
    })
  }
  return tools
}

export async function executeAssistantTool({ call, catalog, host, operations, confirmRead, budget, signal, assertActive = () => {} }) {
  if (!call || call.type !== 'function' || !call.function || typeof call.function.name !== 'string') {
    return { error: 'Unsupported tool call.' }
  }
  if (call.function.name === 'list_entities') {
    let args
    try { args = JSON.parse(call.function.arguments || '{}') } catch { return { error: 'Invalid tool arguments.' } }
    if (!args || typeof args !== 'object' || Array.isArray(args) || Object.keys(args).length) return { error: 'This tool takes no arguments.' }
    const entities = catalog.slice(0, 60).map(item => ({
      key: item.key,
      name: item.name,
      readable: item.readable,
      fields: item.fields.slice(0, 12).map(field => ({ property: field.property, label: field.label, type: field.type, required: field.required })),
      filters: item.filters.slice(0, 10).map(filter => ({ property: filter.property, type: filter.type, options: filter.options.slice(0, 20) }))
    }))
    return { entities, truncated: catalog.length > entities.length }
  }
  if (call.function.name !== 'query_records') return { error: 'This tool is not available.' }
  if (budget.cancelled) return { error: 'The user declined a record read; no further record queries are allowed in this task.' }
  if (budget.count >= TOOL_BUDGET) return { error: 'The read-query limit for this task has been reached.' }

  let input
  try { input = JSON.parse(call.function.arguments || '') } catch { return { error: 'Invalid query arguments.' } }
  let query
  try { query = prepareRecordQuery(input, catalog) } catch (error) { return { error: error.message || 'The query was rejected by policy.' } }
  const capability = catalog.find(item => item.key === query.entity)
  const approved = await confirmRead({
    entity: capability.name,
    page: query.page,
    limit: query.limit,
    fields: query.fields.map(property => capability.fields.find(field => field.property === property)?.label || property),
    filters: input.filters || []
  })
  if (!approved) {
    budget.cancelled = true
    return { cancelled: true, message: 'The user declined this data read.' }
  }
  assertActive()
  if (signal?.aborted) return { cancelled: true, message: 'The read query was cancelled.' }
  budget.count += 1
  try {
    return await host.queryRecords(input, operations, signal)
  } catch (error) {
    return { error: error.message || 'The read query failed.' }
  }
}

export const MAX_ASSISTANT_TOOL_CALLS = TOOL_BUDGET
