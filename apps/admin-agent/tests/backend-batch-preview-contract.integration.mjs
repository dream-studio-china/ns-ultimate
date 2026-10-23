import test from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import capabilities from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import { createBatchMutationHost } from '../src/batch-mutation-host.js'

const root = fileURLToPath(new URL('../../..', import.meta.url))
const fixture = path.join(root, 'apps/admin-agent/backend/tests/Contract/export-batch-previews.php')
const operationPath = '/batch-operations'
const operationIdPattern = /^[a-f0-9]{64}$/
const identifierPattern = /^[1-9]\d{0,14}$/
const previewKeys = ['operationId', 'entity', 'action', 'title', 'affectedRecords', 'expiresAt', 'rows', 'saved']
const previewRowKeys = ['row', 'title', 'identifier', 'differences']
const differenceKeys = ['property', 'before', 'after']
const receiptKeys = ['operationId', 'entity', 'action', 'status', 'rowCount', 'fields', 'results', 'createdAt', 'expiresAt', 'completedAt', 'verified']
const settledReceiptKeys = receiptKeys.filter(key => key !== 'verified')
const fieldOrder = {
  Tag: ['name', 'slug', 'color'],
  Counter: ['storeUuid', 'code', 'name', 'capacity', 'sort', 'enabled']
}
const knownEntities = ['Counter', 'Tag']

test('real backend batch previews and receipts round-trip through the batch host transport', async () => {
  const stdout = execFileSync('php', [fixture], {
    cwd: root, timeout: 60_000, maxBuffer: 4 * 1024 * 1024,
    env: { ...process.env, APP_ENV: 'test', APP_DEBUG: '0' },
    encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']
  })
  const exported = JSON.parse(stdout)
  assert.ok(exported && typeof exported === 'object' && !Array.isArray(exported), 'PHP fixture exporter must return an object')
  assert.deepEqual(Object.keys(exported).sort(), ['cancel', 'cases', 'recent', 'rollback'].sort())
  assert.ok(Array.isArray(exported.cases) && exported.cases.length === 2, 'The fixture must export exactly two batch cases')

  const previews = new Map()
  const statuses = new Map()
  const committed = new Map()
  const restored = new Map()
  const valueCorpus = []

  for (const entry of exported.cases) {
    const { entity, input, prepared, restored: restoredPreview } = entry
    assert.ok(knownEntities.includes(entity), `Unexpected batch entity: ${entity}`)
    assert.deepEqual(Object.keys(input).sort(), ['action', 'entity', 'rows'].sort(), `${entity} batch input shape changed`)
    assert.equal(input.entity, entity)
    assert.equal(input.action, 'create')
    assert.ok(Array.isArray(input.rows) && input.rows.length >= 2, `${entity} must exercise a multiple-row batch`)
    assert.ok(input.rows.every(row => isPlainRecord(row) && Object.keys(row).length > 0), `${entity} rows must be field objects`)
    for (const row of input.rows) {
      for (const value of Object.values(row)) {
        if (typeof value === 'string' && value.length >= 3) valueCorpus.push(value)
      }
    }

    assertPreview(prepared, entity, input.rows)
    assert.deepEqual(restoredPreview, prepared, `${entity} restore must return the prepared canonical preview unchanged`)
    assertPreview(restoredPreview, entity, input.rows)

    assertReceipt(entry.receipt, {
      entity,
      preview: prepared,
      keys: receiptKeys,
      verified: true
    })
    assertReceipt(entry.replay, {
      entity,
      preview: prepared,
      keys: receiptKeys,
      verified: true
    })
    assertReceipt(entry.status, {
      entity,
      preview: prepared,
      keys: settledReceiptKeys,
      verified: false
    })
    assert.deepEqual(entry.replay, entry.receipt, `${entity} replay must return the exact first commit receipt, including verified`)
    assert.deepEqual(entry.replay.results, entry.receipt.results, `${entity} replay must reuse the same ordered identifiers`)
    const settledReplay = { ...entry.replay }
    delete settledReplay.verified
    assert.deepEqual(entry.status, settledReplay, `${entity} status must equal the settled replay receipt without the commit marker`)

    assert.deepEqual(
      entry.receipt.fields,
      expectedFields(entity, input.rows),
      `${entity} receipt fields must follow canonical first-seen field order`
    )
    assert.deepEqual(
      entry.receipt.results.map(result => result.row),
      input.rows.map((_, index) => index + 1),
      `${entity} receipt results must preserve row order`
    )

    assertPersistence(entry.persistence, entity, input.rows, entry.receipt.results)

    const receiptText = JSON.stringify([entry.receipt, entry.replay, entry.status])
    for (const value of input.rows.flatMap(row => Object.values(row)).filter(value => typeof value === 'string')) {
      assert.equal(receiptText.includes(value), false, `${entity} receipt leaked a proposal value: ${value}`)
    }

    previews.set(prepared.operationId, prepared)
    restored.set(prepared.operationId, restoredPreview)
    statuses.set(prepared.operationId, entry.status)
    committed.set(prepared.operationId, [entry.receipt, entry.replay])
  }

  const previewEntities = [...new Set(exported.cases.map(entry => entry.prepared.entity))].sort()
  assert.deepEqual(previewEntities, knownEntities, 'Only Tag and Counter may be exposed as batch capabilities')

  assertRollback(exported.rollback)
  assertCancel(exported.cancel)
  assertRecent(exported.recent, exported, valueCorpus)

  await exerciseHost(exported, { previews, restored, statuses, committed })
})

