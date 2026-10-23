import assert from 'node:assert/strict'
import test from 'node:test'
import {
  BatchRequestError,
  createBatchProposal,
  formatBatchError,
  inspectBatchHost,
  normalizeBatchCapabilities,
  normalizeBatchStatusReceipt,
  normalizePreparedBatch,
  prepareBatchRequest
} from '../src/import-workbench.js'
import { createBatchMutationHost } from '../src/batch-mutation-host.js'
import mutationCapabilities from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'

const OPERATION_ID = 'a'.repeat(64)
const STORE_UUID = '550e8400-e29b-41d4-a716-446655440001'
const OTHER_STORE_UUID = '550e8400-e29b-41d4-a716-446655440002'

const tagCapability = {
  entity: 'Tag',
  actions: ['create'],
  actionFields: {
    create: {
      name: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
      slug: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
      color: { type: 'color', nullable: true }
    }
  }
}

const counterCapability = {
  entity: 'Counter',
  actions: ['create'],
  actionFields: {
    create: {
      storeUuid: { type: 'uuid', requiredOnCreate: true },
      code: { type: 'string', maxLength: 50, requiredOnCreate: true, nonEmpty: true, trimForValidation: true },
      name: { type: 'string', maxLength: 120, requiredOnCreate: true, nonEmpty: true, trimForValidation: true },
      capacity: { type: 'integer', minimum: 1, maximum: 2147483647, nullable: true },
      sort: { type: 'integer', minimum: -2147483648, maximum: 2147483647 },
      enabled: { type: 'boolean' }
    }
  }
}

const BATCH_METHODS = {
  prepareRecordBatch() {},
  restoreRecordBatch() {},
  commitRecordBatch() {},
  getRecordBatchStatus() {},
  cancelRecordBatch() {}
}

const batchCapabilities = [tagCapability, counterCapability]

function normalizeCapabilities(capabilities = batchCapabilities) {
  const inspection = normalizeBatchCapabilities(capabilities)
  assert.equal(inspection.available, true)
  return inspection
}

function preparedPreview(input, overrides = {}) {
  const rows = input.rows.map((row, index) => ({
    row: index + 1,
    title: String(row.name ?? `Row ${index + 1}`),
    identifier: null,
    differences: Object.entries(row).map(([property, after]) => ({ property, before: null, after }))
  }))
  return {
    operationId: OPERATION_ID,
    entity: input.entity,
    action: 'create',
    title: `Create ${rows.length} ${input.entity} records`,
    affectedRecords: rows.length,
    expiresAt: new Date(Date.now() + 300000).toISOString(),
    rows,
    saved: false,
    ...overrides
  }
}

function previewFieldNames(preview) {
  const fields = new Set()
  if (Array.isArray(preview?.records)) {
    for (const record of preview.records) for (const property of Object.keys(record.values || {})) fields.add(property)
  } else {
    for (const row of preview?.rows || []) {
      for (const difference of row.differences || []) fields.add(difference.property)
    }
  }
  return [...fields]
}

function boundReceipt(preview, operationId, status, extra = {}) {
  return {
    operationId,
    entity: preview?.entity,
    action: 'create',
    status,
    rowCount: preview?.rowCount ?? preview?.affectedRecords ?? 0,
    fields: previewFieldNames(preview),
    expiresAt: preview?.expiresAt,
    ...extra
  }
}

function fakeHost(overrides = {}) {
  const calls = { prepare: [], restore: [], commit: [], status: [], cancel: [] }
  let lastPreview = null
  const host = {
    getBatchCreateCapabilities: () => batchCapabilities,
    async prepareRecordBatch(input) {
      calls.prepare.push(input)
      lastPreview = preparedPreview(input)
      return lastPreview
    },
    async restoreRecordBatch(operationId) {
      calls.restore.push(operationId)
      return lastPreview
    },
    async commitRecordBatch(operationId) {
      calls.commit.push(operationId)
      return boundReceipt(lastPreview, operationId, 'succeeded', { verified: true })
    },
    async getRecordBatchStatus(operationId) {
      calls.status.push(operationId)
      return boundReceipt(lastPreview, operationId, 'succeeded')
    },
    async cancelRecordBatch(operationId) {
      calls.cancel.push(operationId)
    },
    ...overrides
  }
  return { host, calls }
}

