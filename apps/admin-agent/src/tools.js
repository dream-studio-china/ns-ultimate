import { prepareRecordQuery } from './capabilities.js'
import { resolveAdminNavigation } from './navigation.js'
import { createWritePreview } from './write-preview.js'

export function createAssistantTools(catalog, navigation = [], previewPolicy = {}, writeCapabilities = [], inspectionPolicy = {}) {
  const readable = catalog.filter(item => item.readable)
  const inspectable = Object.keys(inspectionPolicy || {}).filter(key => readable.some(item => item.key === key))
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
            limit: { type: 'integer', minimum: 1, maximum: 60 },
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
  if (inspectable.length) {
    tools.push({
      type: 'function',
      function: {
        name: 'inspect_record_configuration',
        description: 'Read one record\'s allowlisted configuration scalars through its documented GET detail endpoint. Use only when the user asks about current configuration for a record you can identify with an exact numeric ID/UUID. Returns only fixed non-sensitive nested values with their retrieval time; never invent fields or paths. The result is cached backend data, not live device or scheduler state: never infer that a scheduler or worker is running, and never claim a complete root cause from it.',
        parameters: {
          type: 'object',
          properties: {
            entity: { type: 'string', enum: inspectable },
            identifier: { type: 'string', description: 'Exact record numeric ID or UUID.' }
          },
          required: ['entity', 'identifier'],
          additionalProperties: false
        }
      }
    })
  }
  if (Object.keys(previewPolicy).length) tools.push({
    type: 'function',
    function: {
      name: 'preview_record_update',
      description: `Produce an unsaved single-record update preview only when the user asks to change a record. No write is performed. First query the target and use its exact ID/UUID. Allowed fields: ${JSON.stringify(previewPolicy)}. Never invent old values or claim a change was saved.`,
      parameters: {
        type: 'object',
        properties: {
          entity: { type: 'string', enum: Object.keys(previewPolicy) },
          identifier: { type: 'string' },
          changes: { type: 'object', description: 'Explicit proposed values for allowlisted fields only.' }
        },
        required: ['entity', 'identifier', 'changes'], additionalProperties: false
      }
    }
  })
  if (writeCapabilities.length) tools.push({
    type: 'function',
    function: {
      name: 'prepare_record_change',
      description: `Prepare a server-validated single-record change ONLY when the user explicitly requests a write. Nothing is saved to the target until the user clicks confirmation in the UI. Never claim success from preparation. Use an exact ID/UUID for update/delete, ask for clarification if ambiguous, and never invent relation IDs. Supported policy: ${JSON.stringify(writeCapabilities)}`,
      parameters: {
        type: 'object', properties: {
          entity: { type: 'string', enum: writeCapabilities.map(item => item.entity) },
          action: { type: 'string', enum: ['create', 'update', 'delete'] },
          identifier: { type: 'string', description: 'Exact target numeric ID or UUID. Omit for create.' },
          changes: { type: 'object', description: 'Explicit allowlisted field values; omit for delete.' }
        }, required: ['entity', 'action'], additionalProperties: false
      }
    }
  })
  return tools
}

export async function executeAssistantTool({ call, catalog, navigation = [], previewPolicy = {}, snapshots = new Map(), host, operations, onReadAudit = () => {}, onPreparedChange = () => {}, signal, assertActive = () => {} }) {
  if (!call || call.type !== 'function' || !call.function || typeof call.function.name !== 'string') {
    return { error: 'Unsupported tool call.' }
  }
  if (call.function.name === 'prepare_record_change') {
    assertActive()
    if (signal?.aborted) return { error: 'The operation was cancelled.' }
    if (typeof host?.prepareRecordChange !== 'function') return { error: 'Change preparation is unavailable.' }
    try {
      const input = JSON.parse(call.function.arguments || '')
      const preview = await host.prepareRecordChange(input, signal)
      assertActive()
      if (signal?.aborted) return { error: 'The operation was cancelled.' }
      if (!preview || preview.saved !== false || !Array.isArray(preview.differences)) return { error: 'Unsupported prepared change response.' }
      onPreparedChange(preview)
      // Execution identifiers and full before/after values stay in the host/UI boundary.
      return { prepared: true, saved: false, entity: preview.entity, action: preview.action, identifier: preview.identifier,
        affectedRecords: 1, message: 'Awaiting explicit UI confirmation. No target data has been changed.' }
    } catch {
      return { error: 'Change preparation failed. Check the displayed server error, permissions and field values. Nothing was submitted for execution.' }
    }
  }
  if (call.function.name === 'inspect_record_configuration') {
    assertActive()
    if (signal?.aborted) return { error: 'The configuration inspection was cancelled.', failureType: 'cancelled' }
    if (typeof host?.inspectRecordConfiguration !== 'function') return { error: 'Configuration inspection is unavailable.' }
    let input
    try { input = JSON.parse(call.function.arguments || '') } catch { return { error: 'Invalid inspection arguments.' } }
    try {
      const result = await host.inspectRecordConfiguration(input, operations, signal)
      assertActive()
      if (signal?.aborted) return { error: 'The configuration inspection was cancelled.', failureType: 'cancelled' }
      if (!result || typeof result.configuration !== 'object' || result.configuration === null || Array.isArray(result.configuration)) {
        return { error: 'The configuration inspection returned an unsupported response.', failureType: 'invalid-response' }
      }
      return result
    } catch (error) {
      const message = error.message || ''
      const failureType = /timed out/i.test(message) ? 'timeout'
        : /\b(401|403)\b|unauthori[sz]ed|forbidden|not available to the current account/i.test(message) ? 'authorization'
          : /cancelled/i.test(message) || error.name === 'AbortError' || signal?.aborted ? 'cancelled' : 'request-failed'
      return { error: failureType === 'timeout' ? 'The configuration inspection timed out.' : failureType === 'authorization' ? 'The current account is not authorized to inspect this record.' : failureType === 'cancelled' ? 'The configuration inspection was cancelled.' : 'The configuration inspection failed.', failureType }
    }
  }
  if (call.function.name === 'preview_record_update') {
    assertActive()
    if (signal?.aborted) return { error: 'The preview was cancelled.' }
    try { return createWritePreview(JSON.parse(call.function.arguments || ''), previewPolicy, snapshots) }
    catch (error) { return { error: error.message || 'The update preview was rejected.' } }
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
  let input
  try { input = JSON.parse(call.function.arguments || '') } catch { return { error: 'Invalid query arguments.' } }
  let query
  try { query = prepareRecordQuery(input, catalog) } catch (error) { return { error: error.message || 'The query was rejected by policy.' } }
  assertActive()
  if (signal?.aborted) return { cancelled: true, message: 'The read query was cancelled.' }
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
