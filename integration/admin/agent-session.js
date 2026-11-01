import { getToken } from '@/utils/auth'
import { adminSessionIdentity } from './agent-chat-scope.mjs'

/** Keep the admin token inside the host adapter; the agent only receives lifecycle notifications. */
export function monitorAdminSession(onLogin, onLogout) {
  let previousIdentity = ''
  let disposeAgent = null
  let disposed = false
  let generation = 0
  let mountAttempts = 0
  let retryAt = Infinity

  const readIdentity = () => {
    try { return adminSessionIdentity(getToken() || '') } catch { return '' }
  }

  const check = () => {
    if (disposed) return
    const identity = readIdentity()
    // Token rotation keeps the same account identity. Rebuilding the assistant
    // would discard its panel, conversation and pending previews, so only an
    // account change or logout may replace the mounted instance.
    if (identity !== previousIdentity) {
      previousIdentity = identity
      generation++
      mountAttempts = 0
      retryAt = 0
      if (disposeAgent) {
        disposeAgent()
        disposeAgent = null
      }
      if (!identity) onLogout()
    }
    if (!identity || mountAttempts >= 3 || Date.now() < retryAt) return
    const attemptGeneration = generation
    mountAttempts++
    // Pending and successful mounts must not be duplicated by the poller.
    retryAt = Infinity
    Promise.resolve().then(() => {
      if (disposed || attemptGeneration !== generation) return
      return onLogin()
    }).then(dispose => {
      if (disposed || attemptGeneration !== generation) {
        if (typeof dispose === 'function') dispose()
        return
      }
      if (typeof dispose === 'function') disposeAgent = dispose
    }).catch(error => {
      if (disposed || attemptGeneration !== generation) return
      // Retry transient failures twice with backoff, including synchronous
      // callback errors. A new account session resets the attempt budget.
      retryAt = Date.now() + 800 * (2 ** (mountAttempts - 1))
      console.warn('[admin agent] Failed to initialize.', error)
    })
  }

  check()
  const timer = window.setInterval(check, 800)
  return () => {
    disposed = true
    window.clearInterval(timer)
    if (disposeAgent) disposeAgent()
    disposeAgent = null
    previousIdentity = ''
  }
}
