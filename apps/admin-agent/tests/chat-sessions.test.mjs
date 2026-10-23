import assert from 'node:assert/strict'
import test from 'node:test'
import { createChatSessions } from '../src/chat-sessions.js'

function fixture() {
  const map = new Map()
  let id = 0
  return { map, storage: () => ({ getItem: key => map.get(key), setItem: (key, value) => map.set(key, value) }), randomId: () => `chat-${++id}` }
}
test('chat text survives remount; sessions can be created, selected and deleted', () => {
  const options = fixture()
  const first = createChatSessions(options)
  first.save([{ role: 'user', content: 'First question' }, { role: 'assistant', content: 'First answer' }])
  const firstId = first.activeId
  first.start()
  first.save([{ role: 'user', content: 'Second question' }])
  const restored = createChatSessions(options)
  assert.equal(restored.list().length, 2)
  assert.equal(restored.current()[0].content, 'Second question')
  assert.equal(restored.select(firstId), true)
  assert.equal(restored.current()[1].content, 'First answer')
  restored.remove(firstId)
  assert.equal(restored.current().length, 0)
  assert.equal(createChatSessions(options).list().length, 1)
})
test('only bounded user/assistant text is retained; no tool metadata or attachment objects survive', () => {
  const options = fixture()
  const chats = createChatSessions(options)
  chats.save([{ role: 'system', content: 'system-secret' }, { role: 'tool', content: 'tool-secret' },
    { role: 'user', content: 'Question', attachments: ['binary-secret'] },
    { role: 'assistant', content: 'A'.repeat(30000), tool_calls: ['executable-secret'] }])
  const saved = [...options.map.values()][0]
  for (const text of ['system-secret', 'tool-secret', 'binary-secret', 'executable-secret']) assert.equal(saved.includes(text), false)
  assert.equal(chats.current().reduce((count, message) => count + message.content.length, 0), 24000)
  for (let index = 0; index < 25; index++) { chats.start(); chats.save([{ role: 'user', content: `Question ${index}` }]) }
  assert.equal(chats.list().length, 20)
})
test('account partitions do not share records and missing identity uses memory only', () => {
  const options = fixture()
  createChatSessions({ ...options, scope: 'first' }).save([{ role: 'user', content: 'Private question' }])
  assert.equal(createChatSessions({ ...options, scope: 'second' }).list().length, 0)
  const memory = createChatSessions({ ...options, scope: null })
  memory.save([{ role: 'user', content: 'Memory only' }])
  assert.equal(memory.persistent, false)
  assert.equal(createChatSessions({ ...options, scope: null }).list().length, 0)
})
test('corrupt and unavailable browser storage never breaks chat', () => {
  const corrupt = createChatSessions({ storage: () => ({ getItem: () => '{bad' }) })
  assert.deepEqual(corrupt.list(), [])
  const broken = createChatSessions({ storage: () => { throw new Error('Storage blocked') } })
  broken.save([{ role: 'user', content: 'Still works' }])
  assert.equal(broken.current()[0].content, 'Still works')
  assert.equal(broken.persistent, false)
})
