/** UI-only state machine. No model tool can invoke submission. */
export function createPreparedChange(preview, host) {
  let state = 'ready'
  const isExpired = () => !Number.isFinite(Date.parse(preview.expiresAt)) || Date.parse(preview.expiresAt) <= Date.now()
  return {
    get state() { return state },
    invalidate() { if (state === 'ready' || state === 'conflicted') state = 'invalidated' },
    async submit(confirmed, signal) {
      if (confirmed !== true || state !== 'ready') return { state }
      if (isExpired()) { state = 'expired'; return { state } }
      if (signal?.aborted) return { state }
      state = 'submitting'
      try {
        const receipt = await host.commitRecordChange(preview.operationId, signal)
        state = receipt?.status === 'succeeded' ? 'succeeded' : 'unknown'
        return { state, receipt }
      } catch (error) {
        // An aborted/timed-out request may already have committed. Never retry automatically.
        state = error?.code === 'proposal_conflict' ? 'conflicted' : 'unknown'
        const raw = error?.response?.data?.message ?? error?.response?.data?.detail ?? error?.message
        const reason = typeof raw === 'string' ? raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400) : ''
        return { state, reason }
      }
    },
    async recover(signal) {
      if (state !== 'unknown') return { state }
      try {
        const receipt = await host.getRecordChangeStatus(preview.operationId, signal)
        state = receipt?.status === 'succeeded' ? 'succeeded'
          : receipt?.status === 'cancelled' ? 'invalidated'
            : receipt?.status === 'expired' ? 'expired'
            : receipt?.status === 'pending' ? (isExpired() ? 'expired' : 'ready') : 'unknown'
        if (state === 'ready') {
          // Pending metadata alone cannot establish that the old snapshot is still valid.
          state = 'unknown'
          const restored = await host.restoreRecordChange(preview.operationId, signal)
          if (restored?.operationId !== preview.operationId || restored.expiresAt !== preview.expiresAt
            || restored.saved !== false || !Array.isArray(restored.differences)) throw new TypeError('Unexpected operation preview.')
          state = isExpired() ? 'expired' : 'ready'
        }
        return { state, receipt }
      } catch (error) {
        state = error?.code === 'proposal_conflict' ? 'conflicted' : 'unknown'
        return { state }
      }
    }
  }
}
