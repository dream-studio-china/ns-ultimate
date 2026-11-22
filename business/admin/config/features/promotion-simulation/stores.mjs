export function storeOptions(stores) {
  return stores.filter(store => typeof store.code === 'string' && store.code.trim()).map(store => ({
    code: store.code,
    name: store.name || store.__toString || store.code,
    id: String(store.id ?? ''),
    uuid: String(store.uuid ?? ''),
    search: [store.name, store.__toString, store.code, store.id, store.uuid].filter(value => value != null).join(' ').toLowerCase()
  }))
}

export function filterStores(options, query) {
  const terms = String(query || '').trim().toLowerCase().split(/\s+/).filter(Boolean)
  return options.filter(option => terms.every(term => option.search.includes(term)))
}