function request(input) {
  return prepareBatchRequest(input, normalizeCapabilities().entities)
}

async function readyProposal({ input, overrides = {} } = {}) {
  const { host, calls } = fakeHost(overrides)
  const model = createBatchProposal(host)
  const prepared = request(input || {
    entity: 'Tag',
    action: 'create',
    rows: [{ name: 'North', slug: 'north', color: '#112233' }]
  })
  const raw = await host.prepareRecordBatch(prepared.input)
  const preview = normalizePreparedBatch(raw, prepared)
  assert.equal(model.adopt(preview, prepared), true)
  assert.equal(model.state.phase, 'ready')
  return { model, calls, preview, prepared }
}

test('batch host inspection requires explicit batch capabilities and all five host methods', () => {
  assert.deepEqual(inspectBatchHost({}), { available: false, reason: 'host', entities: [], capabilities: [] })
  assert.equal(inspectBatchHost({ ...BATCH_METHODS }).reason, 'capabilities')
  assert.equal(inspectBatchHost({ ...BATCH_METHODS, getWriteCapabilities: () => batchCapabilities }).available, false)
  assert.equal(inspectBatchHost({ getBatchCreateCapabilities: () => batchCapabilities }).available, false)

  const inspected = inspectBatchHost({ ...BATCH_METHODS, getBatchCreateCapabilities: () => batchCapabilities })
  assert.equal(inspected.available, true)
  assert.deepEqual(inspected.entities.map(item => item.entity), ['Tag', 'Counter'])
  assert.deepEqual(inspected.entities[0].fields.map(field => field.property), ['name', 'slug', 'color'])

  const unsupported = inspectBatchHost({
    ...BATCH_METHODS,
    getBatchCreateCapabilities: () => [{ ...tagCapability, actionFields: { create: { name: { type: 'object' } } } }]
  })
  assert.equal(unsupported.available, false)
  assert.equal(unsupported.reason, 'capabilities')

  const missingStore = inspectBatchHost({
    ...BATCH_METHODS,
    getBatchCreateCapabilities: () => [{
      entity: 'Counter',
      actions: ['create'],
      actionFields: { create: { code: { type: 'string', requiredOnCreate: true } } }
    }]
  })
  assert.equal(missingStore.available, false)
})

test('model batch requests use only declared fields, canonical values and one explicit Counter store', () => {
  const tag = request({
    entity: 'Tag',
    action: 'create',
    rows: [{ name: 'North', slug: 'north', color: null }, { name: 'South', slug: 'south' }]
  })
  assert.deepEqual(tag.input, {
    entity: 'Tag',
    action: 'create',
    rows: [{ name: 'North', slug: 'north', color: null }, { name: 'South', slug: 'south' }]
  })
  assert.deepEqual(tag.records[0], { row: 1, values: { name: 'North', slug: 'north', color: null } })

  const counter = request({
    entity: 'Counter',
    action: 'create',
    rows: [
      { storeUuid: STORE_UUID.toUpperCase(), code: ' A01 ', name: ' Window ', capacity: 4, enabled: true },
      { storeUuid: STORE_UUID, code: ' A02 ', name: ' Door ' }
    ]
  })
  assert.deepEqual(counter.input.rows, [
    { storeUuid: STORE_UUID, code: 'A01', name: 'Window', capacity: 4, enabled: true },
    { storeUuid: STORE_UUID, code: 'A02', name: 'Door' }
  ])

  const rejects = [
    { entity: 'Tag', action: 'update', rows: [{ name: 'A', slug: 'a' }] },
    { entity: 'Product', action: 'create', rows: [{ name: 'A' }] },
    { entity: 'Tag', action: 'create', rows: [] },
    { entity: 'Tag', action: 'create', rows: Array.from({ length: 21 }, () => ({ name: 'A', slug: 'a' })) },
    { entity: 'Tag', action: 'create', rows: [{ name: 'A' }] },
    { entity: 'Tag', action: 'create', rows: [{ name: 'A', slug: 'a', unknown: true }] },
    { entity: 'Tag', action: 'create', rows: [{ name: 4, slug: 'a' }] },
    { entity: 'Tag', action: 'create', rows: [{ name: 'A', slug: 'a', color: 'red' }] },
    { entity: 'Counter', action: 'create', rows: [{ storeUuid: STORE_UUID, code: 'A', name: 'A' }, { storeUuid: OTHER_STORE_UUID, code: 'B', name: 'B' }] },
    { entity: 'Counter', action: 'create', rows: [{ storeUuid: 'not-a-uuid', code: 'A', name: 'A' }] },
    { entity: 'Counter', action: 'create', rows: [{ storeUuid: STORE_UUID, code: 'A', name: 'A', capacity: 0 }] },
    { entity: 'Tag', action: 'create', rows: [{ name: 'A', slug: 'a' }], extra: true }
  ]
  for (const input of rejects) {
    assert.throws(() => request(input), error => {
      assert.ok(error instanceof BatchRequestError)
      assert.equal(error.failureType, 'validation')
      return true
    })
  }
  assert.throws(() => request(JSON.parse('{"entity":"Tag","action":"create","rows":[{"name":"A","slug":"a","__proto__":{"polluted":true}}]}')), BatchRequestError)
})

