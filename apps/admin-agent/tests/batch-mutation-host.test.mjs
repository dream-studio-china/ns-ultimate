import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { batchCapabilities, mutationCapabilities } from './support/capability-fixture.mjs'
import * as batchModule from '../src/batch-mutation-host.js'
import { createBatchMutationHost } from '../src/batch-mutation-host.js'
import { createAssistantTools } from '../src/tools.js'

const PATH = '/api/v1/manage/agent/batch-operations'
const OPERATION_ID = 'a'.repeat(64)
const OTHER_OPERATION_ID = 'b'.repeat(64)
const STORE_UUID = 'a2c6b792-cab7-4fae-8a25-86f5d59e0210'
const OTHER_STORE_UUID = 'b2c6b792-cab7-4fae-8a25-86f5d59e0211'
const CREATED_AT = '2026-10-23T12:00:00+00:00'
const EXPIRES_AT = '2026-10-23T12:05:00+00:00'
const COMPLETED_AT = '2026-10-23T12:01:00+00:00'

function createTransport(handlers = {}) {
  const calls = []
  const request = {
    async get(path, options = {}) {
      calls.push({ method: 'GET', path, options })
      return { data: await handlers.get?.(path, options, calls) }
    },
    async post(path, body, options = {}) {
      calls.push({ method: 'POST', path, body, options })
      return { data: await handlers.post?.(path, body, options, calls) }
    }
  }
  return { request, calls }
}

function makeHost(handlers = {}, capabilities = batchCapabilities) {
  const transport = createTransport(handlers)
  return { host: createBatchMutationHost(transport.request, PATH, capabilities), calls: transport.calls }
}

function tagRow(index, name, slug, ...color) {
  return {
    row: index,
    title: name,
    identifier: null,
    differences: [
      { property: 'name', before: null, after: name },
      { property: 'slug', before: null, after: slug },
      ...(color.length ? [{ property: 'color', before: null, after: color[0] }] : [])
    ]
  }
}

function tagPreview(overrides = {}) {
  return {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    title: 'Create 2 Tag records',
    affectedRecords: 2,
    expiresAt: EXPIRES_AT,
    rows: [
      tagRow(1, 'Vegan', 'vegan', '#12ab34'),
      tagRow(2, 'Spicy', 'spicy', null)
    ],
    saved: false,
    ...overrides
  }
}

function counterPreview(overrides = {}) {
  return {
    operationId: OPERATION_ID,
    entity: 'Counter',
    action: 'create',
    title: 'Create 2 Counter records',
    affectedRecords: 2,
    expiresAt: EXPIRES_AT,
    rows: [{
      row: 1,
      title: 'One',
      identifier: null,
      differences: [
        { property: 'storeUuid', before: null, after: STORE_UUID },
        { property: 'code', before: null, after: 'C-1' },
        { property: 'name', before: null, after: 'One' }
      ]
    }, {
      row: 2,
      title: 'Two',
      identifier: null,
      differences: [
        { property: 'storeUuid', before: null, after: STORE_UUID },
        { property: 'code', before: null, after: 'C-2' },
        { property: 'name', before: null, after: 'Two' },
        { property: 'capacity', before: null, after: 4 },
        { property: 'sort', before: null, after: 0 },
        { property: 'enabled', before: null, after: true }
      ]
    }],
    saved: false,
    ...overrides
  }
}

function pendingReceipt(overrides = {}) {
  return receipt({ status: 'pending', results: [], completedAt: null, ...overrides })
}

function receipt(overrides = {}) {
  return {
    operationId: OPERATION_ID,
    entity: 'Tag',
    action: 'create',
    status: 'succeeded',
    rowCount: 2,
    fields: ['name', 'slug', 'color'],
    results: [{ row: 1, identifier: '41' }, { row: 2, identifier: '42' }],
    createdAt: CREATED_AT,
    expiresAt: EXPIRES_AT,
    completedAt: COMPLETED_AT,
    ...overrides
  }
}

const tagInput = {
  entity: 'Tag',
  action: 'create',
  rows: [
    { name: 'Vegan', slug: 'vegan', color: '#12ab34' },
    { name: 'Spicy', slug: 'spicy', color: null }
  ]
}

const counterInput = {
  entity: 'Counter',
  action: 'create',
  rows: [
    { storeUuid: STORE_UUID, code: 'C-1', name: 'One' },
    { storeUuid: STORE_UUID.toUpperCase(), code: 'C-2', name: 'Two', capacity: 4, sort: 0, enabled: true }
  ]
}

function conflictError(data = { error: 'proposal_conflict' }) {
  return Object.assign(new Error('Conflict'), { response: { status: 409, data } })
}

