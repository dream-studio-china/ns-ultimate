/** Agent-specific exceptions to the effective admin configuration boundary. */
export const AGENT_DELETE_ALLOWLIST = Object.freeze([
  'Category',
  'ProductCategory',
  'Tag',
  'Comment'
])

export function isAgentActionAllowed(entity, action) {
  if (/^(?:.*Wallet.*|PaymentDeduction|Permission|Role|RoleFieldGrant|Assignment|UserRole|UserPermission|Setting|SystemSetting|PaymentSetting|StoreWechatSetting|PrinterAccount|PrinterJob|WechatApplication|WechatMerchant)$/i.test(entity)) return false
  // The ordinary device POST registers with a provider, unlike a database-only form save.
  if (entity === 'PrinterDevice' && action === 'create') return false
  if (['User', 'Profile', 'WechatUser'].includes(entity) && action !== 'create') return false
  if (action !== 'delete') return true
  return AGENT_DELETE_ALLOWLIST.includes(entity)
}

/*
 * Ordinary admin forms also contain fields that the corresponding CRUD
 * controller does not accept. Sending them would produce a successful response
 * while silently discarding the value, so the assistant omits exactly those
 * fields. The effective admin form remains the primary field source.
 */
const ACTION_FIELD_DENYLIST = Object.freeze({
  Store: Object.freeze({ create: ['paymentSetting'], update: ['code', 'paymentSetting'] }),
  Material: Object.freeze({ update: ['code'] }),
  Comment: Object.freeze({ update: ['entityType', 'entityId', 'parent'] }),
  // The commerce admin form and the legacy Trade OrderItem controller expose
  // different writable sets. Only the shared price field can be verified.
  OrderItem: Object.freeze({ update: ['quantity', 'unitPrice', 'cost', 'profit', 'specSnapshot', 'productSnapshot', 'metadata'] }),
  Counter: Object.freeze({ update: ['storeUuid'] }),
  ProductCategory: Object.freeze({ update: ['store'] }),
  PrinterDevice: Object.freeze({ update: ['account', 'serialNumber', 'kind'] }),
  PrinterTemplate: Object.freeze({ update: ['kind'] }),
  // Recipe creation always starts active; only status updates are accepted.
  SpecificationRecipe: Object.freeze({ create: ['status'], update: ['specificationUuid', 'lines'] }),
  WechatUser: Object.freeze({ create: ['sessionKey', 'rawData', 'appId', 'user'] })
})

/** Exceptions only: ordinary fields are supplied by the effective form. */
export function isAgentFieldAllowed(entity, property, action) {
  if (/(?:password|secret|token|credential|privateKey|apiKey)/i.test(property)
    && !(entity === 'User' && property === 'plainPassword')) return false
  if (entity === 'User' && ['roles', 'permissions', 'permission', 'groups'].includes(property)) return false
  return !(ACTION_FIELD_DENYLIST[entity]?.[action] || []).includes(property)
}

export const agentMutationPolicy = Object.freeze({
  isActionAllowed: isAgentActionAllowed,
  isFieldAllowed: isAgentFieldAllowed
})
