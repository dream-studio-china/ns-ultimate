function expandedRelation(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {}
  const metadata = value.__metadata
  return metadata && typeof metadata === 'object' && !Array.isArray(metadata)
    ? { ...value, ...metadata }
    : value
}

function snapshot(value) {
  if (value && typeof value === 'object' && !Array.isArray(value)) return value
  if (typeof value !== 'string') return null
  try {
    const parsed = JSON.parse(value)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null
  } catch {
    return null
  }
}

/** Resolve the frozen ordering mode from the OrderCounter session snapshot. */
export function diningModeFromOrderCounterBindings(bindings, record = null) {
  if (!Array.isArray(bindings)) return diningModeFromOrderMetadata(record)
  for (const binding of bindings) {
    const session = expandedRelation(binding?.counterSession)
    const frozenMode = snapshot(session.diningModeSnapshot) ?? snapshot(session.diningMode)
    const uuid = typeof session.diningModeUuid === 'string' && session.diningModeUuid
      ? session.diningModeUuid
      : (typeof frozenMode?.uuid === 'string' ? frozenMode.uuid : null)
    const name = typeof frozenMode?.name === 'string' && frozenMode.name.trim()
      ? frozenMode.name.trim()
      : null
    if (name || uuid) return { name, uuid }
  }
  return diningModeFromOrderMetadata(record)
}

/** Resolve the mode uuid written by the customer app when no session snapshot is available. */
export function diningModeFromOrderMetadata(record) {
  const metadata = snapshot(record?.metadata) ?? snapshot(record?.__metadata) ?? expandedRelation(record)
  const rawUuid = metadata.diningModeUuid ?? metadata.dining_mode_uuid
  const uuid = typeof rawUuid === 'string' && rawUuid.trim() ? rawUuid.trim() : null
  const rawName = metadata.diningModeName ?? metadata.dining_mode_name
  const name = typeof rawName === 'string' && rawName.trim() ? rawName.trim() : null
  return uuid || name ? { name, uuid } : null
}
