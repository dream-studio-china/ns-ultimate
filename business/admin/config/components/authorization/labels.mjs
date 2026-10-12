export function permissionCode(value) {
  if (typeof value === 'string') return value
  if (!value || typeof value !== 'object') return ''
  const code = value.code ?? value.permission?.code ?? value.permissionCode ?? value.key ?? value.value
  if (typeof code === 'string' && code) return code
  return typeof value.__toString === 'string' && value.__toString.includes(':') ? value.__toString : ''
}

function translatedValue(category, value, translate) {
  if (!value || typeof translate !== 'function') return ''
  const key = `Authorization ${category}:${value}`
  const label = translate(key)
  return label === key ? '' : label
}

export function roleLabel(record, value, translate) {
  const role = value && typeof value === 'object'
    ? value
    : record?.role && typeof record.role === 'object'
      ? record.role
      : record
  const code = role?.code || record?.code || (typeof value === 'string' ? value : '')
  const localized = translatedValue('role', code, translate)
  if (localized) return localized
  if (typeof value === 'string' && value) return value
  return role?.name || record?.name || code || '-'
}

export function permissionLabel(value, translate) {
  const code = permissionCode(value)
  if (!code) return typeof value === 'string' ? value : value?.name || '-'

  const localized = translatedValue('permission', code, translate)
  if (localized) return localized

  const [, resource, action] = code.split(':')
  const actionLabel = translatedValue('action', action, translate)
  const resourceLabel = translatedValue('resource', resource, translate)
  if (actionLabel && resourceLabel) return `${actionLabel}${resourceLabel}`

  return value?.name || code
}

export function permissionDescription(value, translate) {
  const code = permissionCode(value)
  const templateKey = 'Authorization permission description'
  const template = typeof translate === 'function' ? translate(templateKey, permissionLabel(value, translate)) : templateKey
  if (template !== templateKey) return template
  return value?.description || value?.name || code || '-'
}

export function authorizationTokenLabel(category, value, translate) {
  return translatedValue(category, value, translate) || value || '-'
}
