/**
 * Manual bulk-create import workbench.
 *
 * This module is deliberately UI-only: it reads a local TXT/CSV/XLSX/DOCX file
 * through the separately owned parser, maps source columns to the existing
 * create-field capability policy, and calls explicit host batch methods. It
 * never uploads or stores a file, never calls the model provider, and never
 * invents fields or critical values. When the host cannot provide the batch
 * methods or a complete create-field policy, the workbench fails closed.
 *
 * Assumed host protocol (the coordinator owns adapting the final backend):
 *
 *   prepareRecordBatch({
 *     entity: 'Tag' | 'Counter',
 *     action: 'create',
 *     rows: [{
 *       ...mappedCreateFields,
 *       ...(entity === 'Counter' ? { storeUuid: '<same explicit UUID>' } : {})
 *     }]
 *   }, signal)
 *
 *   restoreRecordBatch(operationId, signal)
 *   commitRecordBatch(operationId, signal)
 *   getRecordBatchStatus(operationId, signal)
 *   cancelRecordBatch(operationId)
 *
 * The prepared/restored preview follows the app's create-only batch shape:
 *
 *   {
 *     operationId, entity, action: 'create', title?,
 *     affectedRecords?, rowCount?, expiresAt, saved: false,
 *     rows: [{
 *       row: 1, title?, identifier: null,
 *       differences: [{ property, before: null, after }]
 *     }],
 *     warnings?: [string]
 *   }
 *
 * Every submitted field must appear in the canonical differences with the same
 * value; the UI displays canonical values and never guesses missing ones.
 */

const MAX_IMPORT_COLUMNS = 100
const MAX_IMPORT_ROWS = 20
const MAX_CELL_LENGTH = 5000
const MAX_SOURCE_NAME_LENGTH = 200
const MAX_WARNINGS = 20
const MAX_WARNING_LENGTH = 500
const MAX_ERRORS = 5
const MAX_PREVIEW_CHARACTERS = 60000
const MAX_OPERATION_ID_LENGTH = 128
const MAX_STORE_IDENTIFIER_LENGTH = 200
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const OPERATION_ID_PATTERN = new RegExp(`^[A-Za-z0-9_-]{1,${MAX_OPERATION_ID_LENGTH}}$`)
const SAFE_PROPERTY = /^[A-Za-z][A-Za-z0-9_]*$/
const IMPORT_ENTITIES = Object.freeze(['Tag', 'Counter'])
const SUPPORTED_RULE_TYPES = new Set(['string', 'utf8-string', 'integer', 'number', 'boolean', 'color', 'uuid', 'enum'])
const PREVIEW_KEYS = new Set([
  'operationId', 'entity', 'action', 'title', 'affectedRecords',
  'expiresAt', 'rows', 'saved', 'warnings'
])
const BATCH_STATUSES = new Set(['pending', 'succeeded', 'cancelled', 'expired', 'conflicted'])

export const IMPORT_ACCEPT = [
  '.txt', '.csv', '.xlsx', '.docx',
  'text/plain', 'text/csv',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
].join(',')

export { MAX_IMPORT_COLUMNS, MAX_IMPORT_ROWS }

