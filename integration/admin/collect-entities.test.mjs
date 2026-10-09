import assert from 'node:assert/strict'
import test from 'node:test'
import { collectEntities } from '../../business/admin/config/collect-entities.mjs'

test('rejects duplicate business entities across collection directories', () => {
  assert.throws(() => collectEntities({
    './collections/first/Note.js': { default: { Note: { marker: 'first' } } },
    './collections/second/Note.js': { default: { Note: { marker: 'second' } } }
  }), /Duplicate business entity configuration "Note"/)
})

test('collects unique entities from collection files and nested entity files', () => {
  assert.deepEqual(collectEntities({
    './collections/common/User.js': { default: { User: { marker: 'user' } } },
    './collections/note/nested/Note.js': { default: { marker: 'note' } }
  }), {
    User: { marker: 'user' },
    Note: { marker: 'note' }
  })
})