test('exposes only Tag and Counter create capability descriptors and no model tool', () => {
  const { host } = makeHost()
  const capabilities = host.getBatchCreateCapabilities()
  assert.deepEqual(capabilities.map(capability => capability.entity), ['Tag', 'Counter'])
  for (const capability of capabilities) {
    assert.deepEqual(capability.actions, ['create'])
    assert.deepEqual(Object.keys(capability.actionFields), ['create'])
    assert.equal(capability.validateChanges({}, 'update'), false)
    assert.equal(typeof capability.validatePreview, 'function')
  }
  assert.deepEqual(Object.keys(host), [
    'getBatchCreateCapabilities',
    'prepareRecordBatch',
    'restoreRecordBatch',
    'commitRecordBatch',
    'getRecordBatchStatus',
    'cancelRecordBatch',
    'listRecordBatches'
  ])
  assert.deepEqual(Object.keys(batchModule), ['createBatchMutationHost'])
  const toolNames = createAssistantTools([], [], {}, mutationCapabilities).map(tool => tool.function.name)
  assert.equal(toolNames.some(name => /batch|commit|execute/.test(name)), false)
})

test('prepares Tag and Counter create batches at the fixed route with caller row validation', async() => {
  const signal = new AbortController().signal
  const tag = makeHost({ post: () => tagPreview() })
  assert.deepEqual(await tag.host.prepareRecordBatch(tagInput, signal), tagPreview())
  assert.equal(tag.calls.length, 1)
  assert.equal(tag.calls[0].method, 'POST')
  assert.equal(tag.calls[0].path, PATH)
  assert.deepEqual(tag.calls[0].body, tagInput)
  assert.equal(tag.calls[0].options.signal, signal)
  assert.equal(tag.calls[0].options.timeout, 60000)

  const counter = makeHost({ post: () => counterPreview() })
  assert.deepEqual(await counter.host.prepareRecordBatch(counterInput), counterPreview())
  assert.equal(counter.calls.length, 1)
  assert.equal(counter.calls[0].method, 'POST')
  assert.equal(counter.calls[0].path, PATH)
  assert.deepEqual(counter.calls[0].body, counterInput)

  const rows = Array.from({ length: 20 }, (_, index) => ({ name: `Tag ${index + 1}`, slug: `tag-${index + 1}` }))
  const preview = tagPreview({
    title: 'Create 20 Tag records',
    affectedRecords: 20,
    rows: rows.map((row, index) => tagRow(index + 1, row.name, row.slug))
  })
  const maximum = makeHost({ post: () => preview })
  assert.equal((await maximum.host.prepareRecordBatch({ entity: 'Tag', action: 'create', rows })).affectedRecords, 20)
})

test('binds prepared rows to the frozen submission and accepts backend canonical normalization', async() => {
  const tagSubmission = {
    entity: 'Tag',
    action: 'create',
    rows: [
      { name: ' North ', slug: 'north', color: '#A1B2C3' },
      { name: 'South', slug: 'south', color: null },
      { name: 'East', slug: 'east' }
    ]
  }
  const tagPrepared = tagPreview({
    title: 'Create 3 Tag records',
    affectedRecords: 3,
    rows: [
      tagRow(1, ' North ', 'north', '#A1B2C3'),
      tagRow(2, 'South', 'south', null),
      tagRow(3, 'East', 'east')
    ]
  })
  const tag = makeHost({ post: () => tagPrepared })
  const tagPending = tag.host.prepareRecordBatch(tagSubmission)
  tagSubmission.rows[0].slug = 'mutated-after-dispatch'
  assert.deepEqual(await tagPending, tagPrepared)
  assert.equal(Object.isFrozen(tag.calls[0].body), true)
  assert.equal(Object.isFrozen(tag.calls[0].body.rows), true)
  assert.equal(Object.isFrozen(tag.calls[0].body.rows[0]), true)

  const counterSubmission = {
    entity: 'Counter',
    action: 'create',
    rows: [
      { storeUuid: STORE_UUID.toUpperCase(), code: ' A01 ', name: ' Window ' },
      { storeUuid: STORE_UUID, code: 'A02', name: 'Door', capacity: 1, sort: 0, enabled: false }
    ]
  }
  const counterPrepared = counterPreview({
    rows: [{
      row: 1,
      title: 'Window',
      identifier: null,
      differences: [
        { property: 'storeUuid', before: null, after: STORE_UUID },
        { property: 'code', before: null, after: 'A01' },
        { property: 'name', before: null, after: 'Window' }
      ]
    }, {
      row: 2,
      title: 'Door',
      identifier: null,
      differences: [
        { property: 'storeUuid', before: null, after: STORE_UUID },
        { property: 'code', before: null, after: 'A02' },
        { property: 'name', before: null, after: 'Door' },
        { property: 'capacity', before: null, after: 1 },
        { property: 'sort', before: null, after: 0 },
        { property: 'enabled', before: null, after: false }
      ]
    }]
  })
  const counter = makeHost({ post: () => counterPrepared })
  assert.deepEqual(await counter.host.prepareRecordBatch(counterSubmission), counterPrepared)
})