function assertPreview(preview, entity, rows) {
  assert.ok(isPlainRecord(preview), `${entity} preview must be an object`)
  assert.deepEqual(Object.keys(preview), previewKeys, `${entity} preview envelope shape changed`)
  assert.match(preview.operationId, operationIdPattern, `${entity} preview operation identifier is invalid`)
  assert.equal(preview.entity, entity)
  assert.equal(preview.action, 'create')
  assert.equal(preview.saved, false, `${entity} preview must not be saved`)
  assert.equal(preview.affectedRecords, rows.length, `${entity} preview affectedRecords mismatch`)
  assert.equal(preview.title, `Create ${rows.length} ${entity} record${rows.length === 1 ? '' : 's'}`)
  assert.ok(Number.isFinite(Date.parse(preview.expiresAt)), `${entity} preview expiry is invalid`)
  assert.ok(Array.isArray(preview.rows) && preview.rows.length === rows.length, `${entity} preview rows mismatch`)

  const orderedFields = fieldOrder[entity]
  preview.rows.forEach((row, index) => {
    const input = rows[index]
    assert.deepEqual(Object.keys(row), previewRowKeys, `${entity} preview row shape changed`)
    assert.equal(row.row, index + 1, `${entity} preview row order changed`)
    assert.equal(row.identifier, null, `${entity} prepared rows must not have target identifiers`)
    assert.equal(row.title, input.name, `${entity} preview row title must show the canonical name`)
    assert.ok(Array.isArray(row.differences) && row.differences.length > 0, `${entity} preview row requires differences`)
    assert.deepEqual(
      row.differences.map(difference => difference.property),
      orderedFields.filter(field => Object.hasOwn(input, field)),
      `${entity} preview row differences must follow canonical field order and omit defaulted fields`
    )
    const seen = new Set()
    for (const difference of row.differences) {
      assert.deepEqual(Object.keys(difference), differenceKeys, `${entity} preview difference shape changed`)
      assert.equal(seen.has(difference.property), false, `${entity} preview duplicated a field`)
      seen.add(difference.property)
      assert.equal(difference.before, null, `${entity} create previews must have a null before value`)
      assert.deepEqual(difference.after, input[difference.property], `${entity} preview after value must match the canonical input`)
    }
  })

  if (entity === 'Tag') {
    assert.equal(preview.rows[1].differences.some(difference => difference.property === 'color'), false, 'An omitted Tag color must stay defaulted, not invented')
    assert.equal(preview.rows[2].differences.find(difference => difference.property === 'color').after, null, 'An explicit null Tag color must remain a changed field')
  }
  if (entity === 'Counter') {
    assert.deepEqual(
      preview.rows[0].differences.map(difference => difference.property),
      ['storeUuid', 'code', 'name'],
      'Omitted Counter defaults must not be materialized in the preview'
    )
    assert.equal(preview.rows[2].differences.find(difference => difference.property === 'enabled').after, false)
  }
}

