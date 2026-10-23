import { normalizeDetailPath, validateInspectionIdentifier } from './inspect.js'

// Generic bounded single-record content read. The host supplies the entity and
// field allowlist; the model may choose only an entity from that policy and an
// exact identifier. No URL, field list, filter or path ever comes from the model.
// Keep the whole tool payload below the 18,000-character conversation limit
// enforced before a tool result is sent back to the model.
export const MAX_RECORD_READ_FIELD_CHARACTERS = 12000
export const MAX_RECORD_READ_TOTAL_CHARACTERS = 14000
export const MAX_RECORD_READ_FIELDS = 20
const SAFE_FIELD = /^[A-Za-z][A-Za-z0-9_]*$/
const UNSAFE_FIELD = /^(?:__proto__|prototype|constructor)$/
// Defense in depth for host policy mistakes. Content fields such as title and
// body remain readable; credentials, contact data and opaque blobs do not.
const SENSITIVE_FIELD = /password|secret|token|credential|private.?key|certificate|api.?key|openid|authorization|email|phone|mobile|address|contact|metadata|payment|pay|settings/i

function isRecordObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function hasOwn(value, key) {
  return Object.prototype.hasOwnProperty.call(value, key)
}

export function validateReadFields(fields) {
  if (!Array.isArray(fields) || fields.length === 0 || fields.length > MAX_RECORD_READ_FIELDS) {
    throw new TypeError('The record read policy defines an invalid field list.')
  }
  const seen = new Set()
  for (const field of fields) {
    if (typeof field !== 'string' || !SAFE_FIELD.test(field) || UNSAFE_FIELD.test(field) || SENSITIVE_FIELD.test(field)) {
      throw new TypeError(`The record read policy field is not allowlisted: ${String(field)}`)
    }
    if (seen.has(field)) throw new TypeError(`The record read policy repeats a field: ${field}`)
    seen.add(field)
  }
  return [...fields]
}

export function prepareRecordRead(input, { catalog, policy, operations }) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    throw new TypeError('A record read object is required.')
  }
  if (Object.keys(input).some(key => !['entity', 'identifier'].includes(key))) {
    throw new TypeError('The record read contains unsupported parameters.')
  }
  if (typeof input.entity !== 'string' || !hasOwn(policy || {}, input.entity)) {
    throw new Error('This entity has no approved record read.')
  }
  const fields = validateReadFields(policy[input.entity]?.fields)
  const capability = Array.isArray(catalog) ? catalog.find(item => item.key === input.entity) : null
  if (!capability?.readable || typeof capability.path !== 'string') {
    throw new Error('This entity has no approved, documented read endpoint.')
  }
  const identifier = validateInspectionIdentifier(input.identifier)
  const detailPath = `${capability.path}/{id}`
  const documented = Array.isArray(operations) && operations.some(operation =>
    operation?.method === 'GET' && normalizeDetailPath(operation.path) === detailPath)
  if (!documented) throw new Error('This record has no approved, documented detail endpoint.')
  return {
    key: capability.key,
    name: capability.name,
    path: `${capability.path}/${encodeURIComponent(identifier)}`,
    identifier,
    fields
  }
}

function safeValue(value) {
  if (value === null || typeof value === 'boolean') return true
  return typeof value === 'number' ? Number.isFinite(value) : typeof value === 'string'
}

export function projectRecordRead(record, allowedFields) {
  if (!isRecordObject(record)) throw new TypeError('The detail endpoint returned an unsupported record response.')
  const fields = validateReadFields(allowedFields)
  const output = {}
  const truncatedFields = []
  const omittedFields = []
  for (const field of fields) {
    if (!hasOwn(record, field)) {
      omittedFields.push(field)
      continue
    }
    const value = record[field]
    if (!safeValue(value)) {
      omittedFields.push(field)
      continue
    }
    if (typeof value === 'string' && value.length > MAX_RECORD_READ_FIELD_CHARACTERS) {
      output[field] = value.slice(0, MAX_RECORD_READ_FIELD_CHARACTERS)
      truncatedFields.push(field)
    } else {
      output[field] = value
    }
  }
  while (Object.keys(output).length > 1 && JSON.stringify(output).length > MAX_RECORD_READ_TOTAL_CHARACTERS) {
    const removed = Object.keys(output).pop()
    delete output[removed]
    omittedFields.push(removed)
  }
  return {
    fields: output,
    truncatedFields,
    omittedFields,
    truncated: truncatedFields.length > 0 || omittedFields.length > 0
  }
}
