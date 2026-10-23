/** Agent-specific exceptions to the effective admin configuration boundary. */
export const AGENT_DELETE_ALLOWLIST = Object.freeze([
  'Category',
  'ProductCategory',
  'Tag',
  'Comment'
])

export function isAgentActionAllowed(entity, action) {
  if (action !== 'delete') return true
  return AGENT_DELETE_ALLOWLIST.includes(entity)
}
