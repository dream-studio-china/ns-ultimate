import { formatAdminReadError } from './capabilities.js'

// Generic bounded single-record configuration inspection. This module is
// business-name-free: the caller supplies the allowlisted entity policy and
// the OpenAPI-backed capability catalog. The tool never accepts URLs, field
// lists, or JSON paths from the model; every nested path comes from the
// host-owned policy.

export const INSPECTION_TIMEOUT_MS = 30000
export const MAX_INSPECTION_CHARACTERS = 2000
export const MAX_INSPECTION_DEPTH = 5
export const MAX_INSPECTION_DIAGNOSTICS = 40
export const MAX_INSPECTION_DIAGNOSTIC_CHARACTERS = 2000
const MAX_LEAF_STRING = 120

const NUMERIC_ID = /^[1-9]\d{0,17}$/
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const SAFE_SEGMENT = /^[A-Za-z][A-Za-z0-9_]*$/
const UNSAFE_SEGMENT = /^(?:__proto__|prototype|constructor)$/
// Defense in depth: the generic projection never emits obviously sensitive
// paths even if a host policy erroneously lists them. This pattern mirrors
// the host policy audit and stays clear of the approved Store printing
// fields and Product kitchen flag.
const SENSITIVE_PATH = /password|secret|token|credential|private.?key|certificate|api.?key|openid|authorization|email|phone|mobile|address|contact|metadata|payment|pay/i

function isRecordObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

export function validateInspectionIdentifier(identifier) {
  let normalized = identifier
  if (typeof normalized === 'number') {
    if (!Number.isSafeInteger(normalized) || normalized <= 0) {
      throw new TypeError('Record identifiers must be a positive numeric ID or UUID.')
    }
    normalized = String(normalized)
  }
  if (typeof normalized !== 'string' || (!NUMERIC_ID.test(normalized) && !UUID.test(normalized))) {
    throw new TypeError('Record identifiers must be a positive numeric ID or UUID.')
  }
  return normalized
}

function safeSegments(path) {
  if (typeof path !== 'string') throw new TypeError('Inspection policy paths must be strings.')
  const segments = path.split('.')
  if (segments.length === 0 || segments.length > MAX_INSPECTION_DEPTH) {
    throw new TypeError(`Inspection policy path is out of bounds: ${path}`)
  }
  for (const segment of segments) {
    if (!SAFE_SEGMENT.test(segment) || UNSAFE_SEGMENT.test(segment)) {
      throw new TypeError(`Inspection policy path is not allowlisted: ${path}`)
    }
  }
  if (SENSITIVE_PATH.test(path)) {
    throw new TypeError(`Inspection policy path is not allowlisted: ${path}`)
  }
  return segments
}

function hasOwn(value, key) {
  return Object.prototype.hasOwnProperty.call(value, key)
}

function readPath(record, segments) {
  let node = record
  for (let index = 0; index < segments.length; index += 1) {
    const segment = segments[index]
    if (node === null && index > 0) return { reason: 'null-ancestor' }
    if (!isRecordObject(node)) return { reason: 'invalid' }
    if (!hasOwn(node, segment)) return { reason: 'missing' }
    node = node[segment]
  }
  return { value: node }
}

function safeLeaf(value) {
  if (value === null || typeof value === 'boolean') return { ok: true, value }
  if (typeof value === 'number' && Number.isFinite(value)) return { ok: true, value }
  // Oversized strings are omitted and counted, never silently sliced: callers
  // must not present a truncated value as complete while reporting
  // truncated:false.
  if (typeof value === 'string') {
    if (value.length > MAX_LEAF_STRING) return { ok: false }
    return { ok: true, value }
  }
  return { ok: false }
}

// Project only the policy's scalar leaves into a nested result. Diagnostics
// contain only validated policy paths and fixed reason codes, never values.
export function projectInspectedConfiguration(record, allowedPaths) {
  if (!isRecordObject(record)) throw new TypeError('The detail endpoint returned an unsupported record response.')
  if (!Array.isArray(allowedPaths) || allowedPaths.length === 0) {
    throw new TypeError('The inspection policy defines no readable configuration paths.')
  }
  const configuration = {}
  const omissions = []
  for (const path of allowedPaths) {
    const segments = safeSegments(path)
    const result = readPath(record, segments)
    if (result.reason) {
      omissions.push({ path, reason: result.reason })
      continue
    }
    const leaf = safeLeaf(result.value)
    if (!leaf.ok) {
      omissions.push({ path, reason: typeof result.value === 'string' ? 'oversized' : 'invalid' })
      continue
    }
    let node = configuration
    for (const segment of segments.slice(0, -1)) {
      if (!hasOwn(node, segment) || !isRecordObject(node[segment])) node[segment] = {}
      node = node[segment]
    }
    node[segments[segments.length - 1]] = leaf.value
  }
  if (JSON.stringify(configuration).length > MAX_INSPECTION_CHARACTERS) {
    throw new RangeError('The inspected configuration exceeded the size limit.')
  }
  const boundedOmissions = omissions.slice(0, MAX_INSPECTION_DIAGNOSTICS)
  while (boundedOmissions.length && JSON.stringify(boundedOmissions).length > MAX_INSPECTION_DIAGNOSTIC_CHARACTERS) boundedOmissions.pop()
  return { configuration, omitted: omissions.length, omissions: boundedOmissions, omissionsTruncated: boundedOmissions.length < omissions.length }
}