test('rejects substituted, reordered, omitted, added or altered prepared rows', async() => {
  const input = {
    entity: 'Tag',
    action: 'create',
    rows: [
      { name: 'Alpha', slug: 'alpha', color: null },
      { name: 'Beta', slug: 'beta' }
    ]
  }
  const substituted = tagPreview({ rows: [
    tagRow(1, 'Alpha', 'alpha', '#000000'),
    tagRow(2, 'Beta', 'beta')
  ] })
  const reorderedValues = tagPreview({ rows: [
    tagRow(1, 'Beta', 'beta'),
    tagRow(2, 'Alpha', 'alpha', null)
  ] })
  const omittedField = tagPreview({ rows: [
    { ...tagRow(1, 'Alpha', 'alpha', null), differences: [
      { property: 'name', before: null, after: 'Alpha' },
      { property: 'slug', before: null, after: 'alpha' }
    ] },
    tagRow(2, 'Beta', 'beta')
  ] })
  const addedField = tagPreview({ rows: [
    tagRow(1, 'Alpha', 'alpha', null),
    tagRow(2, 'Beta', 'beta', null)
  ] })
  const changedTitle = tagPreview({ rows: [
    { ...tagRow(1, 'Alpha', 'alpha', null), title: 'Alpha substituted' },
    tagRow(2, 'Beta', 'beta')
  ] })
  const oneRow = tagPreview({
    title: 'Create 1 Tag record',
    affectedRecords: 1,
    rows: [tagRow(1, 'Alpha', 'alpha', null)]
  })
  const threeRows = tagPreview({
    title: 'Create 3 Tag records',
    affectedRecords: 3,
    rows: [
      tagRow(1, 'Alpha', 'alpha', null),
      tagRow(2, 'Beta', 'beta'),
      tagRow(3, 'Gamma', 'gamma')
    ]
  })

  for (const preview of [substituted, reorderedValues, omittedField, addedField, changedTitle, oneRow, threeRows]) {
    const { host } = makeHost({ post: () => preview })
    await assert.rejects(host.prepareRecordBatch(input), /does not match the submitted rows/)
  }

  const counterInputWithoutDefaults = {
    entity: 'Counter',
    action: 'create',
    rows: [{ storeUuid: STORE_UUID, code: 'C-1', name: 'One', sort: 0, enabled: false }]
  }
  const changedFalse = counterPreview({
    title: 'Create 1 Counter record',
    affectedRecords: 1,
    rows: [{
      row: 1,
      title: 'One',
      identifier: null,
      differences: [
        { property: 'storeUuid', before: null, after: STORE_UUID },
        { property: 'code', before: null, after: 'C-1' },
        { property: 'name', before: null, after: 'One' },
        { property: 'sort', before: null, after: 1 },
        { property: 'enabled', before: null, after: true }
      ]
    }]
  })
  await assert.rejects(makeHost({ post: () => changedFalse }).host.prepareRecordBatch(counterInputWithoutDefaults), /does not match the submitted rows/)

  const omittedCounterDefaults = counterPreview({
    title: 'Create 1 Counter record',
    affectedRecords: 1,
    rows: [{
      row: 1,
      title: 'One',
      identifier: null,
      differences: [
        { property: 'storeUuid', before: null, after: STORE_UUID },
        { property: 'code', before: null, after: 'C-1' },
        { property: 'name', before: null, after: 'One' }
      ]
    }]
  })
  await assert.rejects(makeHost({ post: () => omittedCounterDefaults }).host.prepareRecordBatch(counterInputWithoutDefaults), /does not match the submitted rows/)
})

test('restore keeps full preview validation without binding to a submission', async() => {
  const restored = tagPreview({
    operationId: OTHER_OPERATION_ID,
    title: 'Create 2 Tag records',
    rows: [
      tagRow(1, 'Restored One', 'restored-one', '#abcdef'),
      tagRow(2, 'Restored Two', 'restored-two')
    ]
  })
  assert.deepEqual(await makeHost({ get: () => restored }).host.restoreRecordBatch(OTHER_OPERATION_ID), restored)
  const malformed = tagPreview({ rows: [
    { ...tagRow(1, 'Restored One', 'restored-one', '#abcdef'), differences: [] },
    tagRow(2, 'Restored Two', 'restored-two')
  ] })
  await assert.rejects(makeHost({ get: () => malformed }).host.restoreRecordBatch(OPERATION_ID), /row preview/)
})

