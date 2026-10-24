import assert from 'node:assert/strict'
import test from 'node:test'
import { normalizeProvider, PROVIDER_PRESETS, providerUsesApiKey } from '../src/provider-config.js'

test('provides DeepSeek, OpenCode Go, Ollama and Custom presets', () => {
  assert.deepEqual(Object.keys(PROVIDER_PRESETS), ['deepseek', 'opencodeGo', 'ollama', 'custom'])
  assert.equal(PROVIDER_PRESETS.deepseek.baseUrl, 'https://api.deepseek.com/v1')
  assert.equal(PROVIDER_PRESETS.opencodeGo.baseUrl, 'https://opencode.ai/zen/go/v1')
  assert.equal(PROVIDER_PRESETS.opencodeGo.defaultModel, 'deepseek-v4.1-flash')
  assert.equal(PROVIDER_PRESETS.ollama.baseUrl, 'http://localhost:11434/v1')
  assert.equal(normalizeProvider(undefined, 'http://localhost:11434/v1'), 'ollama')
  assert.equal(normalizeProvider(undefined, 'https://api.deepseek.com'), 'deepseek')
  assert.equal(normalizeProvider(undefined, 'https://api.deepseek.com/v1/chat/completions'), 'deepseek')
  assert.equal(normalizeProvider(undefined, 'https://custom.example/v1'), 'custom')
  assert.equal(providerUsesApiKey('ollama'), false)
  assert.equal(providerUsesApiKey('opencodeGo'), true)
})
