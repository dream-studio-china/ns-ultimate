import assert from 'node:assert/strict'
import test from 'node:test'
import previewPolicy from '../adapters/ns-ultimate/admin/preview-policy.mjs'
import mutationCapabilities from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import { createMutationHost } from '../src/mutation-host.js'

test('ns-ultimate host keeps Counter preview-only and explicitly enables Content mutations', () => {
  assert.deepEqual(Object.keys(previewPolicy), ['Counter'])
  assert.deepEqual(Object.keys(previewPolicy.Counter), ['name'])
  assert.deepEqual(mutationCapabilities.map(item => item.entity), ['Content'])
  assert.deepEqual(mutationCapabilities[0].actions, ['create', 'update', 'delete'])
  assert.deepEqual(Object.keys(mutationCapabilities[0].fields), ['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'])
})

test('generic transport stays disabled without host selection and accepts the app-owned capability policy', async() => {
  const calls = []
  const request = { post: async(...args) => { calls.push(args); return { data: { saved: false } } } }
  const path = '/api/v1/manage/agent/operations'
  const input = { entity: 'Content', action: 'create', changes: { title: 'Draft' } }
  await assert.rejects(createMutationHost(request, path).prepareRecordChange(input), /not enabled/)
  assert.equal(calls.length, 0)
  const host = createMutationHost(request, path, mutationCapabilities)
  await assert.rejects(host.prepareRecordChange({ entity: 'Counter', action: 'update', changes: { name: 'No write' } }), /not enabled/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Category', action: 'delete', identifier: '1' }), /not enabled/)
  assert.deepEqual(await host.prepareRecordChange(input), { saved: false })
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], path)
  assert.deepEqual(calls[0][1], input)
})
