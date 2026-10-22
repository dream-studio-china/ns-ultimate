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
        return response.data
      } catch (error) { throw mutationError(error) }
    },
    async prepareRecordChange(input, signal) {
      if (!input || typeof input !== 'object' || Array.isArray(input)
        || !capabilities.some(item => item.entity === input.entity && item.actions.includes(input.action))
        || Object.keys(input).some(key => !['entity', 'action', 'identifier', 'changes'].includes(key))) {
        throw new TypeError('This entity/action is not enabled for assistant mutations.')
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

function mutationError(error) {
  if (error?.response?.status === 409 && error.response.data?.error === 'proposal_conflict') {
    return Object.assign(new Error('The proposal conflicts with the current record.'), { code: 'proposal_conflict' })
  }
  return error
}
