import assert from 'node:assert/strict'
import test from 'node:test'
import { MAX_PROVIDER_REQUEST_BYTES, askProvider } from '../src/provider.js'
import { PNG_3X2_DATA_URL } from './image-fixtures.mjs'

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

test('preserves string messages and sends text plus image_url parts unchanged', async() => {
  const messages = [
    { role: 'user', content: 'hello' },
    { role: 'user', content: [
      { type: 'text', text: 'describe this image' },
      { type: 'image_url', image_url: { url: PNG_3X2_DATA_URL } }
    ] }
  ]
  let requestBody
  await askProvider({
    baseUrl: 'https://provider.example/v1',
    model: 'vision-model',
    apiKey: 'secret',
    messages,
    fetchImpl: async(_url, options) => {
      requestBody = JSON.parse(options.body)
      return { ok: true, json: async() => ({ choices: [{ message: { content: 'ok' } }] }) }
    }
  })
  assert.deepEqual(requestBody.messages, messages)
  assert.equal(requestBody.stream, true)
})

test('rejects remote, blob, SVG, fake, and malformed multimodal content before fetch', async() => {
  let calls = 0
  const fetchImpl = async() => {
    calls += 1
    return { ok: true, json: async() => ({ choices: [{ message: { content: 'must not run' } }] }) }
  }
  const badMessages = [
    [{ role: 'user', content: [{ type: 'image_url', image_url: { url: 'http://169.254.169.254/latest/meta-data' } }] }],
    [{ role: 'user', content: [{ type: 'image_url', image_url: { url: 'https://example.test/image.png' } }] }],
    [{ role: 'user', content: [{ type: 'image_url', image_url: { url: 'blob:https://example.test/image' } }] }],
    [{ role: 'user', content: [{ type: 'image_url', image_url: { url: 'data:image/svg+xml;base64,PHN2Zz4=' } }] }],
    [{ role: 'user', content: [{ type: 'image_url', image_url: { url: 'data:image/png;base64,AA==' } }] }],
    [{ role: 'user', content: [{ type: 'image_url', image_url: {} }] }],
    [{ role: 'user', content: [{ type: 'image_url', image_url: null }] }],
    [{ role: 'user', content: [null] }],
    [{ role: 'user', content: [{ type: 'text', text: 'x', image_url: { url: 'http://169.254.169.254/latest/meta-data' } }] }],
    [{ role: 'user', content: [{ type: 'image_url', image_url: { url: PNG_3X2_DATA_URL, detail: 'high' } }] }],
    [{ role: 'user', content: [{ type: 'image_url', image_url: { url: PNG_3X2_DATA_URL }, remote: 'http://169.254.169.254' }] }],
    [{ role: 'user', content: [{ type: 'input_image', image_url: { url: PNG_3X2_DATA_URL } }] }],
    [{ role: 'user', content: [] }],
    [{ role: 'assistant', content: [{ type: 'text', text: 'not allowed here' }] }],
    [{ role: 'tool', content: [{ type: 'text', text: 'not allowed here' }], tool_call_id: 'call-1' }],
    [{ role: 'user', content: [{ type: 'text', text: 1 }] }]
  ]
  for (const messages of badMessages) {
    await assert.rejects(() => askProvider({
      baseUrl: 'https://provider.example/v1', model: 'm', apiKey: '', messages, fetchImpl
    }))
    assert.equal(calls, 0)
  }

  await askProvider({
    baseUrl: 'https://provider.example/v1',
    model: 'm',
    apiKey: '',
    messages: [{ role: 'user', content: [{ type: 'text', text: 'ok' }, { type: 'image_url', image_url: { url: PNG_3X2_DATA_URL } }] }],
    fetchImpl: async() => {
      calls += 1
      return { ok: true, json: async() => ({ choices: [{ message: { content: 'validated' } }] }) }
    }
  })
  assert.equal(calls, 1)
})

test('rejects an oversized whole request before fetch across tool rounds', async() => {
  let calls = 0
  const fetchImpl = async() => {
    calls += 1
    throw new Error('fetch must not run')
  }
  const huge = 'x'.repeat(MAX_PROVIDER_REQUEST_BYTES)
  await assert.rejects(() => askProvider({
    baseUrl: 'https://provider.example/v1', model: 'm', apiKey: '', messages: [{ role: 'user', content: huge }], fetchImpl
  }), /byte limit/)
  assert.equal(calls, 0)

  await assert.rejects(() => askProvider({
    baseUrl: 'https://provider.example/v1',
    model: 'm',
    apiKey: '',
    messages: [
      { role: 'user', content: 'query' },
      { role: 'assistant', content: null, tool_calls: [{ id: 'call-1', type: 'function', function: { name: 'lookup', arguments: '{}' } }] },
      { role: 'tool', tool_call_id: 'call-1', content: huge }
    ],
    fetchImpl
  }), /byte limit/)
  assert.equal(calls, 0)

  await assert.rejects(() => askProvider({
    baseUrl: 'https://provider.example/v1',
    model: 'm',
    apiKey: '',
    messages: [{ role: 'user', content: 'query' }],
    tools: [{ type: 'function', function: { name: 'lookup', description: huge, parameters: { type: 'object' } } }],
    fetchImpl
  }), /byte limit/)
  assert.equal(calls, 0)
})

test('requests SSE streaming, emits text deltas, and assembles split tool-call deltas', async() => {
  const events = [
    'data: {"choices":[{"delta":{"content":"Hello "}}]}\n\n',
    'data: {"choices":[{"delta":{"content":"there"}}]}\n\n',
    'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call-","type":"function","function":{"name":"lookup_","arguments":"{\\\"id\\\":"}}]}}]}\n\n',
    'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"1","function":{"name":"record","arguments":"1}"}}]}}]}\n\n',
    'data: [DONE]\n\n'
  ]
  let requestBody
  const tokens = []
  const response = await askProvider({
    baseUrl: 'https://provider.example/v1', model: 'm', apiKey: '', messages: [],
    onToken: token => tokens.push(token),
    fetchImpl: async(_url, options) => {
      requestBody = JSON.parse(options.body)
      const encoder = new TextEncoder()
      return {
        ok: true,
        body: new ReadableStream({
          start(controller) {
            for (const event of events) controller.enqueue(encoder.encode(event))
            controller.close()
          }
        })
      }
    }
  })

  assert.equal(requestBody.stream, true)
  assert.deepEqual(tokens, ['Hello ', 'there'])
  assert.equal(response.content, 'Hello there')
  assert.deepEqual(response.toolCalls, [{
    id: 'call-1', type: 'function', function: { name: 'lookup_record', arguments: '{"id":1}' }
  }])
})
