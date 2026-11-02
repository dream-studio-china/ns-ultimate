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

/** Resolve the ordering mode recorded by the customer app on the Trade order. */
export function diningModeFromOrderMetadata(record) {
  const recordMetadata = objectValue(record?.metadata) ?? objectValue(record?.__metadata)
  const nestedMetadata = objectValue(recordMetadata?.metadata)
  const metadata = { ...objectValue(record), ...recordMetadata, ...nestedMetadata }
  const relation = objectValue(metadata.diningMode)
  const uuid = textValue(metadata.diningModeUuid ?? metadata.dining_mode_uuid ?? relation?.uuid)
  const name = textValue(metadata.diningModeName ?? metadata.dining_mode_name ?? relation?.name)

  return uuid || name ? { uuid, name } : null
}