export class ImportWorkbenchError extends Error {
  constructor(key, params = {}) {
    super(key)
    this.name = 'ImportWorkbenchError'
    this.importError = { key, params }
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

function importError(key, params = {}) {
  return { key, params }
}

function resolveMaxRows(value) {
  return Number.isInteger(value) && value > 0 ? Math.min(value, MAX_IMPORT_ROWS) : MAX_IMPORT_ROWS
}

function capErrors(errors) {
  return errors.slice(0, MAX_ERRORS)
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
  if (!isPlainObject(capability) || !IMPORT_ENTITIES.includes(capability.entity)) return null
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
 * Inspect host capabilities without widening the create-field policy. All five
 * batch host methods and the existing create actionFields are required.
 */
export function inspectImportHost(host) {
  const methods = ['prepareRecordBatch', 'restoreRecordBatch', 'commitRecordBatch', 'getRecordBatchStatus', 'cancelRecordBatch']
  if (!host || typeof host !== 'object') return { available: false, reason: 'host', entities: [] }
  if (methods.some(name => typeof host[name] !== 'function')) return { available: false, reason: 'host', entities: [] }
  const readCapabilities = typeof host.getBatchCreateCapabilities === 'function'
    ? () => host.getBatchCreateCapabilities()
    : typeof host.getWriteCapabilities === 'function'
      ? () => host.getWriteCapabilities()
      : null
  if (!readCapabilities) return { available: false, reason: 'capabilities', entities: [] }
  let capabilities
  try { capabilities = readCapabilities() } catch { return { available: false, reason: 'capabilities', entities: [] } }
  if (!Array.isArray(capabilities)) return { available: false, reason: 'capabilities', entities: [] }
  const entities = IMPORT_ENTITIES.flatMap(entity => {
    const capability = capabilities.find(item => isPlainObject(item) && item.entity === entity)
    const descriptor = createEntityDescriptor(capability)
    return descriptor ? [descriptor] : []
  })
  return { available: entities.length > 0, reason: entities.length ? '' : 'capabilities', entities }
}

export function importRowFields(entity, fields) {
  return fields.filter(field => !(entity === 'Counter' && field.property === 'storeUuid'))
}

export function createImportMapping(entity, fields) {
  return Object.fromEntries(importRowFields(entity, fields).map(field => [field.property, null]))
}

function fieldError(field, error, row) {
  return importError('Row {row}, {field}: {reason}', {
    ...(row === undefined ? {} : { row }),
    field: field.property,
    reason: error.key,
    reasonParams: error.params || {}
  })
}

function storeError(error) {
  return importError('{field}: {reason}', {
    field: 'storeUuid',
    reason: error.key,
    reasonParams: error.params || {}
  })
}

/**
 * Validate one source cell against the host create-field rule. Strings are not
 * silently normalized; only numeric and boolean parsing is explicit.
 */
export function validateImportValue(rule, raw) {
  if (typeof raw !== 'string') return { ok: false, error: importError('The value must be text.') }
  const empty = raw.trim().length === 0
  if (empty) {
    if (rule.requiredOnCreate === true) return { ok: false, error: importError('A value is required.') }
    if (rule.nullable === true) return { ok: true, value: null }
    return { ok: true, omitted: true }
  }
  if (/[\u0000-\u001f\u007f]/.test(raw)) {
    return { ok: false, error: importError('The value contains unsupported control characters.') }
  }
  switch (rule.type) {
    case 'string': {
      const checked = rule.trimForValidation ? raw.trim() : raw
      if (rule.maxLength !== undefined && [...checked].length > rule.maxLength) {
        return { ok: false, error: importError('The value exceeds {max} characters.', { max: rule.maxLength }) }
      }
      if (rule.minLength !== undefined && [...raw].length < rule.minLength) {
        return { ok: false, error: importError('The value must be at least {min} characters.', { min: rule.minLength }) }
      }
      if (rule.nonEmpty === true && raw.trim().length === 0) return { ok: false, error: importError('The value cannot be empty.') }
      if (rule.trimExact === true && raw.trim() !== raw) return { ok: false, error: importError('Remove leading or trailing spaces.') }
      if (rule.disallowLessThan === true && raw.includes('<')) return { ok: false, error: importError('The value cannot contain "<".') }
      if (rule.disallowHtmlTags === true && /<\/?[a-z][^>]*>/i.test(raw)) return { ok: false, error: importError('The value cannot contain HTML tags.') }
      if (rule.pattern !== undefined && !new RegExp(rule.pattern, rule.patternFlags || '').test(raw)) {
        return { ok: false, error: importError('The value has an invalid format.') }
      }
      if (rule.format === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) {
        return { ok: false, error: importError('Enter a valid email address.') }
      }
      return { ok: true, value: raw }
    }
    case 'utf8-string': {
      const bytes = new TextEncoder().encode(raw).length
      if (rule.maxBytes !== undefined && bytes > rule.maxBytes) {
        return { ok: false, error: importError('The value exceeds {max} bytes.', { max: rule.maxBytes }) }
      }
      if (rule.disallowHtmlTags === true && /<\/?[a-z][^>]*>/i.test(raw)) return { ok: false, error: importError('The value cannot contain HTML tags.') }
      return { ok: true, value: raw }
    }
    case 'integer': {
      const text = raw.trim()
      if (!/^-?\d+$/.test(text) || !Number.isSafeInteger(Number(text))) return { ok: false, error: importError('Enter a whole number.') }
      const value = Number(text)
      if ((rule.minimum !== undefined && value < rule.minimum) || (rule.maximum !== undefined && value > rule.maximum)) {
        return { ok: false, error: importError('Enter a whole number between {min} and {max}.', { min: rule.minimum ?? '', max: rule.maximum ?? '' }) }
      }
      return { ok: true, value }
    }
    case 'number': {
      const text = raw.trim()
      const value = Number(text)
      if (!text || !Number.isFinite(value)) return { ok: false, error: importError('Enter a number.') }
      if ((rule.minimum !== undefined && value < rule.minimum) || (rule.maximum !== undefined && value > rule.maximum)) {
        return { ok: false, error: importError('Enter a number between {min} and {max}.', { min: rule.minimum ?? '', max: rule.maximum ?? '' }) }
      }
      return { ok: true, value }
    }
    case 'boolean': {
      const text = raw.trim().toLowerCase()
      if (text === 'true') return { ok: true, value: true }
      if (text === 'false') return { ok: true, value: false }
      return { ok: false, error: importError('Use true or false.') }
    }
    case 'enum': {
      const value = raw
      if (!rule.values.includes(value)) return { ok: false, error: importError('Choose one of: {values}.', { values: rule.values.join(', ') }) }
      return { ok: true, value }
    }
    case 'color':
      if (!/^#[0-9a-f]{6}$/i.test(raw)) return { ok: false, error: importError('Enter a color in #RRGGBB format.') }
      return { ok: true, value: raw }
    case 'uuid':
      if (!UUID_PATTERN.test(raw)) return { ok: false, error: importError('Enter a valid UUID.') }
      return { ok: true, value: raw }
    default:
      return { ok: false, error: importError('This field type is not supported for import.') }
  }
}

function canonicalImportValue(rule, value) {
  if (typeof value !== 'string') return value
  const trimmed = rule.trimForValidation ? value.trim() : value
  return rule.type === 'uuid' ? trimmed.toLowerCase() : trimmed
}

/**
 * Validate entity-specific mapping, required fields, duplicate column use, and
 * the explicit same-store UUID for Counter.
 */
export function validateImportMapping({ entity, fields, columns, mapping, storeUuid = '' }) {
  if (!IMPORT_ENTITIES.includes(entity)) return { ok: false, errors: [importError('Select an entity.')] }
  if (!Array.isArray(columns) || columns.length === 0) return { ok: false, errors: [importError('Select a file with at least one column.')] }
  if (columns.length > MAX_IMPORT_COLUMNS) return { ok: false, errors: [importError('The file has too many columns.')] }
  if (!isPlainObject(mapping) || hasUnsafeKeys(mapping)) return { ok: false, errors: [importError('The column mapping is invalid.')] }
  const rowFields = importRowFields(entity, fields)
  const allowed = new Set(rowFields.map(field => field.property))
  if (Object.keys(mapping).some(key => !allowed.has(key))) return { ok: false, errors: [importError('The column mapping contains an unsupported field.')] }
  const errors = []
  const used = new Set()
  for (const field of rowFields) {
    const column = mapping[field.property]
    if (column === null || column === undefined) {
      if (field.required) errors.push(importError('Map a column to {field}.', { field: field.property }))
      continue
    }
    if (!Number.isInteger(column) || column < 0 || column >= columns.length) {
      errors.push(importError('Select a valid column for {field}.', { field: field.property }))
      continue
    }
    if (used.has(column)) {
      errors.push(importError('Each source column can be mapped only once.'))
      continue
    }
    used.add(column)
  }
  if (!used.size) errors.push(importError('Map at least one source column to a field.'))
  let normalizedStore = ''
  if (entity === 'Counter') {
    const field = fields.find(item => item.property === 'storeUuid')
    const raw = typeof storeUuid === 'string' ? storeUuid.trim() : ''
    if (!field) errors.push(importError('The Counter store field is unavailable.'))
    else if (!raw) errors.push(importError('Enter the Store UUID for every Counter row.'))
    else {
      const result = validateImportValue(field.rule, raw)
      if (!result.ok) errors.push(storeError(result.error))
      else normalizedStore = canonicalImportValue(field.rule, result.value)
    }
  }
  if (errors.length) return { ok: false, errors: capErrors(errors) }
  return { ok: true, rowFields, storeUuid: normalizedStore }
}

/**
 * Build the exact host input and the local expected rows. No default values are
 * filled for unmapped fields; blank nullable cells become explicit null.
 */
export function buildImportBatchInput({ entity, fields, columns, rows, mapping, storeUuid = '', maxRows = MAX_IMPORT_ROWS }) {
  const limit = resolveMaxRows(maxRows)
  const validation = validateImportMapping({ entity, fields, columns, mapping, storeUuid })
  if (!validation.ok) return { ok: false, errors: validation.errors }
  if (!Array.isArray(rows) || rows.length === 0) return { ok: false, errors: [importError('The file has no rows.')] }
  if (rows.length > limit) return { ok: false, errors: [importError('The import exceeds the {count}-row limit.', { count: limit })] }
  const records = []
  const errors = []
  for (let rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
    const row = rows[rowIndex]
    if (!Array.isArray(row)) {
      errors.push(importError('Row {row} is invalid.', { row: rowIndex + 1 }))
      continue
    }
    if (row.length > columns.length) {
      errors.push(importError('Row {row} has more values than columns.', { row: rowIndex + 1 }))
      continue
    }
    const values = {}
    for (const field of validation.rowFields) {
      const column = mapping[field.property]
      if (column === null || column === undefined) continue
      const raw = row[column] ?? ''
      if (typeof raw !== 'string') {
        errors.push(fieldError(field, importError('The value must be text.'), rowIndex + 1))
        continue
      }
      if (raw.length > MAX_CELL_LENGTH) {
        errors.push(fieldError(field, importError('The value is too long.'), rowIndex + 1))
        continue
      }
      const result = validateImportValue(field.rule, raw)
      if (!result.ok) {
        errors.push(fieldError(field, result.error, rowIndex + 1))
        continue
      }
      if (result.omitted) continue
      values[field.property] = canonicalImportValue(field.rule, result.value)
    }
    // Counter batches carry the same explicit Store UUID on every create row.
    if (entity === 'Counter') values.storeUuid = validation.storeUuid
    records.push({ row: rowIndex + 1, values })
  }
  if (errors.length) return { ok: false, errors: capErrors(errors) }
  const input = {
    entity,
    action: 'create',
    rows: records.map(record => ({ ...record.values }))
  }
  return { ok: true, input, records, storeUuid: validation.storeUuid, rowFields: validation.rowFields }
}

function normalizePreviewRow(row, index, expected, fieldProperties, entity, storeUuid) {
  if (!isPlainObject(row) || hasUnsafeKeys(row)) throw new TypeError('Invalid batch preview row.')
  if (Object.keys(row).some(key => !['row', 'title', 'identifier', 'differences'].includes(key))) throw new TypeError('Invalid batch preview row.')
  if (!Number.isInteger(row.row) || row.row < 1 || row.row > MAX_IMPORT_ROWS) throw new TypeError('Invalid batch preview row.')
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
  if (Object.keys(values).length !== expectedProperties.length) throw new TypeError('The batch preview does not match the mapped rows.')
  for (const property of expectedProperties) {
    if (!Object.hasOwn(values, property) || !sameScalar(values[property], expected.values[property])) {
      throw new TypeError('The batch preview does not match the mapped rows.')
    }
  }
  if (entity === 'Counter' && values.storeUuid !== storeUuid) throw new TypeError('The Counter batch store does not match.')
  return { row: index + 1, values }
}

/**
 * Strictly validate the canonical prepared preview from the host. It must be
 * complete, match every submitted value, and use only fields from the existing
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

/**
 * Normalize a parser result. The parser owns file decoding; this function owns
 * UI safety bounds only.
 */
export function normalizeParsedImport(result, { maxRows = MAX_IMPORT_ROWS } = {}) {
  const limit = resolveMaxRows(maxRows)
  if (!isPlainObject(result)) throw new ImportWorkbenchError('The file could not be parsed.')
  const columns = result.columns
  const rows = result.rows
  if (!Array.isArray(columns) || columns.length === 0) throw new ImportWorkbenchError('The file has no columns.')
  if (columns.length > MAX_IMPORT_COLUMNS) throw new ImportWorkbenchError('The file has too many columns.')
  if (!Array.isArray(rows) || rows.length === 0) throw new ImportWorkbenchError('The file has no rows.')
  if (rows.length > limit) throw new ImportWorkbenchError('The import exceeds the {count}-row limit.', { count: limit })
  const normalizedColumns = columns.map((column, index) => {
    if (typeof column !== 'string') throw new ImportWorkbenchError('The file headers are invalid.')
    if (column.length > MAX_SOURCE_NAME_LENGTH) throw new ImportWorkbenchError('The file headers are too long.')
    return safeText(column, MAX_SOURCE_NAME_LENGTH)
  })
  const normalizedRows = rows.map((row, rowIndex) => {
    if (!Array.isArray(row)) throw new ImportWorkbenchError('Row {row} is invalid.', { row: rowIndex + 1 })
    if (row.length > columns.length) throw new ImportWorkbenchError('Row {row} has more values than columns.', { row: rowIndex + 1 })
    return row.map((cell, columnIndex) => {
      if (typeof cell !== 'string') throw new ImportWorkbenchError('Row {row}, column {column} is invalid.', { row: rowIndex + 1, column: columnIndex + 1 })
      if (cell.length > MAX_CELL_LENGTH) throw new ImportWorkbenchError('Row {row}, column {column} is too long.', { row: rowIndex + 1, column: columnIndex + 1 })
      return cell
    })
  })
  const warnings = Array.isArray(result.warnings)
    ? result.warnings.slice(0, MAX_WARNINGS).map(warning => {
      if (typeof warning === 'string') return safeText(warning, MAX_WARNING_LENGTH)
      if (isPlainObject(warning)) {
        if (typeof warning.message === 'string') return safeText(warning.message, MAX_WARNING_LENGTH)
        if (typeof warning.code === 'string') return safeText(warning.code, MAX_WARNING_LENGTH)
      }
      return ''
    }).filter(Boolean)
    : []
  return {
    name: safeText(typeof result.name === 'string' ? result.name : '', MAX_SOURCE_NAME_LENGTH),
    format: typeof result.format === 'string' ? result.format.toLowerCase().slice(0, 20) : '',
    columns: normalizedColumns,
    rows: normalizedRows,
    warnings
  }
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
 * the workbench lock. A mismatch always fails closed as "unknown" so a receipt
 * can never be mistaken for the outcome of another batch or a different shape.
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

function defaultParseFile(file, options) {
  return import('./import-files.js').then(module => {
    if (typeof module.parseImportFile !== 'function') throw new TypeError('The import parser is unavailable.')
    return module.parseImportFile(file, options)
  })
}

/**
 * Headless state machine used by the mounted workbench and deterministic unit
 * tests. It has no DOM, storage, provider or network dependency of its own.
 */
export function createImportBatch(host = {}, {
  parseFile = defaultParseFile,
  maxRows = MAX_IMPORT_ROWS,
  canInteract = () => true,
  onChange = () => {},
  now = () => Date.now()
} = {}) {
  let availability = inspectImportHost(host)
  let capabilities = availability.entities
  let entity = ''
  let fields = []
  let columns = []
  let rows = []
  let mapping = {}
  let storeUuid = ''
  let fileName = ''
  let fileFormat = ''
  let warnings = []
  let preview = null
  let preparedInput = null
  let errors = []
  let phase = availability.available ? 'idle' : 'unavailable'
  let busy = false
  let confirmed = false
  let restored = false
  let generation = 0
  let controller = null
  let disposed = false

  const notify = () => {
    if (disposed) return
    try { onChange() } catch { /* The UI must not break the state machine. */ }
  }

  const isCurrent = token => !disposed && token === generation
  const isEditable = () => !disposed && !busy && ['idle', 'editing', 'ready', 'conflicted', 'expired', 'cancelled'].includes(phase)

  function setErrors(next) {
    errors = capErrors(next).map(error => ({ key: error.key, params: { ...(error.params || {}) } }))
  }

  function cancelPreview() {
    if (!preview || !['ready', 'conflicted'].includes(phase)) return
    const operationId = preview.operationId
    try {
      Promise.resolve(host.cancelRecordBatch(operationId)).catch(() => {})
    } catch { /* Cancellation is best effort; a pending proposal expires server-side. */ }
  }

  function invalidate({ cancel = true } = {}) {
    generation += 1
    controller?.abort()
    controller = null
    if (cancel) cancelPreview()
    preview = null
    preparedInput = null
    confirmed = false
    restored = false
    busy = false
  }

  function snapshot() {
    return {
      available: availability.available,
      reason: availability.reason,
      entities: capabilities.map(item => item.entity),
      entity,
      fields: fields.map(field => ({ ...field })),
      columns: [...columns],
      rows: rows.map(row => [...row]),
      mapping: { ...mapping },
      storeUuid,
      fileName,
      format: fileFormat,
      warnings: [...warnings],
      preview: preview ? {
        ...preview,
        records: preview.records.map(record => ({ row: record.row, values: { ...record.values } })),
        warnings: [...(preview.warnings || [])]
      } : null,
      phase,
      busy,
      confirmed,
      restored,
      errors: errors.map(error => ({ key: error.key, params: { ...(error.params || {}) } }))
    }
  }

  function refresh() {
    if (disposed) return false
    if (busy || ['preparing', 'ready', 'submitting', 'unknown'].includes(phase)) return availability.available
    availability = inspectImportHost(host)
    capabilities = availability.entities
    if (!availability.available) {
      entity = ''
      fields = []
      mapping = {}
      phase = 'unavailable'
      setErrors([])
    } else if (phase === 'unavailable') {
      phase = 'idle'
    }
    notify()
    return availability.available
  }

  function setEntity(nextEntity) {
    if (!isEditable() || !availability.available) return false
    if (nextEntity === entity) return true
    const found = capabilities.find(item => item.entity === nextEntity)
    if (!found) {
      setErrors([importError('Select an available entity.')])
      notify()
      return false
    }
    invalidate()
    entity = nextEntity
    fields = found.fields
    mapping = createImportMapping(entity, fields)
    storeUuid = ''
    setErrors([])
    phase = rows.length ? 'editing' : 'idle'
    notify()
    return true
  }

  async function setFile(file) {
    if (!isEditable() || !availability.available) return false
    if (!file) return false
    invalidate()
    generation += 1
    const token = generation
    controller = new AbortController()
    phase = 'parsing'
    busy = true
    columns = []
    rows = []
    fileName = ''
    fileFormat = ''
    warnings = []
    setErrors([])
    notify()
    const signal = controller.signal
    try {
      const result = await parseFile(file, { signal, maxRecords: maxRows })
      if (!isCurrent(token)) return false
      const parsed = normalizeParsedImport(result, { maxRows })
      columns = parsed.columns
      rows = parsed.rows
      fileName = parsed.name || safeText(file.name, MAX_SOURCE_NAME_LENGTH)
      fileFormat = parsed.format
      warnings = parsed.warnings
      mapping = createImportMapping(entity, fields)
      phase = 'editing'
      restored = false
      setErrors([])
      return true
    } catch (error) {
      if (!isCurrent(token)) return false
      setErrors([error?.importError || importError('The file could not be parsed.')])
      phase = 'idle'
      return false
    } finally {
      if (isCurrent(token)) {
        busy = false
        controller = null
        notify()
      }
    }
  }

  function editCell(rowIndex, columnIndex, value) {
    if (!isEditable()) return false
    if (!Number.isInteger(rowIndex) || rowIndex < 0 || rowIndex >= rows.length) return false
    if (!Number.isInteger(columnIndex) || columnIndex < 0 || columnIndex >= columns.length) return false
    if (typeof value !== 'string' || value.length > MAX_CELL_LENGTH) {
      setErrors([importError('The value is too long.')])
      notify()
      return false
    }
    invalidate()
    rows[rowIndex][columnIndex] = value
    phase = 'editing'
    setErrors([])
    notify()
    return true
  }

  function setMapping(property, columnIndex) {
    if (!isEditable()) return false
    const allowed = new Set(importRowFields(entity, fields).map(field => field.property))
    if (!allowed.has(property)) return false
    if (!(columnIndex === null || (Number.isInteger(columnIndex) && columnIndex >= 0 && columnIndex < columns.length))) return false
    invalidate()
    mapping = { ...mapping, [property]: columnIndex }
    phase = 'editing'
    setErrors([])
    notify()
    return true
  }

  function setStoreIdentifier(value) {
    if (!isEditable() || entity !== 'Counter') return false
    if (typeof value !== 'string' || value.length > MAX_STORE_IDENTIFIER_LENGTH) {
      setErrors([importError('Enter a valid Store UUID.')])
      notify()
      return false
    }
    invalidate()
    storeUuid = value
    phase = 'editing'
    setErrors([])
    notify()
    return true
  }

  function setConfirmed(value) {
    if (disposed || phase !== 'ready' || busy) return false
    confirmed = value === true
    notify()
    return true
  }

  async function prepare() {
    if (disposed || !isEditable() || busy) return false
    if (preview) {
      setErrors([importError('Discard or edit the current preview before preparing another.')])
      notify()
      return false
    }
    if (!canInteract()) {
      setErrors([importError('Wait for the current assistant task to finish or cancel it first.')])
      notify()
      return false
    }
    const built = buildImportBatchInput({ entity, fields, columns, rows, mapping, storeUuid, maxRows })
    if (!built.ok) {
      phase = 'editing'
      setErrors(built.errors)
      notify()
      return false
    }
    invalidate({ cancel: false })
    generation += 1
    const token = generation
    controller = new AbortController()
    phase = 'preparing'
    busy = true
    setErrors([])
    notify()
    try {
      const raw = await host.prepareRecordBatch(built.input, controller.signal)
      if (!isCurrent(token)) return false
      if (!canInteract()) {
        const operationId = raw?.operationId
        if (typeof operationId === 'string' && OPERATION_ID_PATTERN.test(operationId)) {
          try { Promise.resolve(host.cancelRecordBatch(operationId)).catch(() => {}) } catch { /* Best effort. */ }
        }
        phase = 'editing'
        setErrors([importError('The preview was discarded because another task started.')])
        return false
      }
      const normalized = normalizePreparedBatch(raw, { entity, records: built.records, storeUuid: built.storeUuid, fields })
      preview = normalized
      preparedInput = { entity, records: built.records, storeUuid: built.storeUuid, fields }
      phase = 'ready'
      confirmed = false
      restored = false
      setErrors([])
      return true
    } catch (error) {
      if (!isCurrent(token)) return false
      phase = 'editing'
      setErrors([error?.importError || importError('The batch preview could not be prepared. Nothing was confirmed.')])
      return false
    } finally {
      if (isCurrent(token)) {
        busy = false
        controller = null
        notify()
      }
    }
  }

  async function commit() {
    if (disposed || !preview || phase !== 'ready' || busy || confirmed !== true) return false
    if (isBatchPreviewExpired(preview, now())) {
      phase = 'expired'
      confirmed = false
      preview = null
      preparedInput = null
      setErrors([importError('The preview expired. Nothing was saved.')])
      notify()
      return false
    }
    if (!canInteract()) {
      setErrors([importError('Wait for the current assistant task to finish or cancel it first.')])
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
      const receipt = await host.commitRecordBatch(preview.operationId, controller.signal)
      if (!isCurrent(token)) return false
      const status = normalizeBatchStatusReceipt(receipt, preview)
      if (status === 'succeeded' && receipt.verified === true) {
        phase = 'succeeded'
        setErrors([])
        return true
      }
      phase = 'unknown'
      setErrors([importError('The commit outcome is unknown. Check the outcome before trying again; cancellation does not undo a submitted batch.')])
      return false
    } catch (error) {
      if (!isCurrent(token)) return false
      phase = isProposalConflict(error) ? 'conflicted' : 'unknown'
      setErrors([phase === 'conflicted'
        ? importError('The batch conflicted with current data. Nothing was saved. Review and prepare a new preview.')
        : importError('The commit outcome is unknown. Check the outcome before trying again; cancellation does not undo a submitted batch.')])
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
      setErrors([importError('Wait for the current assistant task to finish or cancel it first.')])
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
      const receipt = await host.getRecordBatchStatus(preview.operationId, controller.signal)
      if (!isCurrent(token)) return false
      const status = normalizeBatchStatusReceipt(receipt, preview)
      if (status === 'succeeded') {
        phase = 'succeeded'
        setErrors([])
        return true
      }
      if (status === 'cancelled' || status === 'expired' || status === 'conflicted') {
        phase = status
        if (status !== 'conflicted') {
          preview = null
          preparedInput = null
        }
        setErrors([status === 'cancelled'
          ? importError('The batch was cancelled. Nothing was saved.')
          : status === 'expired'
            ? importError('The preview expired. Nothing was saved.')
            : importError('The batch conflicted with current data. Nothing was saved. Review and prepare a new preview.')])
        return false
      }
      if (status === 'pending' && preparedInput) {
        const restoredPreview = await host.restoreRecordBatch(preview.operationId, controller.signal)
        if (!isCurrent(token)) return false
        const normalized = normalizePreparedBatch(restoredPreview, preparedInput)
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
      setErrors([importError('The server still reports an unresolved outcome. Do not retry automatically.')])
      return false
    } catch (error) {
      if (!isCurrent(token)) return false
      phase = isProposalConflict(error) ? 'conflicted' : 'unknown'
      setErrors([phase === 'conflicted'
        ? importError('The batch conflicted with current data. Nothing was saved. Review and prepare a new preview.')
        : importError('Could not check the batch outcome. Do not retry automatically.')])
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
    preview = null
    preparedInput = null
    confirmed = false
    restored = false
    phase = rows.length ? 'editing' : 'idle'
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
    cancelPreview()
    columns = []
    rows = []
    mapping = {}
    storeUuid = ''
    fileName = ''
    fileFormat = ''
    warnings = []
    preview = null
    preparedInput = null
    errors = []
    confirmed = false
    restored = false
    busy = false
    entity = ''
    fields = []
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
    preparedInput = null
    rows = []
    columns = []
    busy = false
    errors = []
    phase = availability.available ? 'idle' : 'unavailable'
  }

  return {
    get state() { return snapshot() },
    get available() { return availability.available },
    blocksNewTask() { return phase === 'submitting' || phase === 'unknown' },
    refresh,
    setEntity,
    setFile,
    editCell,
    setMapping,
    setStoreIdentifier,
    setConfirmed,
    prepare,
    commit,
    checkOutcome,
    discard,
    reset,
    dispose
  }
}

const FIELD_LABEL_KEYS = {
  storeUuid: 'Store UUID',
  code: 'Code',
  name: 'Name',
  capacity: 'Capacity',
  sort: 'Sort',
  enabled: 'Enabled',
  slug: 'Slug',
  color: 'Color'
}

function fieldLabel(t, property) {
  return t(FIELD_LABEL_KEYS[property] || property)
}

export function formatImportError(t, error) {
  if (!error) return ''
  const params = { ...(error.params || {}) }
  if (typeof params.field === 'string') params.field = fieldLabel(t, params.field)
  if (typeof params.reason === 'string') params.reason = t(params.reason, params.reasonParams || {})
  return t(error.key, params)
}

export function formatImportValue(value) {
  if (value === null) return 'null'
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  return String(value)
}

function displayPhase(t, state) {
  switch (state.phase) {
    case 'unavailable': return t('Bulk import is unavailable in this host.')
    case 'parsing': return t('Reading the file locally…')
    case 'preparing': return t('Preparing the batch preview…')
    case 'submitting': return t('Creating records…')
    case 'unknown': return t('Outcome unknown. Check the outcome before trying again; cancellation does not undo a submitted batch.')
    case 'succeeded': return t('Created {count} records.', { count: state.preview?.rowCount ?? 0 })
    case 'cancelled': return t('The batch was cancelled. Nothing was saved.')
    case 'expired': return t('The preview expired. Nothing was saved.')
    case 'conflicted': return t('The batch conflicted with current data. Nothing was saved. Review and prepare a new preview.')
    case 'ready': return state.restored
      ? t('The server reports this batch as pending. The original preview was restored; review every row and confirm again.')
      : t('Preview only. Nothing is saved until you confirm the complete batch.')
    default: return ''
  }
}

/**
 * Mount the workbench inside the assistant panel. File transparency is
 * local-only; the mount does not add storage, provider calls or transport.
 */
export function mountImportWorkbench(document, host, { t, canInteract = () => true, onClose = () => {}, onStateChange = () => {} } = {}) {
  const node = document.createElement('section')
  node.className = 'import-workbench hidden'
  node.setAttribute('aria-label', t('Bulk create import'))
  let model = null
  let selectable = true

  const make = (tag, className = '', text) => {
    const element = document.createElement(tag)
    if (className) element.className = className
    if (text !== undefined) element.textContent = text
    return element
  }
  const button = (label, action, secondary = true) => {
    const control = make('button', `button${secondary ? ' secondary' : ''}`, label)
    control.type = 'button'
    control.addEventListener('click', action)
    return control
  }
  const fieldWrapper = (label, control) => {
    const wrapper = make('label')
    wrapper.append(make('span', '', label), control)
    return wrapper
  }
  const columnLabel = (t, state, index) => {
    const header = state.columns[index]
    return header ? `${index + 1}. ${header}` : t('Column {column}', { column: index + 1 })
  }

  function render() {
    if (!model) return
    const state = model.state
    node.replaceChildren()
    const header = make('div', 'row')
    header.append(make('strong', '', t('Bulk create import')), button(t('Back'), () => { close(); onClose() }))
    node.append(header)
    node.append(make('p', 'notice', t('Import up to {count} rows from a local TXT, CSV, XLSX or DOCX file. The file is parsed in this browser and is not uploaded or sent to the model provider. Only the existing create fields for Tag and Counter are available.', { count: MAX_IMPORT_ROWS })))
    if (!state.available) {
      node.append(make('p', 'message error', t('Bulk import is unavailable in this host.')))
      return
    }

    const entitySelect = make('select')
    entitySelect.setAttribute('aria-label', t('Import entity'))
    entitySelect.append(make('option', '', t('Select an entity')))
    entitySelect.lastChild.value = ''
    for (const name of state.entities) {
      const option = make('option', '', name)
      option.value = name
      entitySelect.append(option)
    }
    entitySelect.value = state.entity
    entitySelect.disabled = state.busy || !['idle', 'editing', 'ready', 'conflicted', 'expired', 'cancelled'].includes(state.phase)
    entitySelect.addEventListener('change', () => model.setEntity(entitySelect.value))
    node.append(fieldWrapper(t('Entity'), entitySelect))

    const fileInput = make('input')
    fileInput.type = 'file'
    fileInput.accept = IMPORT_ACCEPT
    fileInput.hidden = true
    fileInput.setAttribute('aria-label', t('Choose an import file'))
    const fileButton = button(t('Choose file'), () => fileInput.click())
    fileButton.disabled = state.busy || !state.entity
    fileInput.addEventListener('change', () => {
      const file = fileInput.files?.[0]
      fileInput.value = ''
      if (file) model.setFile(file)
    })
    const fileInfo = state.fileName ? `${state.fileName}${state.format ? ` · ${state.format}` : ''} · ${t('{count} rows', { count: state.rows.length })}` : ''
    node.append(fieldWrapper(t('File'), (() => {
      const wrapper = make('div', 'row')
      wrapper.append(fileButton, fileInput, make('span', 'result-note', fileInfo))
      return wrapper
    })()))

    if (state.warnings.length) {
      const list = make('ul', 'notice')
      for (const warning of state.warnings) list.append(make('li', '', warning))
      node.append(list)
    }

    if (state.entity === 'Counter') {
      const storeInput = make('input')
      storeInput.type = 'text'
      storeInput.maxLength = MAX_STORE_IDENTIFIER_LENGTH
      storeInput.value = state.storeUuid
      storeInput.placeholder = t('Store UUID')
      storeInput.disabled = state.busy || !['idle', 'editing', 'ready', 'conflicted', 'expired', 'cancelled'].includes(state.phase)
      storeInput.addEventListener('change', () => model.setStoreIdentifier(storeInput.value))
      node.append(fieldWrapper(t('Store UUID (applies to every Counter row)'), storeInput))
      node.append(make('p', 'result-note', t('This explicit store identifier is sent with the batch and is never inferred from the current admin page or row data.')))
    }

    if (state.entity && state.columns.length) {
      const mappingBox = make('div', 'import-mapping')
      mappingBox.append(make('strong', '', t('Header field mapping')))
      for (const field of importRowFields(state.entity, state.fields)) {
        const select = make('select')
        select.setAttribute('aria-label', t('Map {field}', { field: fieldLabel(t, field.property) }))
        select.append(make('option', '', t('Not mapped')))
        select.lastChild.value = ''
        state.columns.forEach((column, index) => {
          const option = make('option', '', columnLabel(t, state, index))
          option.value = String(index)
          select.append(option)
        })
        select.value = state.mapping[field.property] === null || state.mapping[field.property] === undefined ? '' : String(state.mapping[field.property])
        select.disabled = state.busy || !['idle', 'editing', 'ready', 'conflicted', 'expired', 'cancelled'].includes(state.phase)
        select.addEventListener('change', () => model.setMapping(field.property, select.value === '' ? null : Number(select.value)))
        mappingBox.append(fieldWrapper(`${fieldLabel(t, field.property)}${field.required ? ' *' : ''}`, select))
      }
      node.append(mappingBox)
    }

    if (state.columns.length) {
      const wrap = make('div', 'import-table')
      const table = make('table')
      const head = make('tr')
      head.append(make('th', '', t('Row')))
      state.columns.forEach((column, index) => head.append(make('th', '', columnLabel(t, state, index))))
      table.append(head)
      state.rows.forEach((row, rowIndex) => {
        const tableRow = make('tr')
        tableRow.append(make('td', '', String(rowIndex + 1)))
        state.columns.forEach((column, columnIndex) => {
          const cell = make('td')
          const input = make('input')
          input.type = 'text'
          input.maxLength = MAX_CELL_LENGTH
          input.value = row[columnIndex] ?? ''
          input.setAttribute('aria-label', t('Row {row}, column {column}', { row: rowIndex + 1, column: columnLabel(t, state, columnIndex) }))
          input.disabled = state.busy || !['idle', 'editing', 'ready', 'conflicted', 'expired', 'cancelled'].includes(state.phase)
          input.addEventListener('change', () => model.editCell(rowIndex, columnIndex, input.value))
          cell.append(input)
          tableRow.append(cell)
        })
        table.append(tableRow)
      })
      wrap.append(table)
      node.append(wrap)
    }

    const actions = make('div', 'row')
    const canPrepare = !state.busy && !state.preview && state.rows.length > 0 && ['idle', 'editing', 'expired', 'cancelled'].includes(state.phase)
    actions.append(button(t('Prepare preview'), () => model.prepare(), false))
    actions.lastChild.disabled = !canPrepare
    const discard = button(t('Discard preview'), () => model.discard())
    discard.disabled = !state.preview || state.busy || !['ready', 'conflicted'].includes(state.phase)
    actions.append(discard)
    const clear = button(t('Clear import'), () => model.reset())
    clear.disabled = state.busy || state.phase === 'submitting' || state.phase === 'unknown'
    actions.append(clear)
    node.append(actions)

    if (state.errors.length) {
      const list = make('div', 'message error')
      list.append(make('strong', '', t('Import needs attention')))
      for (const error of state.errors) list.append(make('p', '', formatImportError(t, error)))
      node.append(list)
    }

    const phaseNote = displayPhase(t, state)
    if (phaseNote) node.append(make('p', state.phase === 'succeeded' ? 'message' : 'result-note', phaseNote))

    if (state.preview) {
      const previewBox = make('div', 'import-preview')
      previewBox.append(make('strong', '', t('Batch preview — not saved')))
      previewBox.append(make('p', '', `${state.preview.entity} · ${t('{count} rows', { count: state.preview.rowCount })} · ${t('Expires')}: ${state.preview.expiresAt}`))
      const table = make('table')
      const values = new Set()
      for (const record of state.preview.records) for (const key of Object.keys(record.values)) values.add(key)
      const ordered = state.fields.map(field => field.property).filter(property => values.has(property))
      const head = make('tr')
      head.append(make('th', '', t('Row')))
      for (const property of ordered) head.append(make('th', '', fieldLabel(t, property)))
      table.append(head)
      for (const record of state.preview.records) {
        const row = make('tr')
        row.append(make('td', '', String(record.row)))
        for (const property of ordered) row.append(make('td', '', formatImportValue(record.values[property])))
        table.append(row)
      }
      previewBox.append(table)
      if (state.preview.warnings.length) {
        const list = make('ul', 'result-note')
        for (const warning of state.preview.warnings) list.append(make('li', '', warning))
        previewBox.append(list)
      }
      if (state.phase === 'ready') {
        const confirmLabel = make('label', 'checkbox')
        const confirm = make('input')
        confirm.type = 'checkbox'
        confirm.checked = state.confirmed
        confirm.disabled = state.busy
        confirm.addEventListener('change', () => model.setConfirmed(confirm.checked))
        confirmLabel.append(confirm, make('span', '', t('I reviewed every row and understand that {count} records will be created.', { count: state.preview.rowCount })))
        previewBox.append(confirmLabel)
        const commit = button(t('Create {count} records', { count: state.preview.rowCount }), () => model.commit(), false)
        commit.disabled = !state.confirmed || state.busy
        previewBox.append(commit)
      }
      if (state.phase === 'unknown') {
        const check = button(t('Check outcome'), () => model.checkOutcome())
        check.disabled = state.busy
        previewBox.append(check)
      }
      node.append(previewBox)
    }
  }

  model = createImportBatch(host, {
    canInteract,
    onChange: () => {
      if (selectable) render()
      try { onStateChange() } catch { /* The host must not break the workbench. */ }
    }
  })
  render()

  function open() {
    selectable = true
    model.refresh()
    node.classList.remove('hidden')
    render()
    return model.available
  }

  function close() {
    node.classList.add('hidden')
  }

  return {
    node,
    get available() { return model.available },
    isOpen: () => !node.classList.contains('hidden'),
    open,
    close,
    reset: options => model.reset(options),
    blocksNewTask: () => model.blocksNewTask(),
    dispose: () => {
      selectable = false
      model.dispose()
      node.replaceChildren()
    }
  }
}
