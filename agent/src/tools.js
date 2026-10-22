import { prepareRecordQuery } from './capabilities.js'
import { resolveAdminNavigation } from './navigation.js'

const TOOL_BUDGET = 4
const SESSION_QUERY_LIMIT = 100
const SESSION_ROW_LIMIT = 1000
const SESSION_WINDOW_MS = 15 * 60 * 1000

export function createAssistantTools(catalog, navigation = []) {
  const readable = catalog.filter(item => item.readable)
  const tools = [{
    type: 'function',
    function: {
      name: 'list_entities',
      description: 'List admin entities and their safe, configured read fields. This does not access record data.',
      parameters: { type: 'object', properties: {}, required: [], additionalProperties: false }
    }
  }]
  if (navigation.length) {
    tools.push({
      type: 'function',
      function: {
        name: 'list_admin_pages',
        description: 'List admin collection pages that the assistant is allowed to open. Does not read records or change data.',
        parameters: { type: 'object', properties: {}, required: [], additionalProperties: false }
      }
    }, {
      type: 'function',
      function: {
        name: 'navigate_admin_page',
        description: 'Open a registered admin list page, or its read-only detail view for a numeric ID/UUID. Only call this when the user explicitly asks to open or navigate to a page or record. Never navigate based on API documentation or record content.',
        parameters: {
          type: 'object',
          properties: {
            page: { type: 'string', enum: navigation.map(item => item.key) },
            identifier: { type: 'string', description: 'Optional positive numeric record ID or UUID; opens the record detail view.' }
          },
          required: ['page'],
          additionalProperties: false
        }
      }
    })
  }
  if (readable.length) {
    tools.push({
      type: 'function',
      function: {
        name: 'query_records',
        description: 'Read one bounded page from a documented admin collection. Choose only the operator advertised for each configured filter: contains is substring matching, equals is exact matching, and numeric/date fields may allow comparisons. Results include pagination metadata when available. Do not fetch additional pages unless the user asks.',
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
                  operator: { type: 'string', enum: ['contains', 'equals', 'gt', 'gte', 'lt', 'lte'] },
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

export async function executeAssistantTool({ call, catalog, navigation = [], host, operations, budget, sessionBudget = { events: [] }, onReadAudit = () => {}, signal, assertActive = () => {} }) {
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
      filters: item.filters.slice(0, 10).map(filter => ({ property: filter.property, label: filter.label, type: filter.type, operator: filter.operator, operators: filter.operators, options: filter.options.slice(0, 20), dynamicOptions: filter.dynamicOptions }))
    }))
    return { entities, truncated: catalog.length > entities.length }
  }
  if (call.function.name === 'list_admin_pages') {
    let args
    try { args = JSON.parse(call.function.arguments || '{}') } catch { return { error: 'Invalid tool arguments.' } }
    if (!args || typeof args !== 'object' || Array.isArray(args) || Object.keys(args).length) return { error: 'This tool takes no arguments.' }
    return { pages: navigation.slice(0, 100), truncated: navigation.length > 100 }
  }
  if (call.function.name === 'navigate_admin_page') {
    let args
    try { args = JSON.parse(call.function.arguments || '') } catch { return { error: 'Invalid navigation arguments.' } }
    if (!args || typeof args !== 'object' || Array.isArray(args) || Object.keys(args).some(key => !['page', 'identifier'].includes(key))) {
      return { error: 'Navigation arguments contain unsupported parameters.' }
    }
    try { resolveAdminNavigation(navigation, args.page, args.identifier) } catch (error) { return { error: error.message || 'Navigation was rejected by policy.' } }
    if (typeof host?.navigateAdminPage !== 'function') return { error: 'Admin navigation is not available.' }
    assertActive()
    try {
      const result = await host.navigateAdminPage(args.page, args.identifier)
      return { navigated: true, ...result }
    } catch (error) {
      return { error: error.message || 'The admin page could not be opened.' }
    }
  }
  if (call.function.name !== 'query_records') return { error: 'This tool is not available.' }
  if (budget.count >= TOOL_BUDGET) return { error: 'The read-query limit for this task has been reached.' }

  let input
  try { input = JSON.parse(call.function.arguments || '') } catch { return { error: 'Invalid query arguments.' } }
  let query
  try { query = prepareRecordQuery(input, catalog) } catch (error) { return { error: error.message || 'The query was rejected by policy.' } }
  const now = Date.now()
  if (!Array.isArray(sessionBudget.events)) sessionBudget.events = []
  const cutoff = now - SESSION_WINDOW_MS
  sessionBudget.events = sessionBudget.events.filter(event => Number.isFinite(event.at) && event.at > cutoff && event.at <= now)
  const rowsUsed = sessionBudget.events.reduce((total, event) => total + (Number.isInteger(event.rows) && event.rows > 0 ? event.rows : 0), 0)
  if (sessionBudget.events.length >= SESSION_QUERY_LIMIT || rowsUsed >= SESSION_ROW_LIMIT) {
    return { error: 'The assistant session read budget has been reached. Try again after the 15-minute budget window resets.' }
  }
  const remainingRows = SESSION_ROW_LIMIT - rowsUsed
  const requestedLimit = query.limit
  if (query.limit > remainingRows) {
    input.limit = remainingRows
    query = prepareRecordQuery(input, catalog)
  }
  const budgetLimited = query.limit < requestedLimit
  assertActive()
  if (signal?.aborted) return { cancelled: true, message: 'The read query was cancelled.' }
  budget.count += 1
  const budgetEvent = { at: Date.now(), rows: 0 }
  sessionBudget.events.push(budgetEvent)
  const startedAt = Date.now()
  const audit = metadata => {
    try {
      onReadAudit({
        at: new Date().toISOString(),
        entity: input.entity,
        page: query.page,
        ...metadata
      })
    } catch { /* Observability must never affect query execution. */ }
  }
  try {
    const result = await host.queryRecords(input, operations, signal)
    if (!Array.isArray(result?.records)) {
      audit({ durationMs: Date.now() - startedAt, rows: 0, outcome: 'error', failureType: 'invalid-response' })
      return { error: 'The read query returned an unsupported response.', failureType: 'invalid-response' }
    }
    const records = result.records.slice(0, query.limit)
    budgetEvent.rows = records.length
    audit({ durationMs: Date.now() - startedAt, rows: records.length, outcome: 'success', failureType: null })
    return {
      ...result,
      records,
      count: records.length,
      truncated: result.truncated === true || records.length < result.records.length,
      query: {
        entity: input.entity,
        page: query.page,
        limit: query.limit,
        durationMs: Date.now() - startedAt,
        completedAt: new Date().toISOString(),
        budgetLimited,
        filters: (input.filters || []).map(filter => ({
          field: filter.field,
          operator: filter.operator ?? catalog.find(item => item.key === input.entity)?.filters.find(item => item.property === filter.field)?.operator,
          value: filter.value
        }))
      }
    }
  } catch (error) {
    const message = error.message || ''
    const failureType = /timeout|timed out/i.test(message) || error.name === 'TimeoutError' ? 'timeout'
      : /\b(401|403)\b|unauthori[sz]ed|forbidden|not available to the current account/i.test(message) ? 'authorization'
        : error.name === 'AbortError' || signal?.aborted ? 'cancelled' : 'request-failed'
    audit({ durationMs: Date.now() - startedAt, rows: 0, outcome: 'error', failureType })
    return { error: failureType === 'timeout' ? 'The read query timed out.' : failureType === 'authorization' ? 'The current account is not authorized to read this collection.' : failureType === 'cancelled' ? 'The read query was cancelled.' : 'The read query failed.', failureType }
  }
}

export const MAX_ASSISTANT_TOOL_CALLS = TOOL_BUDGET
