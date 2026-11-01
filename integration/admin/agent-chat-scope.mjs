/** Local partition hint only, never authentication or authorization. */
export function chatScopeFromToken(token) {
  try {
    if (typeof token !== 'string' || token.length > 20000) return null
    const part = token.split('.')[1]
    if (!part || !/^[A-Za-z0-9_-]+$/.test(part)) return null
    const payload = JSON.parse(atob(part.replace(/-/g, '+').replace(/_/g, '/')))
    return typeof payload.sub === 'string' && /^[1-9]\d{0,19}$/.test(payload.sub) ? `account:${payload.sub}` : null
  } catch { return null }
}

/**
 * Stable browser-session identity for lifecycle decisions. A rotated access
 * token keeps the same account identity, so the assistant must not be torn
 * down and rebuilt; an opaque token falls back to the credential itself.
 * Never authentication or authorization.
 */
export function adminSessionIdentity(token) {
  if (typeof token !== 'string' || !token) return ''
  return chatScopeFromToken(token) || `token:${token}`
}
