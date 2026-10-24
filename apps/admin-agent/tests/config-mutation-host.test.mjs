import assert from 'node:assert/strict'
import test from 'node:test'
import { buildAdminConfigProjection } from '../src/admin-config-policy.js'
import { configuredCapabilities, createConfigMutationHost } from '../src/config-mutation-host.js'
import { agentMutationPolicy } from '../adapters/ns-ultimate/admin/write-policy.mjs'
import { normalizeBatchCapabilities, normalizePreparedBatch, prepareBatchRequest, createBatchProposal } from '../src/import-workbench.js'
import { specialWrite } from '../adapters/ns-ultimate/admin/special-writes.mjs'

function setup(extra = {}) {
  let clock = 1800000000000
  let serial = 0
  const calls = []
  let record = { id: 7, title: 'Old', body: 'Old body', metadata: { custom: [1, true] }, category: { id: 2 }, tags: [{ id: 3 }] }
  let config = {
    entities: Object.fromEntries(['Content', 'Page', 'Comment', 'Category', 'User', 'Wallet', 'Setting', 'Permission', 'Assignment', 'RoleFieldGrant', 'PaymentDeduction'].map(name => [name, {
      form: { fields: ['title', { property: 'body', type: 'text' }, { property: 'metadata', type: 'json' },
        { property: 'category', relation: { target: 'Category' } }, { property: 'tags', relation: { target: 'Tag', multiple: true } }] },
      list: {}, detail: {}
    }])),
    routes: ['Content', 'Page', 'Comment', 'Category', 'User', 'Wallet', 'Setting', 'Permission', 'Assignment', 'RoleFieldGrant', 'PaymentDeduction'].map(name => ({ name: `${name}List`, path: `/${name.toLowerCase()}/list` }))
  }
  const projection = () => buildAdminConfigProjection(config)
  const request = {
    get: async(...args) => { calls.push(['get', ...args]); return { data: structuredClone(record) } },
    post: async(...args) => { calls.push(['post', ...args]); return { data: { id: 8 } } },
    put: async(...args) => { calls.push(['put', ...args]); return { data: { id: 7 } } },
    delete: async(...args) => { calls.push(['delete', ...args]); return { data: null } },
    ...extra
  }
  const host = createConfigMutationHost({ request, policy: agentMutationPolicy, getProjection: projection, now: () => clock, randomId: () => `session-${++serial}` })
  return { host, calls, projection, config, request, setRecord: value => { record = value }, advance: () => { clock += 300001 } }
}

test('form configuration is the only ordinary field membership source, including new entities and JSON keys', () => {
  const { config, projection } = setup()
  const before = configuredCapabilities(projection(), agentMutationPolicy)
  assert.deepEqual(before.find(item => item.entity === 'Page').actions, ['create', 'update'])
  assert.deepEqual(before.find(item => item.entity === 'Comment').actions, ['create', 'update', 'delete'])
  config.entities.Page.form.fields.push('newConfiguredField')
  assert.ok(configuredCapabilities(projection(), agentMutationPolicy).find(item => item.entity === 'Page').actionFields.update.newConfiguredField)
})

test('blacklist retains wallet, system configuration, authorization and existing user restrictions', async() => {
  const { host } = setup()
  const caps = host.getWriteCapabilities()
  for (const name of ['Wallet', 'Setting', 'Permission', 'Assignment', 'RoleFieldGrant', 'PaymentDeduction']) assert.equal(caps.some(item => item.entity === name), false)
  assert.deepEqual(caps.find(item => item.entity === 'User').actions, ['create'])
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'delete', identifier: '7' }), /not available/)
})

test('prepare reads the actual detail but never writes; confirm sends only configured proposed fields to existing PUT', async() => {
  const { host, calls } = setup()
  const preview = await host.prepareRecordChange({ entity: 'Content', action: 'update', identifier: '7', changes: { title: 'New', metadata: { arbitrary: ['custom'] } } })
  assert.equal(preview.saved, false)
  assert.equal(calls.length, 1)
  assert.deepEqual(preview.differences[0], { property: 'title', before: 'Old', after: 'New' })
  const result = await host.commitRecordChange(preview.operationId)
  assert.equal(result.status, 'succeeded')
  assert.deepEqual(calls.at(-1).slice(0, 3), ['put', '/api/v1/manage/contents/7', { title: 'New', metadata: { arbitrary: ['custom'] } }])
  assert.equal(calls.some(call => call[1].includes('/agent/')), false)
  assert.equal(JSON.stringify(await host.listRecordChanges()).includes('arbitrary'), false)
  await assert.rejects(host.commitRecordChange(preview.operationId), /no longer pending/)
})

