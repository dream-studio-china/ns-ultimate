import coreI18n, { t as coreTranslate, getLocale as getCoreLocale, setLocale as setCoreLocale } from '../../core/crud-admin/src/i18n'
import en from '../../business/admin/i18n/en'
import zh from '../../business/admin/i18n/zh'
import zhHant from '../../business/admin/i18n/zh-Hant'
import ja from '../../business/admin/i18n/ja'

const businessMessages = { en, zh, 'zh-Hant': zhHant, ja }

export function t(key, ...args) {
  const value = businessMessages[getCoreLocale()]?.[key]
  if (typeof value !== 'string') {
    return coreTranslate(key, ...args)
  }

  if (args.length === 0) return value
  const named = args[0] !== null && typeof args[0] === 'object' && !Array.isArray(args[0]) ? args[0] : null
  return value.replace(/\{(\d+|[A-Za-z_][A-Za-z0-9_]*)\}/g, (placeholder, name) => {
    if (/^\d+$/.test(name)) return String(args[name] ?? '')
    return named && Object.hasOwn(named, name) && named[name] != null ? String(named[name]) : placeholder
  })
}

export function setLocale(locale) {
  setCoreLocale(locale)
}

export function getLocale() {
  return getCoreLocale()
}

export default {
  install(app) {
    coreI18n.install(app)
    app.config.globalProperties.$t = t
  }
}
