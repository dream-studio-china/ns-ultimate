import assert from 'node:assert/strict'
import test from 'node:test'
import {
  createImportBatch,
  formatImportError,
  inspectImportHost,
  normalizeBatchStatusReceipt,
  normalizeParsedImport,
  normalizePreparedBatch,
  validateImportMapping,
  validateImportValue,
  buildImportBatchInput,
  ImportWorkbenchError
} from '../src/import-workbench.js'
import { createBatchMutationHost } from '../src/batch-mutation-host.js'
import mutationCapabilities from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'

const OPERATION_ID = 'a'.repeat(64)
const STORE_UUID = '550e8400-e29b-41d4-a716-446655440001'

const tagCapability = {
  entity: 'Tag',
  actions: ['create', 'update', 'delete'],
  actionFields: {
    create: {
      name: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
      slug: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
      color: { type: 'color', nullable: true }
    },
    update: {
      name: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
      slug: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
      color: { type: 'color', nullable: true },
      internalNote: { type: 'string', maxLength: 255 }
    }
  },
  fields: {
    name: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
    slug: { type: 'string', maxLength: 255, requiredOnCreate: true, nonEmpty: true },
    color: { type: 'color', nullable: true },
    internalNote: { type: 'string', maxLength: 255 }
  }
}

const counterCapability = {
  entity: 'Counter',
  actions: ['create', 'update', 'delete'],
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

const parserResult = () => ({
  name: 'tags.csv',
  format: 'csv',
  columns: ['Name', 'Slug', 'Color'],
  rows: [['North', 'north', '#112233'], ['South', 'south', '']],
  warnings: []
})

function fakeHost(overrides = {}) {
  const calls = {
    prepare: [],
    restore: [],
    commit: [],
    status: [],
    cancel: []
  }
  let lastPreview = null
  const host = {
    getWriteCapabilities: () => [tagCapability, counterCapability],
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
    action: preview?.action,
    status,
    rowCount: preview?.rowCount ?? preview?.affectedRecords ?? preview?.rows?.length ?? 0,
    fields: previewFieldNames(preview),
    expiresAt: preview?.expiresAt,
    ...extra
  }
}

function preparedPreview(input, overrides = {}) {
  const rows = input.rows.map((row, index) => ({
    row: index + 1,
    title: `Row ${index + 1}`,
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

async function readyModel({ rows = [['North', 'north', '#112233']], entity = 'Tag', overrides = {} } = {}) {
  const { host, calls } = fakeHost(overrides)
  const model = createImportBatch(host, {
    parseFile: async() => ({
      name: 'import.csv',
      format: 'csv',
      columns: ['Name', 'Slug', 'Color'],
      rows,
      warnings: []
    })
  })
  assert.equal(model.setEntity(entity), true)
  assert.equal(await model.setFile({ name: 'import.csv' }), true)
  if (entity === 'Tag') {
    assert.equal(model.setMapping('name', 0), true)
    assert.equal(model.setMapping('slug', 1), true)
    assert.equal(model.setMapping('color', 2), true)
  } else {
    assert.equal(model.setStoreIdentifier(STORE_UUID), true)
    assert.equal(model.setMapping('code', 0), true)
    assert.equal(model.setMapping('name', 1), true)
  }
  return { model, calls }
}

test('host capabilities fail closed and only expose complete create policies for Tag and Counter', () => {
  assert.deepEqual(inspectImportHost({}), { available: false, reason: 'host', entities: [] })
  assert.deepEqual(inspectImportHost({ getWriteCapabilities: () => [] }), { available: false, reason: 'host', entities: [] })

  const methods = {
    prepareRecordBatch() {},
    restoreRecordBatch() {},
    commitRecordBatch() {},
    getRecordBatchStatus() {},
    cancelRecordBatch() {}
  }
  const inspected = inspectImportHost({
    ...methods,
    getWriteCapabilities: () => [tagCapability, counterCapability, { entity: 'Product', actions: ['create'], actionFields: { create: { name: { type: 'string', requiredOnCreate: true } } } }]
  })
  assert.equal(inspected.available, true)
  assert.deepEqual(inspected.entities.map(item => item.entity), ['Tag', 'Counter'])
  assert.deepEqual(inspected.entities[0].fields.map(field => field.property), ['name', 'slug', 'color'])
  assert.equal(inspected.entities[0].fields.some(field => field.property === 'internalNote'), false)
  assert.equal(inspected.entities[1].fields.some(field => field.property === 'storeUuid'), true)

  const unsupported = inspectImportHost({
    ...methods,
    getWriteCapabilities: () => [{ ...tagCapability, actionFields: { create: { name: { type: 'object' } } } }]
  })
  assert.equal(unsupported.available, false)
  assert.equal(unsupported.reason, 'capabilities')

  const missingStore = inspectImportHost({
    ...methods,
    getWriteCapabilities: () => [{ entity: 'Counter', actions: ['create'], actionFields: { create: { code: { type: 'string', requiredOnCreate: true } } } }]
  })
  assert.equal(missingStore.available, false)
  assert.deepEqual(missingStore.entities, [])
})

test('parser bounds and mapping rules reject oversized or ambiguous imports without guessing values', () => {
  assert.equal(normalizeParsedImport(parserResult()).rows.length, 2)
  assert.deepEqual(normalizeParsedImport({ ...parserResult(), warnings: [{ code: 'first_sheet', message: 'Only the first worksheet was read.' }] }).warnings, ['Only the first worksheet was read.'])
  assert.throws(() => normalizeParsedImport({ ...parserResult(), rows: Array.from({ length: 21 }, () => ['a', 'b', '']) }), error => {
    assert.ok(error instanceof ImportWorkbenchError)
    assert.equal(error.importError.key, 'The import exceeds the {count}-row limit.')
    return true
  })
  assert.throws(
    () => normalizeParsedImport({ ...parserResult(), rows: Array.from({ length: 21 }, () => ['a', 'b', '']) }, { maxRows: 100 }),
    error => error.importError.params.count === 20
  )
  assert.throws(() => normalizeParsedImport({ ...parserResult(), columns: [] }), /no columns/)
  assert.throws(() => normalizeParsedImport({ ...parserResult(), columns: ['a', 'b'], rows: [['one', 'two', 'three']] }), /more values than columns/)

  const fields = inspectImportHost({
    getWriteCapabilities: () => [tagCapability],
    prepareRecordBatch() {}, restoreRecordBatch() {}, commitRecordBatch() {}, getRecordBatchStatus() {}, cancelRecordBatch() {}
  }).entities[0].fields
  assert.equal(validateImportMapping({ entity: 'Tag', fields, columns: ['Name', 'Slug'], mapping: { name: 0, slug: 1 }, storeUuid: '' }).ok, true)
  assert.equal(validateImportMapping({ entity: 'Tag', fields, columns: ['Name', 'Slug'], mapping: { name: 0, slug: 0 }, storeUuid: '' }).ok, false)
  assert.equal(validateImportMapping({ entity: 'Tag', fields, columns: ['Name'], mapping: { name: 0 }, storeUuid: '' }).ok, false)
  assert.equal(validateImportMapping({ entity: 'Tag', fields, columns: ['Name', 'Slug'], mapping: { name: 0, slug: 1, unknown: null }, storeUuid: '' }).ok, false)

  const built = buildImportBatchInput({
    entity: 'Tag',
    fields,
    columns: ['Name', 'Slug', 'Color', 'Enabled'],
    rows: [['North', 'north', '']],
    mapping: { name: 0, slug: 1, color: 2 }
  })
  assert.equal(built.ok, true)
  assert.deepEqual(built.input.rows, [{ name: 'North', slug: 'north', color: null }])
  assert.equal(Object.hasOwn(built.input.rows[0], 'enabled'), false)

  const missing = buildImportBatchInput({ entity: 'Tag', fields, columns: ['Name', 'Slug'], rows: [[' ', 'north']], mapping: { name: 0, slug: 1 } })
  assert.equal(missing.ok, false)
  assert.equal(missing.errors[0].params.reason, 'A value is required.')

  const counterFields = inspectImportHost({
    getWriteCapabilities: () => [counterCapability],
    prepareRecordBatch() {}, restoreRecordBatch() {}, commitRecordBatch() {}, getRecordBatchStatus() {}, cancelRecordBatch() {}
  }).entities[0].fields
  assert.equal(validateImportMapping({ entity: 'Counter', fields: counterFields, columns: ['Code', 'Name'], mapping: { code: 0, name: 1 }, storeUuid: '' }).ok, false)
  assert.equal(validateImportMapping({ entity: 'Counter', fields: counterFields, columns: ['Code', 'Name'], mapping: { code: 0, name: 1 }, storeUuid: 'not-a-uuid' }).ok, false)
  assert.equal(validateImportMapping({ entity: 'Counter', fields: counterFields, columns: ['Code', 'Name'], mapping: { code: 0, name: 1 }, storeUuid: STORE_UUID }).ok, true)

  assert.equal(validateImportValue({ type: 'integer', minimum: 1, maximum: 5 }, '1.5').ok, false)
  assert.equal(validateImportValue({ type: 'integer', minimum: 1, maximum: 5 }, '0').ok, false)
  assert.equal(validateImportValue({ type: 'boolean' }, 'yes').ok, false)
  assert.deepEqual(validateImportValue({ type: 'boolean' }, 'TRUE'), { ok: true, value: true })
  assert.equal(validateImportValue({ type: 'color' }, '#ABCDEF').ok, true)
  assert.equal(validateImportValue({ type: 'color' }, 'red').ok, false)
})

test('prepare requires a complete canonical preview and edits invalidate and cancel it', async() => {
  const { model, calls } = await readyModel()
  assert.equal(await model.prepare(), true)
  assert.equal(model.state.phase, 'ready')
  assert.equal(model.state.preview.operationId, OPERATION_ID)
  assert.deepEqual(model.state.preview.records[0].values, { name: 'North', slug: 'north', color: '#112233' })
  assert.equal(calls.prepare.length, 1)
  assert.equal(calls.prepare[0].action, 'create')

  assert.equal(model.editCell(0, 0, 'North updated'), true)
  assert.equal(model.state.phase, 'editing')
  assert.equal(model.state.preview, null)
  assert.deepEqual(calls.cancel, [OPERATION_ID])

  assert.equal(await model.prepare(), true)
  assert.equal(calls.prepare.length, 2)
  assert.equal(calls.prepare[1].rows[0].name, 'North updated')
})

test('Counter requires one explicit store UUID and sends canonical values with every row', async() => {
  const { model, calls } = await readyModel({ entity: 'Counter', rows: [[' A01 ', ' Window '], [' A02 ', ' Door ']] })
  assert.equal(model.state.storeUuid, STORE_UUID)
  assert.equal(await model.prepare(), true)
  const input = calls.prepare[0]
  assert.equal(Object.hasOwn(input, 'storeUuid'), false)
  assert.equal(input.rows.length, 2)
  assert.equal(input.rows.every(row => row.storeUuid === STORE_UUID), true)
  assert.equal(input.rows[0].code, 'A01')
  assert.equal(input.rows[0].name, 'Window')
  assert.equal(model.state.preview.records.every(record => record.values.storeUuid === STORE_UUID), true)
})

test('commit requires the human confirmation checkbox and a verified success receipt', async() => {
  const { model, calls } = await readyModel()
  assert.equal(await model.prepare(), true)
  assert.equal(await model.commit(), false)
  assert.equal(calls.commit.length, 0)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), true)
  assert.equal(model.state.phase, 'succeeded')
  assert.equal(model.state.confirmed, false)
  assert.deepEqual(calls.commit, [OPERATION_ID])
  assert.equal(await model.commit(), false)
  assert.equal(calls.commit.length, 1)
})

test('unknown commit outcome blocks retry and checks status before restoring the same proposal', async() => {
  const statuses = ['pending']
  let commitCalls = 0
  let statusCalls = 0
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
  const model = createImportBatch(host, { parseFile: async() => parserResult() })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  assert.equal(await model.prepare(), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'unknown')
  assert.equal(model.blocksNewTask(), true)
  assert.equal(await model.commit(), false)
  assert.equal(commitCalls, 1)
  assert.equal(model.reset(), false)

  assert.equal(await model.checkOutcome(), true)
  assert.equal(statusCalls, 1)
  assert.equal(calls.restore.length, 1)
  assert.equal(model.state.phase, 'ready')
  assert.equal(model.state.restored, true)
  assert.equal(model.state.confirmed, false)
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
  const model = createImportBatch(host, { parseFile: async() => parserResult() })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  assert.equal(await model.prepare(), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'unknown')
  assert.equal(commitCalls, 1)
  assert.equal(formatImportError(key => key, model.state.errors[0]).includes('Sensitive'), false)
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
  const model = createImportBatch(host, { parseFile: async() => parserResult() })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  assert.equal(await model.prepare(), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'conflicted')
  assert.equal(await model.checkOutcome(), false)
  assert.equal(statusCalls, 0)
})

test('an expired preview is not committed and lets the user prepare a fresh batch', async() => {
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
  const model = createImportBatch(host, { parseFile: async() => parserResult(), now: () => current })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  assert.equal(await model.prepare(), true)
  current += 2000
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'expired')
  assert.equal(model.state.preview, null)
  assert.equal(commitCalls, 0)
  assert.equal(await model.prepare(), true)
  assert.equal(model.state.phase, 'ready')
})

test('discard cancels a pending proposal and reset forces cleanup of unknown outcomes', async() => {
  const { model, calls } = await readyModel()
  assert.equal(await model.prepare(), true)
  assert.equal(model.discard(), true)
  assert.equal(model.state.phase, 'editing')
  assert.deepEqual(calls.cancel, [OPERATION_ID])

  assert.equal(await model.prepare(), true)
  assert.equal(model.setConfirmed(true), true)
  const unknown = createImportBatch(fakeHost({ async commitRecordBatch() { return { status: 'unknown' } } }).host, { parseFile: async() => parserResult() })
  assert.equal(unknown.setEntity('Tag'), true)
  assert.equal(await unknown.setFile({ name: 'tags.csv' }), true)
  assert.equal(unknown.setMapping('name', 0), true)
  assert.equal(unknown.setMapping('slug', 1), true)
  assert.equal(await unknown.prepare(), true)
  assert.equal(unknown.setConfirmed(true), true)
  await unknown.commit()
  assert.equal(unknown.reset(), false)
  assert.equal(unknown.reset({ force: true }), true)
  assert.equal(unknown.state.phase, 'idle')
})

test('dispose and reset suppress stale prepare responses and abort the request', async() => {
  let resolvePrepare
  let signal
  const { host } = fakeHost({
    prepareRecordBatch(input, requestSignal) {
      signal = requestSignal
      return new Promise(resolve => { resolvePrepare = () => resolve(preparedPreview(input)) })
    }
  })
  const model = createImportBatch(host, { parseFile: async() => parserResult() })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  const pending = model.prepare()
  await Promise.resolve()
  assert.equal(signal.aborted, false)
  model.dispose()
  assert.equal(signal.aborted, true)
  resolvePrepare()
  assert.equal(await pending, false)
  assert.equal(model.state.preview, null)
  assert.equal(model.state.phase, 'idle')
})

test('prepared preview validation requires full canonical differences that match the submitted rows', () => {
  const expected = {
    entity: 'Tag',
    fields: [
      { property: 'name', rule: { type: 'string', requiredOnCreate: true } },
      { property: 'slug', rule: { type: 'string', requiredOnCreate: true } },
      { property: 'enabled', rule: { type: 'boolean' } }
    ],
    records: [{ row: 1, values: { name: 'North', slug: 'north' } }],
    storeUuid: ''
  }
  const valid = {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    title: 'Create 1 Tag record',
    affectedRecords: 1,
    expiresAt: new Date(Date.now() + 300000).toISOString(),
    rows: [{
      row: 1,
      title: 'North',
      identifier: null,
      differences: [
        { property: 'name', before: null, after: 'North' },
        { property: 'slug', before: null, after: 'north' }
      ]
    }],
    saved: false
  }
  assert.equal(normalizePreparedBatch(valid, expected).rowCount, 1)
  assert.throws(() => normalizePreparedBatch({ ...valid, saved: true }, expected), /not be saved/)
  assert.throws(() => normalizePreparedBatch({ ...valid, affectedRecords: 2 }, expected), /row count/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], identifier: '1' }] }, expected), /target identifier/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], differences: [{ property: 'name', before: null, after: 'North' }] }] }, expected), /does not match/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], differences: [...valid.rows[0].differences, { property: 'enabled', before: null, after: true }] }] }, expected), /does not match/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], differences: [{ property: 'name', before: 'old', after: 'North' }, valid.rows[0].differences[1]] }] }, expected), /null before/)
  assert.throws(() => normalizePreparedBatch({ ...valid, rows: [{ ...valid.rows[0], differences: [{ property: 'slug', before: null, after: 'north' }, { property: 'name', before: null, after: 'Changed' }] }] }, expected), /does not match/)
})

