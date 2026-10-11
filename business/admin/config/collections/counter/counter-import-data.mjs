export const projectCounterRecords = source => {
  if (!Array.isArray(source) || source.length === 0) {
    throw new Error('Expected a non-empty JSON array')
  }

  const seenNames = new Set()
  return source.map((item, index) => {
    const name = typeof item?.name === 'string' ? item.name.trim() : ''
    const sort = item?.listOrder
    const nameKey = name.toLocaleLowerCase()
    const duplicateName = Boolean(nameKey && seenNames.has(nameKey))
    if (nameKey) seenNames.add(nameKey)

    const validName = Boolean(name) && name.length <= 120
    const validSort = Number.isInteger(sort) && sort >= 0

    return {
      key: `${index}-${name || 'counter'}`,
      name,
      sort: validSort ? sort : null,
      valid: validName && validSort && !duplicateName,
      duplicateName,
      issue: !validName ? 'invalid-name' : !validSort ? 'invalid-sort' : duplicateName ? 'duplicate-source-name' : ''
    }
  })
}

export const nextCounterCode = usedCodes => {
  const normalizedCodes = new Set([...usedCodes].map(code => String(code).trim().toLocaleUpperCase()))
  let sequence = 1
  while (normalizedCodes.has(`T${String(sequence).padStart(3, '0')}`)) sequence++
  return `T${String(sequence).padStart(3, '0')}`
}
