export const PREFS_KEY = 'ns-ultimate-agent.preferences.v1'

export const DEFAULT_PREFS = {
  baseUrl: 'https://api.openai.com/v1',
  model: '',
  docsUrl: '/api/doc.json',
  apiKey: ''
}

const MAX_LENGTHS = {
  baseUrl: 2000,
  model: 180,
  docsUrl: 2000,
  apiKey: 8192
}

export function readPreferences(storage) {
  try {
    storage ||= globalThis.localStorage
    const value = JSON.parse(storage.getItem(PREFS_KEY) || '{}')
    return Object.fromEntries(Object.entries(DEFAULT_PREFS).map(([key, fallback]) => {
      const candidate = value?.[key]
      return [key, typeof candidate === 'string' && candidate.length <= MAX_LENGTHS[key] ? candidate : fallback]
    }))
  } catch {
    return { ...DEFAULT_PREFS }
  }
}

export function savePreferences(preferences, storage) {
  const value = Object.fromEntries(Object.entries(DEFAULT_PREFS).map(([key, fallback]) => {
    const candidate = preferences?.[key]
    return [key, typeof candidate === 'string' && candidate.length <= MAX_LENGTHS[key] ? candidate : fallback]
  }))
  try {
    storage ||= globalThis.localStorage
    storage.setItem(PREFS_KEY, JSON.stringify(value))
    return true
  } catch {
    return false
  }
}
