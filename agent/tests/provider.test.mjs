import assert from 'node:assert/strict'
import test from 'node:test'
import { askProvider } from '../src/provider.js'

test('sends only a provider credential and omits application cookies', async() => {
  let request
  const answer = await askProvider({
    baseUrl: 'https://provider.example/v1',
    model: 'test-model',
    apiKey: 'provider-secret',
    messages: [{ role: 'user', content: 'hello' }],
    fetchImpl: async(url, options) => {
      request = { url, options }
      return { ok: true, json: async() => ({ choices: [{ message: { content: 'hello back' } }] }) }
    }
  })

  assert.equal(answer, 'hello back')
  assert.equal(request.url, 'https://provider.example/v1/chat/completions')
  assert.equal(request.options.credentials, 'omit')
  assert.equal(request.options.redirect, 'error')
  assert.equal(request.options.headers.Authorization, 'Bearer provider-secret')
  assert.equal('Cookie' in request.options.headers, false)
  assert.equal('credentials' in request.options, true)
})

test('rejects empty keys and invalid provider responses', async() => {
  await assert.rejects(() => askProvider({ baseUrl: 'https://provider.example/v1', model: 'm', apiKey: '', messages: [] }), /API key/)
  await assert.rejects(() => askProvider({
    baseUrl: 'https://provider.example/v1', model: 'm', apiKey: 'secret', messages: [],
    fetchImpl: async() => ({ ok: true, json: async() => ({ choices: [] }) })
  }), /no text response/)
})
