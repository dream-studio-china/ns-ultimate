export function conditionValueType(value) {
  if (value === null) return 'null'
  if (typeof value === 'boolean') return 'boolean'
  if (Number.isInteger(value)) return 'integer'
  return 'string'
}

export function initialConditionValue(type) {
  switch (type) {
    case 'null': return null
    case 'boolean': return false
    case 'integer': return 0
    default: return ''
  }
}

export function changeConditionOperator(condition, operator) {
  const next = { ...condition, operator }
  if (operator === 'exists' || operator === 'not_exists') {
    delete next.value
    delete next.valueKind
  }
  else if (!Object.prototype.hasOwnProperty.call(next, 'value')) next.value = ''
  return next
}

export function withConditionValueType(condition, type) {
  const next = { ...condition, value: initialConditionValue(type) }
  if (type === 'null') next.valueKind = 'null'
  else delete next.valueKind
  return next
}
