/** Fixed-route transport with explicitly compiled host capabilities; never a general HTTP tool. */
import { describeChangeRejection } from './capability-validation.js'

const MAX_RETAINED_POLICIES = 256
const OPERATION_ID_PATTERN = /^[a-f0-9]{64}$/
const MAX_IDENTITY_LENGTH = 512

/**
 * Resolve a capability source without introducing an async getter. The host
 * initializes the runtime catalog before tool generation and passes a sync
 * source for the exact compiled snapshot. A static array remains supported for
 * tests and embedders.
 *
 * `identity` is an opaque, generation-qualified string. A dynamic source may
 * supply it through `getIdentity()`/`identity`; otherwise the existing
 * `getRevision()`/`revision` remains the compatibility fallback. Callers must
 * make that value change on every catalog generation, not just on content hash
 * changes, so ABA across an awaited request cannot be mistaken for stability.
 */
export function resolveCapabilitySource(source) {
  let capabilities = []
  let revision = null
  let identity = null
  if (typeof source === 'function') {
    const value = source()
    if (Array.isArray(value)) capabilities = value
    else if (value && typeof value === 'object') {
      capabilities = typeof value.getCapabilities === 'function' ? value.getCapabilities() : value.capabilities
      revision = typeof value.getRevision === 'function' ? value.getRevision() : value.revision
      identity = typeof value.getIdentity === 'function' ? value.getIdentity() : value.identity
    }
  } else if (Array.isArray(source)) {
    capabilities = source
  } else if (source && typeof source === 'object') {
    capabilities = typeof source.getCapabilities === 'function' ? source.getCapabilities() : source.capabilities
    revision = typeof source.getRevision === 'function' ? source.getRevision() : source.revision
    identity = typeof source.getIdentity === 'function' ? source.getIdentity() : source.identity
  }
  const safeRevision = safeIdentityString(revision)
  return {
    capabilities: Array.isArray(capabilities) ? capabilities : [],
    revision: safeRevision,
    identity: safeIdentityString(identity) ?? safeRevision
  }
}

function safeIdentityString(value) {
  return typeof value === 'string'
    && value.length > 0
    && value.length <= MAX_IDENTITY_LENGTH
    && !/[\u0000-\u001f\u007f]/.test(value)
    ? value
    : null
}

export function findCapability(capabilities, entity) {
  if (!Array.isArray(capabilities)) return null
  return capabilities.find(item => isPlainRecord(item) && item.entity === entity) ?? null
}

function effectiveFieldDescriptor(capability, action) {
  if (!isPlainRecord(capability) || !Array.isArray(capability.actions) || !capability.actions.includes(action)) return null
  if (action === 'delete') return {}
  const fields = capability.actionFields?.[action] ?? capability.fields
  return isPlainRecord(fields) && !hasUnsafeKeys(fields) ? fields : null
}

/** @returns {Set<string>|null} */
export function capabilityFields(capability, action) {
  const fields = effectiveFieldDescriptor(capability, action)
  return fields === null ? null : new Set(Object.keys(fields))
}

/**
 * A stable fingerprint of the action descriptor (actions + top-level field
 * rules, including nested rule objects). Function-valued validators are omitted
 * because the same descriptor instance is responsible for both prepare and
 * commit validation; the transport compares the declarative scope only.
 */
export function capabilityFingerprint(capability, action) {
  if (!isPlainRecord(capability) || !Array.isArray(capability.actions)) return null
  const fields = effectiveFieldDescriptor(capability, action)
  if (fields === null) return null
  try {
    return JSON.stringify(canonicalize({
      actions: [...capability.actions].sort(),
      fields
    }))
  } catch {
    return null
  }
}

export function capabilityConflict(message) {
  const error = new TypeError(message || 'The proposal is stale. Request a fresh preview.')
  error.code = 'proposal_conflict'
  return error
}

export function retainCapabilityPolicy(retained, operationId, policy) {
  if (retained.size >= MAX_RETAINED_POLICIES) {
    const oldest = retained.keys().next().value
    if (typeof oldest === 'string') retained.delete(oldest)
  }
  retained.set(operationId, policy)
}