// Unwrap the admin client's `{ data: record, code: 0 }` envelope. A payload
// that already looks like a record (own id/uuid) is accepted as-is so a
// record containing its own `data` key is never unwrapped by mistake.
// Collections and anything else fail closed. The final node must carry record
// identity (own id or uuid): a bare envelope without data is malformed.
export function unwrapDetailPayload(body) {
  let node = body
  for (let depth = 0; depth < 2; depth += 1) {
    if (!isRecordObject(node) || !hasOwn(node, 'data')) break
    if (hasOwn(node, 'id') || hasOwn(node, 'uuid')) break
    node = node.data
  }
  if (!isRecordObject(node) || (!hasOwn(node, 'id') && !hasOwn(node, 'uuid'))) {
    throw new TypeError('The detail endpoint returned an unsupported record response.')
  }
  return node
}

// Confirm the unwrapped record is the requested one before any projection
// leaves the host. Accepts actual backend identity semantics: a numeric id
// returned as a number or string matches a numeric request, and a uuid
// returned as a string matches a UUID request (case-insensitive). Anything
// else (missing, malformed, or mismatched identity) fails closed so one
// record's configuration is never presented as another's.
function normalizeIdentityCandidate(value) {
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value) || value <= 0) return null
    return String(value)
  }
  if (typeof value === 'string' && (NUMERIC_ID.test(value) || UUID.test(value))) return value
  return null
}

export function assertDetailIdentity(record, identifier) {
  if (!isRecordObject(record)) {
    throw new TypeError('The detail endpoint returned a mismatched record response.')
  }
  const expected = validateInspectionIdentifier(identifier)
  const candidates = [normalizeIdentityCandidate(record.id), normalizeIdentityCandidate(record.uuid)].filter(Boolean)
  if (candidates.length === 0) {
    throw new TypeError('The detail endpoint returned a mismatched record response.')
  }
  const matched = candidates.some(candidate => candidate.toLowerCase() === expected.toLowerCase())
  if (!matched) {
    throw new Error('The detail endpoint returned a mismatched record response.')
  }
}

export function normalizeDetailPath(path) {
  if (typeof path !== 'string') return null
  const withoutTrailing = path.length > 1 ? path.replace(/\/$/, '') : path
  return withoutTrailing.replace(/\/\{[^/}]+\}$/, '/{id}')
}

// Resolve the fixed GET detail endpoint for one policy entity. The endpoint
// must be documented in the loaded OpenAPI operations and backed by the
// composed entity config; anything else (unknown entities, unreadable
// collections, missing detail routes, bad identifiers, extra arguments)
// fails closed without touching the network.
export function prepareRecordInspection(input, { catalog, policy, operations }) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    throw new TypeError('A configuration inspection object is required.')
  }
  if (Object.keys(input).some(key => !['entity', 'identifier'].includes(key))) {
    throw new TypeError('The inspection contains unsupported parameters.')
  }
  if (typeof input.entity !== 'string' || !hasOwn(policy || {}, input.entity)) {
    throw new Error('This entity has no approved configuration inspection.')
  }
  const capability = Array.isArray(catalog) ? catalog.find(item => item.key === input.entity) : null
  if (!capability?.readable || typeof capability.path !== 'string') {
    throw new Error('This entity has no approved, documented read endpoint.')
  }
  const identifier = validateInspectionIdentifier(input.identifier)
  const detailPath = `${capability.path}/{id}`
  const documented = Array.isArray(operations) && operations.some(operation =>
    operation?.method === 'GET' && normalizeDetailPath(operation.path) === detailPath)
  if (!documented) throw new Error('This record has no approved, documented detail endpoint.')
  return { key: capability.key, name: capability.name, path: `${capability.path}/${encodeURIComponent(identifier)}`, identifier }
}

// Authenticated single-record GET over the admin client. GET only: no caller
// URL, no method override, no body. Honors caller cancellation and fails
// closed on timeout.
export async function fetchInspectedRecord(request, path, { signal, timeoutMs = INSPECTION_TIMEOUT_MS, label = 'Configuration inspection' } = {}) {
  if (!request || typeof request.get !== 'function') throw new Error(`${label} is unavailable.`)
  if (signal?.aborted) throw new Error(`The ${label.toLowerCase()} was cancelled.`)
  const controller = new AbortController()
  let timedOut = false
  const abortFromCaller = () => controller.abort(signal?.reason)
  signal?.addEventListener('abort', abortFromCaller, { once: true })
  const timeout = setTimeout(() => {
    timedOut = true
    controller.abort()
  }, timeoutMs)
  if (signal?.aborted) abortFromCaller()
  try {
    const response = await request.get(path, { signal: controller.signal, timeout: timeoutMs })
    return response?.data
  } catch (error) {
    if (timedOut) throw new Error(`The ${label.toLowerCase()} timed out after 30 seconds.`)
    if (error?.name === 'AbortError' || signal?.aborted || controller.signal.aborted) {
      throw new Error(`The ${label.toLowerCase()} was cancelled.`)
    }
    throw new Error(formatAdminReadError(error).replace('Admin read query failed', `${label} failed`))
  } finally {
    clearTimeout(timeout)
    signal?.removeEventListener('abort', abortFromCaller)
  }
}
