import assert from 'node:assert/strict'
import test from 'node:test'
import { createPreparedChange } from '../src/prepared-change.js'
import { createAssistantTools, executeAssistantTool } from '../src/tools.js'
import { createMutationHost } from '../src/mutation-host.js'

const preview = { operationId: 'a'.repeat(64), entity: 'Content', action: 'create', saved: false,
  expiresAt: new Date(Date.now() + 300000).toISOString(), differences: [{ property: 'body', before: null, after: 'Private draft body' }] }

test('requires explicit UI confirmation and serializes submission', async() => {
  let resolve
  let calls = 0
  const change = createPreparedChange(preview, { commitRecordChange: () => { calls += 1; return new Promise(done => { resolve = done }) } })
  assert.equal((await change.submit(false)).state, 'ready')
  assert.equal(calls, 0)
  const pending = change.submit(true)
  assert.equal((await change.submit(true)).state, 'submitting')
  assert.equal(calls, 1)
  resolve({ status: 'succeeded' })
  assert.equal((await pending).state, 'succeeded')
  await change.submit(true)
  assert.equal(calls, 1)
})

test('invalidates stale/expired proposals and treats network failure as unknown rather than retrying', async() => {
  const host = { commitRecordChange: async() => { throw new Error('timeout') }, getRecordChangeStatus: async() => ({ status: 'succeeded' }) }
  const stale = createPreparedChange(preview, host)
  stale.invalidate()
  assert.equal((await stale.submit(true)).state, 'invalidated')
  const expired = createPreparedChange({ ...preview, expiresAt: new Date(0).toISOString() }, host)
  assert.equal((await expired.submit(true)).state, 'expired')
  const change = createPreparedChange(preview, host)
  assert.equal((await change.submit(true)).state, 'unknown')
  assert.equal((await change.submit(true)).state, 'unknown')
  assert.equal((await change.recover()).state, 'succeeded')
})

test('preparation tool never submits and withholds operation tokens and full content from the model', async() => {
  let displayed
  let commits = 0
  const host = { prepareRecordChange: async() => preview, commitRecordChange: () => { commits += 1 } }
  const result = await executeAssistantTool({
    call: { type: 'function', function: { name: 'prepare_record_change', arguments: '{"entity":"Content","action":"create","changes":{"title":"Draft"}}' } },
    catalog: [], host, onPreparedChange: value => { displayed = value }
  })
  assert.equal(displayed, preview)
  assert.equal(result.saved, false)
  assert.equal(commits, 0)
  assert.equal(JSON.stringify(result).includes(preview.operationId), false)
  assert.equal(JSON.stringify(result).includes('Private draft body'), false)
  const tools = createAssistantTools([], [], {}, [{ entity: 'Content' }])
  assert.deepEqual(tools.map(tool => tool.function.name), ['list_entities', 'prepare_record_change'])
})

test('server-pruned expired proposals resolve unknown submissions without permitting another write', async() => {
  let calls = 0
  const change = createPreparedChange(preview, {
    commitRecordChange: async() => { calls += 1; throw new Error('timeout') },
    getRecordChangeStatus: async() => ({ status: 'expired' })
  })
  assert.equal((await change.submit(true)).state, 'unknown')
  assert.equal((await change.recover()).state, 'expired')
  assert.equal((await change.submit(true)).state, 'expired')
  assert.equal(calls, 1)
})

test('a pending status permits only a new explicit confirmation and an aborted request cannot start a write', async() => {
  let calls = 0
  const host = {
    commitRecordChange: async() => { calls += 1; throw new Error('connection lost') },
    getRecordChangeStatus: async() => ({ status: 'pending' }),
    restoreRecordChange: async() => preview
  }
  const change = createPreparedChange(preview, host)
  const controller = new AbortController()
  controller.abort()
  assert.equal((await change.submit(true, controller.signal)).state, 'ready')
  assert.equal(calls, 0)
  await change.submit(true)
  assert.equal((await change.recover()).state, 'ready')
  assert.equal(calls, 1)
  assert.equal((await change.submit(false)).state, 'ready')
  assert.equal(calls, 1)
})

test('a successful late response after commit cancellation is terminal and never retries the write', async() => {
  let commitSignal
  let resolveCommit
  let commits = 0
  const change = createPreparedChange(preview, {
    commitRecordChange: (_id, signal) => {
      commits += 1
      commitSignal = signal
      return new Promise(resolve => { resolveCommit = resolve })
    }
  })
  const controller = new AbortController()
  const submission = change.submit(true, controller.signal)
  assert.equal(commits, 1)
  assert.equal(change.state, 'submitting')

  controller.abort()
  assert.equal(commitSignal.aborted, true)
  resolveCommit({ status: 'succeeded' })
  assert.equal((await submission).state, 'succeeded')
  assert.equal((await change.submit(true)).state, 'succeeded')
  assert.equal(commits, 1)
})

