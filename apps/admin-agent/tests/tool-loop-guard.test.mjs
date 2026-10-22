import assert from 'node:assert/strict'
import test from 'node:test'
import { createToolLoopGuard } from '../src/tool-loop-guard.js'

test('allows distinct and normalized tool calls but blocks repeated identical calls', () => {
  const guard = createToolLoopGuard()
  const query = argumentsValue => ({ function: { name: 'query_records', arguments: argumentsValue } })

  assert.equal(guard.check(query('{"entity":"Counter","page":1}')).allowed, true)
  assert.equal(guard.check(query('{ "page": 1, "entity": "Counter" }')).allowed, true)
  assert.equal(guard.check(query('{"entity":"Counter","page":1}')).allowed, true)
  assert.deepEqual(guard.check(query('{"page":1,"entity":"Counter"}')), { allowed: false, reason: 'repeated' })
})

test('caps tool calls within one task as a backstop against unique-call loops', () => {
  const guard = createToolLoopGuard()
  for (let index = 0; index < 100; index += 1) {
    assert.equal(guard.check({ function: { name: 'query_records', arguments: JSON.stringify({ page: index + 1 }) } }).allowed, true)
  }
  assert.deepEqual(guard.check({ function: { name: 'query_records', arguments: '{"page":101}' } }), { allowed: false, reason: 'limit' })
})
