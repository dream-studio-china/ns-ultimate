import zh from './locales/zh.js'
import en from './locales/en.js'

const locale = (() => {
  try { return localStorage.getItem('app_locale') || navigator.language || 'en' } catch { return 'en' }
})()
const messages = locale.toLowerCase().startsWith('zh') ? zh : en

export function translate(key, params = {}) {
  return Object.entries(params).reduce(
    (text, [name, value]) => text.replaceAll(`{${name}}`, String(value)),
    messages[key] || key
  )
}
