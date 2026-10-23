/** Fixed-route transport with explicitly compiled host capabilities; never a general HTTP tool. */

/**
 * Resolve a capability source without introducing an async getter. The host
 * initializes the runtime catalog before tool generation and passes a sync
 * source for the exact compiled snapshot. A static array remains supported for
 * tests and embedders.
 */
export function resolveCapabilitySource(source) {
  let capabilities = []
  let revision = null
  if (typeof source === 'function') {
    const value = source()
    if (Array.isArray(value)) capabilities = value
    else if (value && typeof value === 'object') {
      capabilities = typeof value.getCapabilities === 'function' ? value.getCapabilities() : value.capabilities
      revision = typeof value.getRevision === 'function' ? value.getRevision() : value.revision
    }
  } else if (Array.isArray(source)) {
    capabilities = source
  } else if (source && typeof source === 'object') {
    capabilities = typeof source.getCapabilities === 'function' ? source.getCapabilities() : source.capabilities
    revision = typeof source.getRevision === 'function' ? source.getRevision() : source.revision
  }
  return {
    capabilities: Array.isArray(capabilities) ? capabilities : [],
    revision: typeof revision === 'string' && revision ? revision : null
  }
}

function assertSameRevision(before, source) {
  if (!before.revision) return
  const after = resolveCapabilitySource(source)
  if (after.revision !== before.revision) {
    const error = new TypeError('The host capability catalog changed. Request a fresh preview.')
    error.code = 'capabilities_changed'
    throw error
  }
}

export function createMutationHost(request, path, capabilities = []) {
  const operationPath = operationId => {
    if (typeof operationId !== 'string' || !/^[a-f0-9]{64}$/.test(operationId)) throw new TypeError('Invalid operation identifier.')
    return `${path}/${operationId}`
  }
  return {
    getWriteCapabilities() { return resolveCapabilitySource(capabilities).capabilities },
    async listRecordChanges(page = 1, signal) {
      if (!Number.isInteger(page) || page < 1 || page > 100) throw new TypeError('Invalid operation page.')
      const response = await request.get(path, { params: { page }, signal, timeout: 60000 })
      return response.data
    },
    async restoreRecordChange(operationId, signal) {
      const sourceState = resolveCapabilitySource(capabilities)
      try {
        const response = await request.get(`${operationPath(operationId)}/preview`, { signal, timeout: 60000 })
        assertSameRevision(sourceState, capabilities)
        const preview = response.data
        const capability = sourceState.capabilities.find(item => item.entity === preview?.entity)
        if (!capability || !Array.isArray(capability.actions) || !capability.actions.includes(preview?.action)) {
          throw new TypeError('This entity/action is not enabled for assistant mutations.')
        }
        if (capability.validatePreview && !capability.validatePreview(preview, capability)) throw new TypeError('Invalid restored preview.')
        return preview
      } catch (error) { throw mutationError(error) }
    },
    async prepareRecordChange(input, signal) {
      const sourceState = resolveCapabilitySource(capabilities)
      const enabled = sourceState.capabilities
      if (!isPlainRecord(input)
        || !enabled.some(item => item.entity === input.entity && item.actions.includes(input.action))
        || hasUnsafeKeys(input) || Object.keys(input).some(key => !['entity', 'action', 'identifier', 'changes'].includes(key))) {
        throw new TypeError('This entity/action is not enabled for assistant mutations.')
      }
      const capability = enabled.find(item => item.entity === input.entity)
      if ((input.action === 'create' && input.identifier !== undefined)
        || (input.action !== 'create' && (typeof input.identifier !== 'string' || !/^(?:[1-9]\d{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i.test(input.identifier)))) {
        throw new TypeError('An exact target identifier is required for update/delete; create does not accept one.')
      }
      if (input.changes !== undefined) {
        if (!isPlainRecord(input.changes) || hasUnsafeKeys(input.changes)
          || (capability.fields && Object.keys(input.changes).some(key => !Object.hasOwn(capability.fields, key)))
          || (capability.validateChanges && !capability.validateChanges(input.changes, input.action))) {
          throw new TypeError('Changes contain unsupported fields or values.')
        }
      } else if (capability.validateChanges && !capability.validateChanges(undefined, input.action)) {
        throw new TypeError('Changes contain unsupported fields or values.')
      }
      const response = await request.post(path, input, { signal, timeout: 60000 })
      assertSameRevision(sourceState, capabilities)
      return response.data
    },
    async commitRecordChange(operationId, signal) {
      try {
        const response = await request.post(`${operationPath(operationId)}/commit`, { confirmed: true }, { signal, timeout: 60000 })
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
