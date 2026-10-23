// Headless i18n boundary for build-time configuration loading.
// Mirrors integration/admin/i18n.js precedence with a fixed English locale and
// no localStorage, navigator, Vue reactive state, or DOM access.
import coreMessages from '../../../core/crud-admin/src/i18n/en.js'
import businessMessages from '../../../business/admin/i18n/en.js'

const messages = { ...coreMessages, ...businessMessages }

export function t(key, ...args) {
  const value = messages[key]
  if (typeof value !== 'string') return String(key)
  if (args.length === 0) return value
  return value.replace(/\{(\d+)\}/g, (_, index) => String(args[index] ?? ''))
}

export function setLocale() {}

export function getLocale() {
  return 'en'
}

export default {
  install() {}
}
