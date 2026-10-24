/** Config-driven CRUD. Proposals and receipts exist only in this host session. */
const targetPattern = /^(?:[1-9]\d{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i
const unsafe = new Set(['__proto__', 'prototype', 'constructor'])
const clone = value => JSON.parse(JSON.stringify(value))
const same = (a, b) => canonical(a) === canonical(b)
const canonical = value => JSON.stringify(value, (_, item) => plain(item)
  ? Object.fromEntries(Object.keys(item).sort().map(key => [key, item[key]])) : item)
const conflict = message => Object.assign(new Error(message), { code: 'proposal_conflict' })
function plain(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    && [Object.prototype, null].includes(Object.getPrototypeOf(value))
}
export function safeJson(value, depth = 0) {
  if (depth > 16) return false
  if (value === null || typeof value === 'string' || typeof value === 'boolean') return true
  if (typeof value === 'number') return Number.isFinite(value)
  if (Array.isArray(value)) return value.length <= 1000 && value.every(item => safeJson(item, depth + 1))
  return plain(value) && Object.keys(value).every(key => !unsafe.has(key) && safeJson(value[key], depth + 1))
}
function active(signal) {
  if (signal?.aborted) throw Object.assign(new Error('The request was cancelled.'), { name: 'AbortError' })
}
function ruleFor(field) {
  return {
    type: 'json', nullable: true, requiredOnCreate: field.required, requiredOnUpdate: field.required,
    ...(Object.hasOwn(field, 'defaultValue') ? { default: field.defaultValue } : {}),
    description: `${field.label}: ${field.type}${field.relation ? `; ${field.relation.multiple ? 'array of ' : ''}${field.relation.target} ${field.relation.valueKey}` : ''}${field.multiplier ? `; enter ${field.currency} major units (for example 12.50); stored as minor units x${field.multiplier}` : ''}${field.options ? `; options: ${JSON.stringify(field.options.map(item => item.value))}` : ''}${field.nestedPaths?.length ? `; nested paths: ${field.nestedPaths.slice(0, 40).join(', ')}` : ''}`
  }
}
export function configuredCapabilities(projection, policy, isActionAvailable) {
  if (typeof policy?.isActionAllowed !== 'function' || typeof policy?.isFieldAllowed !== 'function') throw new TypeError('An explicit host mutation policy is required.')
  return projection.entities.flatMap(entity => {
    const actionFields = {}
    for (const [action, config] of Object.entries(entity.actions)) {
      if (!config.configured || !policy.isActionAllowed(entity.name, action)) continue
      // The admin form may enable an action whose CRUD route does not exist
      // (for example Invoice PUT). Do not advertise an operation that can only
      // fail after the user confirms it.
      if (typeof isActionAvailable === 'function' && !isActionAvailable(entity.name, action, entity.path)) continue
      const fields = config.fields.filter(field => policy.isFieldAllowed(entity.name, field.property, action))
      if (action === 'create' && config.fields.some(field => field.required && !policy.isFieldAllowed(entity.name, field.property, action))) continue
      // A required unsupported control must not silently disappear from create.
      if (action === 'create' && projection.unsupported.some(item => item.entityName === entity.name && item.required === true
        && ['custom_component', 'unsupported_type', 'dynamic_hidden', 'callback_field'].includes(item.reason))) continue
      if (action !== 'delete' && !fields.length) continue
      actionFields[action] = Object.fromEntries(fields.map(field => [field.property, ruleFor(field)]))
    }
    const actions = Object.keys(actionFields)
    return actions.length ? [{ entity: entity.name, actions, actionFields,
      fields: { ...actionFields.create, ...actionFields.update },
      validatePreview: preview => preview?.entity === entity.name && preview.saved === false
        && actions.includes(preview.action) && Array.isArray(preview.differences)
    }] : []
  })
}
function valueFor(field, value, { convertCurrency = false, stored = false } = {}) {
  if (value === null) return null
  if (field.relation) {
    const id = item => {
      const key = plain(item) ? item[field.relation.valueKey] : item
      if (!targetPattern.test(String(key))) throw new TypeError(`Invalid relation identifier: ${field.property}.`)
      return key
    }
    return field.relation.multiple ? (Array.isArray(value) ? value.map(id) : (() => { throw new TypeError(`Expected relation array: ${field.property}.`) })()) : id(value)
  }
  if (field.type === 'object' || field.type === 'array') {
    if (!safeJson(value)) throw new TypeError(`Invalid JSON: ${field.property}.`)
    return value
  }
  // Stored enum labels may differ from the form's submit values (Store stores
  // "active" while the status endpoint accepts "activate"). Options constrain
  // new input, not the current snapshot used for comparison.
  if (!stored && field.type === 'enum' && field.options && !field.options.some(item => item.value === value)) throw new TypeError(`Invalid option: ${field.property}.`)
  if (field.type === 'boolean' && typeof value !== 'boolean') throw new TypeError(`Expected boolean: ${field.property}.`)
  if (['number', 'integer'].includes(field.type) && (typeof value !== 'number' || !Number.isFinite(value)
    || field.type === 'integer' && !Number.isSafeInteger(value))) throw new TypeError(`Expected ${field.type}: ${field.property}.`)
  if (['string', 'date', 'datetime'].includes(field.type) && typeof value !== 'string') throw new TypeError(`Expected text: ${field.property}.`)
  if (convertCurrency && field.multiplier) {
    const scaled = Math.round(value * field.multiplier)
    if (!Number.isSafeInteger(scaled)) throw new TypeError(`The amount is out of range: ${field.property}.`)
    return scaled
  }
  return value
}
/**
 * Validate the JSON-schema subset used by composed form controls. The backend
 * remains authoritative; this only prevents a preview that cannot possibly be
 * accepted and gives the model the same constraints the admin form displays.
 */
function validateSchema(schema, value, path, depth = 0) {
  if (!plain(schema) || depth > 12) return
  if (value === null) {
    if (schema.nullable === true || schema.type === 'null') return
    if (Array.isArray(schema.type) && schema.type.includes('null')) return
    throw new TypeError(`Expected ${schema.type || 'a value'}: ${path}.`)
  }
  if (Array.isArray(schema.anyOf) && schema.anyOf.length) {
    const accepted = schema.anyOf.some(candidate => {
      try { validateSchema(candidate, value, path, depth + 1); return true } catch { return false }
    })
    if (!accepted) throw new TypeError(`Value does not match the allowed schema: ${path}.`)
    return
  }
  if (schema.type === 'object' || plain(schema.properties)) {
    if (!plain(value)) throw new TypeError(`Expected an object: ${path}.`)
    for (const required of Array.isArray(schema.required) ? schema.required : []) {
      if (!Object.hasOwn(value, required) || value[required] === null) throw new TypeError(`Missing required nested field: ${path}.${required}.`)
    }
    const properties = plain(schema.properties) ? schema.properties : {}
    for (const [key, child] of Object.entries(value)) {
      if (Object.hasOwn(properties, key)) validateSchema(properties[key], child, `${path}.${key}`, depth + 1)
      else if (schema.additionalProperties === false) throw new TypeError(`Unsupported nested field: ${path}.${key}.`)
      else if (plain(schema.additionalProperties)) validateSchema(schema.additionalProperties, child, `${path}.${key}`, depth + 1)
    }
    if (plain(schema.dependencies)) {
      for (const [key, dependencies] of Object.entries(schema.dependencies)) {
        if (!Object.hasOwn(value, key)) continue
        for (const dependency of Array.isArray(dependencies) ? dependencies : []) {
          if (!Object.hasOwn(value, dependency)) throw new TypeError(`Nested field ${path}.${dependency} is required with ${path}.${key}.`)
        }
      }
    }
    return
  }
  if (schema.type === 'array') {
    if (!Array.isArray(value)) throw new TypeError(`Expected an array: ${path}.`)
    if (schema.items) value.forEach((item, index) => validateSchema(schema.items, item, `${path}[${index}]`, depth + 1))
    return
  }
  if (schema.type === 'string') {
    if (typeof value !== 'string') throw new TypeError(`Expected text: ${path}.`)
    if (schema.minLength !== undefined && value.length < schema.minLength) throw new TypeError(`Text is too short: ${path}.`)
    if (schema.maxLength !== undefined && value.length > schema.maxLength) throw new TypeError(`Text is too long: ${path}.`)
    if (typeof schema.pattern === 'string' && !new RegExp(schema.pattern).test(value)) throw new TypeError(`Text does not match the configured pattern: ${path}.`)
  } else if (schema.type === 'integer' || schema.type === 'number') {
    if (typeof value !== 'number' || !Number.isFinite(value) || (schema.type === 'integer' && !Number.isSafeInteger(value))) {
      throw new TypeError(`Expected ${schema.type}: ${path}.`)
    }
    if (schema.minimum !== undefined && value < schema.minimum) throw new TypeError(`Value is below the minimum: ${path}.`)
    if (schema.maximum !== undefined && value > schema.maximum) throw new TypeError(`Value is above the maximum: ${path}.`)
  } else if (schema.type === 'boolean') {
    if (typeof value !== 'boolean') throw new TypeError(`Expected boolean: ${path}.`)
  }
  if (Array.isArray(schema.enum) && !schema.enum.some(item => item === value)) throw new TypeError(`Invalid option: ${path}.`)
}

function mergeObject(before, patch) {
  if (!plain(patch)) return clone(patch)
  const result = plain(before) ? clone(before) : {}
  for (const [key, value] of Object.entries(patch)) result[key] = plain(value) ? mergeObject(result[key], value) : clone(value)
  return result
}
function nestedAllowed(field, before, after, prefix = '') {
  if (same(before, after)) return true
  if (!plain(after)) return prefix !== '' && !field.nestedPaths.some(path => path.startsWith(`${prefix}.`))
  return Object.keys(after).every(key => {
    const path = prefix ? `${prefix}.${key}` : key
    return same(before?.[key], after[key]) || field.nestedPaths.includes(path) && nestedAllowed(field, before?.[key], after[key], path)
  })
}

export function createConfigMutationHost({ getProjection, request, policy, beforeDelete, specialWrite, isActionAvailable, now = Date.now, randomId = () => crypto.randomUUID() }) {
  const operations = new Map()
  let disposed = false
  let writing = false
  const projection = () => {
    if (disposed) throw conflict('The admin session ended.')
    return getProjection()
  }
  const capabilities = () => configuredCapabilities(projection(), policy, isActionAvailable)
  function binding(entity, action) {
    const current = projection()
    const capability = configuredCapabilities(current, policy, isActionAvailable).find(item => item.entity === entity && item.actions.includes(action))
    const config = current.entities.find(item => item.name === entity)
    if (!capability || !config?.path) throw conflict('This entity/action is not available in the current admin configuration.')
    return { current, config, fields: config.actions[action].fields.filter(field => policy.isFieldAllowed(entity, field.property, action)) }
  }
  const receipt = op => ({ operationId: op.id, entity: op.entity, action: op.action, identifier: op.identifier,
    status: op.status, createdAt: op.createdAt, expiresAt: op.expiresAt, fields: op.fields,
    ...(op.rows ? { rowCount: op.rows, results: clone(op.results || []) } : {}) })
  function lookup(id) {
    projection()
    const op = operations.get(id)
    if (!op) throw new Error('No operation is available in this browser session.')
    if (op.status === 'pending' && Date.parse(op.expiresAt) <= now()) { op.status = 'expired'; delete op.draft }
    return op
  }
  function retain(data) {
    for (const [id, op] of operations) {
      if (op.status === 'pending' && Date.parse(op.expiresAt) <= now()) { op.status = 'expired'; delete op.draft }
      if (operations.size >= 200 && ['succeeded', 'cancelled', 'expired'].includes(op.status)) operations.delete(id)
    }
    if (operations.size >= 200) throw new Error('This session has too many unfinished operations. Inspect outcomes and discard unused previews.')
    const id = randomId()
    const op = { ...data, id, status: 'pending', createdAt: new Date(now()).toISOString(), expiresAt: new Date(now() + 300000).toISOString() }
    operations.set(id, op)
    return op
  }
  async function draft(input, signal, { normalized = false } = {}) {
    active(signal)
    if (!plain(input) || Object.keys(input).some(key => !['entity', 'action', 'identifier', 'changes'].includes(key))) throw new TypeError('Unsupported change parameters.')
    const { current, config, fields } = binding(input.entity, input.action)
    if (input.action === 'create' ? input.identifier !== undefined : !targetPattern.test(String(input.identifier))) throw new TypeError('An exact target identifier is required; omit it for create.')
    if (input.action === 'delete' && input.changes !== undefined) throw new TypeError('Deletion does not accept changes.')
    if (input.action !== 'delete' && (!plain(input.changes) || !Object.keys(input.changes).length || !safeJson(input.changes))) throw new TypeError('Changes must be a non-empty JSON field object.')
    const changes = {}
    const byName = new Map(fields.map(field => [field.property, field]))
    for (const [key, value] of Object.entries(input.changes || {})) {
      if (!byName.has(key)) throw new TypeError(`Unsupported change field: ${key}.`)
      changes[key] = valueFor(byName.get(key), value, { convertCurrency: !normalized })
    }
    if (input.action === 'create') for (const field of fields) {
      if (!Object.hasOwn(changes, field.property) && Object.hasOwn(field, 'defaultValue')) changes[field.property] = clone(field.defaultValue)
      if (field.required && (changes[field.property] === undefined || changes[field.property] === null || changes[field.property] === '')) throw new TypeError(`Missing required field: ${field.property}.`)
    }
    const path = config.path + (input.action === 'create' ? '' : `/${encodeURIComponent(input.identifier)}`)
    const before = input.action === 'create' ? {} : (await request.get(path, { signal, timeout: 60000 })).data
    active(signal)
    if (projection().revision !== current.revision) throw conflict('The admin configuration changed.')
    if (!plain(before)) throw new TypeError('The record detail response is unavailable.')
    if (input.action !== 'create' && ![before.id, before.uuid].some(value => value != null && String(value).toLowerCase() === String(input.identifier).toLowerCase())) {
      throw new TypeError('The detail response does not identify the requested record.')
    }
    if (input.action === 'delete' && typeof beforeDelete === 'function') {
      await beforeDelete({ entity: input.entity, identifier: input.identifier, record: before, signal })
      active(signal)
      if (projection().revision !== current.revision) throw conflict('The admin configuration changed.')
    }
    const snapshot = {}
    const differences = []
    const selected = input.action === 'delete' ? fields : fields.filter(field => Object.hasOwn(changes, field.property))
    if (input.action === 'delete') {
      // Deletion previews contain identity only, not unrelated sensitive fields.
      differences.push({ property: 'id', before: input.identifier, after: null })
      snapshot.record = before
    }
    for (const field of selected) {
      const key = field.property
      if (input.action !== 'create' && !Object.hasOwn(before, key)) throw new TypeError(`Current field is missing from record details: ${key}.`)
      if (input.action === 'update' && field.required
        && (changes[key] === null || changes[key] === '')) throw new TypeError(`Missing required field: ${key}.`)
      const old = input.action === 'create' ? null : valueFor(field, before[key], { stored: true })
      let after = changes[key]
      if (field.nestedPaths) {
        if (!nestedAllowed(field, old, after)) throw new TypeError(`Change includes hidden or read-only nested fields: ${key}.`)
        after = mergeObject(old, after)
        changes[key] = after
      }
      if (field.schema) validateSchema(field.schema, after, key)
      snapshot[key] = old
      if (input.action === 'create' || !same(old, after)) differences.push({ property: key, before: old, after })
    }
    if (!differences.length) throw new TypeError('The proposed change has no differences.')
    if (JSON.stringify(differences).length > 200000) throw new TypeError('The preview is too large. Use the standard editor.')
    let submission
    if (typeof specialWrite === 'function') {
      const special = specialWrite({ entity: input.entity, action: input.action,
        identifier: input.identifier == null ? null : String(input.identifier), changes, record: before,
        path: config.path })
      if (special !== undefined && special !== null) {
        if (!plain(special) || typeof special.path !== 'string' || !['post', 'put', 'delete'].includes(special.method)
          || !plain(special.changes || {})) throw new TypeError('The special write adapter returned an unsupported request.')
        submission = { method: special.method, path: special.path, changes: special.changes || {} }
      }
    }
    return clone({ entity: input.entity, action: input.action, identifier: input.identifier == null ? null : String(input.identifier),
      revision: current.revision, path, changes, snapshot, differences, fields: Object.keys(changes), ...(submission ? { submission } : {}) })
  }
  function preview(op) {
    return { operationId: op.id, entity: op.entity, action: op.action, identifier: op.identifier,
      title: `${op.entity} ${op.action}`, affectedRecords: 1, expiresAt: op.expiresAt,
      differences: clone(op.draft.differences), saved: false }
  }
  async function prepareRecordChange(input, signal) {
    const data = await draft(input, signal)
    return preview(retain({ entity: data.entity, action: data.action, identifier: data.identifier, fields: data.fields, draft: data }))
  }
  async function check(op, signal) {
    if (op.status !== 'pending' || !op.draft) throw conflict('This proposal is no longer pending. Prepare a new preview.')
    if (projection().revision !== op.draft.revision) throw conflict('The admin configuration changed. Prepare a new preview.')
    const fresh = await draft({ entity: op.entity, action: op.action, ...(op.action === 'create' ? {} : { identifier: op.identifier }),
      ...(op.action === 'delete' ? {} : { changes: op.draft.changes }) }, signal, { normalized: true })
    if (!same(fresh.snapshot, op.draft.snapshot)) throw conflict('The record changed. Prepare a new preview.')
    return fresh
  }
  async function send(data, signal) {
    active(signal)
    binding(data.entity, data.action)
    if (projection().revision !== data.revision) throw conflict('The admin configuration changed before submission.')
    // The shared request client refreshes/replays 401 responses by default.
    // Disable that replay for mutations; a write must never be silently resent.
    const options = { signal, timeout: 60000, _retry: true }
    const submission = data.submission || { method: data.action === 'delete' ? 'delete' : data.action === 'create' ? 'post' : 'put',
      path: data.path, changes: data.changes }
    const result = submission.method === 'delete' ? await request.delete(submission.path, options)
      : submission.method === 'post' ? await request.post(submission.path, submission.changes, options)
        : await request.put(submission.path, submission.changes, options)
    if (!plain(result) || result.code !== undefined && ![0, 200].includes(result.code)) throw new Error('The CRUD response did not acknowledge success.')
    const candidate = data.action === 'create' ? result?.data?.id ?? result?.data?.uuid ?? null : data.identifier
    const identifier = candidate !== null && targetPattern.test(String(candidate)) ? candidate : null
    return { identifier: identifier === null ? null : String(identifier) }
  }
  async function commitRecordChange(id, signal) {
    const op = lookup(id)
    if (writing) throw conflict('Another write is already in progress.')
    if (op.status === 'pending' && [...operations.values()].some(item => item.status === 'unknown')) throw conflict('Inspect the unresolved outcome before starting another write.')
    writing = true
    try {
      let data
      try {
        data = await check(op, signal)
        active(signal)
      } catch (error) {
        // Every failure here precedes the mutation request, so it is not an
        // ambiguous write outcome. Keep the original diagnostic for the UI.
        error.code = 'proposal_conflict'
        throw error
      }
      op.status = 'submitting'
      try { Object.assign(op, await send(data, signal)); op.status = 'succeeded' }
      catch (error) { op.status = 'unknown'; throw error }
      finally { delete op.draft }
      return receipt(op)
    } finally { writing = false }
  }
  async function prepareRecordBatch(input, signal) {
    if (!plain(input) || Object.keys(input).some(key => !['entity', 'action', 'rows'].includes(key))
      || input.action !== 'create' || !Array.isArray(input.rows) || input.rows.length < 1 || input.rows.length > 20) throw new TypeError('Only create batches of 1–20 rows are supported.')
    const drafts = []
    for (const changes of input.rows) drafts.push(await draft({ entity: input.entity, action: 'create', changes }, signal))
    const op = retain({ entity: input.entity, action: 'create', identifier: null, rows: drafts.length,
      fields: [...new Set(drafts.flatMap(item => item.fields))], draft: drafts, results: [] })
    return batchPreview(op)
  }
  function batchPreview(op) {
    return { operationId: op.id, entity: op.entity, action: 'create', title: `${op.entity} create`,
      affectedRecords: op.rows, expiresAt: op.expiresAt, saved: false,
      rows: op.draft.map((data, index) => ({ row: index + 1, identifier: null, title: `${op.entity} ${index + 1}`, differences: clone(data.differences) })),
      warnings: ['Rows are submitted sequentially, not in one transaction. A failure may leave a partially created batch.'] }
  }
  async function commitRecordBatch(id, signal) {
    const op = lookup(id)
    if (writing) throw conflict('Another write is already in progress.')
    if (op.status !== 'pending' || !Array.isArray(op.draft)) throw conflict('This batch is no longer pending.')
    if ([...operations.values()].some(item => item.status === 'unknown')) throw conflict('Inspect the unresolved outcome before starting another write.')
    writing = true
    try {
      for (const data of op.draft) {
        binding(data.entity, 'create')
        if (projection().revision !== data.revision) throw conflict('The admin configuration changed.')
      }
      active(signal)
      op.status = 'submitting'
      for (let index = 0; index < op.draft.length; index += 1) {
        try {
          const result = await send(op.draft[index], signal)
          op.results.push({ row: index + 1, status: 'succeeded', ...result })
        } catch {
          op.results.push({ row: index + 1, status: 'unknown' })
          break
        }
      }
      for (let row = op.results.length + 1; row <= op.rows; row += 1) op.results.push({ row, status: 'not-submitted' })
      op.status = op.results.every(item => item.status === 'succeeded') ? 'succeeded' : 'unknown'
      delete op.draft
      return receipt(op)
    } finally { writing = false }
  }
  return {
    operationHistoryScope: 'session',
    getUnsupportedFormControls: () => projection().unsupported.filter(item => ['field', 'form'].includes(item.feature)),
    getWriteCapabilities: capabilities,
    getBatchCreateCapabilities: () => capabilities().filter(item => item.actions.includes('create')),
    prepareRecordChange, commitRecordChange, prepareRecordBatch, commitRecordBatch,
    async restoreRecordChange(id, signal) { const op = lookup(id); await check(op, signal); return preview(op) },
    async restoreRecordBatch(id, signal) {
      const op = lookup(id)
      if (op.status !== 'pending' || !Array.isArray(op.draft)) throw conflict('The batch is no longer pending.')
      active(signal)
      for (const data of op.draft) {
        binding(data.entity, 'create')
        if (projection().revision !== data.revision) throw conflict('The admin configuration changed.')
      }
      return batchPreview(op)
    },
    async getRecordChangeStatus(id) { return receipt(lookup(id)) },
    async getRecordBatchStatus(id) { return receipt(lookup(id)) },
    async listRecordChanges(page = 1) {
      if (!Number.isInteger(page) || page < 1 || page > 100) throw new TypeError('Invalid operation page.')
      const items = [...operations.keys()].reverse().map(id => receipt(lookup(id)))
      return { page, items: items.slice((page - 1) * 20, page * 20), hasMore: items.length > page * 20 }
    },
    async cancelRecordChange(id) { const op = lookup(id); if (op.status === 'pending') { op.status = 'cancelled'; delete op.draft } },
    async cancelRecordBatch(id) { const op = lookup(id); if (op.status === 'pending') { op.status = 'cancelled'; delete op.draft } },
    dispose() { disposed = true; operations.clear() }
  }
}