test('an aborted dispatched request that rejects remains unknown and requires status recovery', async() => {
  let commits = 0
  let checks = 0
  const change = createPreparedChange(preview, {
    commitRecordChange: (_id, signal) => new Promise((_resolve, reject) => {
      commits += 1
      signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })), { once: true })
    }),
    getRecordChangeStatus: async() => { checks += 1; return { status: 'succeeded' } }
  })
  const controller = new AbortController()
  const submission = change.submit(true, controller.signal)
  controller.abort()
  assert.equal((await submission).state, 'unknown')
  assert.equal((await change.submit(true)).state, 'unknown')
  assert.equal(commits, 1)
  assert.equal((await change.recover()).state, 'succeeded')
  assert.equal(checks, 1)
  assert.equal(commits, 1)
})

test('only an explicit conflict response invalidates submission without an outcome check or retry', async() => {
  for (const error of [
    { response: { status: 409, data: { error: 'proposal_conflict' } } },
    { response: { status: 409, data: { message: 'Conflict' } } },
    { response: { status: 500, data: { error: 'proposal_conflict' } } },
    new Error('timeout'),
    Object.assign(new Error('cancelled'), { name: 'AbortError' })
  ]) {
    let commits = 0
    const host = createMutationHost({ post: async() => { commits += 1; throw error } }, '/operations')
    const change = createPreparedChange(preview, host)
    const expected = error.response?.status === 409 && error.response.data?.error === 'proposal_conflict' ? 'conflicted' : 'unknown'
    assert.equal((await change.submit(true)).state, expected)
    assert.equal((await change.submit(true)).state, expected)
    if (expected === 'conflicted') {
      assert.equal((await change.recover()).state, 'conflicted')
      change.invalidate()
      assert.equal(change.state, 'invalidated')
    }
    assert.equal(commits, 1)
  }
})

test('pending outcome revalidates the original preview and never re-enables a stale proposal', async() => {
  for (const conflict of [true, false]) {
    const requests = []
    const host = createMutationHost({
      post: async() => { requests.push('commit'); throw new Error('lost response') },
      get: async path => {
        requests.push(path.endsWith('/preview') ? 'preview' : 'status')
        if (!path.endsWith('/preview')) return { data: { status: 'pending' } }
        if (conflict) throw { response: { status: 409, data: { error: 'proposal_conflict' } } }
        return { data: preview }
      }
    }, '/operations', [{ entity: 'Content', actions: ['create'] }])
    const change = createPreparedChange(preview, host)
    await change.submit(true)
    assert.equal((await change.recover()).state, conflict ? 'conflicted' : 'ready')
    assert.deepEqual(requests, ['commit', 'status', 'preview'])
    assert.equal((await change.submit(false)).state, conflict ? 'conflicted' : 'ready')
  }
})

test('failed preview revalidation remains unknown and a successful receipt needs no preview check', async() => {
  let checks = 0
  const host = {
    commitRecordChange: async() => { throw new Error('lost response') },
    getRecordChangeStatus: async() => ({ status: 'pending' }),
    restoreRecordChange: async() => { checks += 1; throw new Error('network') }
  }
  const change = createPreparedChange(preview, host)
  await change.submit(true)
  assert.equal((await change.recover()).state, 'unknown')
  host.getRecordChangeStatus = async() => ({ status: 'succeeded' })
  assert.equal((await change.recover()).state, 'succeeded')
  assert.equal(checks, 1)
})

test('generic mutation host fixes destinations/methods and rejects unregistered entities or arbitrary parameters', async() => {
  const calls = []
  const request = { post: async(...args) => { calls.push(args); return { data: preview } } }
  const adapter = createMutationHost(request, '/api/v1/manage/agent/operations', [{ entity: 'Content', actions: ['create'] }])
  await assert.rejects(adapter.prepareRecordChange({ entity: 'Category', action: 'delete' }), /not enabled/)
  await assert.rejects(adapter.prepareRecordChange({ entity: 'Content', action: 'create', url: '/other' }), /not enabled/)
  await adapter.prepareRecordChange({ entity: 'Content', action: 'create', changes: { title: 'Draft' } })
  assert.equal(calls[0][0], '/api/v1/manage/agent/operations')
  await adapter.commitRecordChange(preview.operationId)
  assert.deepEqual(calls[1][1], { confirmed: true })
  await assert.rejects(adapter.commitRecordChange('../records/7'), /Invalid operation/)
})