function assertReceipt(receipt, { entity, preview, keys, verified }) {
  assert.ok(isPlainRecord(receipt), `${entity} receipt must be an object`)
  assert.deepEqual(Object.keys(receipt), keys, `${entity} receipt shape changed`)
  assert.equal(receipt.operationId, preview.operationId)
  assert.equal(receipt.entity, entity)
  assert.equal(receipt.action, 'create')
  assert.equal(receipt.status, 'succeeded')
  assert.equal(receipt.rowCount, preview.rows.length)
  assert.equal(Object.hasOwn(receipt, 'verified'), verified)
  if (verified) assert.equal(receipt.verified, true)
  assert.ok(Array.isArray(receipt.fields) && receipt.fields.every(field => typeof field === 'string'))
  assert.ok(Array.isArray(receipt.results) && receipt.results.length === preview.rows.length)
  for (const [index, result] of receipt.results.entries()) {
    assert.deepEqual(Object.keys(result), ['row', 'identifier'], `${entity} receipt result shape changed`)
    assert.equal(result.row, index + 1, `${entity} receipt row order changed`)
    assert.match(result.identifier, identifierPattern, `${entity} receipt identifier is invalid`)
  }
  assert.ok(Number.isFinite(Date.parse(receipt.createdAt)))
  assert.ok(Number.isFinite(Date.parse(receipt.expiresAt)))
  assert.ok(Number.isFinite(Date.parse(receipt.completedAt)))
  assert.equal(receipt.expiresAt, preview.expiresAt)
  assert.equal(Object.hasOwn(receipt, 'rows'), false, `${entity} receipt must not replay proposal rows`)
  assert.equal(Object.hasOwn(receipt, 'differences'), false, `${entity} receipt must not replay proposal values`)
}

function assertPersistence(persistence, entity, rows, results) {
  assert.ok(isPlainRecord(persistence) && isPlainRecord(persistence.counts), `${entity} persistence evidence is missing`)
  const { counts } = persistence
  assert.deepEqual(counts.afterPrepare, counts.beforePrepare, `${entity} prepare wrote domain rows`)
  assert.deepEqual(counts.afterRestore, counts.beforePrepare, `${entity} restore wrote domain rows`)
  const other = entity === 'Tag' ? 'Counter' : 'Tag'
  assert.equal(counts.afterCommit[entity], counts.beforePrepare[entity] + rows.length, `${entity} commit must create each row exactly once`)
  assert.equal(counts.afterCommit[other], counts.beforePrepare[other], `${entity} commit changed unrelated ${other} rows`)
  assert.deepEqual(counts.afterReplay, counts.afterCommit, `${entity} idempotent replay changed domain rows`)

  assert.ok(Array.isArray(persistence.rows) && persistence.rows.length === rows.length, `${entity} persistence rows are incomplete`)
  persistence.rows.forEach((record, index) => {
    assert.deepEqual(Object.keys(record), ['row', 'identifier', 'found', 'values'], `${entity} persistence row shape changed`)
    assert.equal(record.row, index + 1)
    assert.equal(record.found, true, `${entity} committed row ${index + 1} was not persisted`)
    assert.equal(record.identifier, results[index].identifier, `${entity} persisted row identifier mismatch`)
    assert.deepEqual(record.values, expectedPersistedValues(entity, rows[index]), `${entity} persisted defaults or values changed`)
  })
}

function expectedPersistedValues(entity, row) {
  if (entity === 'Tag') {
    return { name: row.name, slug: row.slug, color: row.color ?? null }
  }
  return {
    storeUuid: row.storeUuid,
    code: row.code,
    name: row.name,
    capacity: row.capacity ?? null,
    sort: row.sort ?? 0,
    enabled: row.enabled ?? true
  }
}

function expectedFields(entity, rows) {
  return fieldOrder[entity].filter(field => rows.some(row => Object.hasOwn(row, field)))
}