test('the coordinator batch transport accepts the workbench input and canonical backend preview', async() => {
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
  const model = createImportBatch(host, {
    parseFile: async() => ({ ...parserResult(), rows: [['North', 'north', '#112233']] })
  })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  assert.equal(model.setMapping('color', 2), true)
  assert.equal(await model.prepare(), true)
  assert.deepEqual(posted[0].body, {
    entity: 'Tag',
    action: 'create',
    rows: [{ name: 'North', slug: 'north', color: '#112233' }]
  })
  assert.equal(model.state.phase, 'ready')
  assert.deepEqual(model.state.preview.records[0].values, { name: 'North', slug: 'north', color: '#112233' })
})

test('Counter canonical trimming matches the transport and preview without changing unrelated text', async() => {
  const posted = []
  const preview = {
    operationId: OPERATION_ID,
    entity: 'Counter',
    action: 'create',
    title: 'Create 1 Counter record',
    affectedRecords: 1,
    expiresAt: `${new Date(Date.now() + 300000).toISOString().slice(0, 19)}+00:00`,
    rows: [{
      row: 1,
      title: 'Window',
      identifier: null,
      differences: [
        { property: 'storeUuid', before: null, after: STORE_UUID },
        { property: 'code', before: null, after: 'A01' },
        { property: 'name', before: null, after: 'Window' }
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
  const model = createImportBatch(host, {
    parseFile: async() => ({
      name: 'counters.csv',
      format: 'csv',
      columns: ['Code', 'Name'],
      rows: [[' A01 ', ' Window ']],
      warnings: []
    })
  })
  assert.equal(model.setEntity('Counter'), true)
  assert.equal(await model.setFile({ name: 'counters.csv' }), true)
  assert.equal(model.setMapping('code', 0), true)
  assert.equal(model.setMapping('name', 1), true)
  assert.equal(model.setStoreIdentifier(STORE_UUID.toUpperCase()), true)
  assert.equal(await model.prepare(), true)
  assert.deepEqual(posted[0].body.rows, [{ storeUuid: STORE_UUID, code: 'A01', name: 'Window' }])
  assert.deepEqual(model.state.preview.records[0].values, { storeUuid: STORE_UUID, code: 'A01', name: 'Window' })
})

test('the default parser integration parses a local file without storage or transport', async() => {
  const bytes = new TextEncoder().encode('Name,Slug\nNorth,north\n')
  const file = {
    name: 'tags.csv',
    size: bytes.byteLength,
    arrayBuffer: async() => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength)
  }
  const model = createImportBatch(fakeHost().host)
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile(file), true)
  assert.equal(model.state.phase, 'editing')
  assert.deepEqual(model.state.columns, ['Name', 'Slug'])
  assert.deepEqual(model.state.rows, [['North', 'north']])
})

test('batch receipts must match the retained canonical preview before any status applies', () => {
  const preview = {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    rowCount: 2,
    records: [
      { row: 1, values: { name: 'North', slug: 'north' } },
      { row: 2, values: { name: 'South', slug: 'south' } }
    ],
    expiresAt: '2026-10-23T12:00:00+00:00'
  }
  const receipt = {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    status: 'pending',
    rowCount: 2,
    fields: ['slug', 'name'],
    expiresAt: preview.expiresAt
  }
  assert.equal(normalizeBatchStatusReceipt(receipt, preview), 'pending')
  assert.equal(normalizeBatchStatusReceipt({ ...receipt, fields: ['name', 'slug'] }, preview), 'pending')
  for (const status of ['succeeded', 'cancelled', 'expired', 'conflicted']) {
    assert.equal(normalizeBatchStatusReceipt({ ...receipt, status }, preview), status)
  }
  const mismatches = [
    { ...receipt, operationId: 'b'.repeat(64) },
    { ...receipt, entity: 'Counter' },
    { ...receipt, action: 'update' },
    { ...receipt, rowCount: 1 },
    { ...receipt, rowCount: '2' },
    { ...receipt, fields: ['name'] },
    { ...receipt, fields: ['name', 'slug', 'color'] },
    { ...receipt, fields: ['name', 'name'] },
    { ...receipt, fields: ['name', 'color'] },
    { ...receipt, expiresAt: '2026-10-23T12:01:00+00:00' }
  ]
  for (const mismatch of mismatches) {
    assert.equal(normalizeBatchStatusReceipt(mismatch, preview), 'unknown')
  }
  assert.equal(normalizeBatchStatusReceipt({ ...receipt, status: 'failed' }, preview), 'unknown')
  assert.equal(normalizeBatchStatusReceipt(receipt, null), 'unknown')
  assert.equal(normalizeBatchStatusReceipt({ ...receipt, operationId: undefined }, preview), 'unknown')
})

test('an unverified success commit returns false and blocks retry until a matching status resolves it', async() => {
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
  const model = createImportBatch(host, { parseFile: async() => parserResult() })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  assert.equal(await model.prepare(), true)
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
})

test('a same-identity commit receipt with different metadata stays unknown and never retries', async() => {
  let commitCalls = 0
  let statusCalls = 0
  const { host } = fakeHost({
    async commitRecordBatch(operationId) {
      commitCalls += 1
      const preview = model.state.preview
      return {
        ...boundReceipt(preview, operationId, 'succeeded', { verified: true }),
        entity: 'Counter',
        rowCount: preview.rowCount + 1,
        fields: ['storeUuid'],
        expiresAt: '2026-10-23T12:01:00+00:00'
      }
    },
    async getRecordBatchStatus() {
      statusCalls += 1
      throw new Error('A mismatched receipt must not trigger an automatic status request.')
    }
  })
  const model = createImportBatch(host, { parseFile: async() => parserResult() })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  assert.equal(await model.prepare(), true)
  assert.equal(model.setConfirmed(true), true)
  assert.equal(await model.commit(), false)
  assert.equal(model.state.phase, 'unknown')
  assert.equal(model.blocksNewTask(), true)
  assert.equal(commitCalls, 1)
  assert.equal(statusCalls, 0)
  assert.match(formatImportError(key => key, model.state.errors[0]), /outcome is unknown/)
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
  const model = createImportBatch(host, { parseFile: async() => parserResult() })
  assert.equal(model.setEntity('Tag'), true)
  assert.equal(await model.setFile({ name: 'tags.csv' }), true)
  assert.equal(model.setMapping('name', 0), true)
  assert.equal(model.setMapping('slug', 1), true)
  assert.equal(await model.prepare(), true)
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
    const model = createImportBatch(host, { parseFile: async() => parserResult() })
    assert.equal(model.setEntity('Tag'), true)
    assert.equal(await model.setFile({ name: 'tags.csv' }), true)
    assert.equal(model.setMapping('name', 0), true)
    assert.equal(model.setMapping('slug', 1), true)
    assert.equal(await model.prepare(), true)
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