test('relation objects are converted using the form relation value key, including many-valued relations', async() => {
  const { host } = setup()
  const preview = await host.prepareRecordChange({ entity: 'Content', action: 'update', identifier: '7', changes: { category: 4, tags: [5, 6] } })
  assert.deepEqual(preview.differences.map(item => item.before), [2, [3]])
  await assert.rejects(host.prepareRecordChange({ entity: 'Content', action: 'update', identifier: '7', changes: { category: 'invented name' } }), /relation identifier/)
})

test('changed records, revoked configuration and expired previews cannot submit', async() => {
  for (const change of ['record', 'config', 'expiry']) {
    const ctx = setup()
    const preview = await ctx.host.prepareRecordChange({ entity: 'Page', action: 'update', identifier: '7', changes: { title: 'New' } })
    if (change === 'record') ctx.setRecord({ id: 7, title: 'Changed elsewhere' })
    if (change === 'config') ctx.config.entities.Page.list.disabled_actions = ['edit']
    if (change === 'expiry') ctx.advance()
    await assert.rejects(ctx.host.commitRecordChange(preview.operationId), error => error.code === 'proposal_conflict')
    assert.equal(ctx.calls.some(call => call[0] === 'put'), false)
  }
})

test('unknown write outcomes stay unknown; status inspection does not infer success or repeat the request', async() => {
  let writes = 0
  const { host } = setup({ post: async() => { writes += 1; throw new Error('Network timeout after send') } })
  const preview = await host.prepareRecordChange({ entity: 'Page', action: 'create', changes: { title: 'New' } })
  await assert.rejects(host.commitRecordChange(preview.operationId), /timeout/)
  assert.equal((await host.getRecordChangeStatus(preview.operationId)).status, 'unknown')
  await host.cancelRecordChange(preview.operationId)
  await assert.rejects(host.commitRecordChange(preview.operationId), /no longer pending/)
  assert.equal(writes, 1)
})

test('missing detail fields never produce a fabricated before value', async() => {
  const { host, setRecord } = setup()
  setRecord({ id: 7 })
  await assert.rejects(host.prepareRecordChange({ entity: 'Page', action: 'update', identifier: '7', changes: { title: 'New' } }), /missing/)
})

test('safe payload checks reject reserved fields, injected routes, non-JSON values and unsafe targets', async() => {
  const { host } = setup()
  for (const input of [
    { entity: 'Page', action: 'create', path: '/other', changes: { title: 'x' } },
    { entity: 'Page', action: 'update', identifier: '../7', changes: { title: 'x' } },
    { entity: 'Page', action: 'create', changes: JSON.parse('{"metadata":{"__proto__":{"polluted":true}}}') },
    { entity: 'Page', action: 'create', changes: { missingField: 'x' } },
    { entity: 'Page', action: 'create', changes: { metadata: { bad: Infinity } } }
  ]) await assert.rejects(host.prepareRecordChange(input))
  assert.equal({}.polluted, undefined)
})

test('new arbitrary entities can batch-create using exactly the same form capability rules and UI confirmation', async() => {
  const { host, calls } = setup()
  const policy = normalizeBatchCapabilities(host.getBatchCreateCapabilities())
  const input = { entity: 'Page', action: 'create', rows: [{ title: 'One', metadata: { nested: [1] } }, { title: 'Two' }] }
  const expected = prepareBatchRequest(input, policy.entities)
  const raw = await host.prepareRecordBatch(input)
  const prepared = normalizePreparedBatch(raw, expected)
  const model = createBatchProposal(host)
  assert.equal(model.adopt(prepared, expected), true)
  assert.equal(await model.commit(), false)
  assert.equal(calls.length, 0)
  model.setConfirmed(true)
  assert.equal(await model.commit(), true)
  assert.equal(calls.filter(call => call[0] === 'post').length, 2)
  assert.equal(model.state.receipt.results.length, 2)
})

test('partial batches stop at the first uncertain request and report every row without retrying', async() => {
  let writes = 0
  const { host } = setup({ post: async() => { if (++writes === 2) throw new Error('timeout'); return { data: { id: 8 } } } })
  const preview = await host.prepareRecordBatch({ entity: 'Page', action: 'create', rows: [{ title: 'A' }, { title: 'B' }, { title: 'C' }] })
  const result = await host.commitRecordBatch(preview.operationId)
  assert.equal(result.status, 'unknown')
  assert.deepEqual(result.results.map(item => item.status), ['succeeded', 'unknown', 'not-submitted'])
  assert.deepEqual((await host.getRecordBatchStatus(preview.operationId)).results, result.results)
  await assert.rejects(host.commitRecordBatch(preview.operationId), /no longer pending/)
  assert.equal(writes, 2)
})

