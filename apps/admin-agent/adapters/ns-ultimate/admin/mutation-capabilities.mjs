// ns-ultimate host allowlist. The generic agent transport never infers capabilities from entity names.
export const contentAgentCapabilities = {
  entity: 'Content',
  actions: ['create', 'update', 'delete'],
  fields: {
    title: 'Non-empty string, at most 255 characters; required on create.',
    body: 'String or null, at most 20,000 UTF-8 bytes. Plain text preview; HTML is not rendered.',
    category: 'Existing category numeric ID or null. Never invent an ID.',
    tags: 'Array of existing tag numeric IDs, at most 50. Never invent IDs.',
    metadata: 'Object with optional featured (boolean) and layout (article or landing), or null. Other keys are rejected.',
    sortOrder: 'Signed 32-bit integer.'
  }
}

export const counterAgentCapabilities = {
  entity: 'Counter',
  actions: ['update'],
  fields: {
    name: 'Non-empty string, at most 120 characters.'
  },
  validateChanges(changes) {
    return Object.keys(changes).length === 1
      && typeof changes.name === 'string'
      && changes.name.trim().length > 0
      && [...changes.name.trim()].length <= 120
  },
  validatePreview(preview) {
    return preview.action === 'update'
      && Array.isArray(preview.differences)
      && preview.differences.every(item => item && item.property === 'name'
        && typeof item.after === 'string' && item.after.trim().length > 0
        && [...item.after.trim()].length <= 120)
  }
}

export default [contentAgentCapabilities, counterAgentCapabilities]
