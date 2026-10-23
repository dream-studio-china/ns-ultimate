/**
 * Headless create-only batch proposal support.
 *
 * The former manual import workbench has been intentionally replaced: files are
 * now ordinary chat attachments, and the model may prepare a Tag/Counter create
 * batch only through the host-declared batch capability policy. This module owns
 * the safe boundary between that model tool, the canonical server preview and the
 * explicit human confirmation card. It has no DOM, storage, provider or network
 * dependency of its own.
 *
 * Assumed host protocol (the coordinator owns adapting the final backend):
 *
 *   getBatchCreateCapabilities() -> [{
 *     entity: 'Tag' | 'Counter',
 *     actions: ['create'],
 *     actionFields: { create: { ...fieldRules } }
 *   }]
 *
 *   prepareRecordBatch({ entity, action: 'create', rows }, signal)
 *   restoreRecordBatch(operationId, signal)
 *   commitRecordBatch(operationId, signal)
 *   getRecordBatchStatus(operationId, signal)
 *   cancelRecordBatch(operationId)
 *
 * Every submitted field must appear in the canonical differences with the same
 * value; the UI displays canonical values and never guesses missing ones.
 */

const MAX_BATCH_ROWS = 20
const MAX_WARNINGS = 20
const MAX_WARNING_LENGTH = 500
const MAX_PREVIEW_CHARACTERS = 60000
const MAX_OPERATION_ID_LENGTH = 128
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const PHP_TRIM_PATTERN = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g
const OPERATION_ID_PATTERN = new RegExp(`^[A-Za-z0-9_-]{1,${MAX_OPERATION_ID_LENGTH}}$`)
const SAFE_PROPERTY = /^[A-Za-z][A-Za-z0-9_]*$/
const BATCH_ENTITIES = Object.freeze(['Tag', 'Counter'])
const SUPPORTED_RULE_TYPES = new Set(['string', 'utf8-string', 'integer', 'number', 'boolean', 'color', 'uuid', 'enum'])
const PREVIEW_KEYS = new Set([
  'operationId', 'entity', 'action', 'title', 'affectedRecords',
  'expiresAt', 'rows', 'saved', 'warnings'
])
const BATCH_STATUSES = new Set(['pending', 'succeeded', 'cancelled', 'expired', 'conflicted'])
const BATCH_HOST_METHODS = Object.freeze([
  'prepareRecordBatch', 'restoreRecordBatch', 'commitRecordBatch',
  'getRecordBatchStatus', 'cancelRecordBatch'
])

export { MAX_BATCH_ROWS, BATCH_ENTITIES }

export class BatchRequestError extends Error {
  constructor(message) {
    super(message)
    this.name = 'BatchRequestError'
    this.failureType = 'validation'
  }
}

function isPlainObject(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const prototype = Object.getPrototypeOf(value)
  return (prototype === Object.prototype || prototype === null)
    && Reflect.ownKeys(value).every(key => typeof key === 'string')
}

function hasUnsafeKeys(value) {
  return Object.keys(value).some(key => ['__proto__', 'prototype', 'constructor'].includes(key))
}

function safeText(value, maxLength) {
  if (typeof value !== 'string') return ''
  return value.replace(/[\u0000-\u001f\u007f]/g, ' ').slice(0, maxLength)
}

function fail(message) {
  throw new BatchRequestError(message)
}

function sameScalar(left, right) {
  return left === right || (typeof left === 'number' && typeof right === 'number' && Number.isNaN(left) && Number.isNaN(right))
}

function isDisplayScalar(value) {
  return value === null
    || typeof value === 'string'
    || typeof value === 'boolean'
    || (typeof value === 'number' && Number.isFinite(value))
}

function isSupportedRule(rule) {
  if (!isPlainObject(rule) || typeof rule.type !== 'string' || !SUPPORTED_RULE_TYPES.has(rule.type)) return false
  if (rule.maxLength !== undefined && (!Number.isInteger(rule.maxLength) || rule.maxLength < 0)) return false
  if (rule.minLength !== undefined && (!Number.isInteger(rule.minLength) || rule.minLength < 0)) return false
  if (rule.maxBytes !== undefined && (!Number.isInteger(rule.maxBytes) || rule.maxBytes < 0)) return false
  if (rule.minimum !== undefined && (typeof rule.minimum !== 'number' || !Number.isFinite(rule.minimum))) return false
  if (rule.maximum !== undefined && (typeof rule.maximum !== 'number' || !Number.isFinite(rule.maximum))) return false
  if (rule.type === 'enum' && (!Array.isArray(rule.values) || rule.values.length === 0 || !rule.values.every(value => typeof value === 'string'))) return false
  if (rule.pattern !== undefined) {
    if (typeof rule.pattern !== 'string') return false
    try { new RegExp(rule.pattern, rule.patternFlags || '') } catch { return false }
  }
  return true
}

