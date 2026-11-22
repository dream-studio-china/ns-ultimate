import {
  categoryStoreUuid,
  categoryBelongsToProductScope
} from './product-category-scope.mjs'

let filterState = {
  storeUuid: '',
  data: []
}

export function configureProductFilterState(state) {
  filterState = state
}

export function productFilterState() {
  return filterState
}

export function normalizeStoreUuid(value) {
  return typeof value === 'string' ? value.trim() : ''
}

export function productCategoryFilterOptions(categories, storeUuid) {
  const targetStoreUuid = normalizeStoreUuid(storeUuid)
  if (!targetStoreUuid) return []

  return (Array.isArray(categories) ? categories : [])
    .filter(category => category?.id !== null && category?.id !== undefined)
    .filter(category => categoryBelongsToProductScope(category, targetStoreUuid))
    .map(category => {
      const name = category.name || category.__toString || String(category.id)
      return {
        value: String(category.id),
        label: name,
        name,
        isGlobal: !categoryStoreUuid(category)
      }
    })
    .sort((left, right) => left.name.localeCompare(right.name))
}