test('receipt identifiers accept only positive signed 32-bit numeric database IDs', async() => {
  for (const identifier of ['1', '2147483647']) {
    const { host } = makeHost({ get: () => receipt({ results: [
      { row: 1, identifier },
      { row: 2, identifier: '42' }
    ] }) })
    assert.equal((await host.getRecordBatchStatus(OPERATION_ID)).results[0].identifier, identifier)
  }

  for (const identifier of [
    'a2c6b792-cab7-4fae-8a25-86f5d59e0210',
    '123456789012345',
    '2147483648',
    '9999999999',
    '0',
    '01',
    '41\n',
    '41\r',
    '41\u2028',
    '41\u2029',
    ' 41',
    '41 ',
    '-1',
    '1.5',
    41
  ]) {
    const { host } = makeHost({ get: () => receipt({ results: [
      { row: 1, identifier },
      { row: 2, identifier: '42' }
    ] }) })
    await assert.rejects(host.getRecordBatchStatus(OPERATION_ID), /receipt results/)
  }
})

test('rejects unsupported entities, actions, fields, row shapes and duplicate Counter scopes before transport', async() => {
  const { host, calls } = makeHost()
  const tagRows = [
    { name: 'N', slug: 'n', storeUuid: STORE_UUID },
    { name: 'N' },
    { name: 'N', slug: 'n', metadata: {} },
    { name: 'N', slug: 'n', color: '#fff' },
    Object.assign(Object.create({ inherited: true }), { name: 'N', slug: 'n' }),
    ['not-an-object']
  ]
  const invalid = [
    { entity: 'Product', action: 'create', rows: [{ name: 'N' }] },
    { entity: 'Content', action: 'create', rows: [{ title: 'N' }] },
    { entity: 'Unknown', action: 'create', rows: [{ name: 'N' }] },
    { entity: 'Tag', action: 'update', rows: [{ name: 'N', slug: 'n' }] },
    { entity: 'Tag', action: 'delete', rows: [{ name: 'N', slug: 'n' }] },
    { entity: 'Tag', action: 'create', rows: [] },
    { entity: 'Tag', action: 'create', rows: Array.from({ length: 21 }, (_, index) => ({ name: `N${index}`, slug: `n-${index}` })) },
    { entity: 'Tag', action: 'create', rows: [{ name: 'N', slug: 'n' }], url: '/arbitrary' },
    { entity: 'Tag', action: 'create', rows: 'not-an-array' },
    { entity: 'Tag', action: 'create', rows: tagRows[0] },
    { entity: 'Tag', action: 'create', rows: tagRows[1] },
    { entity: 'Tag', action: 'create', rows: tagRows[2] },
    { entity: 'Tag', action: 'create', rows: tagRows[3] },
    { entity: 'Tag', action: 'create', rows: [tagRows[4]] },
    { entity: 'Tag', action: 'create', rows: [tagRows[5]] },
    { entity: 'Counter', action: 'create', rows: [{ code: 'C', name: 'N' }] },
    { entity: 'Counter', action: 'create', rows: [
      { storeUuid: STORE_UUID, code: 'C-1', name: 'One' },
      { storeUuid: OTHER_STORE_UUID, code: 'C-2', name: 'Two' }
    ] },
    { entity: 'Counter', action: 'create', rows: [{ storeUuid: 'not-a-uuid', code: 'C', name: 'N' }] },
    JSON.parse('{"entity":"Tag","action":"create","rows":[{"name":"N","slug":"n","__proto__":{"polluted":true}}]}'),
    JSON.parse('{"entity":"Tag","action":"create","rows":[{"name":"N","slug":"n"}],"__proto__":{"polluted":true}}')
  ]
  const sparse = []
  sparse[0] = { name: 'N', slug: 'n' }
  sparse[2] = { name: 'M', slug: 'm' }
  invalid.push({ entity: 'Tag', action: 'create', rows: sparse })
  const symbolInput = { entity: 'Tag', action: 'create', rows: [{ name: 'N', slug: 'n' }] }
  symbolInput[Symbol('unexpected')] = true
  invalid.push(symbolInput)

  for (const input of invalid) {
    await assert.rejects(host.prepareRecordBatch(input), /Invalid batch|Only Tag|Only the create|Changes contain|between 1 and 20|Store UUID|field object|unsupported/)
  }
  assert.equal(calls.length, 0)
})

