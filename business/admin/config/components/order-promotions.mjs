/** Maximum promotion tags rendered inline before the "+N" summary. */
export const PROMOTION_LIST_LIMIT = 2

const PHASES = ['inner', 'outer', 'best_price']

const TYPE_LABELS = {
  discount: 'Discount',
  full_reduction: 'Full reduction',
  gift: 'Gift',
  nth_discount: 'Nth item discount',
  tiered: 'Tiered discount',
  free_shipping: 'Free shipping',
  member_discount: 'Member discount'
}

function objectValue(value) {
  if (value && typeof value === 'object' && !Array.isArray(value)) return value
  if (typeof value !== 'string') return null

  try {
    const parsed = JSON.parse(value)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null
  } catch {
    return null
  }
}

function textValue(value) {
  return typeof value === 'string' && value.trim() ? value.trim() : null
}

function integerValue(value) {
  return typeof value === 'number' && Number.isInteger(value) ? value : null
}

function normalizeEntry(value) {
  const entry = objectValue(value)
  if (!entry) return null

  const id = integerValue(entry.id ?? entry.promotionId)
  const name = textValue(entry.name ?? entry.promotionName) ?? textValue(entry.template ?? entry.templateName)
  if (id === null && !name) return null

  return {
    id,
    name: name || `#${id}`,
    type: textValue(entry.type),
    phase: PHASES.includes(entry.phase) ? entry.phase : 'unknown'
  }
}

/**
 * Resolve the server-recorded promotion snapshot from a Trade order record.
 *
 * Returns null when the order predates the snapshot, so the admin can
 * distinguish "not recorded" from "recorded without promotions".
 */
export function orderPromotionsFromMetadata(record) {
  const recordMetadata = objectValue(record?.metadata) ?? objectValue(record?.__metadata)
  const nestedMetadata = objectValue(recordMetadata?.metadata)
  const metadata = { ...objectValue(record), ...recordMetadata, ...nestedMetadata }
  const snapshot = objectValue(metadata._promotion)
  if (!snapshot) return null

  const applied = (Array.isArray(snapshot.applied) ? snapshot.applied : [])
    .map(normalizeEntry)
    .filter(Boolean)

  return {
    applied,
    recordedAt: textValue(snapshot.recordedAt)
  }
}

/** Translation key for a stored promotion phase. */
export function promotionPhaseLabel(phase) {
  if (phase === 'inner') return 'Inner promotion phase'
  if (phase === 'outer') return 'Outer promotion phase'
  if (phase === 'best_price') return 'Best available promotion'
  return 'Promotion'
}

/** Translation key for a stored promotion template type. */
export function promotionTypeLabel(type) {
  return TYPE_LABELS[type] || type || ''
}

export function formatPromotionRecordedAt(value) {
  if (!value) return ''
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
}
