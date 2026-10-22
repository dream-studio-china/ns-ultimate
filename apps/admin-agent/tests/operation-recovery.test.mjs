import assert from 'node:assert/strict'
import test from 'node:test'
import { createOperationRecovery } from '../src/operation-recovery.js'
import { createMutationHost } from '../src/mutation-host.js'

const id = 'a'.repeat(64)
const receipt = { operationId: id, entity: 'Content', action: 'create', status: 'pending' }
const preview = { operationId: id, saved: false, differences: [{ property: 'title', before: null, after: 'Private draft' }] }

test('reload discovers receipts and restores the same preview only after a user request, never committing', async() => {
  let restores = 0
  let commits = 0
  let displayed
  const host = {
    listRecordChanges: async page => ({ items: [receipt], page, hasMore: true }),
    restoreRecordChange: async operationId => { assert.equal(operationId, id); restores += 1; return preview },
    commitRecordChange: async() => { commits += 1 }
  }
  const recovery = createOperationRecovery(host, { onRestore: value => { displayed = value } })
  await recovery.load()
  assert.deepEqual(recovery.state.items, [receipt])
  assert.equal(restores, 0)
  await recovery.restore(id)
  assert.equal(displayed, preview)
  assert.equal(restores, 1)
  assert.equal(commits, 0)
  assert.equal(JSON.stringify(recovery.state).includes('Private draft'), false)
  // A newly mounted assistant can discover the same server-owned operation without browser storage.
  const remounted = createOperationRecovery(host)
  await remounted.load()
  assert.equal(remounted.state.items[0].operationId, id)
})

test('checking outcome updates the receipt without fetching values or retrying a write', async() => {
  const recovery = createOperationRecovery({
    listRecordChanges: async page => ({ items: [receipt], page }),
    getRecordChangeStatus: async() => ({ ...receipt, status: 'succeeded' })
  })
  await recovery.load()
  await recovery.check(id)
  assert.equal(recovery.state.items[0].status, 'succeeded')
})

test('busy operations are serialized and work is rejected while chat or an uncertain write is active', async() => {
  let allowed = false
  let calls = 0
  let resolve
  const recovery = createOperationRecovery({ listRecordChanges: () => { calls += 1; return new Promise(done => { resolve = done }) } }, { canInteract: () => allowed })
  await recovery.load()
  assert.equal(calls, 0)
  allowed = true
  const pending = recovery.load()
  assert.equal(recovery.state.busy, true)
  await recovery.load(2)
  assert.equal(calls, 1)
  resolve({ items: [], page: 1, hasMore: false })
  await pending
  assert.equal(recovery.state.busy, false)
})

test('dispose aborts requests and suppresses responses and restored field values after a session change', async() => {
  let resolve
  let signal
  let displayed = false
  const recovery = createOperationRecovery({ restoreRecordChange: (_id, value) => { signal = value; return new Promise(done => { resolve = done }) } }, {
    onRestore: () => { displayed = true }
  })
  const pending = recovery.restore(id)
  recovery.dispose()
  assert.equal(signal.aborted, true)
  resolve(preview)
  await pending
  assert.equal(displayed, false)
  assert.deepEqual(recovery.state.items, [])
  assert.equal(recovery.state.busy, false)
})

test('failed or mismatched restored previews do not reach the confirmation UI or expose server errors', async() => {
  let displayed = false
  const recovery = createOperationRecovery({ restoreRecordChange: async() => ({ ...preview, operationId: 'b'.repeat(64) }) }, { onRestore: () => { displayed = true } })
  await recovery.restore(id)
  assert.equal(displayed, false)
  assert.match(recovery.state.error, /Could not restore/)
  const failed = createOperationRecovery({ listRecordChanges: async() => { throw new Error('Sensitive internal error') } })
  await failed.load()
  assert.equal(failed.state.error.includes('Sensitive'), false)
  assert.equal(failed.state.busy, false)
})

test('reset clears receipts and invalidates old responses while allowing a fresh page-session request', async() => {
  let resolve
  let calls = 0
  const recovery = createOperationRecovery({
    listRecordChanges: async page => {
      calls += 1
      if (calls === 1) return new Promise(done => { resolve = done })
      return { items: [receipt], page, hasMore: false }
    }
  })
  const old = recovery.load()
  recovery.reset()
  await recovery.load()
  resolve({ items: [], page: 1, hasMore: false })
  await old
  assert.deepEqual(recovery.state.items, [receipt])
  assert.equal(recovery.state.busy, false)
  assert.equal(recovery.state.error, '')
})

test('history transport uses fixed read-only routes and rejects invalid pages and operation identifiers', async() => {
  const calls = []
  const host = createMutationHost({ get: async(...args) => {
    calls.push(args)
    return { data: { entity: 'Content', action: 'create', saved: false, differences: [] } }
  } }, '/manage/agent/operations', [{ entity: 'Content', actions: ['create'] }])
  await host.listRecordChanges(2)
  assert.equal(calls[0][0], '/manage/agent/operations')
  assert.deepEqual(calls[0][1].params, { page: 2 })
  await host.restoreRecordChange(id)
  assert.equal(calls[1][0], `/manage/agent/operations/${id}/preview`)
  await assert.rejects(host.listRecordChanges(101), /Invalid operation page/)
  await assert.rejects(host.restoreRecordChange('../other'), /Invalid operation identifier/)
  assert.equal(calls.length, 2)
})

test('Content-only transport blocks restored Counter values before recovery can invoke confirmation', async() => {
  const calls = []
  let onRestoredPreviewCalled = false
  const counterPreview = {
    operationId: id, entity: 'Counter', action: 'update', saved: false,
    differences: [{ property: 'name', before: 'Private counter name', after: 'Another private name' }]
  }
  const transport = createMutationHost({
    get: async(...args) => { calls.push(args); return { data: counterPreview } }
  }, '/manage/agent/operations', [{ entity: 'Content', actions: ['create', 'update', 'delete'] }])
  const recovery = createOperationRecovery(transport, {
    onRestore: () => { onRestoredPreviewCalled = true }
  })

  await recovery.restore(id)

  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], `/manage/agent/operations/${id}/preview`)
  assert.equal(onRestoredPreviewCalled, false)
  assert.equal(JSON.stringify(recovery.state).includes('Private counter name'), false)
  assert.equal(JSON.stringify(recovery.state).includes('Another private name'), false)
  assert.match(recovery.state.error, /Could not restore this preview/)
})
