import { getToken } from '@/utils/auth'

/** Keep the admin token inside the host adapter; the agent only receives lifecycle notifications. */
export function monitorAdminSession(onLogin, onLogout) {
  let previousToken = ''
  let disposeAgent = null
  let disposed = false

  const check = () => {
    if (disposed) return
    let token = ''
    try { token = getToken() || '' } catch { token = '' }
    if (token === previousToken) return
    previousToken = token
    if (disposeAgent) {
      disposeAgent()
      disposeAgent = null
    }
    if (token) {
      Promise.resolve(onLogin()).then(dispose => {
        if (disposed || token !== previousToken) {
          if (typeof dispose === 'function') dispose()
          return
        }
        if (typeof dispose === 'function') disposeAgent = dispose
      }).catch(() => {})
    } else {
      onLogout()
    }
  }

  check()
  const timer = window.setInterval(check, 800)
  return () => {
    disposed = true
    window.clearInterval(timer)
    if (disposeAgent) disposeAgent()
    disposeAgent = null
    previousToken = ''
  }
}
