/** Agent deletion exceptions only; ordinary editable fields remain config-driven. */
const references = Object.freeze({
  Category: [['Category', 'parent'], ['Content', 'category'], ['Picture', 'category'], ['Media', 'category']],
  ProductCategory: [['ProductCategory', 'parent'], ['Product', 'category']],
  Comment: [['Comment', 'parent']]
})

export function createDeleteGuard({ request, getProjection }) {
  return async({ entity, record, signal }) => {
    const checks = references[entity]
    if (!checks) return
    if (!/^[1-9]\d{0,14}$/.test(String(record.id))) throw new TypeError('A stored numeric target ID is required for deletion reference checks.')
    for (const [name, property] of checks) {
      const config = getProjection().entities.find(item => item.name === name)
      if (!config?.path || !config.route) throw new TypeError(`Cannot verify ${name} references. Use the standard admin page.`)
      const response = await request.get(config.path, {
        params: { page: 1, limit: 1, '@filter': `entity.get${property[0].toUpperCase()}${property.slice(1)}().getId() == ${record.id}` },
        signal, timeout: 60000
      })
      if (!Array.isArray(response?.data)) throw new TypeError(`Cannot verify ${name} references. Use the standard admin page.`)
      if (response.data.length) throw new TypeError(`${entity} is referenced by ${name} and cannot be deleted by the assistant.`)
    }
  }
}
