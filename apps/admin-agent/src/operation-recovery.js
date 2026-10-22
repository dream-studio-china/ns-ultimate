/** UI-only receipt discovery and preview recovery. Never submits or persists a mutation. */
export function createOperationRecovery(host, { canInteract = () => true, onChange = () => {}, onBusy = () => {}, onRestore = () => {} } = {}) {
  let state = { items: [], page: 1, hasMore: false, busy: false, error: '' }
  let controller = null
  let disposed = false
  let generation = 0
  const emit = update => { state = { ...state, ...update }; onChange(state) }
  async function run(action, success, error) {
    if (disposed || controller || !canInteract()) return
    const request = new AbortController()
    const requestGeneration = generation
    controller = request
    emit({ busy: true, error: '' })
    onBusy(true, request)
    try {
      const result = await action(request.signal)
      if (!disposed && requestGeneration === generation && !request.signal.aborted) success(result)
    } catch {
      if (!disposed && requestGeneration === generation) emit({ error: request.signal.aborted ? 'Task cancelled.' : error })
    } finally {
      if (!disposed && requestGeneration === generation) {
        controller = null
        emit({ busy: false })
        onBusy(false, request)
      }
    }
  }
  function reset() {
    generation += 1
    const active = controller
    active?.abort()
    controller = null
    state = { items: [], page: 1, hasMore: false, busy: false, error: '' }
    if (!disposed) {
      onChange(state)
      if (active) onBusy(false, active)
    }
  }
  return {
    get state() { return state },
    load(page = 1) {
      return run(signal => host.listRecordChanges(page, signal), result => {
        if (!Array.isArray(result?.items) || result.page !== page || result.items.length > 20) throw new TypeError('Invalid operation list.')
        emit({ items: result.items, page, hasMore: result.hasMore === true })
      }, 'Could not load operations. Refresh to try again.')
    },
    check(operationId) {
      return run(signal => host.getRecordChangeStatus(operationId, signal), receipt => {
        if (receipt?.operationId !== operationId) throw new TypeError('Unexpected operation receipt.')
        emit({ items: state.items.map(item => item.operationId === operationId ? receipt : item) })
      }, 'Could not check the operation outcome. Do not retry the write automatically.')
    },
    restore(operationId) {
      return run(signal => host.restoreRecordChange(operationId, signal), preview => {
        if (preview?.operationId !== operationId || preview.saved !== false || !Array.isArray(preview.differences)) throw new TypeError('Unexpected operation preview.')
        onRestore(preview)
      }, 'Could not restore this preview. Check the outcome; it may be expired, changed, or no longer permitted.')
    },
    reset,
    dispose() {
      disposed = true
      reset()
    }
  }
}