test('session disposal drops values and receipts; a fresh host cannot restore old operations', async() => {
  const { host } = setup()
  const preview = await host.prepareRecordChange({ entity: 'Page', action: 'create', changes: { title: 'Session draft' } })
  host.dispose()
  await assert.rejects(host.getRecordChangeStatus(preview.operationId), /session ended/)
  await assert.rejects(setup().host.restoreRecordChange(preview.operationId), /browser session/)
})

test('prepared payloads are copied and request-client automatic authentication replay is disabled for writes', async() => {
  const { host, calls } = setup()
  const input = { entity: 'Page', action: 'create', changes: { title: 'Confirmed', metadata: { custom: 1 } } }
  const preview = await host.prepareRecordChange(input)
  input.changes.metadata.custom = 99
  preview.differences.find(item => item.property === 'metadata').after.custom = 77
  await host.commitRecordChange(preview.operationId)
  assert.equal(calls.at(-1)[2].metadata.custom, 1)
  assert.equal(calls.at(-1)[3]._retry, true)
})

test('required uploads block creation while optional custom controls do not hide otherwise editable entities', () => {
  const { config, projection } = setup()
  config.entities.Page.form.fields.push({ property: 'image', type: 'image', required: true })
  assert.deepEqual(configuredCapabilities(projection(), agentMutationPolicy).find(item => item.entity === 'Page').actions, ['update'])
  config.entities.Page.form.fields.at(-1).required = false
  assert.ok(configuredCapabilities(projection(), agentMutationPolicy).find(item => item.entity === 'Page').actions.includes('create'))
})

test('user creation follows form fields but cannot assign roles or modify existing identities', async() => {
  const { config, host, calls } = setup()
  config.entities.User.form.fields = ['username', { property: 'plainPassword', type: 'password' }, { property: 'roles', type: 'json' }]
  const capability = host.getWriteCapabilities().find(item => item.entity === 'User')
  assert.ok(capability.actionFields.create.plainPassword)
  assert.equal(Object.hasOwn(capability.actionFields.create, 'roles'), false)
  await assert.rejects(host.prepareRecordChange({ entity: 'User', action: 'create', changes: { username: 'example', roles: ['ROLE_ADMIN'] } }), /Unsupported change field/)
  const preview = await host.prepareRecordChange({ entity: 'User', action: 'create', changes: { username: 'example' } })
  await host.commitRecordChange(preview.operationId)
  assert.equal(calls.at(-1)[1], '/api/v1/manage/users')
  await assert.rejects(host.prepareRecordChange({ entity: 'User', action: 'update', identifier: '7', changes: { username: 'replacement' } }), /not available/)
})

test('schema-form patches preserve omitted data and reject hidden nested clears', async() => {
  const { config, host, calls, setRecord } = setup()
  config.entities.Page.form.fields = [{ property: 'settings', type: 'json_schema', type_options: { schema: {
    type: 'object', properties: { public: { type: 'boolean' } }
  } } }]
  setRecord({ id: 7, settings: { public: false, privateUnconfigured: 'unchanged' } })
  await assert.rejects(host.prepareRecordChange({ entity: 'Page', action: 'update', identifier: '7', changes: { settings: null } }), /hidden or read-only/)
  const preview = await host.prepareRecordChange({ entity: 'Page', action: 'update', identifier: '7', changes: { settings: { public: true } } })
  await host.commitRecordChange(preview.operationId)
  assert.deepEqual(calls.at(-1)[2], { settings: { public: true, privateUnconfigured: 'unchanged' } })
})

test('aborted confirmation cannot send and malformed acknowledgements stay unknown', async() => {
  const { host, calls } = setup({ post: async() => undefined })
  const preview = await host.prepareRecordChange({ entity: 'Page', action: 'create', changes: { title: 'New' } })
  const abort = new AbortController()
  abort.abort()
  await assert.rejects(host.commitRecordChange(preview.operationId, abort.signal), error => error.name === 'AbortError')
  assert.equal(calls.length, 0)
  await assert.rejects(host.commitRecordChange(preview.operationId), /did not acknowledge/)
  assert.equal((await host.getRecordChangeStatus(preview.operationId)).status, 'unknown')
})

