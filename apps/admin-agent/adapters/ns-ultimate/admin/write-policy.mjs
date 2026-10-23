/** Agent-specific exceptions to the effective admin configuration boundary. */
export const AGENT_DELETE_ALLOWLIST = Object.freeze([
  'Category',
  'ProductCategory',
  'Tag',
  'Comment'
])

export function isAgentActionAllowed(entity, action) {
  if (/^(?:.*Wallet.*|PaymentDeduction|Permission|Role|RoleFieldGrant|Assignment|UserRole|UserPermission|Setting|SystemSetting|PaymentSetting|PrinterAccount|PrinterJob|WechatApplication|WechatMerchant)$/i.test(entity)) return false
  // The ordinary device POST registers with a provider, unlike a database-only form save.
  if (entity === 'PrinterDevice' && action === 'create') return false
  if (['User', 'Profile', 'WechatUser'].includes(entity) && action !== 'create') return false
  if (action !== 'delete') return true
  return AGENT_DELETE_ALLOWLIST.includes(entity)
}

/** Exceptions only: ordinary fields are supplied by the effective form. */
export function isAgentFieldAllowed(entity, property) {
  if (/(?:password|secret|token|credential|privateKey|apiKey)/i.test(property)
    && !(entity === 'User' && property === 'plainPassword')) return false
  return !(entity === 'User' && ['roles', 'permissions', 'permission', 'groups'].includes(property))
}

export const agentMutationPolicy = Object.freeze({
  isActionAllowed: isAgentActionAllowed,
  isFieldAllowed: isAgentFieldAllowed
})