test('prepared preview validation requires full canonical differences that match the request', () => {
  const expected = request({
    entity: 'Tag',
    action: 'create',
    rows: [{ name: 'North', slug: 'north' }]
  })
  const valid = preparedPreview(expected.input)
  assert.equal(normalizePreparedBatch(valid, expected).rowCount, 1)
  assert.throws(() => normalizePreparedBatch({ ...valid, saved: true }, expected), /not be saved/)
  assert.throws(() => normalizePreparedBatch({ ...valid, affectedRecords: 2 }, expected), /row count/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], identifier: '1' }] }, expected), /target identifier/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], differences: [{ property: 'name', before: null, after: 'North' }] }] }, expected), /does not match/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], differences: [...valid.rows[0].differences, { property: 'color', before: null, after: '#112233' }] }] }, expected), /does not match/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], differences: [{ property: 'name', before: 'old', after: 'North' }, valid.rows[0].differences[1]] }] }, expected), /null before/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], differences: [{ property: 'slug', before: null, after: 'north' }, { property: 'name', before: null, after: 'Changed' }] }] }, expected), /does not match/)
})

test('commit requires the explicit human checkbox and a verified success receipt', async() => {
  const { model, calls } = await readyProposal()
  assert.equal(await model.commit(), false)
  assert.equal(calls.commit.length, 0)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(model.blocksNewTask(), false)
  assert.equal(model.blocksWrites(), true)
  assert.equal(await model.commit(), true)
  assert.equal(model.state.phase, 'succeeded')
  assert.equal(model.state.confirmed, false)
  assert.equal(model.state.receipt.status, 'succeeded')
  assert.deepEqual(calls.commit, [OPERATION_ID])
  assert.equal(await model.commit(), false)
  assert.equal(calls.commit.length, 1)
})

test('unknown commit outcome blocks new tasks and writes, and status can only restore the same proposal', async() => {
  let commitCalls = 0
  let statusCalls = 0
  const statuses = ['pending']
  const { host, calls } = fakeHost({
    async commitRecordBatch(operationId) {
      commitCalls += 1
      return boundReceipt(model.state.preview, operationId, 'unknown')
    },
    async getRecordBatchStatus(operationId) {
      statusCalls += 1
      return boundReceipt(model.state.preview, operationId, statuses.shift())
    }
  })
  const model = createBatchProposal(host)
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  assert.equal(model.adopt(normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared), prepared), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'unknown')
  assert.equal(model.blocksNewTask(), true)
  assert.equal(model.blocksWrites(), true)
  assert.equal(await model.commit(), false)
  assert.equal(commitCalls, 1)
  assert.equal(model.reset(), false)

  assert.equal(await model.checkOutcome(), true)
  assert.equal(statusCalls, 1)
  assert.equal(calls.restore.length, 1)
  assert.equal(model.state.phase, 'ready')
  assert.equal(model.state.restored, true)
  assert.equal(model.state.confirmed, false)
  assert.equal(model.state.preview.operationId, OPERATION_ID)
  assert.equal(commitCalls, 1)
  assert.equal(calls.prepare.length, 1)
})