test('explicit delete whitelist still uses the ordinary DELETE endpoint and snapshots the target', async() => {
  const { host, calls, setRecord } = setup()
  const preview = await host.prepareRecordChange({ entity: 'Comment', action: 'delete', identifier: '7' })
  setRecord({ id: 7, title: 'Changed' })
  await assert.rejects(host.commitRecordChange(preview.operationId), error => error.code === 'proposal_conflict')
  const fresh = await host.prepareRecordChange({ entity: 'Comment', action: 'delete', identifier: '7' })
  assert.equal((await host.commitRecordChange(fresh.operationId)).status, 'succeeded')
  assert.equal(calls.at(-1)[0], 'delete')
})

function hostFor(entities, { availability, special = false, record = { id: 7 } } = {}) {
  const calls = []
  const config = {
    entities: Object.fromEntries(Object.entries(entities).map(([name, fields]) => [name, {
      form: { fields }, list: {}, detail: {}
    }])),
    routes: Object.keys(entities).map(name => ({ name: `${name}List`, path: `/${name.toLowerCase()}/list` }))
  }
  const request = {
    get: async(...args) => { calls.push(['get', ...args]); return { data: structuredClone(record) } },
    post: async(...args) => { calls.push(['post', ...args]); return { data: { id: 8 } } },
    put: async(...args) => { calls.push(['put', ...args]); return { data: { id: 7 } } },
    delete: async(...args) => { calls.push(['delete', ...args]); return { data: null } }
  }
  const host = createConfigMutationHost({
    request,
    policy: agentMutationPolicy,
    getProjection: () => buildAdminConfigProjection(config),
    isActionAvailable: availability,
    specialWrite: special ? specialWrite : undefined,
    now: () => 1800000000000,
    randomId: () => `operation-${calls.length}`
  })
  return { host, calls, config }
}

test('currency form controls accept major units and submit the configured minor units', async() => {
  const { host, calls } = hostFor({ Invoice: [
    { property: 'amount', type: 'currency', type_options: { multiplier: 100, currency: 'CNY' } }
  ] }, { record: { id: 7, amount: 1250 } })
  const preview = await host.prepareRecordChange({ entity: 'Invoice', action: 'update', identifier: '7', changes: { amount: 19.9 } })
  assert.deepEqual(preview.differences, [{ property: 'amount', before: 1250, after: 1990 }])
  await host.commitRecordChange(preview.operationId)
  assert.deepEqual(calls.at(-1).slice(0, 3), ['put', '/api/v1/manage/invoices/7', { amount: 1990 }])
})

test('actions whose CRUD route is absent from the loaded API document are not advertised', () => {
  const { host } = hostFor({ Invoice: ['amount'] }, {
    availability: (entity, action) => !(entity === 'Invoice' && action === 'update')
  })
  const [invoice] = host.getWriteCapabilities()
  assert.deepEqual(invoice.actions, ['create'])
  assert.equal(invoice.actionFields.update, undefined)
})

test('Store status uses the dedicated endpoint and never silently ignores the form field', async() => {
  const { host, calls } = hostFor({ Store: ['name', { property: 'status', type: 'select', default_value: 'activate',
    type_options: { options: [{ value: 'activate' }, { value: 'suspend' }, { value: 'close' }] } }, 'paymentSetting'] },
  { special: true, record: { id: 7, uuid: '550e8400-e29b-41d4-a716-446655440007', name: 'Store', status: 'active' } })
  assert.equal(host.getWriteCapabilities()[0].actionFields.create.paymentSetting, undefined)
  assert.equal(host.getWriteCapabilities()[0].actionFields.update.paymentSetting, undefined)
  await assert.rejects(host.prepareRecordChange({ entity: 'Store', action: 'create', changes: { name: 'S', status: 'suspend' } }), /change its status separately/)
  const created = await host.prepareRecordChange({ entity: 'Store', action: 'create', changes: { name: 'S', status: 'activate' } })
  await host.commitRecordChange(created.operationId)
  assert.deepEqual(calls.at(-1).slice(0, 3), ['post', '/api/v1/manage/stores', { name: 'S' }])
  const updated = await host.prepareRecordChange({ entity: 'Store', action: 'update', identifier: '7', changes: { status: 'suspend' } })
  await host.commitRecordChange(updated.operationId)
  assert.deepEqual(calls.at(-1).slice(0, 3), ['post', '/api/v1/manage/stores/550e8400-e29b-41d4-a716-446655440007/status/suspend', {}])
  await assert.rejects(host.prepareRecordChange({ entity: 'Store', action: 'update', identifier: '7', changes: { name: 'N', status: 'close' } }), /separately/)
})