test('rejects malformed prepared previews before they can reach the UI', async() => {
  const cases = []
  cases.push(tagPreview({ rawSnapshot: { password: 'secret' } }))
  cases.push(tagPreview({ operationId: 'not-an-operation' }))
  cases.push(tagPreview({ entity: 'Content' }))
  cases.push(tagPreview({ action: 'update' }))
  cases.push(tagPreview({ saved: true }))
  cases.push(tagPreview({ saved: undefined }))
  cases.push(tagPreview({ affectedRecords: 1 }))
  cases.push(tagPreview({ expiresAt: 'not-a-date' }))
  cases.push(tagPreview({ expiresAt: '2026-10-23' }))
  cases.push(tagPreview({ rows: 'not-an-array' }))
  cases.push(tagPreview({ rows: [], affectedRecords: 0 }))
  cases.push(tagPreview({ rows: Array.from({ length: 21 }, (_, index) => tagRow(index + 1, `N${index}`, `n-${index}`)), affectedRecords: 21 }))
  const missingTitle = tagPreview()
  delete missingTitle.title
  cases.push(missingTitle)
  const reordered = tagPreview()
  reordered.rows = [reordered.rows[1], reordered.rows[0]]
  cases.push(reordered)
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), identifier: '12' }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), extra: true }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), title: '' }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), differences: [] }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), differences: [
    { property: 'metadata', before: null, after: {} }
  ] }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), differences: [
    { property: 'name', before: null, after: 'Vegan', raw: true }
  ] }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), differences: [
    { property: 'name', before: 'old', after: 'Vegan' }
  ] }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), differences: [
    { property: 'color', before: null, after: '#fff' }
  ] }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), differences: [
    { property: 'name', before: null, after: 'Vegan' }
  ] }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), differences: [
    { property: 'name', before: null, after: 'Vegan' },
    { property: 'name', before: null, after: 'Other' }
  ] }, tagRow(2, 'Spicy', 'spicy', null)] }))
  cases.push(counterPreview({ rows: [counterPreview().rows[0], {
    ...counterPreview().rows[1],
    differences: counterPreview().rows[1].differences.map(difference => difference.property === 'storeUuid'
      ? { ...difference, after: OTHER_STORE_UUID }
      : difference)
  }] }))

  for (const preview of cases) {
    const { host } = makeHost({ post: () => preview })
    await assert.rejects(host.prepareRecordBatch(tagInput), /Invalid batch|unsupported|not enabled|does not match|must be create|must not be saved|expiry|row count|row order|identifier|title|row preview|Store UUID/)
  }
})

test('restores the same operation and rejects mismatched or malformed responses', async() => {
  const signal = new AbortController().signal
  const valid = makeHost({ get: () => tagPreview() })
  assert.deepEqual(await valid.host.restoreRecordBatch(OPERATION_ID, signal), tagPreview())
  assert.equal(valid.calls.length, 1)
  assert.equal(valid.calls[0].method, 'GET')
  assert.equal(valid.calls[0].path, `${PATH}/${OPERATION_ID}/preview`)
  assert.equal(valid.calls[0].options.signal, signal)
  assert.equal(valid.calls[0].options.timeout, 60000)

  for (const operationId of ['../operations', OPERATION_ID.toUpperCase(), 'a'.repeat(63), 12, null]) {
    const { host, calls } = makeHost({ get: () => tagPreview() })
    await assert.rejects(host.restoreRecordBatch(operationId), /Invalid operation identifier/)
    assert.equal(calls.length, 0)
  }
  for (const preview of [
    tagPreview({ operationId: OTHER_OPERATION_ID }),
    tagPreview({ rows: [{ ...tagRow(1, 'Vegan', 'vegan', '#12ab34'), identifier: '12' }, tagRow(2, 'Spicy', 'spicy', null)] }),
    tagPreview({ rawSnapshot: {} })
  ]) {
    const { host } = makeHost({ get: () => preview })
    await assert.rejects(host.restoreRecordBatch(OPERATION_ID), /Invalid batch|unsupported|identity|identifier/)
  }
})

test('maps only the exact 409 proposal_conflict marker and preserves every other error', async() => {
  for (const method of ['restore', 'commit']) {
    const conflict = makeHost({ get: () => { throw conflictError() }, post: () => { throw conflictError() } })
    await assert.rejects(conflict.host[method === 'restore' ? 'restoreRecordBatch' : 'commitRecordBatch'](OPERATION_ID), error => {
      assert.equal(error.code, 'proposal_conflict')
      assert.doesNotMatch(error.message, /stored|payload|value/i)
      return true
    })

    for (const original of [
      conflictError({ error: 'conflict' }),
      conflictError(null),
      Object.assign(new Error('Server failure'), { response: { status: 500, data: {} } }),
      Object.assign(new Error('Timed out'))
    ]) {
      const preserved = makeHost({ get: () => { throw original }, post: () => { throw original } })
      await assert.rejects(
        preserved.host[method === 'restore' ? 'restoreRecordBatch' : 'commitRecordBatch'](OPERATION_ID),
        error => error === original
      )
    }
  }
})

