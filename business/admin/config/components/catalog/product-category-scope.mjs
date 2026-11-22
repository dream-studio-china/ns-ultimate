import { referenceUuid } from './catalog-copy.mjs'

export function categoryStoreUuid(category) {
  return referenceUuid(category?.store)
}

export function categoryBelongsToProductScope(category, storeUuid) {
  const categoryStore = categoryStoreUuid(category)
  if (!categoryStore) return true
  const targetStore = referenceUuid(storeUuid)
  return Boolean(targetStore) && categoryStore.toLowerCase() === targetStore.toLowerCase()
}

export function productCategoryOptions(categories, storeUuid) {
  return (Array.isArray(categories) ? categories : [])
    .filter(category => category?.uuid && categoryBelongsToProductScope(category, storeUuid))
    .map(category => {
      const name = category.name || category.__toString || category.uuid
      const isGlobal = !categoryStoreUuid(category)
      return {
        value: category.uuid,
        label: name,
        name,
        isGlobal
      }
    })
    .sort((left, right) => left.name.localeCompare(right.name))
}
