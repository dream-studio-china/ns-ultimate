/** UI-only state machine. No model tool can invoke submission. */
export function createPreparedChange(preview, host) {
  let state = 'ready'
  const isExpired = () => !Number.isFinite(Date.parse(preview.expiresAt)) || Date.parse(preview.expiresAt) <= Date.now()
  return {
    get state() { return state },
    invalidate() { if (state === 'ready') state = 'invalidated' },
    async submit(confirmed, signal) {
      if (confirmed !== true || state !== 'ready') return { state }
      if (isExpired()) { state = 'expired'; return { state } }
      if (signal?.aborted) return { state }
      state = 'submitting'
      try {
        const receipt = await host.commitRecordChange(preview.operationId, signal)
        state = receipt?.status === 'succeeded' ? 'succeeded' : 'unknown'
        return { state, receipt }
      } catch {
        // An aborted/timed-out request may already have committed. Never retry automatically.
        state = 'unknown'
        return { state }
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
        return { state, receipt }
      } catch { return { state: 'unknown' } }
    }
  }
}