test('commits only with explicit confirmation and validates metadata-only receipts', async() => {
  const signal = new AbortController().signal
  const verifiedReceipt = receipt({ verified: true })
  const valid = makeHost({ post: () => verifiedReceipt })
  assert.deepEqual(await valid.host.commitRecordBatch(OPERATION_ID, signal), verifiedReceipt)
  assert.equal(valid.calls.length, 1)
  assert.equal(valid.calls[0].method, 'POST')
  assert.equal(valid.calls[0].path, `${PATH}/${OPERATION_ID}/commit`)
  assert.deepEqual(valid.calls[0].body, { confirmed: true })
  assert.equal(valid.calls[0].options.signal, signal)
  assert.equal(valid.calls[0].options.timeout, 60000)

  for (const operationId of ['../commit', OPERATION_ID.toUpperCase(), 'a'.repeat(63), 12]) {
    const { host, calls } = makeHost({ post: () => verifiedReceipt })
    await assert.rejects(host.commitRecordBatch(operationId), /Invalid operation identifier/)
    assert.equal(calls.length, 0)
  }

  const invalid = [
    receipt(),
    receipt({ verified: 'true' }),
    receipt({ operationId: OTHER_OPERATION_ID, verified: true }),
    receipt({ entity: 'Product', verified: true }),
    receipt({ action: 'update', verified: true }),
    receipt({ status: 'done', verified: true }),
    receipt({ status: 'pending', results: [], completedAt: null, verified: true }),
    receipt({ rowCount: 0, verified: true }),
    receipt({ rowCount: 21, verified: true }),
    receipt({ fields: [], verified: true }),
    receipt({ fields: ['name', '__proto__'], verified: true }),
    receipt({ fields: ['name', 'name', 'slug', 'color'], verified: true }),
    receipt({ fields: 'name', verified: true }),
    receipt({ createdAt: 'not-a-date', verified: true }),
    receipt({ expiresAt: '2026-10-23T11:59:00+00:00', verified: true }),
    receipt({ completedAt: null, verified: true }),
    receipt({ results: [], verified: true }),
    receipt({ results: [{ row: 2, identifier: '41' }, { row: 1, identifier: '42' }], verified: true }),
    receipt({ results: [{ row: 1, identifier: '' }, { row: 2, identifier: '42' }], verified: true }),
    receipt({ results: [{ row: 1, identifier: '41', values: { name: 'Vegan' } }, { row: 2, identifier: '42' }], verified: true }),
    receipt({ results: [{ row: 1, identifier: '41' }, { row: 2, identifier: '41' }], verified: true }),
    { ...verifiedReceipt, rows: tagInput.rows }
  ]
  for (const invalidReceipt of invalid) {
    const { host } = makeHost({ post: () => invalidReceipt })
    await assert.rejects(host.commitRecordBatch(OPERATION_ID), /Invalid batch|unsupported|not verified|did not succeed|identity|entity|action|status|row count|receipt time|receipt fields|receipt results/)
  }
})

test('commit replay returns the same verified receipt and never accepts an unverified commit', async() => {
  const verified = receipt({ verified: true })
  const replay = makeHost({ post: () => verified })
  assert.deepEqual(await replay.host.commitRecordBatch(OPERATION_ID), verified)
  assert.deepEqual(await replay.host.commitRecordBatch(OPERATION_ID), verified)
  assert.equal(replay.calls.length, 2)
  assert.ok(replay.calls.every(call => call.method === 'POST' && call.path === `${PATH}/${OPERATION_ID}/commit`))
  assert.ok(replay.calls.every(call => JSON.stringify(call.body) === JSON.stringify({ confirmed: true })))

  const unverified = makeHost({ post: () => receipt() })
  await assert.rejects(unverified.host.commitRecordBatch(OPERATION_ID), /not verified/)
  const wrongMarker = makeHost({ post: () => receipt({ verified: false }) })
  await assert.rejects(wrongMarker.host.commitRecordBatch(OPERATION_ID), /not verified/)
})

