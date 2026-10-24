import assert from 'node:assert/strict'
import test from 'node:test'
import { createMutationHost, resolveCapabilitySource } from '../src/mutation-host.js'
import { mutationCapabilities } from './support/capability-fixture.mjs'

const PATH = '/api/v1/manage/agent/operations'
const OPERATION_ID = 'a'.repeat(64)
const RECEIPT = { operationId: OPERATION_ID, status: 'succeeded', verified: true }

const preview = {
  operationId: OPERATION_ID,
  entity: 'Tag',
  action: 'update',
  identifier: '12',
  title: 'Tag',
  expiresAt: '2026-10-23T12:05:00+00:00',
  differences: [{ property: 'color', before: null, after: '#123456' }],
  saved: false
}

function cloneCapabilities(capabilities = mutationCapabilities) {
  return capabilities.map(capability => ({
    ...capability,
    actions: [...capability.actions],
    actionFields: capability.actionFields
      ? Object.fromEntries(Object.entries(capability.actionFields).map(([action, fields]) => [action, { ...fields }]))
      : undefined,
    fields: capability.fields ? { ...capability.fields } : capability.fields
  }))
}

function dynamicState(identity = 'runtime-1:config-1') {
  return { identity, capabilities: cloneCapabilities() }
}

function source(state) {
  return {
    getCapabilities: () => state.capabilities,
    getRevision: () => state.identity
  }
}

function transport(state, options = {}) {
  const calls = []
  const request = {
    async post(path, body, requestOptions = {}) {
      calls.push({ method: 'POST', path, body, options: requestOptions })
      if (path === PATH) return { data: options.prepare ? options.prepare() : preview }
      return { data: options.commit ? await options.commit() : RECEIPT }
    },
    async get(path, requestOptions = {}) {
      calls.push({ method: 'GET', path, options: requestOptions })
      return { data: options.restore ? options.restore() : preview }
    }
  }
  return { host: createMutationHost(request, PATH, source(state)), calls }
}

function tagCapability(state) {
  return state.capabilities.find(item => item.entity === 'Tag')
}

function updateInput() {
  return { entity: 'Tag', action: 'update', identifier: '12', changes: { color: '#123456' } }
}

function assertNoCommit(calls) {
  assert.equal(calls.filter(call => call.method === 'POST' && call.path === `${PATH}/${OPERATION_ID}/commit`).length, 0)
}

test('dynamic identity is opaque and falls back to the legacy revision', () => {
  assert.deepEqual(resolveCapabilitySource([{ entity: 'Tag' }]), {
    capabilities: [{ entity: 'Tag' }], revision: null, identity: null
  })
  assert.equal(resolveCapabilitySource({ getCapabilities: () => [], getRevision: () => 'r:1' }).identity, 'r:1')
  assert.equal(resolveCapabilitySource({ getCapabilities: () => [], getIdentity: () => 'epoch:7', getRevision: () => 'r:1' }).identity, 'epoch:7')
})

test('change rejection names unsupported fields and bounded rule failures without posting', async() => {
  const { host, calls } = transport(dynamicState())

  await assert.rejects(
    host.prepareRecordChange({ entity: 'Tag', action: 'update', identifier: '12', changes: { metadata: { x: 1 } } }),
    /Unsupported change fields: metadata\./
  )
  await assert.rejects(
    host.prepareRecordChange({ entity: 'Tag', action: 'update', identifier: '12', changes: { color: '#fff' } }),
    /Invalid change values: color must be #RRGGBB\./
  )
  await assert.rejects(
    host.prepareRecordChange({ entity: 'Content', action: 'update', identifier: '12', changes: { body: 'x'.repeat(32001) } }),
    /Invalid change values: body exceeds 32000 UTF-8 bytes\./
  )
  await assert.rejects(
    host.prepareRecordChange({ entity: 'Tag', action: 'delete', identifier: '12', changes: { color: '#123456' } }),
    /Deletion does not accept change fields\./
  )
  assert.equal(calls.length, 0)
})

test('commit rejects a removed field with the same entity, action and identity without posting', async() => {
  const state = dynamicState()
  const { host, calls } = transport(state)
  await host.prepareRecordChange(updateInput())
  delete tagCapability(state).actionFields.update.color
  delete tagCapability(state).fields.color

  await assert.rejects(host.commitRecordChange(OPERATION_ID), error => error.code === 'proposal_conflict')
  assertNoCommit(calls)
})

test('commit rejects a revoked current action without posting', async() => {
  const state = dynamicState()
  const { host, calls } = transport(state)
  await host.prepareRecordChange(updateInput())
  tagCapability(state).actions = tagCapability(state).actions.filter(action => action !== 'update')

  await assert.rejects(host.commitRecordChange(OPERATION_ID), error => error.code === 'proposal_conflict')
  assertNoCommit(calls)
})