function assertRollback(rollback) {
  assert.ok(isPlainRecord(rollback), 'Rollback evidence is missing')
  assert.equal(rollback.rejected, true, 'A conflicting row must reject the whole batch')
  assert.equal(rollback.error, 'NsUltimate\\Apps\\AdminAgent\\Backend\\Exception\\ProposalConflictException')
  assert.equal(rollback.rowsRetained, 3, 'A rolled-back batch must retain its pending proposal rows')
  assert.deepEqual(rollback.proposalRowCounts, [0, 0, 0], 'A rolled-back batch created proposal rows')
  assert.equal(rollback.counts.after.Tag, rollback.counts.before.Tag + 1, 'Rollback must leave only the external claim')
  assert.equal(rollback.counts.after.Counter, rollback.counts.before.Counter, 'Rollback changed unrelated rows')
  assert.equal(rollback.operation.status, 'pending')
  assert.equal(rollback.operation.action, 'create')
  assert.equal(rollback.operation.entity, 'Tag')
  assert.equal(rollback.operation.rowCount, 3)
  assert.deepEqual(rollback.operation.results, [])
  assert.equal(rollback.operation.completedAt, null)
  assert.deepEqual(Object.keys(rollback.operation), settledReceiptKeys)
  assert.deepEqual(rollback.operation.fields, ['name', 'slug'])

  const metadata = JSON.stringify([
    rollback.operation, rollback.proposalRowCounts, rollback.counts,
    rollback.error, rollback.rejected, rollback.rowsRetained
  ])
  for (const value of rollback.input.rows.flatMap(row => Object.values(row))) {
    if (typeof value === 'string') assert.equal(metadata.includes(value), false, `Rollback metadata leaked a proposal value: ${value}`)
  }
}

function assertCancel(cancel) {
  assert.ok(isPlainRecord(cancel), 'Cancellation evidence is missing')
  assert.equal(cancel.rowsErased, true, 'Cancellation must erase the proposal rows')
  assert.equal(cancel.createdRows, 0, 'Cancellation must not create domain rows')
  assert.deepEqual(Object.keys(cancel.receipt), settledReceiptKeys)
  assert.equal(cancel.receipt.status, 'cancelled')
  assert.equal(cancel.receipt.action, 'create')
  assert.equal(cancel.receipt.entity, 'Tag')
  assert.equal(cancel.receipt.rowCount, 2)
  assert.deepEqual(cancel.receipt.results, [])
  assert.deepEqual(cancel.receipt.fields, ['name', 'slug'])

  const metadata = JSON.stringify([cancel.receipt, cancel.rowsErased, cancel.createdRows])
  for (const value of cancel.input.rows.flatMap(row => Object.values(row))) {
    if (typeof value === 'string') assert.equal(metadata.includes(value), false, `Cancellation metadata leaked a proposal value: ${value}`)
  }
}

function assertRecent(recent, exported, valueCorpus) {
  assert.ok(isPlainRecord(recent), 'Recent batch metadata is missing')
  assert.deepEqual(Object.keys(recent), ['items', 'page', 'hasMore'])
  assert.equal(recent.page, 1)
  assert.equal(recent.hasMore, false)
  assert.ok(Array.isArray(recent.items) && recent.items.length >= 4)
  const ids = new Set()
  for (const item of recent.items) {
    assert.deepEqual(Object.keys(item), settledReceiptKeys)
    assert.match(item.operationId, operationIdPattern)
    assert.equal(ids.has(item.operationId), false, 'Recent batch metadata repeated an operation')
    ids.add(item.operationId)
    assert.ok(knownEntities.includes(item.entity))
    assert.equal(item.action, 'create')
    assert.ok(['pending', 'succeeded', 'cancelled', 'expired'].includes(item.status))
  }
  for (const entry of exported.cases) assert.ok(ids.has(entry.prepared.operationId), 'Recent metadata omitted a committed batch')
  assert.ok(ids.has(exported.rollback.operation.operationId), 'Recent metadata omitted a pending batch')
  assert.ok(ids.has(exported.cancel.receipt.operationId), 'Recent metadata omitted a cancelled batch')

  const serialized = JSON.stringify(recent)
  for (const value of valueCorpus) assert.equal(serialized.includes(value), false, `Recent metadata leaked a proposal value: ${value}`)
}