test('reads status and cancel receipts without exposing proposal values', async() => {
  const signal = new AbortController().signal
  const pending = pendingReceipt()
  const status = makeHost({ get: () => pending })
  assert.deepEqual(await status.host.getRecordBatchStatus(OPERATION_ID, signal), pending)
  assert.equal(status.calls.length, 1)
  assert.equal(status.calls[0].method, 'GET')
  assert.equal(status.calls[0].path, `${PATH}/${OPERATION_ID}`)
  assert.equal(status.calls[0].options.signal, signal)
  assert.equal(status.calls[0].options.timeout, 60000)

  for (const invalidReceipt of [
    pendingReceipt({ operationId: OTHER_OPERATION_ID }),
    { ...pending, verified: true },
    { ...pending, results: [{ row: 1, identifier: '41' }] },
    { ...pending, fields: ['name', '__proto__'] },
    { ...pending, rows: tagInput.rows }
  ]) {
    const { host } = makeHost({ get: () => invalidReceipt })
    await assert.rejects(host.getRecordBatchStatus(OPERATION_ID), /Invalid batch|unsupported|identity|receipt fields|receipt results/)
  }

  const cancelled = receipt({ status: 'cancelled', results: [], completedAt: COMPLETED_AT })
  const cancel = makeHost({ post: () => cancelled })
  assert.deepEqual(await cancel.host.cancelRecordBatch(OPERATION_ID), cancelled)
  assert.equal(cancel.calls.length, 1)
  assert.equal(cancel.calls[0].method, 'POST')
  assert.equal(cancel.calls[0].path, `${PATH}/${OPERATION_ID}/cancel`)
  assert.deepEqual(cancel.calls[0].body, {})
  assert.equal(cancel.calls[0].options.timeout, 60000)

  const conflict = makeHost({ post: () => { throw conflictError() } })
  await assert.rejects(conflict.host.cancelRecordBatch(OPERATION_ID), error => error.code === 'proposal_conflict')
})

test('lists bounded metadata receipts with the requested page and no duplicate operations', async() => {
  const signal = new AbortController().signal
  const page = { items: [pendingReceipt(), receipt({ operationId: OTHER_OPERATION_ID })], page: 2, hasMore: true }
  const valid = makeHost({ get: () => page })
  assert.deepEqual(await valid.host.listRecordBatches(2, signal), page)
  assert.equal(valid.calls.length, 1)
  assert.equal(valid.calls[0].method, 'GET')
  assert.equal(valid.calls[0].path, PATH)
  assert.deepEqual(valid.calls[0].options.params, { page: 2 })
  assert.equal(valid.calls[0].options.signal, signal)
  assert.equal(valid.calls[0].options.timeout, 60000)

  for (const invalidPage of [0, 101, 1.5, '1', null]) {
    const { host, calls } = makeHost({ get: () => page })
    await assert.rejects(host.listRecordBatches(invalidPage), /Invalid batch page/)
    assert.equal(calls.length, 0)
  }

  const many = Array.from({ length: 21 }, (_, index) => pendingReceipt({ operationId: String(index).padStart(64, '0') }))
  const malformed = [
    { ...page, page: 3 },
    { ...page, page: '2' },
    { ...page, hasMore: 'yes' },
    { ...page, items: 'not-an-array' },
    { ...page, items: many },
    { ...page, items: [pendingReceipt(), pendingReceipt()] },
    { ...page, items: [receipt({ entity: 'Product' })] },
    { ...page, items: [{ ...pendingReceipt(), verified: true }] },
    { ...page, items: [{ ...pendingReceipt(), rows: tagInput.rows }] },
    { ...page, extra: true }
  ]
  for (const invalid of malformed) {
    const { host } = makeHost({ get: () => invalid })
    await assert.rejects(host.listRecordBatches(2), /Invalid batch|unsupported|page number|duplicate|entity|identity/)
  }

  const serialized = JSON.stringify(await makeHost({ get: () => page }).host.listRecordBatches(2))
  assert.equal(serialized.includes('Vegan'), false)
  assert.equal(serialized.includes('storeUuid'), false)
})

test('aborts before transport and never accepts a response after caller cancellation', async() => {
  const preAborted = new AbortController()
  preAborted.abort()
  for (const [method, args] of [
    ['prepareRecordBatch', [tagInput, preAborted.signal]],
    ['restoreRecordBatch', [OPERATION_ID, preAborted.signal]],
    ['commitRecordBatch', [OPERATION_ID, preAborted.signal]],
    ['getRecordBatchStatus', [OPERATION_ID, preAborted.signal]],
    ['listRecordBatches', [1, preAborted.signal]]
  ]) {
    const { host, calls } = makeHost({
      get: () => tagPreview(),
      post: () => ({ ...receipt({ verified: true }) })
    })
    await assert.rejects(host[method](...args), error => error.name === 'AbortError')
    assert.equal(calls.length, 0)
  }

  for (const [method, args, handler] of [
    ['prepareRecordBatch', [tagInput, null], 'post'],
    ['restoreRecordBatch', [OPERATION_ID, null], 'get'],
    ['commitRecordBatch', [OPERATION_ID, null], 'post'],
    ['getRecordBatchStatus', [OPERATION_ID, null], 'get'],
    ['listRecordBatches', [1, null], 'get']
  ]) {
    const controller = new AbortController()
    args[args.length - 1] = controller.signal
    const payload = {
      post: () => tagPreview(),
      get: () => tagPreview()
    }
    payload[handler] = () => {
      controller.abort()
      return handler === 'post' ? (method === 'prepareRecordBatch' ? tagPreview() : { ...receipt({ verified: true }) }) : (method === 'restoreRecordBatch' ? tagPreview() : (method === 'getRecordBatchStatus' ? pendingReceipt() : { items: [], page: 1, hasMore: false }))
    }
    const { host, calls } = makeHost(payload)
    await assert.rejects(host[method](...args), error => error.name === 'AbortError')
    assert.equal(calls.length, 1)
  }
})

