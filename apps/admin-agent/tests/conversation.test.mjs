import assert from 'node:assert/strict'
import test from 'node:test'
import { trimConversation } from '../src/conversation.js'

test('keeps only supported recent chat turns within message and character limits', () => {
  const messages = [
    { role: 'user', content: 'old question' },
    { role: 'assistant', content: 'old answer' },
    { role: 'user', content: 'current question' },
    { role: 'assistant', content: 'current answer' },
    { role: 'tool', content: 'tool output is never retained' }
  ]
  const trimmed = trimConversation(messages, 3, 40)
  assert.deepEqual(trimmed, [
    { role: 'user', content: 'current question' },
    { role: 'assistant', content: 'current answer' }
  ])
  assert.ok(trimmed.reduce((total, message) => total + message.content.length, 0) <= 40)
})

test('truncates a single oversized latest message without retaining older context', () => {
  assert.deepEqual(trimConversation([
    { role: 'user', content: 'old' },
    { role: 'assistant', content: '0123456789' }
  ], 16, 4), [{ role: 'assistant', content: '6789' }])
})
