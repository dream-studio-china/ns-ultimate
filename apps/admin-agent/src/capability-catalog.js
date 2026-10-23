/**
 * Runtime capability catalog transport and validation.
 *
 * The authenticated backend is the single authority for write descriptors. This
 * module validates a bounded versioned JSON response, compiles it once with the
 * generic validation engine, and exposes an account/session-scoped runtime. It
 * deliberately has no module-global cache, no localStorage path, and no static
 * fallback field table.
 */

import {
  CapabilityCatalogError,
  compileCapabilityDescriptor,
  isPlainRecord,
  validateCapabilityDescriptor
} from './capability-validation.js'

export { CapabilityCatalogError } from './capability-validation.js'

export const CAPABILITY_SCHEMA_VERSION = 1
const DEFAULT_PATH = '/api/v1/manage/agent/capabilities'
const DEFAULT_TIMEOUT_MS = 60000
const DEFAULT_MAX_AGE_MS = 30000
const MAX_RESPONSE_CHARACTERS = 262144
const MAX_CAPABILITIES = 64
const MAX_BATCH_CAPABILITIES = 8
const MAX_DESCRIPTION_LENGTH = 2000
const REVISION_PATTERN = /^[0-9a-f]{64}$/i
const PATH_PATTERN = /^\/[A-Za-z0-9_./-]+$/
const BATCH_ENTITY_NAMES = Object.freeze(['Tag', 'Counter'])

function fail(code, message) {
  throw new CapabilityCatalogError(code, message)
}

function payloadOf(response) {
  if (isPlainRecord(response) && !Object.hasOwn(response, 'schemaVersion') && Object.hasOwn(response, 'data')) return response.data
  return response
}

function parsePayload(response) {
  let payload = payloadOf(response)
  if (typeof payload === 'string') {
    if (payload.length > MAX_RESPONSE_CHARACTERS) fail('too_large', 'The capability catalog response is too large.')
    try {
      payload = JSON.parse(payload)
    } catch {
      fail('malformed', 'The capability catalog is not valid JSON.')
    }
  }
  if (!isPlainRecord(payload)) fail('malformed', 'The capability catalog is not a plain object.')
  let serialized
  try {
    serialized = JSON.stringify(payload)
  } catch {
    fail('malformed', 'The capability catalog is not serializable.')
  }
  if (serialized.length > MAX_RESPONSE_CHARACTERS) fail('too_large', 'The capability catalog response is too large.')
  return payload
}

/**
 * Validate the versioned wire envelope. Descriptor-level validation is handled
 * by the compiler, so the envelope stage only enforces bounded arrays and
 * stable identity before any descriptor is used.
 */
export function validateCapabilityCatalogResponse(response) {
  const payload = parsePayload(response)
  const allowedKeys = new Set(['schemaVersion', 'revision', 'capabilities', 'batchCapabilities'])
  for (const key of Object.keys(payload)) {
    if (!allowedKeys.has(key)) fail('malformed', 'The capability catalog contains an unsupported key.')
  }
  if (!Object.hasOwn(payload, 'schemaVersion') || !Object.hasOwn(payload, 'revision')
    || !Object.hasOwn(payload, 'capabilities') || !Object.hasOwn(payload, 'batchCapabilities')) {
    fail('malformed', 'The capability catalog envelope is incomplete.')
  }
  if (payload.schemaVersion !== CAPABILITY_SCHEMA_VERSION) {
    fail('unsupported_schema_version', 'This assistant does not support the server capability catalog version.')
  }
  if (typeof payload.revision !== 'string' || !REVISION_PATTERN.test(payload.revision)) {
    fail('malformed', 'The capability catalog revision is invalid.')
  }
  if (!Array.isArray(payload.capabilities) || payload.capabilities.length > MAX_CAPABILITIES) {
    fail('malformed', 'The capability catalog capability list is out of bounds.')
  }
  if (!Array.isArray(payload.batchCapabilities) || payload.batchCapabilities.length > MAX_BATCH_CAPABILITIES) {
    fail('malformed', 'The capability catalog batch list is out of bounds.')
  }
  const entities = new Set()
  for (const descriptor of payload.capabilities) {
    if (!isPlainRecord(descriptor) || typeof descriptor.entity !== 'string') fail('malformed', 'A capability descriptor is invalid.')
    if (entities.has(descriptor.entity)) fail('malformed', 'The capability catalog contains a duplicate entity.')
    entities.add(descriptor.entity)
  }
  const batchEntities = new Set()
  for (const descriptor of payload.batchCapabilities) {
    if (!isPlainRecord(descriptor) || typeof descriptor.entity !== 'string') fail('malformed', 'A batch capability descriptor is invalid.')
    if (batchEntities.has(descriptor.entity)) fail('malformed', 'The capability catalog contains a duplicate batch entity.')
    batchEntities.add(descriptor.entity)
    if (!BATCH_ENTITY_NAMES.includes(descriptor.entity) || !entities.has(descriptor.entity)) {
      fail('malformed', 'The capability catalog contains an unsupported batch entity.')
    }
  }
  return payload
}

/**
 * Compile a validated catalog response into reusable capability instances.
 * previewSemantics maps an entity name to an optional extra preview validator
 * for specialized safety checks (for example ordered preview titles). The
 * mapped function receives the compiled capability and must not duplicate
 * field rules already present in the descriptor.
 */