/** @param {{identity: string|null}} state */
export function assertCapabilityPolicyCurrent(state, retained) {
  if (retained.identity !== state.identity) {
    throw capabilityConflict('The capability catalog changed and this entity/action is not enabled for the retained generation. Request a fresh preview.')
  }
  const capability = findCapability(state.capabilities, retained.entity)
  if (!capability || !Array.isArray(capability.actions) || !capability.actions.includes(retained.action)) {
    throw capabilityConflict('This entity or action is not enabled for assistant mutations.')
  }
  const allowed = capabilityFields(capability, retained.action)
  if (allowed !== null) {
    for (const field of retained.fields) {
      if (!allowed.has(field)) {
        throw capabilityConflict('This field is not enabled for assistant mutations.')
      }
    }
  }
  if (retained.fingerprint !== capabilityFingerprint(capability, retained.action)) {
    throw capabilityConflict('The capability policy changed. Request a fresh preview.')
  }
}

export function createMutationHost(request, path, capabilities = []) {
  const operationPath = operationId => {
    if (typeof operationId !== 'string' || !OPERATION_ID_PATTERN.test(operationId)) throw new TypeError('Invalid operation identifier.')
    return `${path}/${operationId}`
  }
  // Retain only metadata/schema references for prepared and restored operations;
  // never the submitted values. Unknown operation IDs are closed on commit.
  const retained = new Map()

  return {
    getWriteCapabilities() { return resolveCapabilitySource(capabilities).capabilities },
    async listRecordChanges(page = 1, signal) {
      if (!Number.isInteger(page) || page < 1 || page > 100) throw new TypeError('Invalid operation page.')
      const response = await request.get(path, { params: { page }, signal, timeout: 60000 })
      return response.data
    },
    async restoreRecordChange(operationId, signal) {
      const target = operationPath(operationId)
      const sourceState = resolveCapabilitySource(capabilities)
      try {
        const response = await request.get(`${target}/preview`, { signal, timeout: 60000 })
        assertSameIdentity(sourceState, capabilities)
        const current = resolveCapabilitySource(capabilities)
        const preview = response.data
        const capability = findCapability(current.capabilities, preview?.entity)
        if (!capability || !Array.isArray(capability.actions) || !capability.actions.includes(preview?.action)) {
          throw new TypeError('This entity/action is not enabled for assistant mutations.')
        }
        if (preview?.operationId !== undefined && preview.operationId !== operationId) {
          throw new TypeError('The operation identity changed.')
        }
        if (capability.validatePreview && !capability.validatePreview(preview, capability)) throw new TypeError('Invalid restored preview.')
        retainCapabilityPolicy(retained, operationId, captureOperationPolicy(current, capability, preview.action, preview))
        return preview
      } catch (error) { throw mutationError(error) }
    },
    async prepareRecordChange(input, signal) {
      const sourceState = resolveCapabilitySource(capabilities)
      const enabled = sourceState.capabilities
      if (!isPlainRecord(input)
        || !enabled.some(item => item.entity === input.entity && Array.isArray(item.actions) && item.actions.includes(input.action))
        || hasUnsafeKeys(input) || Object.keys(input).some(key => !['entity', 'action', 'identifier', 'changes'].includes(key))) {
        throw new TypeError('This entity/action is not enabled for assistant mutations.')
      }
      const capability = enabled.find(item => item.entity === input.entity)
      if ((input.action === 'create' && input.identifier !== undefined)
        || (input.action !== 'create' && (typeof input.identifier !== 'string' || !/^(?:[1-9]\d{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i.test(input.identifier)))) {
        throw new TypeError('An exact target identifier is required for update/delete; create does not accept one.')
      }
      const actionFields = capability.actionFields?.[input.action]
        ?? (Array.isArray(capability.actions) && capability.actions.includes(input.action) ? capability.fields : undefined)
      if (input.action === 'delete' && input.changes !== undefined) {
        throw new TypeError('Changes contain unsupported fields or values. Deletion does not accept change fields.')
      }
      if (input.changes !== undefined) {
        if (!isPlainRecord(input.changes) || hasUnsafeKeys(input.changes)) {
          throw new TypeError('Changes contain unsupported fields or values. Changes must be a non-empty plain object.')
        }
        if (isPlainRecord(actionFields)) {
          const unknown = Object.keys(input.changes).filter(key => !Object.hasOwn(actionFields, key))
          if (unknown.length) {
            throw new TypeError(`Changes contain unsupported fields or values. Unsupported change fields: ${unknown.join(', ')}.`)
          }
          if (capability.validateChanges && !capability.validateChanges(input.changes, input.action)) {
            throw new TypeError(describeChangeRejection(input.changes, actionFields, input.action))
          }
        } else if (capability.validateChanges && !capability.validateChanges(input.changes, input.action)) {
          throw new TypeError('Changes contain unsupported fields or values.')
        }
      } else if (capability.validateChanges && !capability.validateChanges(undefined, input.action)) {
        throw new TypeError('Changes contain unsupported fields or values. Changes are required for this action.')
      }
      const response = await request.post(path, input, { signal, timeout: 60000 })
      assertSameIdentity(sourceState, capabilities)
      const current = resolveCapabilitySource(capabilities)
      const preview = response.data
      // Legacy/embedded transports may return a preview without an operation ID
      // for local validation only. It cannot be committed (unknown IDs are
      // closed); real previews always carry one and are retained below.
      if (preview?.operationId === undefined) return preview
      if (typeof preview.operationId !== 'string' || !OPERATION_ID_PATTERN.test(preview.operationId)) {
        throw new TypeError('Invalid prepared operation identifier.')
      }
      const currentCapability = findCapability(current.capabilities, preview?.entity)
      if (!currentCapability || !Array.isArray(currentCapability.actions) || !currentCapability.actions.includes(preview?.action)) {
        throw new TypeError('This entity/action is not enabled for assistant mutations.')
      }
      if (currentCapability.validatePreview && !currentCapability.validatePreview(preview, currentCapability)) {
        throw new TypeError('Invalid prepared preview.')
      }
      retainCapabilityPolicy(retained, preview.operationId, captureOperationPolicy(current, currentCapability, preview.action, preview))
      return preview
    },
    async commitRecordChange(operationId, signal) {
      const target = operationPath(operationId)
      assertActive(signal)
      const retainedPolicy = retained.get(operationId)
      if (!retainedPolicy) {
        throw capabilityConflict('This operation was not prepared in this session. Request a fresh preview.')
      }
      assertCapabilityPolicyCurrent(resolveCapabilitySource(capabilities), retainedPolicy)
      try {
        // Deliberately no identity/policy re-check after this POST: once sent, an
        // already-in-flight commit may settle with a receipt even if authority is
        // revoked while the request is in flight. A thrown request stays unknown.
        const response = await request.post(`${target}/commit`, { confirmed: true }, { signal, timeout: 60000 })
        return response.data
      } catch (error) { throw mutationError(error) }
    },
    async getRecordChangeStatus(operationId, signal) {
      const response = await request.get(operationPath(operationId), { signal, timeout: 60000 })
      return response.data
    },
    async cancelRecordChange(operationId) {
      await request.post(`${operationPath(operationId)}/cancel`, {}, { timeout: 60000 })
    }
  }
}

function captureOperationPolicy(state, capability, action, preview) {
  const allowed = capabilityFields(capability, action)
  const fields = action === 'delete' ? [] : previewFields(preview)
  if (allowed !== null) {
    for (const field of fields) {
      if (!allowed.has(field)) throw new TypeError('Invalid prepared preview field.')
    }
  }
  return Object.freeze({
    entity: capability.entity,
    action,
    fields: Object.freeze(fields),
    identity: state.identity,
    fingerprint: capabilityFingerprint(capability, action)
  })
}

function previewFields(preview) {
  if (!Array.isArray(preview?.differences)) throw new TypeError('Invalid prepared preview.')
  const fields = []
  for (const difference of preview.differences) {
    if (!isPlainRecord(difference) || typeof difference.property !== 'string' || !difference.property) {
      throw new TypeError('Invalid prepared preview.')
    }
    if (!fields.includes(difference.property)) fields.push(difference.property)
  }
  return fields
}

function assertActive(signal) {
  if (signal?.aborted) {
    const error = new Error('The request was cancelled.')
    error.name = 'AbortError'
    throw error
  }
}

function assertSameIdentity(before, source) {
  const after = resolveCapabilitySource(source)
  if (before.identity === after.identity) return
  const error = new TypeError('The host capability catalog changed. Request a fresh preview.')
  error.code = 'capabilities_changed'
  throw error
}

function canonicalize(value) {
  if (!value || typeof value !== 'object') return value
  if (Array.isArray(value)) return value.map(canonicalize)
  const result = {}
  for (const key of Object.keys(value).sort()) result[key] = canonicalize(value[key])
  return result
}

function isPlainRecord(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const prototype = Object.getPrototypeOf(value)
  return (prototype === Object.prototype || prototype === null)
    && Reflect.ownKeys(value).every(key => typeof key === 'string')
}

function hasUnsafeKeys(value) {
  return Object.keys(value).some(key => ['__proto__', 'prototype', 'constructor'].includes(key))
}

function mutationError(error) {
  if (error?.response?.status === 409 && error.response.data?.error === 'proposal_conflict') {
    return Object.assign(new Error('The proposal conflicts with the current record.'), { code: 'proposal_conflict' })
  }
  return error
}