test('commit rejects an epoch-qualified identity change even when fields are unchanged', async() => {
  const state = dynamicState('runtime-a:config-1')
  const { host, calls } = transport(state)
  await host.prepareRecordChange(updateInput())
  state.identity = 'runtime-a:config-2'

  await assert.rejects(host.commitRecordChange(OPERATION_ID), error => error.code === 'proposal_conflict')
  assertNoCommit(calls)
})

test('commit rejects a nested descriptor fingerprint change under the same identity', async() => {
  const state = dynamicState()
  const { host, calls } = transport(state)
  await host.prepareRecordChange(updateInput())
  tagCapability(state).actionFields.update.color = { ...tagCapability(state).actionFields.update.color, nested: { maxLength: 9 } }

  await assert.rejects(host.commitRecordChange(OPERATION_ID), error => error.code === 'proposal_conflict')
  assertNoCommit(calls)
})

test('unknown operation IDs are closed and never posted blindly', async() => {
  const state = dynamicState()
  const { host, calls } = transport(state)
  await assert.rejects(host.commitRecordChange(OPERATION_ID), error => error.code === 'proposal_conflict')
  assertNoCommit(calls)
})

test('restore rebinds the current generation and requires a fresh confirmation before commit', async() => {
  const state = dynamicState('generation-1')
  const { host, calls } = transport(state)
  await host.prepareRecordChange(updateInput())
  state.identity = 'generation-2'
  await assert.rejects(host.commitRecordChange(OPERATION_ID), error => error.code === 'proposal_conflict')
  assertNoCommit(calls)

  const restored = await host.restoreRecordChange(OPERATION_ID)
  assert.equal(restored.operationId, OPERATION_ID)
  const receipt = await host.commitRecordChange(OPERATION_ID)
  assert.deepEqual(receipt, RECEIPT)
  assert.equal(calls.filter(call => call.method === 'POST' && call.path === `${PATH}/${OPERATION_ID}/commit`).length, 1)
})

test('an in-flight commit may settle with a receipt after authority is revoked', async() => {
  const state = dynamicState()
  let release
  const { host, calls } = transport(state, {
    commit: () => new Promise(resolve => { release = resolve })
  })
  await host.prepareRecordChange(updateInput())
  const pending = host.commitRecordChange(OPERATION_ID)
  state.capabilities = []
  state.identity = 'revoked-generation'
  release(RECEIPT)
  assert.deepEqual(await pending, RECEIPT)
  assert.equal(calls.filter(call => call.method === 'POST' && call.path === `${PATH}/${OPERATION_ID}/commit`).length, 1)
})

test('status, cancel and recent reads stay available after write authority is revoked', async() => {
  const state = dynamicState()
  const { host, calls } = transport(state)
  await host.prepareRecordChange(updateInput())
  state.capabilities = []
  state.identity = 'revoked-generation'

  assert.deepEqual(await host.getRecordChangeStatus(OPERATION_ID), preview)
  await host.cancelRecordChange(OPERATION_ID)
  assert.deepEqual(await host.listRecordChanges(2), preview)
  assert.deepEqual(calls.filter(call => call.method === 'GET').map(call => [call.path, call.options.params]), [
    [`${PATH}/${OPERATION_ID}`, undefined],
    [PATH, { page: 2 }]
  ])
  assert.equal(calls.filter(call => call.method === 'POST' && call.path === `${PATH}/${OPERATION_ID}/cancel`).length, 1)
})

test('a changed identity during an awaited preparation rejects and does not retain a commit policy', async() => {
  const state = dynamicState('generation-1')
  const { host, calls } = transport(state, {
    prepare: () => {
      state.identity = 'generation-2'
      return preview
    }
  })

  await assert.rejects(host.prepareRecordChange(updateInput()), error => error.code === 'capabilities_changed')
  await assert.rejects(host.commitRecordChange(OPERATION_ID), error => error.code === 'proposal_conflict')
  assertNoCommit(calls)
})

function legacyTagCapability() {
  return {
    entity: 'Tag',
    actions: ['update'],
    fields: { name: { maxLength: 20 }, color: { nullable: true } },
    validateChanges: () => true,
    validatePreview: () => true
  }
}

function legacyPreview() {
  return {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'update',
    identifier: '12',
    differences: [{ property: 'name', before: null, after: 'abc' }],
    saved: false
  }
}

test('legacy fields-only descriptors fingerprint nested rule changes and field removal at the same identity', async() => {
  const mutations = [
    capability => { capability.fields.name = { ...capability.fields.name, maxLength: 1 } },
    capability => { capability.fields.name = { maxLength: 20, nested: { maxLength: 1 } } },
    capability => { delete capability.fields.name }
  ]
  for (const mutate of mutations) {
    const state = { identity: 'legacy-generation-1', capabilities: [legacyTagCapability()] }
    const { host, calls } = transport(state, { prepare: legacyPreview })
    await host.prepareRecordChange({ entity: 'Tag', action: 'update', identifier: '12', changes: { name: 'abc' } })
    mutate(state.capabilities[0])

    await assert.rejects(host.commitRecordChange(OPERATION_ID), error => error.code === 'proposal_conflict')
    assertNoCommit(calls)
  }
})