test('ambiguous commit errors become unknown and are never retried automatically', async() => {
  let commitCalls = 0
  const { host } = fakeHost({
    async commitRecordBatch() {
      commitCalls += 1
      throw new Error('Sensitive transport error')
    }
  })
  const model = createBatchProposal(host)
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  assert.equal(model.adopt(normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared), prepared), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'unknown')
  assert.equal(commitCalls, 1)
  assert.equal(formatBatchError(key => key, model.state.errors[0]).includes('Sensitive'), false)
  assert.equal(await model.commit(), false)
  assert.equal(commitCalls, 1)
})

test('an explicit proposal conflict ends the batch without status recovery or retry', async() => {
  let statusCalls = 0
  const { host } = fakeHost({
    async commitRecordBatch() {
      const error = new Error('Conflict')
      error.response = { status: 409, data: { error: 'proposal_conflict' } }
      throw error
    },
    async getRecordBatchStatus() {
      statusCalls += 1
      return { status: 'succeeded' }
    }
  })
  const model = createBatchProposal(host)
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  assert.equal(model.adopt(normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared), prepared), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'conflicted')
  assert.equal(await model.checkOutcome(), false)
  assert.equal(statusCalls, 0)
  assert.equal(model.blocksWrites(), false)
})

test('an expired preview is not committed and lets the user adopt a fresh batch', async() => {
  let current = Date.now()
  let commitCalls = 0
  const { host } = fakeHost({
    async prepareRecordBatch(input) {
      return preparedPreview(input, { expiresAt: new Date(current + 1000).toISOString() })
    },
    async commitRecordBatch() {
      commitCalls += 1
      return { status: 'succeeded' }
    }
  })
  const model = createBatchProposal(host, { now: () => current })
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  assert.equal(model.adopt(normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared), prepared), true)
  current += 2000
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'expired')
  assert.equal(model.state.preview, null)
  assert.equal(commitCalls, 0)

  const refreshed = request({ entity: 'Tag', action: 'create', rows: [{ name: 'South', slug: 'south' }] })
  assert.equal(model.adopt(normalizePreparedBatch(await host.prepareRecordBatch(refreshed.input), refreshed), refreshed), true)
  assert.equal(model.state.phase, 'ready')
})

test('discard cancels a pending proposal and reset forces cleanup of unknown outcomes', async() => {
  const { model, calls } = await readyProposal()
  assert.equal(model.discard(), true)
  assert.equal(model.state.phase, 'idle')
  assert.deepEqual(calls.cancel, [OPERATION_ID])

  const unknownHost = fakeHost({ async commitRecordBatch() { return { status: 'unknown' } } })
  const unknown = createBatchProposal(unknownHost.host)
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  unknown.adopt(normalizePreparedBatch(await unknownHost.host.prepareRecordBatch(prepared.input), prepared), prepared)
  unknown.setConfirmed(true)
  await unknown.commit()
  assert.equal(unknown.reset(), false)
  assert.equal(unknown.reset({ force: true }), true)
  assert.equal(unknown.state.phase, 'idle')
})

test('invalidate cancels only supersedable proposals and dispose releases everything', async() => {
  const { model, calls } = await readyProposal()
  assert.equal(model.invalidate(), true)
  assert.equal(model.state.phase, 'invalidated')
  assert.equal(model.state.preview, null)
  assert.deepEqual(calls.cancel, [OPERATION_ID])
  assert.equal(model.invalidate(), true)

  const { host, calls: disposedCalls } = fakeHost()
  const disposed = createBatchProposal(host)
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  disposed.adopt(normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared), prepared)
  disposed.dispose()
  assert.equal(disposed.state.phase, 'idle')
  assert.equal(disposed.state.preview, null)
  assert.deepEqual(disposedCalls.cancel, [OPERATION_ID])
  assert.equal(disposed.adopt(prepared, prepared), false)
})

