import assert from 'node:assert/strict'
import test from 'node:test'
import previewPolicy from '../adapters/ns-ultimate/admin/preview-policy.mjs'
import mutationCapabilities from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import { createMutationHost } from '../src/mutation-host.js'

test('ns-ultimate host explicitly enables Content mutations and Counter name-only update', () => {
  assert.deepEqual(Object.keys(previewPolicy), ['Counter'])
  assert.deepEqual(Object.keys(previewPolicy.Counter), ['name'])
  assert.deepEqual(mutationCapabilities.map(item => item.entity), ['Content', 'Counter'])
  assert.deepEqual(mutationCapabilities[0].actions, ['create', 'update', 'delete'])
  assert.deepEqual(Object.keys(mutationCapabilities[0].fields), ['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'])
  assert.deepEqual(mutationCapabilities[1].actions, ['update'])
  assert.deepEqual(Object.keys(mutationCapabilities[1].fields), ['name'])
})

test('generic transport stays disabled without host selection and accepts the app-owned capability policy', async() => {
  const calls = []
  const request = { post: async(...args) => { calls.push(args); return { data: { saved: false } } } }
  const path = '/api/v1/manage/agent/operations'
  const input = { entity: 'Content', action: 'create', changes: { title: 'Draft' } }
  await assert.rejects(createMutationHost(request, path).prepareRecordChange(input), /not enabled/)
  assert.equal(calls.length, 0)
  const host = createMutationHost(request, path, mutationCapabilities)
  await host.prepareRecordChange({ entity: 'Counter', action: 'update', identifier: '12', changes: { name: 'New name' } })
  await assert.rejects(host.prepareRecordChange({ entity: 'Counter', action: 'create', changes: { name: 'No' } }), /not enabled/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Counter', action: 'delete', identifier: '12' }), /not enabled/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Category', action: 'delete', identifier: '1' }), /not enabled/)
  assert.deepEqual(await host.prepareRecordChange(input), { saved: false })
  assert.equal(calls.length, 2)
  assert.equal(calls[0][0], path)
  assert.deepEqual(calls[0][1], { entity: 'Counter', action: 'update', identifier: '12', changes: { name: 'New name' } })
  assert.deepEqual(calls[1][1], input)
})

test('Counter requests enforce the host-owned name-only value policy before transport', async() => {
  const calls = []
  const request = { post: async(...args) => { calls.push(args); return { data: { saved: false } } } }
  const host = createMutationHost(request, '/operations', mutationCapabilities)
  for (const changes of [
    { name: 'Valid', enabled: false },
    { name: 'Valid', storeUuid: 'other' },
    { name: 42 },
    { name: '' },
    { name: '   ' },
    { name: 'x'.repeat(121) },
    {},
    [],
    Object.assign(Object.create({ inherited: true }), { name: 'Valid' }),
    JSON.parse('{"name":"Valid","__proto__":{"polluted":true}}')
  ]) {
    await assert.rejects(host.prepareRecordChange({ entity: 'Counter', action: 'update', identifier: '12', changes }), /Changes contain unsupported/)
  }
  await assert.rejects(host.prepareRecordChange({ entity: 'Counter', action: 'update', identifier: '12' }), /Changes contain unsupported/)
  assert.equal(calls.length, 0)
  await host.prepareRecordChange({ entity: 'Counter', action: 'update', identifier: '12', changes: { name: '  New name  ' } })
  assert.equal(calls.length, 1)
})

test('request envelopes reject arrays, custom prototypes, and prototype-pollution keys', async() => {
  const calls = []
  const host = createMutationHost({ post: async(...args) => { calls.push(args); return { data: {} } } }, '/operations', mutationCapabilities)
  await assert.rejects(host.prepareRecordChange([]), /not enabled/)
  await assert.rejects(host.prepareRecordChange(Object.assign(Object.create({ inherited: true }), { entity: 'Content', action: 'delete' })), /not enabled/)
  await assert.rejects(host.prepareRecordChange(JSON.parse('{"entity":"Content","action":"delete","__proto__":{}}')), /not enabled/)
  assert.equal(calls.length, 0)
})

test('Content create/delete compatibility remains intact while unknown nested fields are rejected', async() => {
  const calls = []
  const host = createMutationHost({ post: async(...args) => { calls.push(args); return { data: {} } } }, '/operations', mutationCapabilities)
  await host.prepareRecordChange({ entity: 'Content', action: 'create', changes: { title: 'Draft' } })
  await host.prepareRecordChange({ entity: 'Content', action: 'delete', identifier: '12' })
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'create', changes: { title: 'Draft', unexpected: true } }), /Changes contain unsupported/)
  assert.equal(calls.length, 2)
})

test('restored Counter previews are checked against the host field policy', async() => {
  const host = createMutationHost({ get: async() => ({ data: { entity: 'Counter', action: 'update', differences: [{ property: 'enabled', before: true, after: false }] } }) }, '/operations', mutationCapabilities)
  await assert.rejects(host.restoreRecordChange('a'.repeat(64)), /Invalid restored preview/)
})

test('restored proposals require an enabled entity/action before they can reach confirmation', async() => {
  let displayed = false
  const previews = [
    { entity: 'Counter', action: 'create', differences: [] },
    { entity: 'Unknown', action: 'update', differences: [] },
    { entity: 'Content', action: 'restore', differences: [] }
  ]
  for (const preview of previews) {
    const host = createMutationHost({ get: async() => ({ data: preview }) }, '/operations', mutationCapabilities)
    await host.restoreRecordChange('a'.repeat(64)).then(() => { displayed = true }, () => {})
    assert.equal(displayed, false)
  }

  for (const preview of [
    { entity: 'Content', action: 'create', differences: [] },
    { entity: 'Counter', action: 'update', differences: [{ property: 'name', before: 'Old', after: 'New' }] }
  ]) {
    const host = createMutationHost({ get: async() => ({ data: preview }) }, '/operations', mutationCapabilities)
    await host.restoreRecordChange('a'.repeat(64)).then(() => { displayed = true })
    assert.equal(displayed, true)
    displayed = false
  }
})