async function exerciseHost(exported, { previews, restored, statuses, committed }) {
  const requests = []
  const prepareQueue = exported.cases.map(entry => entry.prepared)
  const preparedInputs = exported.cases.map(entry => entry.input)
  const cancellations = new Map([[exported.cancel.receipt.operationId, exported.cancel.receipt]])

  const match = (url, suffix) => {
    const escaped = escapeRegExp(operationPath)
    const result = new RegExp(`^${escaped}/([a-f0-9]{64})/${suffix}$`).exec(url)
    return result ? result[1] : null
  }

  const request = {
    async post(url, body, options) {
      requests.push({ method: 'post', url, body, options })
      if (url === operationPath) {
        const prepared = prepareQueue.shift()
        assert.ok(prepared, 'Unexpected prepare request')
        return { data: prepared }
      }
      const commitId = match(url, 'commit')
      if (commitId) {
        const receipt = committed.get(commitId)?.shift()
        assert.ok(receipt, `Unexpected commit request for ${commitId}`)
        return { data: receipt }
      }
      const cancelId = match(url, 'cancel')
      if (cancelId) {
        const receipt = cancellations.get(cancelId)
        assert.ok(receipt, `Unexpected cancel request for ${cancelId}`)
        return { data: receipt }
      }
      throw new Error(`Unexpected batch transport POST route: ${url}`)
    },
    async get(url, options) {
      requests.push({ method: 'get', url, options })
      if (url === operationPath) return { data: exported.recent }
      const restoreId = match(url, 'preview')
      if (restoreId) {
        assert.ok(restored.has(restoreId), `Unexpected restore request for ${restoreId}`)
        return { data: restored.get(restoreId) }
      }
      const result = new RegExp(`^${escapeRegExp(operationPath)}/([a-f0-9]{64})$`).exec(url)
      if (result) {
        assert.ok(statuses.has(result[1]), `Unexpected status request for ${result[1]}`)
        return { data: statuses.get(result[1]) }
      }
      throw new Error(`Unexpected batch transport GET route: ${url}`)
    }
  }

  const host = createBatchMutationHost(request, operationPath, capabilities)
  for (const entry of exported.cases) {
    const id = entry.prepared.operationId
    assert.deepEqual(await host.prepareRecordBatch(entry.input), previews.get(id))
    assert.deepEqual(await host.restoreRecordBatch(id), restored.get(id))
    assert.deepEqual(await host.getRecordBatchStatus(id), statuses.get(id))
    assert.deepEqual(await host.commitRecordBatch(id), entry.receipt)
    assert.deepEqual(await host.commitRecordBatch(id), entry.replay)
  }
  assert.deepEqual(await host.cancelRecordBatch(exported.cancel.receipt.operationId), exported.cancel.receipt)
  assert.deepEqual(await host.listRecordBatches(), exported.recent)

  const prepareRequests = requests.filter(item => item.method === 'post' && item.url === operationPath)
  assert.deepEqual(prepareRequests.map(item => item.body), preparedInputs, 'The host must submit the exact backend batch input')
  const commitRequests = requests.filter(item => item.method === 'post' && match(item.url, 'commit'))
  assert.equal(commitRequests.length, exported.cases.length * 2, 'The host must send one commit request per commit and replay call')
  for (const item of commitRequests) assert.deepEqual(item.body, { confirmed: true }, 'The host must send only explicit confirmation on commit')
  const cancelRequests = requests.filter(item => item.method === 'post' && match(item.url, 'cancel'))
  assert.equal(cancelRequests.length, 1, 'The host must send one cancellation request')
  assert.ok(cancelRequests[0].body === undefined || Object.keys(cancelRequests[0].body).length === 0, 'Cancellation must not resend proposal payloads')
  assert.equal(requests.filter(item => item.method === 'get' && item.url === operationPath).length, 1, 'The host must expose one bounded recent request')
  assert.equal(requests.filter(item => item.method === 'post' && item.url !== operationPath && !match(item.url, 'commit') && !match(item.url, 'cancel')).length, 0, 'The host must not post to unowned routes')
}

function isPlainRecord(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const prototype = Object.getPrototypeOf(value)
  return (prototype === Object.prototype || prototype === null)
    && Reflect.ownKeys(value).every(key => typeof key === 'string')
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