test('batch receipts must match the retained canonical preview before any status applies', async() => {
  const { preview } = await readyProposal()
  const receipt = {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    status: 'pending',
    rowCount: 1,
    fields: ['name', 'slug', 'color'],
    expiresAt: preview.expiresAt
  }
  assert.equal(normalizeBatchStatusReceipt(receipt, preview), 'pending')
  assert.equal(normalizeBatchStatusReceipt({ ...receipt, fields: ['slug', 'name', 'color'] }, preview), 'pending')
  for (const status of ['succeeded', 'cancelled', 'expired', 'conflicted']) {
    assert.equal(normalizeBatchStatusReceipt({ ...receipt, status }, preview), status)
  }
  const mismatches = [
    { ...receipt, operationId: 'b'.repeat(64) },
    { ...receipt, entity: 'Counter' },
    { ...receipt, action: 'update' },
    { ...receipt, rowCount: 2 },
    { ...receipt, rowCount: '1' },
    { ...receipt, fields: ['name', 'slug'] },
    { ...receipt, fields: ['name', 'slug', 'color', 'sort'] },
    { ...receipt, fields: ['name', 'name', 'color'] },
    { ...receipt, fields: ['name', 'slug', 'sort'] },
    { ...receipt, expiresAt: '2026-10-23T12:01:00+00:00' }
  ]
  for (const mismatch of mismatches) {
    assert.equal(normalizeBatchStatusReceipt(mismatch, preview), 'unknown')
  }
  assert.equal(normalizeBatchStatusReceipt({ ...receipt, status: 'failed' }, preview), 'unknown')
  assert.equal(normalizeBatchStatusReceipt(receipt, null), 'unknown')
  assert.equal(normalizeBatchStatusReceipt({ ...receipt, operationId: undefined }, preview), 'unknown')
})

test('unverified success and mismatched receipt metadata stay unknown and never retry', async() => {
  let commitCalls = 0
  let statusCalls = 0
  const { host } = fakeHost({
    async commitRecordBatch(operationId) {
      commitCalls += 1
      return boundReceipt(model.state.preview, operationId, 'succeeded', { verified: false })
    },
    async getRecordBatchStatus(operationId) {
      statusCalls += 1
      return boundReceipt(model.state.preview, operationId, 'succeeded')
    }
  })
  const model = createBatchProposal(host)
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  assert.equal(model.adopt(normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared), prepared), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'unknown')
  assert.equal(model.blocksNewTask(), true)
  assert.equal(await model.commit(), false)
  assert.equal(commitCalls, 1)
  assert.equal(statusCalls, 0)
  assert.equal(await model.checkOutcome(), true)
  assert.equal(statusCalls, 1)
  assert.equal(model.state.phase, 'succeeded')
  assert.equal(model.blocksNewTask(), false)

  let mismatchedCommitCalls = 0
  let mismatchedStatusCalls = 0
  const mismatchedHost = fakeHost({
    async commitRecordBatch(operationId) {
      mismatchedCommitCalls += 1
      return {
        ...boundReceipt(mismatched.state.preview, operationId, 'succeeded', { verified: true }),
        entity: 'Counter',
        rowCount: 2,
        fields: ['storeUuid']
      }
    },
    async getRecordBatchStatus() {
      mismatchedStatusCalls += 1
      throw new Error('A mismatched receipt must not trigger an automatic status request.')
    }
  })
  const mismatched = createBatchProposal(mismatchedHost.host)
  const other = request({ entity: 'Tag', action: 'create', rows: [{ name: 'South', slug: 'south' }] })
  mismatched.adopt(normalizePreparedBatch(await mismatchedHost.host.prepareRecordBatch(other.input), other), other)
  assert.equal(mismatched.setConfirmed(true), true)
  assert.equal(await mismatched.commit(), false)
  assert.equal(mismatched.state.phase, 'unknown')
  assert.equal(mismatched.blocksNewTask(), true)
  assert.equal(mismatchedCommitCalls, 1)
  assert.equal(mismatchedStatusCalls, 0)
  assert.match(formatBatchError(key => key, mismatched.state.errors[0]), /outcome is unknown/)
})