test('integration host uses the fixed routes and one compiled runtime capability catalog', () => {
  const source = readFileSync(new URL('../../../integration/admin/agent-host.js', import.meta.url), 'utf8')
  assert.match(source, /import \{ createBatchMutationHost \} from '\.\.\/\.\.\/apps\/admin-agent\/src\/batch-mutation-host\.js'/)
  assert.match(source, /createNsUltimateMutationRuntime\(\{/)
  assert.match(source, /path: `\$\{defaultPrefix\}\/agent\/capabilities`/)
  assert.match(source, /createMutationHost\(request, `\$\{defaultPrefix\}\/agent\/operations`, singleSource\)/)
  assert.match(source, /createBatchMutationHost\(request, `\$\{defaultPrefix\}\/agent\/batch-operations`, batchSource\)/)
  assert.match(source, /initializeAgentCapabilities/)
  assert.match(source, /onCapabilityChange/)
})

test('status, cancel and receipt reads settle after write authority is removed', async() => {
  let active = batchCapabilities
  const source = {
    getCapabilities: () => active,
    getRevision: () => active.length ? 'a'.repeat(64) : 'b'.repeat(64)
  }
  const calls = []
  const request = {
    async get(path) {
      calls.push(['get', path])
      if (path === PATH) return { data: { items: [receipt()], page: 1, hasMore: false } }
      if (path.endsWith('/preview')) return { data: tagPreview() }
      return { data: pendingReceipt() }
    },
    async post(path) {
      calls.push(['post', path])
      if (path === PATH) return { data: tagPreview() }
      if (path.endsWith('/commit')) return { data: receipt({ verified: true }) }
      return { data: receipt({ status: 'cancelled', results: [], completedAt: COMPLETED_AT }) }
    }
  }
  const host = createBatchMutationHost(request, PATH, source)
  await host.prepareRecordBatch(tagInput)
  active = []

  // Status and cancel are read/outcome operations: they must not depend on the
  // current write policy. The retained prepared policy still checks field
  // correspondence for this operation.
  assert.deepEqual(await host.getRecordBatchStatus(OPERATION_ID), pendingReceipt())
  assert.deepEqual(await host.cancelRecordBatch(OPERATION_ID), receipt({ status: 'cancelled', results: [], completedAt: COMPLETED_AT }))
  assert.deepEqual(await host.listRecordBatches(1), { items: [receipt()], page: 1, hasMore: false })

  // Restore and commit are policy-bearing/mutation paths and fail closed.
  await assert.rejects(host.restoreRecordBatch(OPERATION_ID), /not enabled/)
  const commitPosts = calls.filter(([method, path]) => method === 'post' && path === `${PATH}/${OPERATION_ID}/commit`).length
  await assert.rejects(host.commitRecordBatch(OPERATION_ID), /not enabled/)
  assert.equal(calls.filter(([method, path]) => method === 'post' && path === `${PATH}/${OPERATION_ID}/commit`).length, commitPosts, 'A revoked write policy must reject before commit transport.')
})

test('receipt reads without retained history accept only bounded protocol field names', async() => {
  const host = createBatchMutationHost({
    async get() { return { data: receipt() } },
    async post() { return { data: receipt({ status: 'cancelled', results: [], completedAt: COMPLETED_AT }) } }
  }, PATH, [])
  assert.deepEqual(await host.getRecordBatchStatus(OPERATION_ID), receipt())
  assert.deepEqual(await host.cancelRecordBatch(OPERATION_ID), receipt({ status: 'cancelled', results: [], completedAt: COMPLETED_AT }))

  for (const fields of [['name', 'not a field'], ['name', '__proto__'], ['name', 'name']]) {
    const invalid = createBatchMutationHost({
      async get() { return { data: receipt({ fields }) } },
      async post() { throw new Error('Unexpected transport.') }
    }, PATH, [])
    await assert.rejects(invalid.getRecordBatchStatus(OPERATION_ID), /receipt fields/)
  }
})