test('User creation maps the admin plainPassword control to the backend password contract', async() => {
  const { host, calls } = hostFor({ User: ['username', 'email', { property: 'plainPassword', type: 'password' }, 'roles'] }, { special: true })
  const caps = host.getWriteCapabilities()[0]
  assert.deepEqual(Object.keys(caps.actionFields.create).sort(), ['email', 'plainPassword', 'username'])
  const preview = await host.prepareRecordChange({ entity: 'User', action: 'create', changes: { username: 'u', email: 'u@example.test', plainPassword: 'temporary-value' } })
  await host.commitRecordChange(preview.operationId)
  assert.deepEqual(calls.at(-1).slice(0, 3), ['post', '/api/v1/manage/users', { username: 'u', email: 'u@example.test', password: 'temporary-value' }])
})

test('fields ignored by the backend controller are absent from the actionable capability', () => {
  const { host } = hostFor({
    Comment: ['body', 'entityType', 'entityId', 'status', 'parent'],
    Material: ['code', 'name', 'kind', 'unit', 'status'],
    Counter: ['storeUuid', 'code', 'name', 'capacity']
  })
  const comment = host.getWriteCapabilities().find(item => item.entity === 'Comment')
  assert.equal(comment.actionFields.update.entityType, undefined)
  assert.equal(comment.actionFields.update.entityId, undefined)
  assert.equal(comment.actionFields.update.parent, undefined)
  assert.ok(comment.actionFields.update.body)
  assert.equal(host.getWriteCapabilities().find(item => item.entity === 'Material').actionFields.update.code, undefined)
  assert.equal(host.getWriteCapabilities().find(item => item.entity === 'Counter').actionFields.update.storeUuid, undefined)
})

test('WeChat identity and store binding exceptions fail closed', () => {
  const { host } = hostFor({
    StoreWechatSetting: ['storeUuid', 'miniappUuid', 'officialUuid', 'merchantUuid'],
    WechatUser: ['openid', 'unionid', 'appType', 'sessionKey', 'rawData', 'appId', 'user', 'nickname']
  })
  assert.equal(host.getWriteCapabilities().some(item => item.entity === 'StoreWechatSetting'), false)
  const wechat = host.getWriteCapabilities().find(item => item.entity === 'WechatUser')
  assert.deepEqual(Object.keys(wechat.actionFields.create).sort(), ['appType', 'nickname', 'openid', 'unionid'])
})

test('nested schema constraints are enforced before a preview can be confirmed', async() => {
  const { config, host, setRecord } = setup()
  setRecord({ id: 7, settings: { copies: 1 } })
  config.entities.Page.form.fields = [{ property: 'settings', type: 'json_schema', type_options: { schema: {
    type: 'object', additionalProperties: false,
    properties: { copies: { type: 'integer', minimum: 1, maximum: 5 }, note: { type: 'string', maxLength: 4 } },
    required: ['copies']
  } } }]
  await assert.rejects(host.prepareRecordChange({ entity: 'Page', action: 'update', identifier: '7', changes: { settings: { copies: 6 } } }), /maximum/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Page', action: 'update', identifier: '7', changes: { settings: { copies: 1, hidden: true } } }), /hidden or read-only|Unsupported nested field/)
  await assert.rejects(host.prepareRecordChange({ entity: 'Page', action: 'update', identifier: '7', changes: { settings: { copies: 1, note: 'longer' } } }), /too long/)
  const preview = await host.prepareRecordChange({ entity: 'Page', action: 'update', identifier: '7', changes: { settings: { copies: 2 } } })
  assert.equal(preview.saved, false)
})

test('OrderCounter creation maps the configured relation name to counterUuid', async() => {
  const { host, calls } = hostFor({ OrderCounter: [
    { property: 'tradeOrderUuid', type: 'RelationToOne', relation: { target: 'Order', valueKey: 'uuid' } },
    { property: 'counter', type: 'RelationToOne', relation: { target: 'Counter', valueKey: 'uuid' } }
  ] }, { special: true })
  const preview = await host.prepareRecordChange({ entity: 'OrderCounter', action: 'create', changes: {
    tradeOrderUuid: '550e8400-e29b-41d4-a716-446655440001',
    counter: '550e8400-e29b-41d4-a716-446655440002'
  } })
  await host.commitRecordChange(preview.operationId)
  assert.deepEqual(calls.at(-1).slice(0, 3), ['post', '/api/v1/manage/order-counters', {
    tradeOrderUuid: '550e8400-e29b-41d4-a716-446655440001',
    counterUuid: '550e8400-e29b-41d4-a716-446655440002'
  }])
})
