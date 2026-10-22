/** Fixed-route transport with explicit host-owned capabilities; never a general HTTP tool. */
export function createMutationHost(request, path, capabilities = []) {
  const operationPath = operationId => {
    if (typeof operationId !== 'string' || !/^[a-f0-9]{64}$/.test(operationId)) throw new TypeError('Invalid operation identifier.')
    return `${path}/${operationId}`
  }
  return {
    getWriteCapabilities() { return capabilities },
    async listRecordChanges(page = 1, signal) {
      if (!Number.isInteger(page) || page < 1 || page > 100) throw new TypeError('Invalid operation page.')
      const response = await request.get(path, { params: { page }, signal, timeout: 60000 })
      return response.data
    },
    async restoreRecordChange(operationId, signal) {
      try {
        const response = await request.get(`${operationPath(operationId)}/preview`, { signal, timeout: 60000 })
        const preview = response.data
        const capability = capabilities.find(item => item.entity === preview?.entity)
        if (!capability || !Array.isArray(capability.actions) || !capability.actions.includes(preview?.action)) {
          throw new TypeError('This entity/action is not enabled for assistant mutations.')
        }
        if (capability.validatePreview && !capability.validatePreview(preview)) throw new TypeError('Invalid restored preview.')
        return preview
      } catch (error) { throw mutationError(error) }
    },
    async prepareRecordChange(input, signal) {
      if (!isPlainRecord(input)
        || !capabilities.some(item => item.entity === input.entity && item.actions.includes(input.action))
        || hasUnsafeKeys(input) || Object.keys(input).some(key => !['entity', 'action', 'identifier', 'changes'].includes(key))) {
        throw new TypeError('This entity/action is not enabled for assistant mutations.')
      }
      const capability = capabilities.find(item => item.entity === input.entity)
      if (input.changes !== undefined) {
        if (!isPlainRecord(input.changes) || hasUnsafeKeys(input.changes)
          || (capability.fields && Object.keys(input.changes).some(key => !Object.hasOwn(capability.fields, key)))
          || (capability.validateChanges && !capability.validateChanges(input.changes))) {
          throw new TypeError('Changes contain unsupported fields or values.')
        }
      } else if (capability.validateChanges) {
        throw new TypeError('Changes contain unsupported fields or values.')
      }
      const response = await request.post(path, input, { signal, timeout: 60000 })
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
  return prototype === Object.prototype || prototype === null
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
