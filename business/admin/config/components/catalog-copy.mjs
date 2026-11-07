const COPY_PAGE_SIZE = 100
const COPY_MAX_PAGES = 1000

export function listItems(response) {
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response)) return response
  return []
}

export async function fetchAllPages(get, path, params = {}, pageSize = COPY_PAGE_SIZE) {
  const items = []
  let page = 1
  let hasNext = true

  while (hasNext) {
    if (page > COPY_MAX_PAGES) throw new Error('catalog_copy_too_large')
    const response = await get(path, { params: { ...params, page, limit: pageSize } })
    const batch = listItems(response)
    items.push(...batch)
    hasNext = Boolean(response?.paginator?.has_next) && batch.length > 0
    page += 1
  }

  return items
}

export function referenceUuid(value) {
  if (value && typeof value === 'object' && typeof value.uuid === 'string') return value.uuid
  return typeof value === 'string' ? value : ''
}

export function referenceKeys(value) {
  if (value === null || value === undefined || value === '') return []
  if (typeof value === 'object') {
    const keys = []
    if (value.uuid !== null && value.uuid !== undefined && value.uuid !== '') keys.push(String(value.uuid))
    if (value.id !== null && value.id !== undefined && value.id !== '') keys.push(String(value.id))
    return keys
  }
  return [String(value)]
}

export function categoryStoreUuid(category) {
  return referenceUuid(category?.store)
}

export function productStoreUuid(product) {
  return referenceUuid(product?.store)
}

export function categoryParentReference(category) {
  return category?.parent ?? null
}

function categoryIndex(categories) {
  const index = new Map()
  for (const category of categories) {
    for (const key of referenceKeys(category)) index.set(key, category)
  }
  return index
}

function compareCategories(left, right) {
  return Number(left?.sortOrder ?? 0) - Number(right?.sortOrder ?? 0)
    || String(left?.name ?? '').localeCompare(String(right?.name ?? ''))
}

export function orderedSourceCategories(categories) {
  const source = [...categories].sort(compareCategories)
  const index = categoryIndex(source)
  const state = new Map()
  const ordered = []

  const visit = (category, ancestry = new Set()) => {
    const identity = referenceKeys(category)[0]
    if (!identity) throw new Error('catalog_category_identity_missing')
    if (state.get(identity) === 2) return
    if (state.get(identity) === 1 || ancestry.has(identity)) throw new Error('catalog_category_cycle')

    const nextAncestry = new Set(ancestry)
    nextAncestry.add(identity)
    state.set(identity, 1)
    const parent = referenceKeys(categoryParentReference(category)).map(key => index.get(key)).find(Boolean)
    if (parent) visit(parent, nextAncestry)
    state.set(identity, 2)
    ordered.push(category)
  }

  for (const category of source) visit(category)
  return ordered
}

export function targetCategoriesBySlug(categories) {
  return new Map(categories
    .filter(category => typeof category?.slug === 'string' && category.slug !== '')
    .map(category => [category.slug, category]))
}

export function rememberCategoryMapping(mapping, sourceCategory, targetCategory) {
  for (const key of referenceKeys(sourceCategory)) mapping.set(key, targetCategory)
  return mapping
}

export function mappedCategory(mapping, value) {
  for (const key of referenceKeys(value)) {
    const category = mapping.get(key)
    if (category) return category
  }
  return null
}

export function findCategoryByReference(categories, value) {
  const keys = new Set(referenceKeys(value))
  if (keys.size === 0) return null
  return categories.find(category => referenceKeys(category).some(key => keys.has(key))) ?? null
}

export function categoryCopyPayload(category, targetStoreUuid, parentUuid = null) {
  return {
    name: String(category?.name ?? '').trim(),
    slug: String(category?.slug ?? '').trim(),
    description: category?.description ?? null,
    parent: parentUuid || null,
    sortOrder: integerValue(category?.sortOrder),
    enabled: category?.enabled !== false,
    store: targetStoreUuid
  }
}

export function productCopyPayload(product, targetStoreUuid, categoryUuid) {
  return {
    name: String(product?.name ?? '').trim(),
    description: product?.description ?? null,
    status: product?.status === 'inactive' ? 'inactive' : 'active',
    metadata: cloneJson(product?.metadata),
    settings: cloneJson(product?.settings),
    store: targetStoreUuid,
    category: categoryUuid || null,
    sortOrder: integerValue(product?.sortOrder),
    recommendationLevel: integerValue(product?.recommendationLevel),
    isSoldout: product?.isSoldout === true || product?.is_soldout === true
  }
}

export function specificationCopyPayload(specification) {
  return {
    name: String(specification?.name ?? '').trim(),
    price: integerValue(specification?.price),
    status: specification?.status === 'inactive' ? 'inactive' : 'active',
    sort: integerValue(specification?.sort)
  }
}

export function productNameKey(product) {
  return String(product?.name ?? '').trim().toLocaleLowerCase()
}

function integerValue(value) {
  const number = Number(value)
  return Number.isFinite(number) ? Math.trunc(number) : 0
}

function cloneJson(value) {
  if (value === null || value === undefined) return null
  return JSON.parse(JSON.stringify(value))
}
