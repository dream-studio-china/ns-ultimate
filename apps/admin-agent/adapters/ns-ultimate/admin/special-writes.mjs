/**
 * Writes whose ordinary admin form field maps to a dedicated backend endpoint.
 *
 * The generic CRUD host must not submit these fields to the collection update
 * endpoint: the controller ignores them and still returns success.
 */
const STORE_STATUSES = new Set(['activate', 'suspend', 'close'])
const SAFE_IDENTIFIER = /^(?:[1-9]\d{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i

function safePath(path) {
  return typeof path === 'string' && path.startsWith('/api/v1/manage/')
    && !path.includes('..') && !path.includes('?') && !path.includes('#')
}

export function specialWrite({ entity, action, identifier, changes, path, record }) {
  // The admin password control submits plainPassword; the identity API accepts
  // the same plaintext as password. Applying the rename here keeps the form
  // field name in previews while sending the backend contract.
  if (entity === 'User' && action === 'create' && Object.hasOwn(changes, 'plainPassword')) {
    const { plainPassword, ...remaining } = changes
    return { method: 'post', path, changes: { ...remaining, password: plainPassword } }
  }
  // The order-counter form exposes the relation as `counter`, while the create
  // controller expects the concrete `counterUuid` identifier.
  if (entity === 'OrderCounter' && action === 'create' && Object.hasOwn(changes, 'counter')) {
    const { counter, ...remaining } = changes
    return { method: 'post', path, changes: { ...remaining, counterUuid: counter } }
  }
  if (entity !== 'Store') return null
  if (!Object.hasOwn(changes, 'status')) return null
  if (!STORE_STATUSES.has(changes.status)) throw new TypeError('Invalid Store status.')
  const { status, ...remaining } = changes
  if (action === 'create') {
    // Store creation always starts in the default "activate" state; the create
    // controller does not accept status. A non-default status is a second
    // operation and must not be reported as part of one creation.
    if (status !== 'activate') throw new TypeError('Create the store first, then change its status separately.')
    return { method: 'post', path, changes: remaining }
  }
  if (action !== 'update') return null
  if (Object.keys(remaining).length) throw new TypeError('Change Store status separately from other fields.')
  // The status route is keyed by UUID even though the generic detail route also
  // accepts a numeric ID.
  const target = record?.uuid
  if (!SAFE_IDENTIFIER.test(String(target)) || !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(String(target))
    || !safePath(path)) throw new TypeError('The Store status target is invalid.')
  return { method: 'post', path: `${path}/${encodeURIComponent(target)}/status/${status}`, changes: {} }
}

export default specialWrite
