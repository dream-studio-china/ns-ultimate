export const PRODUCT_SETTING_KEYS = Object.freeze([
  'kitchenPrinterEnable',
  'kitchenTicketDisabled'
])

export function normalizeRecommendationLevel(value) {
  const level = Number(value)
  return Number.isInteger(level) ? Math.min(5, Math.max(0, level)) : 0
}

export function nextRecommendationLevel(current, clicked) {
  const normalizedCurrent = normalizeRecommendationLevel(current)
  const normalizedClicked = normalizeRecommendationLevel(clicked)
  return normalizedClicked === normalizedCurrent ? 0 : normalizedClicked
}

export function productSettingUpdate(record, key, value) {
  if (!PRODUCT_SETTING_KEYS.includes(key)) throw new Error(`Unsupported product list setting: ${key}`)
  if (typeof value !== 'boolean') throw new Error('Product list settings must be boolean values.')

  const current = record?.settings
  return {
    settings: {
      ...(current && typeof current === 'object' && !Array.isArray(current) ? current : {}),
      [key]: value
    }
  }
}

export function productSoldoutUpdate(value) {
  if (typeof value !== 'boolean') throw new Error('Sold-out state must be a boolean value.')
  return { isSoldout: value }
}

export function productRecommendationUpdate(value) {
  const recommendationLevel = normalizeRecommendationLevel(value)
  if (recommendationLevel !== value) throw new Error('Recommendation level must be an integer from 0 to 5.')
  return { recommendationLevel }
}

export function productSortOrderUpdate(value) {
  if (!Number.isInteger(value) || value < 0 || value > 1000000) {
    throw new Error('Sort order must be an integer from 0 to 1000000.')
  }
  return { sortOrder: value }
}

export function productSpecificationRows(record) {
  const specifications = Array.isArray(record?.specifications) ? record.specifications : []
  return specifications
    .filter(specification => specification && specification.isDeleted !== true)
    .map(specification => {
      const metadata = specification.__metadata && typeof specification.__metadata === 'object'
        ? specification.__metadata
        : specification
      return {
        id: specification.id ?? metadata.id ?? null,
        name: String(metadata.name ?? specification.name ?? specification.__toString ?? '').trim(),
        price: Number(metadata.price ?? specification.price),
        sort: Number(metadata.sort ?? specification.sort ?? 0),
        active: metadata.active !== false && metadata.status !== 'inactive'
      }
    })
    .filter(specification => specification.name && Number.isFinite(specification.price) && specification.price >= 0)
    .sort((left, right) => left.sort - right.sort || Number(left.id || 0) - Number(right.id || 0))
}

export function formatProductSpecificationPrice(cents) {
  const amount = Number(cents)
  if (!Number.isFinite(amount) || amount < 0) return ''
  return `¥${(amount / 100).toFixed(2)}`
}
