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

  assert.equal(answer.content, 'hello back')
  assert.equal(request.url, 'https://provider.example/v1/chat/completions')
  assert.equal(request.options.credentials, 'omit')
  assert.equal(request.options.redirect, 'error')
  assert.equal(request.options.headers.Authorization, 'Bearer provider-secret')
  assert.equal('Cookie' in request.options.headers, false)
  assert.equal('credentials' in request.options, true)
})

test('supports optional API keys and function calls while rejecting malformed responses', async() => {
  let keylessRequest
  const keyless = await askProvider({
    baseUrl: 'http://localhost:11434/v1', model: 'llama3', apiKey: '', messages: [],
    fetchImpl: async(_url, options) => {
      keylessRequest = options
      return { ok: true, json: async() => ({ choices: [{ message: { content: 'local response' } }] }) }
    }
  })
  assert.equal(keyless.content, 'local response')
  assert.equal('Authorization' in keylessRequest.headers, false)

  const toolResponse = await askProvider({
    baseUrl: 'https://provider.example/v1', model: 'm', apiKey: 'secret', messages: [],
    tools: [{ type: 'function', function: { name: 'list_entities', parameters: { type: 'object' } } }],
    fetchImpl: async(_url, options) => {
      assert.equal(JSON.parse(options.body).tool_choice, 'auto')
      return { ok: true, json: async() => ({ choices: [{ message: { tool_calls: [{ id: 'call-1', type: 'function', function: { name: 'list_entities', arguments: '{}' } }] } }] }) }
    }
  })
  assert.equal(toolResponse.toolCalls[0].function.name, 'list_entities')
  assert.equal(toolResponse.message.role, 'assistant')
  await assert.rejects(() => askProvider({
    baseUrl: 'https://provider.example/v1', model: 'm', apiKey: 'secret', messages: [],
    fetchImpl: async() => ({ ok: true, json: async() => ({ choices: [] }) })
  }), /no text response/)
})
