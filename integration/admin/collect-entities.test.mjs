import assert from 'node:assert/strict'
import test from 'node:test'
import { collectEntities } from '../../business/admin/config/collect-entities.mjs'

test('rejects duplicate business entities across collection directories', () => {
  assert.throws(() => collectEntities({
    './collections/first/Dummy.js': { default: { Dummy: { marker: 'first' } } },
    './collections/second/Dummy.js': { default: { Dummy: { marker: 'second' } } }
  }), /Duplicate business entity configuration "Dummy"/)
})

test('collects unique entities from collection files and nested entity files', () => {
  assert.deepEqual(collectEntities({
    './collections/common/User.js': { default: { User: { marker: 'user' } } },
    './collections/dummy/nested/Dummy.js': { default: { marker: 'dummy' } }
  }), {
    User: { marker: 'user' },
    Dummy: { marker: 'dummy' }
  })
})
