import assert from 'node:assert/strict'
import test from 'node:test'
import { formatOperations, loadOpenApi, normalizeOpenApi, searchOpenApi } from '../src/openapi.js'

test('normalizes documented operations without turning writes into executable tools', () => {
  const operations = normalizeOpenApi({ paths: {
    '/api/products': { get: { operationId: 'productList', summary: 'List products', parameters: [{ name: 'page', in: 'query', schema: { type: 'integer' } }], responses: { 200: { description: 'Product collection' } } }, post: { operationId: 'productCreate', requestBody: { content: { 'application/json': { schema: { $ref: '#/components/schemas/ProductInput' } } } } } },
    '/api/products/{id}': { delete: { summary: 'Delete product' } }
  }, components: { schemas: { ProductInput: { type: 'object', required: ['name'], properties: { name: { type: 'string', description: 'Product name' }, description: { type: 'string' } } } } } })
  assert.deepEqual(operations.map(item => item.method), ['GET', 'POST', 'DELETE'])
  assert.deepEqual(searchOpenApi(operations, 'list').map(item => item.id), ['productList'])
  assert.match(formatOperations([operations[0]]), /query page/)
  assert.match(formatOperations([operations[0]]), /200: Product collection/)
  assert.equal(formatOperations([operations[0]], 8).length, 8)
  assert.deepEqual(searchOpenApi(operations, 'description').map(item => item.id), ['productCreate'])
  assert.match(formatOperations([operations[1]]), /name\*: string/)
})

test('loads same-origin API documentation and rejects cross-origin URLs', async() => {
  const originalLocation = globalThis.location
  globalThis.location = { origin: 'https://admin.example.test' }
  try {
    await assert.rejects(() => loadOpenApi('https://attacker.test/api/doc.json', async() => { throw new Error('must not fetch') }), /this application origin/)
    const result = await loadOpenApi('/api/doc.json', async(url, options) => {
      assert.equal(url, 'https://admin.example.test/api/doc.json')
      assert.equal(options.method, 'GET')
      assert.equal(options.credentials, 'same-origin')
      assert.equal(options.redirect, 'error')
      return { ok: true, headers: { get: () => '35' }, text: async() => '{"paths":{"/ping":{"get":{"summary":"Health"}}}}' }
    })
    assert.equal(result.operations[0].path, '/ping')
    await assert.rejects(() => loadOpenApi('/api/doc.json', async() => ({
      ok: true,
      url: 'https://external.example/api/doc.json',
      headers: { get: () => '2' },
      text: async() => '{"paths":{}}'
    })), /redirected outside/)
  } finally {
    if (originalLocation === undefined) delete globalThis.location
    else globalThis.location = originalLocation
  }
})

test('provider endpoint allows HTTP only for localhost and private LAN addresses', async() => {
  const { normalizeChatEndpoint } = await import('../src/provider.js')
  assert.throws(() => normalizeChatEndpoint('http://api.example.test/v1'), /HTTPS/)
  assert.equal(normalizeChatEndpoint('http://localhost:1234/v1'), 'http://localhost:1234/v1/chat/completions')
  assert.equal(normalizeChatEndpoint('http://127.0.0.1:1234/v1'), 'http://127.0.0.1:1234/v1/chat/completions')
  assert.equal(normalizeChatEndpoint('http://100.78.52.73:11434/'), 'http://100.78.52.73:11434/v1/chat/completions')
  assert.equal(normalizeChatEndpoint('http://192.168.1.20:11434/v1'), 'http://192.168.1.20:11434/v1/chat/completions')
  assert.equal(normalizeChatEndpoint('http://[::1]:11434/v1'), 'http://[::1]:11434/v1/chat/completions')
  assert.equal(normalizeChatEndpoint('http://[fd12::20]:11434/v1'), 'http://[fd12::20]:11434/v1/chat/completions')
  assert.equal(normalizeChatEndpoint('http://ollama.lan:11434/v1'), 'http://ollama.lan:11434/v1/chat/completions')
  assert.equal(normalizeChatEndpoint('https://provider.example/v1/chat/completions'), 'https://provider.example/v1/chat/completions')
  assert.throws(() => normalizeChatEndpoint('https://provider.example/v1?key=secret'), /query parameters/)
})