export function compileCapabilityCatalogResponse(response, { previewSemantics = {}, changeSemantics = {} } = {}) {
  const payload = validateCapabilityCatalogResponse(response)
  const previews = previewSemantics && typeof previewSemantics === 'object' ? previewSemantics : {}
  const changes = changeSemantics && typeof changeSemantics === 'object' ? changeSemantics : {}
  const capabilities = payload.capabilities.map(descriptor => compileCapabilityDescriptor(descriptor, {
    previewValidator: previews[descriptor.entity],
    changesValidator: changes[descriptor.entity]
  }))
  const batchCapabilities = payload.batchCapabilities.map(descriptor => {
    validateCapabilityDescriptor(descriptor, { batch: true })
    return compileCapabilityDescriptor(descriptor)
  })
  return Object.freeze({
    schemaVersion: payload.schemaVersion,
    revision: payload.revision.toLowerCase(),
    capabilities: Object.freeze(capabilities),
    batchCapabilities: Object.freeze(batchCapabilities)
  })
}

function abortError(label = 'capability catalog request') {
  const error = new Error(`${label} was aborted.`)
  error.name = 'AbortError'
  return error
}

function normalizePath(value) {
  if (typeof value !== 'string' || !PATH_PATTERN.test(value) || value.includes('//') || value.includes('..')) {
    throw new TypeError('A bounded capability catalog path is required.')
  }
  return value
}

/**
 * Create one account/session-scoped catalog runtime. The runtime is intentionally
 * created per host mount: no descriptors, revisions or values cross actors.
 */
export function createCapabilityCatalogRuntime({
  request,
  path = DEFAULT_PATH,
  compile = compileCapabilityCatalogResponse,
  maxAgeMs = DEFAULT_MAX_AGE_MS,
  timeoutMs = DEFAULT_TIMEOUT_MS,
  now = () => Date.now()
} = {}) {
  if (!request || typeof request.get !== 'function') throw new TypeError('An authenticated GET client is required.')
  if (typeof compile !== 'function') throw new TypeError('A capability compiler is required.')
  if (!Number.isFinite(maxAgeMs) || maxAgeMs < 0) throw new TypeError('The capability cache age is invalid.')
  if (!Number.isFinite(timeoutMs) || timeoutMs <= 0) throw new TypeError('The capability request timeout is invalid.')
  const endpoint = normalizePath(path)

  let current = null
  let fetchedAt = 0
  let inFlight = null
  let controller = null
  let disposed = false
  let epoch = 0
  let status = Object.freeze({ available: false, error: 'not_loaded' })
  const listeners = new Set()

  function emit(event) {
    for (const listener of [...listeners]) {
      try { listener(event) } catch { /* UI listeners must not break the transport. */ }
    }
  }

  function publish(next) {
    const previousRevision = current?.revision ?? null
    current = next
    fetchedAt = now()
    status = Object.freeze({ available: true, error: '' })
    if (previousRevision !== next.revision) {
      epoch += 1
      emit(Object.freeze({ epoch, revision: next.revision, previousRevision, error: '' }))
    }
  }

  function failClosed(error) {
    const normalized = error instanceof CapabilityCatalogError
      ? error
      : new CapabilityCatalogError('unavailable', 'The capability catalog could not be loaded.')
    const previousRevision = current?.revision ?? null
    current = null
    fetchedAt = 0
    status = Object.freeze({ available: false, error: normalized.code })
    epoch += 1
    emit(Object.freeze({ epoch, revision: null, previousRevision, error: normalized.code }))
    return normalized
  }

  async function requestCatalog() {
    const requestController = new AbortController()
    controller = requestController
    try {
      const response = await request.get(endpoint, {
        signal: requestController.signal,
        timeout: timeoutMs,
        headers: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' }
      })
      if (disposed) fail('disposed', 'The capability catalog request was cancelled because the admin session changed.')
      return compile(response)
    } finally {
      if (controller === requestController) controller = null
    }
  }

  function awaitCaller(promise, signal) {
    if (!signal) return promise
    if (signal.aborted) return Promise.reject(abortError())
    return new Promise((resolve, reject) => {
      let settled = false
      const finish = (callback, value) => {
        if (settled) return
        settled = true
        signal.removeEventListener?.('abort', onAbort)
        callback(value)
      }
      const onAbort = () => finish(reject, abortError())
      signal.addEventListener?.('abort', onAbort, { once: true })
      Promise.resolve(promise).then(
        value => finish(resolve, value),
        error => finish(reject, error)
      )
    })
  }

  async function ensure({ signal, force = false } = {}) {
    if (disposed) throw new CapabilityCatalogError('disposed', 'The capability catalog is unavailable because the admin session changed.')
    if (signal?.aborted) throw abortError()
    if (!force && current && now() - fetchedAt <= maxAgeMs) return current
    if (!inFlight) {
      inFlight = (async() => {
        try {
          const compiled = await requestCatalog()
          if (disposed) throw new CapabilityCatalogError('disposed', 'The capability catalog request was cancelled because the admin session changed.')
          publish(compiled)
          return compiled
        } catch (error) {
          if (disposed) throw new CapabilityCatalogError('disposed', 'The capability catalog request was cancelled because the admin session changed.')
          throw failClosed(error)
        } finally {
          inFlight = null
        }
      })()
    }
    return awaitCaller(inFlight, signal)
  }

  return {
    get current() { return current },
    get status() { return status },
    get epoch() { return epoch },
    get revision() { return current?.revision ?? null },
    ensure,
    refresh(options = {}) { return ensure({ ...options, force: true }) },
    subscribe(listener) {
      if (typeof listener !== 'function' || disposed) return () => {}
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    dispose() {
      if (disposed) return
      disposed = true
      controller?.abort()
      controller = null
      inFlight = null
      current = null
      fetchedAt = 0
      status = Object.freeze({ available: false, error: 'disposed' })
      listeners.clear()
    }
  }
}