function createFieldDescriptor(property, rule) {
  if (!SAFE_PROPERTY.test(property) || !isSupportedRule(rule)) return null
  return {
    property,
    required: rule.requiredOnCreate === true,
    nullable: rule.nullable === true,
    rule
  }
}

function createEntityDescriptor(capability) {
  if (!isPlainObject(capability) || !BATCH_ENTITIES.includes(capability.entity)) return null
  if (!Array.isArray(capability.actions) || !capability.actions.includes('create')) return null
  const createFields = capability.actionFields?.create
  if (!isPlainObject(createFields) || hasUnsafeKeys(createFields) || Object.keys(createFields).length === 0) return null
  const fields = []
  for (const [property, rule] of Object.entries(createFields)) {
    const field = createFieldDescriptor(property, rule)
    if (!field) return null
    fields.push(field)
  }
  if (capability.entity === 'Counter') {
    const storeField = fields.find(field => field.property === 'storeUuid')
    if (!storeField || storeField.rule.type !== 'uuid' || storeField.required !== true) return null
  }
  return { entity: capability.entity, fields }
}

/**
 * Normalize explicit host batch capabilities. Ordinary single-record write
 * capabilities are deliberately not accepted as a fallback: a host must opt in
 * to create-only Tag/Counter batches through getBatchCreateCapabilities.
 */
export function normalizeBatchCapabilities(capabilities) {
  if (!Array.isArray(capabilities)) return { available: false, reason: 'capabilities', entities: [] }
  const entities = BATCH_ENTITIES.flatMap(entity => {
    const capability = capabilities.find(item => isPlainObject(item) && item.entity === entity)
    const descriptor = createEntityDescriptor(capability)
    return descriptor ? [descriptor] : []
  })
  return { available: entities.length > 0, reason: entities.length ? '' : 'capabilities', entities }
}

/**
 * Inspect the host protocol without widening the create-field policy. All five
 * batch host methods and an explicit batch capability reader are required.
 */
export function inspectBatchHost(host) {
  if (!host || typeof host !== 'object') return { available: false, reason: 'host', entities: [], capabilities: [] }
  if (BATCH_HOST_METHODS.some(name => typeof host[name] !== 'function')) {
    return { available: false, reason: 'host', entities: [], capabilities: [] }
  }
  if (typeof host.getBatchCreateCapabilities !== 'function') {
    return { available: false, reason: 'capabilities', entities: [], capabilities: [] }
  }
  let capabilities
  try { capabilities = host.getBatchCreateCapabilities() } catch {
    return { available: false, reason: 'capabilities', entities: [], capabilities: [] }
  }
  const inspection = normalizeBatchCapabilities(capabilities)
  return {
    ...inspection,
    capabilities: inspection.available ? capabilities : []
  }
}

function validateStringField(rule, value, property) {
  if (typeof value !== 'string') fail(`Batch field "${property}" must be text.`)
  const checked = rule.trimForValidation === true ? value.replace(PHP_TRIM_PATTERN, '') : value
  if (rule.maxLength !== undefined && [...checked].length > rule.maxLength) {
    fail(`Batch field "${property}" is too long.`)
  }
  if (rule.minLength !== undefined && [...checked].length < rule.minLength) {
    fail(`Batch field "${property}" is too short.`)
  }
  if (rule.nonEmpty === true && checked.trim().length === 0) fail(`Batch field "${property}" cannot be empty.`)
  if (rule.trimExact === true && checked !== value) fail(`Remove leading or trailing spaces from "${property}".`)
  if (rule.disallowLessThan === true && checked.includes('<')) fail(`Batch field "${property}" cannot contain "<".`)
  if (rule.disallowHtmlTags === true && /<\/?[a-z][^>]*>/i.test(checked)) {
    fail(`Batch field "${property}" cannot contain HTML tags.`)
  }
  if (rule.pattern !== undefined && !new RegExp(rule.pattern, rule.patternFlags || '').test(checked)) {
    fail(`Batch field "${property}" has an invalid format.`)
  }
  if (rule.format === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(checked)) {
    fail(`Batch field "${property}" must be an email address.`)
  }
  return checked
}