test('mismatched status receipts never restore, and a later matching status releases the lock', async() => {
  let statusCalls = 0
  let restoreCalls = 0
  const { host } = fakeHost({
    async commitRecordBatch() {
      throw new Error('Ambiguous transport failure')
    },
    async getRecordBatchStatus(operationId) {
      statusCalls += 1
      const preview = model.state.preview
      if (statusCalls === 1) return boundReceipt(preview, operationId, 'pending', { fields: ['slug'] })
      if (statusCalls === 2) return boundReceipt(preview, operationId, 'succeeded', { expiresAt: '2026-10-23T12:30:00+00:00' })
      return boundReceipt(preview, operationId, 'succeeded')
    },
    async restoreRecordBatch() {
      restoreCalls += 1
      throw new Error('A mismatched pending receipt must not restore.')
    }
  })
  const model = createBatchProposal(host)
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
  assert.equal(model.adopt(normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared), prepared), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'unknown')

  assert.equal(await model.checkOutcome(), false)
  assert.equal(model.state.phase, 'unknown')
  assert.equal(restoreCalls, 0)
  assert.equal(await model.checkOutcome(), false)
  assert.equal(model.state.phase, 'unknown')
  assert.equal(restoreCalls, 0)
  assert.equal(model.blocksNewTask(), true)

  assert.equal(await model.checkOutcome(), true)
  assert.equal(statusCalls, 3)
  assert.equal(restoreCalls, 0)
  assert.equal(model.state.phase, 'succeeded')
  assert.equal(model.blocksNewTask(), false)
})

test('matching cancelled and expired status receipts release the lock without saving', async() => {
  for (const status of ['cancelled', 'expired']) {
    let statusCalls = 0
    const { host } = fakeHost({
      async commitRecordBatch() {
        throw new Error('Ambiguous transport failure')
      },
      async getRecordBatchStatus(operationId) {
        statusCalls += 1
        return boundReceipt(model.state.preview, operationId, status)
      }
    })
    const model = createBatchProposal(host)
    const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'North', slug: 'north' }] })
    assert.equal(model.adopt(normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared), prepared), true)
    assert.equal(model.setConfirmed(true), true)
    assert.equal(await model.commit(), false)
    assert.equal(model.state.phase, 'unknown')
    assert.equal(await model.checkOutcome(), false)
    assert.equal(statusCalls, 1)
    assert.equal(model.state.phase, status)
    assert.equal(model.state.preview, null)
    assert.equal(model.blocksNewTask(), false)
  }
})

test('a newer proposal cancels the older pending batch before the card is replaced', async() => {
  const { model, calls } = await readyProposal()
  const prepared = request({ entity: 'Tag', action: 'create', rows: [{ name: 'South', slug: 'south' }] })
  const raw = preparedPreview(prepared.input, { operationId: 'b'.repeat(64) })
  const preview = normalizePreparedBatch(raw, prepared)
  assert.equal(model.adopt(preview, prepared), true)
  assert.deepEqual(calls.cancel, [OPERATION_ID])
  assert.equal(model.state.preview.operationId, 'b'.repeat(64))
  assert.equal(model.state.confirmed, false)
  assert.equal(model.state.restored, false)
})

test('the coordinator batch transport accepts the model request and canonical backend preview', async() => {
  const posted = []
  const preview = {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    title: 'Create 1 Tag record',
    affectedRecords: 1,
    expiresAt: `${new Date(Date.now() + 300000).toISOString().slice(0, 19)}+00:00`,
    rows: [{
      row: 1,
      title: 'North',
      identifier: null,
      differences: [
        { property: 'name', before: null, after: 'North' },
        { property: 'slug', before: null, after: 'north' },
        { property: 'color', before: null, after: '#112233' }
      ]
    }],
    saved: false
  }
  const host = createBatchMutationHost({
    async post(path, body) {
      posted.push({ path, body })
      return { data: preview }
    },
    async get() {
      throw new Error('Unexpected GET in this test.')
    }
  }, '/api/v1/manage/agent/batch-operations', mutationCapabilities)
  const inspected = inspectBatchHost(host)
  assert.equal(inspected.available, true)
  const prepared = prepareBatchRequest({
    entity: 'Tag',
    action: 'create',
    rows: [{ name: 'North', slug: 'north', color: '#112233' }]
  }, inspected.entities)
  const normalized = normalizePreparedBatch(await host.prepareRecordBatch(prepared.input), prepared)
  assert.deepEqual(posted[0].body, {
    entity: 'Tag',
    action: 'create',
    rows: [{ name: 'North', slug: 'north', color: '#112233' }]
  })
  const model = createBatchProposal(host)
  assert.equal(model.adopt(normalized, prepared), true)
  assert.equal(model.state.phase, 'ready')
  assert.deepEqual(model.state.preview.records[0].values, { name: 'North', slug: 'north', color: '#112233' })
})
