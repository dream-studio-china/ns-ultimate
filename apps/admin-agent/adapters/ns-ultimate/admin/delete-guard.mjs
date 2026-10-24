/** Agent deletion exceptions only; ordinary editable fields remain config-driven. */
const references = Object.freeze({
  Category: [['Category', 'parent'], ['Content', 'category'], ['Picture', 'category'], ['Media', 'category']],
  ProductCategory: [['ProductCategory', 'parent'], ['Product', 'category']],
  Comment: [['Comment', 'parent']]
})

// A many-to-many collection has no simple getter comparison. The Content list
// includes its tags, so scan bounded pages and fail closed if the whole list
// cannot be checked.
const COLLECTION_SCAN_LIMIT = 100
const COLLECTION_MAX_PAGES = 200
const collectionReferences = Object.freeze({
  Tag: 'Content'
})

export function createDeleteGuard({ request, getProjection }) {
  return async({ entity, record, signal }) => {
    const checks = references[entity] || []
    const collection = collectionReferences[entity]
    if (!checks.length && !collection) return
    if (!/^[1-9]\d{0,14}$/.test(String(record.id))) throw new TypeError('A stored numeric target ID is required for deletion reference checks.')
    const configFor = name => {
      const config = getProjection().entities.find(item => item.name === name)
      if (!config?.path || !config.route) throw new TypeError(`Cannot verify ${name} references. Use the standard admin page.`)
      return config
    }
    for (const [name, property] of checks) {
      const config = configFor(name)
      const response = await request.get(config.path, {
        params: { page: 1, limit: 1, '@filter': `entity.get${property[0].toUpperCase()}${property.slice(1)}().getId() == ${record.id}` },
        signal, timeout: 60000
      })
      if (!Array.isArray(response?.data)) throw new TypeError(`Cannot verify ${name} references. Use the standard admin page.`)
      if (response.data.length) throw new TypeError(`${entity} is referenced by ${name} and cannot be deleted by the assistant.`)
    }
    if (collection) {
      const config = configFor(collection)
      for (let page = 1; page <= COLLECTION_MAX_PAGES; page += 1) {
        const response = await request.get(config.path, {
          params: { page, limit: COLLECTION_SCAN_LIMIT, '@order': 'entity.id|ASC' },
          signal, timeout: 60000
        })
        if (!Array.isArray(response?.data)) throw new TypeError(`Cannot verify ${collection} references. Use the standard admin page.`)
        if (response.data.some(item => Array.isArray(item?.tags)
          && item.tags.some(tag => String(tag?.id) === String(record.id)))) {
          throw new TypeError(`${entity} is referenced by ${collection} and cannot be deleted by the assistant.`)
        }
        const paginator = response.paginator
        if (paginator?.has_next === false || (!paginator && response.data.length < COLLECTION_SCAN_LIMIT)) return
      }
      throw new TypeError(`Cannot verify all ${collection} references. Use the standard admin page.`)
    }
  }
}
