import assert from 'node:assert/strict'
import test from 'node:test'
import { DEFAULT_PREFS, PREFS_KEY, readPreferences, savePreferences } from '../src/preferences.js'

function memoryStorage(initial = {}) {
  const values = new Map(Object.entries(initial))
  return {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    values
  }
}

test('persists provider preferences, the public vision flag, and API keys across reloads', () => {
  const storage = memoryStorage()
  const configured = { ...DEFAULT_PREFS, baseUrl: 'http://localhost:11434/v1', model: 'llama3', apiKey: 'local-secret', visionEnabled: true }
  assert.equal(savePreferences(configured, storage), true)
  assert.deepEqual(readPreferences(storage), configured)

  assert.equal(savePreferences({ ...configured, apiKey: '' }, storage), true)
  assert.deepEqual(readPreferences(storage), { ...configured, apiKey: '' })
  assert.equal(JSON.parse(storage.getItem(PREFS_KEY)).apiKey, '')
  assert.equal(JSON.parse(storage.getItem(PREFS_KEY)).visionEnabled, true)
})

test('uses defaults for malformed or oversized persisted settings and handles unavailable storage', () => {
  const storage = memoryStorage({ [PREFS_KEY]: JSON.stringify({ baseUrl: 'x'.repeat(2001), model: 4, apiKey: 'y'.repeat(8193), visionEnabled: 'true' }) })
  assert.deepEqual(readPreferences(storage), DEFAULT_PREFS)
  assert.deepEqual(readPreferences({ getItem() { throw new Error('blocked') } }), DEFAULT_PREFS)
  assert.equal(savePreferences(DEFAULT_PREFS, { setItem() { throw new Error('blocked') } }), false)
})

test('persists provider selection and migrates existing endpoints to a preset or Custom', () => {
  const storage = memoryStorage()
  const configured = { ...DEFAULT_PREFS, provider: 'ollama', baseUrl: 'http://localhost:11434/v1', model: 'qwen3.5' }
  assert.equal(savePreferences(configured, storage), true)
  assert.deepEqual(readPreferences(storage), configured)

  storage.setItem(PREFS_KEY, JSON.stringify({ baseUrl: 'https://api.deepseek.com/v1', model: 'deepseek-chat' }))
  assert.equal(readPreferences(storage).provider, 'deepseek')
  storage.setItem(PREFS_KEY, JSON.stringify({ baseUrl: 'https://gateway.example/v1', model: 'model-x' }))
  assert.equal(readPreferences(storage).provider, 'custom')
})