function validateFieldValue(rule, value, property) {
  if (value === null) {
    if (rule.nullable === true) return null
    fail(`Batch field "${property}" cannot be null.`)
  }
  switch (rule.type) {
    case 'string':
      return validateStringField(rule, value, property)
    case 'utf8-string': {
      if (typeof value !== 'string') fail(`Batch field "${property}" must be text.`)
      if (rule.maxBytes !== undefined && new TextEncoder().encode(value).length > rule.maxBytes) {
        fail(`Batch field "${property}" is too large.`)
      }
      if (rule.disallowHtmlTags === true && /<\/?[a-z][^>]*>/i.test(value)) {
        fail(`Batch field "${property}" cannot contain HTML tags.`)
      }
      return value
    }
    case 'integer':
      if (!Number.isSafeInteger(value)) fail(`Batch field "${property}" must be a whole number.`)
      if ((rule.minimum !== undefined && value < rule.minimum) || (rule.maximum !== undefined && value > rule.maximum)) {
        fail(`Batch field "${property}" is outside the allowed range.`)
      }
      return value
    case 'number':
      if (typeof value !== 'number' || !Number.isFinite(value)) fail(`Batch field "${property}" must be a number.`)
      if ((rule.minimum !== undefined && value < rule.minimum) || (rule.maximum !== undefined && value > rule.maximum)) {
        fail(`Batch field "${property}" is outside the allowed range.`)
      }
      return value
    case 'boolean':
      if (typeof value !== 'boolean') fail(`Batch field "${property}" must be true or false.`)
      return value
    case 'enum':
      if (typeof value !== 'string' || !rule.values.includes(value)) fail(`Batch field "${property}" is not an allowed value.`)
      return value
    case 'color':
      if (typeof value !== 'string' || !/^#[0-9a-f]{6}$/i.test(value)) fail(`Batch field "${property}" must use #RRGGBB format.`)
      return value
    case 'uuid':
      if (typeof value !== 'string' || !UUID_PATTERN.test(value)) fail(`Batch field "${property}" must be a valid UUID.`)
      return value.toLowerCase()
    default:
      fail(`Batch field "${property}" uses an unsupported type.`)
  }
}

function validateRow(row, fields, index) {
  if (!isPlainObject(row) || hasUnsafeKeys(row)) fail(`Batch row ${index + 1} must be a field object.`)
  const allowed = new Map(fields.map(field => [field.property, field]))
  const values = {}
  for (const property of Object.keys(row)) {
    if (!allowed.has(property)) fail(`Batch row ${index + 1} contains an unsupported field.`)
  }
  for (const field of fields) {
    if (!Object.hasOwn(row, field.property)) {
      if (field.required) fail(`Batch field "${field.property}" is required.`)
      continue
    }
    values[field.property] = validateFieldValue(field.rule, row[field.property], field.property)
  }
  if (!Object.keys(values).length) fail(`Batch row ${index + 1} must contain at least one value.`)
  return values
}

/**
 * Validate the model tool arguments against only the explicit host create
 * policy. Values are canonicalized exactly as the batch transport binds them:
 * Counter code/name are trimmed and UUIDs are lower-cased. No value is invented,
 * defaulted or inferred from user data.
 */
export function prepareBatchRequest(input, entities) {
  if (!isPlainObject(input) || hasUnsafeKeys(input)) fail('The batch request is invalid.')
  if (Object.keys(input).some(key => !['entity', 'action', 'rows'].includes(key))) {
    fail('The batch request contains unsupported parameters.')
  }
  if (typeof input.entity !== 'string' || !BATCH_ENTITIES.includes(input.entity)) {
    fail('Only Tag and Counter batches are enabled.')
  }
  if (input.action !== 'create') fail('Only the create action is enabled for batches.')
  const descriptor = Array.isArray(entities) ? entities.find(item => item.entity === input.entity) : null
  if (!descriptor) fail('The selected entity is not enabled for batches.')
  if (!Array.isArray(input.rows) || input.rows.length < 1 || input.rows.length > MAX_BATCH_ROWS) {
    fail(`A batch must contain between 1 and ${MAX_BATCH_ROWS} rows.`)
  }
  const rows = []
  for (let index = 0; index < input.rows.length; index += 1) {
    if (!Object.hasOwn(input.rows, index)) fail('Each batch row must be a field object.')
    rows.push(validateRow(input.rows[index], descriptor.fields, index))
  }
  let storeUuid = ''
  if (input.entity === 'Counter') {
    const scope = rows[0].storeUuid
    if (typeof scope !== 'string' || !UUID_PATTERN.test(scope)) fail('Counter rows require one explicit Store UUID.')
    storeUuid = scope.toLowerCase()
    for (const row of rows) {
      if (typeof row.storeUuid !== 'string' || row.storeUuid.toLowerCase() !== storeUuid) {
        fail('Counter rows must use one explicit Store UUID.')
      }
      row.storeUuid = storeUuid
    }
  }
  return {
    entity: input.entity,
    action: 'create',
    input: { entity: input.entity, action: 'create', rows: rows.map(row => ({ ...row })) },
    records: rows.map((values, index) => ({ row: index + 1, values: { ...values } })),
    fields: descriptor.fields,
    storeUuid
  }
}

function normalizePreviewRow(row, index, expected, fieldProperties, entity, storeUuid) {
  if (!isPlainObject(row) || hasUnsafeKeys(row)) throw new TypeError('Invalid batch preview row.')
  if (Object.keys(row).some(key => !['row', 'title', 'identifier', 'differences'].includes(key))) throw new TypeError('Invalid batch preview row.')
  if (!Number.isInteger(row.row) || row.row < 1 || row.row > MAX_BATCH_ROWS) throw new TypeError('Invalid batch preview row.')
  if (row.row !== expected.row) throw new TypeError('The batch preview row order changed.')
  if (row.identifier !== null) throw new TypeError('A create preview row must not have a target identifier.')
  if (typeof row.title !== 'string' || !row.title.trim() || [...row.title].length > 1024) throw new TypeError('Invalid batch preview row title.')
  if (!Array.isArray(row.differences) || row.differences.length === 0) throw new TypeError('Invalid batch preview differences.')
  const values = {}
  for (const difference of row.differences) {
    if (!isPlainObject(difference) || hasUnsafeKeys(difference)) throw new TypeError('Invalid batch preview difference.')
    if (Object.keys(difference).some(key => !['property', 'before', 'after'].includes(key))) throw new TypeError('Invalid batch preview difference.')
    if (!fieldProperties.has(difference.property)) throw new TypeError('The batch preview contains an unsupported field.')
    if (difference.before !== null) throw new TypeError('A create preview difference must have a null before value.')
    if (!isDisplayScalar(difference.after)) throw new TypeError('The batch preview contains an unsupported value.')
    if (Object.hasOwn(values, difference.property)) throw new TypeError('The batch preview contains a duplicate field.')
    values[difference.property] = difference.after
  }
  // The canonical differences must account for exactly the submitted fields
  // and preserve their values, so nothing is silently added, omitted or changed.
  const expectedProperties = Object.keys(expected.values)
  if (Object.keys(values).length !== expectedProperties.length) throw new TypeError('The batch preview does not match the submitted rows.')
  for (const property of expectedProperties) {
    if (!Object.hasOwn(values, property) || !sameScalar(values[property], expected.values[property])) {
      throw new TypeError('The batch preview does not match the submitted rows.')
    }
  }
  if (entity === 'Counter' && values.storeUuid !== storeUuid) throw new TypeError('The Counter batch store does not match.')
  return { row: index + 1, values }
}

/**
 * Strictly validate the canonical prepared preview from the host. It must be
 * complete, match every submitted value, and use only fields from the explicit
 * create policy. A malformed response never reaches the confirmation UI.
 */
export function normalizePreparedBatch(preview, { entity, records, storeUuid = '', fields = [] }) {
  if (!isPlainObject(preview) || hasUnsafeKeys(preview)) throw new TypeError('Invalid batch preview.')
  if (Object.keys(preview).some(key => !PREVIEW_KEYS.has(key))) throw new TypeError('The batch preview contains unsupported keys.')
  if (typeof preview.operationId !== 'string' || !OPERATION_ID_PATTERN.test(preview.operationId)) throw new TypeError('Invalid batch operation identifier.')
  if (preview.entity !== entity) throw new TypeError('The batch entity does not match.')
  if (preview.action !== 'create') throw new TypeError('The batch action must be create.')
  if (preview.saved !== false) throw new TypeError('The batch preview must not be saved.')
  if (typeof preview.expiresAt !== 'string' || !Number.isFinite(Date.parse(preview.expiresAt))) throw new TypeError('The batch preview expiry is invalid.')
  if (typeof preview.title !== 'string' || !preview.title.trim() || [...preview.title].length > 1024) throw new TypeError('Invalid batch preview title.')
  if (!Number.isInteger(preview.affectedRecords) || preview.affectedRecords !== records.length) throw new TypeError('The batch preview row count changed.')
  if (!Array.isArray(preview.rows) || preview.rows.length !== records.length) throw new TypeError('The batch preview row count changed.')
  if (entity === 'Counter' && !storeUuid) throw new TypeError('The Counter batch store is missing.')
  const fieldProperties = new Set(fields.map(field => field.property))
  const normalizedRecords = preview.rows.map((row, index) => normalizePreviewRow(row, index, records[index], fieldProperties, entity, storeUuid))
  const warnings = []
  if (preview.warnings !== undefined) {
    if (!Array.isArray(preview.warnings) || preview.warnings.length > MAX_WARNINGS) throw new TypeError('Invalid batch preview warnings.')
    for (const warning of preview.warnings) {
      if (typeof warning !== 'string') throw new TypeError('Invalid batch preview warning.')
      warnings.push(safeText(warning, MAX_WARNING_LENGTH))
    }
  }
  const normalized = {
    operationId: preview.operationId,
    entity,
    action: 'create',
    rowCount: records.length,
    records: normalizedRecords,
    expiresAt: preview.expiresAt,
    saved: false,
    warnings
  }
  if (entity === 'Counter') normalized.storeUuid = storeUuid
  if (JSON.stringify(normalized).length > MAX_PREVIEW_CHARACTERS) throw new TypeError('The batch preview is too large.')
  return normalized
}

function previewReceiptFields(preview) {
  const fields = new Set()
  for (const record of preview.records || []) {
    for (const property of Object.keys(record.values || {})) fields.add(property)
  }
  return fields
}

/**
 * Accept a batch receipt only when it identifies the exact retained canonical
 * preview. An operation ID alone is not enough: the entity, action, row count,
 * field set and original expiry must all match before any status can release
 * the confirmation lock. A mismatch always fails closed as "unknown" so a
 * receipt can never be mistaken for the outcome of another batch.
 */
export function normalizeBatchStatusReceipt(receipt, preview) {
  if (!isPlainObject(receipt) || hasUnsafeKeys(receipt)) return 'unknown'
  if (!isPlainObject(preview)) return 'unknown'
  if (receipt.operationId !== preview.operationId) return 'unknown'
  if (receipt.entity !== preview.entity) return 'unknown'
  if (receipt.action !== 'create') return 'unknown'
  if (!Number.isInteger(receipt.rowCount) || receipt.rowCount !== preview.rowCount) return 'unknown'
  if (typeof receipt.expiresAt !== 'string' || receipt.expiresAt !== preview.expiresAt) return 'unknown'
  const expectedFields = previewReceiptFields(preview)
  if (!Array.isArray(receipt.fields) || receipt.fields.length !== expectedFields.size) return 'unknown'
  const seen = new Set()
  for (const field of receipt.fields) {
    if (typeof field !== 'string' || !expectedFields.has(field) || seen.has(field)) return 'unknown'
    seen.add(field)
  }
  return typeof receipt.status === 'string' && BATCH_STATUSES.has(receipt.status) ? receipt.status : 'unknown'
}

function isProposalConflict(error) {
  return error?.code === 'proposal_conflict'
    || (error?.response?.status === 409 && error.response.data?.error === 'proposal_conflict')
}

export function isBatchPreviewExpired(preview, now = Date.now()) {
  const expiresAt = Date.parse(preview?.expiresAt)
  return !Number.isFinite(expiresAt) || expiresAt <= now
}

export function isBatchOperationId(value) {
  return typeof value === 'string' && OPERATION_ID_PATTERN.test(value)
}

function batchError(key) {
  return { key }
}

/**
 * Headless proposal state machine used by the chat confirmation card and
 * deterministic unit tests. It owns only the already-prepared canonical preview
 * and the explicit commit/status lifecycle; preparing always happens through the
 * model tool and the host transport.
 */
export function createBatchProposal(host = {}, {
  canInteract = () => true,
  onChange = () => {},
  now = () => Date.now()
} = {}) {
  const availability = inspectBatchHost(host)
  let preview = null
  let expected = null
  let phase = availability.available ? 'idle' : 'unavailable'
  let busy = false
  let confirmed = false
  let restored = false
  let errors = []
  let receipt = null
  let generation = 0
  let controller = null
  let disposed = false

  const notify = () => {
    if (disposed) return
    try { onChange() } catch { /* The UI must not break the state machine. */ }
  }

  const isCurrent = token => !disposed && token === generation

  function setErrors(next) {
    errors = next
  }

  function cancelPreview() {
    if (!preview || !['ready', 'conflicted'].includes(phase)) return
    const operationId = preview.operationId
    try {
      Promise.resolve(host.cancelRecordBatch(operationId)).catch(() => {})
    } catch { /* Cancellation is best effort; a pending proposal expires server-side. */ }
  }

  function clearPreview() {
    preview = null
    expected = null
    confirmed = false
    restored = false
    receipt = null
  }

  function snapshot() {
    return {
      available: availability.available,
      reason: availability.reason,
      entities: availability.entities.map(item => item.entity),
      entity: expected?.entity || '',
      fields: (expected?.fields || []).map(field => ({ ...field, rule: { ...field.rule } })),
      preview: preview ? {
        ...preview,
        records: preview.records.map(record => ({ row: record.row, values: { ...record.values } })),
        warnings: [...(preview.warnings || [])]
      } : null,
      phase,
      busy,
      confirmed,
      restored,
      receipt: receipt ? { ...receipt } : null,
      errors: errors.map(error => ({ ...error }))
    }
  }

  function adopt(prepared, context = {}) {
    if (disposed || !availability.available) return false
    if (busy || !prepared || !isBatchOperationId(prepared.operationId)) return false
    // A newer model proposal always supersedes an older pending card. Unknown
    // outcomes and in-flight submissions are serialized by the entry locks and
    // must never be silently replaced here.
    if (['submitting', 'unknown'].includes(phase)) return false
    if (['ready', 'conflicted'].includes(phase)) cancelPreview()
    generation += 1
    controller?.abort()
    controller = null
    preview = prepared
    expected = {
      entity: prepared.entity,
      records: prepared.records.map(record => ({ row: record.row, values: { ...record.values } })),
      fields: Array.isArray(context.fields) ? context.fields : [],
      storeUuid: typeof context.storeUuid === 'string' ? context.storeUuid : prepared.storeUuid || ''
    }
    phase = 'ready'
    confirmed = false
    restored = false
    receipt = null
    errors = []
    notify()
    return true
  }

  function setConfirmed(value) {
    if (disposed || phase !== 'ready' || busy) return false
    confirmed = value === true
    notify()
    return true
  }

  async function commit() {
    if (disposed || !preview || phase !== 'ready' || busy || confirmed !== true) return false
    if (isBatchPreviewExpired(preview, now())) {
      phase = 'expired'
      clearPreview()
      setErrors([batchError('The preview expired. Nothing was saved.')])
      notify()
      return false
    }
    if (!canInteract()) {
      setErrors([batchError('Wait for the current assistant task to finish or cancel it first.')])
      notify()
      return false
    }
    generation += 1
    const token = generation
    controller = new AbortController()
    phase = 'submitting'
    busy = true
    confirmed = false
    setErrors([])
    notify()
    try {
      const value = await host.commitRecordBatch(preview.operationId, controller.signal)
      if (!isCurrent(token)) return false
      const status = normalizeBatchStatusReceipt(value, preview)
      if (status === 'succeeded' && value.verified === true) {
        phase = 'succeeded'
        receipt = { ...value }
        setErrors([])
        return true
      }
      phase = 'unknown'
      setErrors([batchError('The commit outcome is unknown. Check the outcome before trying again; cancellation does not undo a submitted batch.')])
      return false
    } catch (error) {
      if (!isCurrent(token)) return false
      phase = isProposalConflict(error) ? 'conflicted' : 'unknown'
      setErrors([phase === 'conflicted'
        ? batchError('The batch conflicted with current data. Nothing was saved. Review and prepare a new preview.')
        : batchError('The commit outcome is unknown. Check the outcome before trying again; cancellation does not undo a submitted batch.')])
      return false
    } finally {
      if (isCurrent(token)) {
        busy = false
        controller = null
        notify()
      }
    }
  }

  async function checkOutcome() {
    if (disposed || !preview || phase !== 'unknown' || busy) return false
    if (!canInteract()) {
      setErrors([batchError('Wait for the current assistant task to finish or cancel it first.')])
      notify()
      return false
    }
    generation += 1
    const token = generation
    controller = new AbortController()
    busy = true
    setErrors([])
    notify()
    try {
      const value = await host.getRecordBatchStatus(preview.operationId, controller.signal)
      if (!isCurrent(token)) return false
      const status = normalizeBatchStatusReceipt(value, preview)
      if (status === 'succeeded') {
        phase = 'succeeded'
        receipt = { ...value }
        setErrors([])
        return true
      }
      if (status === 'cancelled' || status === 'expired' || status === 'conflicted') {
        phase = status
        if (status !== 'conflicted') clearPreview()
        setErrors([status === 'cancelled'
          ? batchError('The batch was cancelled. Nothing was saved.')
          : status === 'expired'
            ? batchError('The preview expired. Nothing was saved.')
            : batchError('The batch conflicted with current data. Nothing was saved. Review and prepare a new preview.')])
        return false
      }
      if (status === 'pending' && expected) {
        const restoredPreview = await host.restoreRecordBatch(preview.operationId, controller.signal)
        if (!isCurrent(token)) return false
        const normalized = normalizePreparedBatch(restoredPreview, expected)
        if (normalized.operationId !== preview.operationId) throw new TypeError('The restored batch identity changed.')
        if (normalized.expiresAt !== preview.expiresAt) throw new TypeError('The restored batch expiry changed.')
        preview = normalized
        phase = 'ready'
        confirmed = false
        restored = true
        setErrors([])
        return true
      }
      phase = 'unknown'
      setErrors([batchError('The server still reports an unresolved outcome. Do not retry automatically.')])
      return false
    } catch (error) {
      if (!isCurrent(token)) return false
      phase = isProposalConflict(error) ? 'conflicted' : 'unknown'
      setErrors([phase === 'conflicted'
        ? batchError('The batch conflicted with current data. Nothing was saved. Review and prepare a new preview.')
        : batchError('Could not check the batch outcome. Do not retry automatically.')])
      return false
    } finally {
      if (isCurrent(token)) {
        busy = false
        controller = null
        notify()
      }
    }
  }

  function discard() {
    if (disposed || busy || !preview || !['ready', 'conflicted'].includes(phase)) return false
    cancelPreview()
    generation += 1
    controller?.abort()
    controller = null
    clearPreview()
    phase = 'idle'
    setErrors([])
    notify()
    return true
  }

  function invalidate({ cancel = true } = {}) {
    if (disposed) return false
    if (busy || ['submitting', 'unknown'].includes(phase)) return false
    generation += 1
    controller?.abort()
    controller = null
    if (cancel) cancelPreview()
    clearPreview()
    phase = 'invalidated'
    setErrors([])
    notify()
    return true
  }

  function reset({ force = false } = {}) {
    if (disposed) return false
    if (!force && ['submitting', 'unknown'].includes(phase)) return false
    generation += 1
    controller?.abort()
    controller = null
    if (force || ['ready', 'conflicted'].includes(phase)) cancelPreview()
    clearPreview()
    errors = []
    busy = false
    phase = availability.available ? 'idle' : 'unavailable'
    notify()
    return true
  }

  function dispose() {
    if (disposed) return
    generation += 1
    controller?.abort()
    controller = null
    cancelPreview()
    disposed = true
    preview = null
    expected = null
    receipt = null
    busy = false
    errors = []
    phase = availability.available ? 'idle' : 'unavailable'
  }

  return {
    get state() { return snapshot() },
    get available() { return availability.available },
    blocksNewTask() { return phase === 'submitting' || phase === 'unknown' },
    blocksWrites() { return ['ready', 'submitting', 'unknown'].includes(phase) },
    adopt,
    setConfirmed,
    commit,
    checkOutcome,
    discard,
    invalidate,
    reset,
    dispose
  }
}

export function formatBatchError(t, error) {
  if (!error) return ''
  return t(error.key, error.params || {})
}
