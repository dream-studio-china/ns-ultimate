import { normalizeProvider } from './provider-config.js'

export const PREFS_KEY = 'ns-ultimate-agent.preferences.v1'

export const DEFAULT_PREFS = {
  provider: 'deepseek',
  baseUrl: 'https://api.deepseek.com/v1',
  model: 'deepseek-flash',
  docsUrl: '/api/doc.json',
  apiKey: '',
  visionEnabled: false
}

const MAX_LENGTHS = {
  provider: 32,
  baseUrl: 2000,
  model: 180,
  docsUrl: 2000,
  apiKey: 8192
}

const BOOLEAN_KEYS = new Set(['visionEnabled'])

export function readPreferences(storage) {
  try {
    storage ||= globalThis.localStorage
    const value = JSON.parse(storage.getItem(PREFS_KEY) || '{}')
    const result = Object.fromEntries(Object.entries(DEFAULT_PREFS).map(([key, fallback]) => {
      const candidate = value?.[key]
      if (BOOLEAN_KEYS.has(key)) return [key, typeof candidate === 'boolean' ? candidate : fallback]
      return [key, typeof candidate === 'string' && candidate.length <= MAX_LENGTHS[key] ? candidate : fallback]
    }))
    // Existing browser preferences predate the provider selector. Infer the
    // matching preset when possible, otherwise preserve the endpoint as Custom.
    if (typeof value?.provider !== 'string') result.provider = normalizeProvider(undefined, result.baseUrl)
    else result.provider = normalizeProvider(value.provider, result.baseUrl)
    return result
  } catch {
    return { ...DEFAULT_PREFS }
  }
}

export function savePreferences(preferences, storage) {
  const value = Object.fromEntries(Object.entries(DEFAULT_PREFS).map(([key, fallback]) => {
    const candidate = preferences?.[key]
    if (BOOLEAN_KEYS.has(key)) return [key, typeof candidate === 'boolean' ? candidate : fallback]
    return [key, typeof candidate === 'string' && candidate.length <= MAX_LENGTHS[key] ? candidate : fallback]
  }))
  value.provider = normalizeProvider(value.provider, value.baseUrl)
  try {
    storage ||= globalThis.localStorage
    storage.setItem(PREFS_KEY, JSON.stringify(value))
    return true
  } catch {
    return false
  }
}
