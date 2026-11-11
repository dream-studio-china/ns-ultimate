import assert from 'node:assert/strict'
import test from 'node:test'
import { buildPromotionDraft, createPromotionWorkbench, promotionDraftTools } from '../adapters/ns-ultimate/admin/promotion-workbench.mjs'
import { createAssistantTools, executeAssistantTool } from '../src/tools.js'
import { createConfigMutationHost } from '../src/config-mutation-host.js'
import { buildAdminConfigProjection } from '../src/admin-config-policy.js'
import { agentMutationPolicy } from '../adapters/ns-ultimate/admin/write-policy.mjs'
import { normalizePreparedBatch } from '../src/import-workbench.js'

const cases = [9999, 10000].map((unitPrice, index) => ({
  items: [{ specificationId: 1, quantity: 1, unitPrice }], expected: { totalAmount: index ? 9000 : 9999 }
}))

function fixture() {
  let revision = 'template-v1'
  let available = true
  const requests = []
  const draft = buildPromotionDraft({ kind: 'full_reduction', name: 'Threshold promotion', threshold: 100, amount: 10 })
  const request = {
    async post(path, body) {
      requests.push({ method: 'post', path, body })
      return { data: { valid: true, errors: [], parameterSchema: { type: 'object', additionalProperties: false, properties: {}, required: [] },
        templateRevision: body.templateId ? revision : null, warnings: ['Single rule only.'],
        ...(body.cases ? { passed: true, cases: body.cases.map(item => ({ actual: item.expected, passed: true })) } : {}) } }
    },
    async get(path) { requests.push({ method: 'get', path }); return { data: { ...draft.template, id: 4 } } }
  }
  const workbench = createPromotionWorkbench({ request, path: '/api/v1/manage/promotion-drafts/check', isAvailable: () => available })
  return { workbench, request, requests, draft, setRevision: value => { revision = value }, disable: () => { available = false } }
}

test('recipes generate threshold minor units, amount major units and parameterized rates', () => {
  const reduction = buildPromotionDraft({ kind: 'full_reduction', name: 'Promotion', threshold: 100, amount: 10 })
  assert.deepEqual(reduction.config, { threshold: 10000, amount: 10 })
  assert.match(reduction.template.dsl, /cart.subtotal >= config.threshold/)
  assert.match(reduction.template.dsl, /discount order config.amount/)
  assert.equal(reduction.template.enabled, false)
  for (const [kind, values, text] of [
    ['discount', { rate: 90 }, 'discount order config.rate%'],
    ['item_discount', { rate: 90, specificationIds: [4] }, 'discount items config.rate%'],
    ['member_discount', { rate: 90, minLevel: 'gold' }, 'member discount 90%'],
    ['nth_discount', { rate: 50, position: 2 }, 'discount item 2 50%'],
    ['gift', { giftSpecId: 4, giftQuantity: 1 }, 'add gift spec 4 count 1'],
    ['free_shipping', {}, 'free shipping']
  ]) assert.ok(buildPromotionDraft({ kind, name: 'Promotion', ...values }).template.dsl.includes(text))
  for (const input of [
    { kind: 'discount', rate: 101 }, { kind: 'discount', rate: '90' }, { kind: 'gift', giftSpecId: 1 },
    { kind: 'full_reduction', amount: 10, threshold: 1.001 }, { kind: 'discount', rate: 90, unknown: true }
  ]) assert.throws(() => buildPromotionDraft({ name: 'Promotion', ...input }))
})

test('draft tools are opt-in, do not replace built-ins and never offer submit', async() => {
  assert.ok(!createAssistantTools([]).some(item => item.function.name === 'check_promotion_draft'))
  const tools = createAssistantTools([], [], {}, [], {}, [], [], {}, [...promotionDraftTools, { ...promotionDraftTools[0], function: { name: 'list_entities' } }])
  assert.equal(tools.filter(item => item.function.name === 'list_entities').length, 1)
  assert.ok(!tools.some(item => /submit|commit/.test(item.function.name)))
  const { workbench, draft, requests } = fixture()
  const result = await executeAssistantTool({ call: { type: 'function', function: { name: 'check_promotion_draft', arguments: JSON.stringify({ template: draft.template, config: draft.config, cases }) } }, host: workbench })
  assert.equal(result.passed, true)
  assert.equal(requests[0].path, '/api/v1/manage/promotion-drafts/check')
})

test('campaign preview fails closed without distinct expected-result evidence', async() => {
  const { workbench, draft } = fixture()
  const input = { entity: 'Promotion', action: 'create', record: {}, changes: { template: 4, config: draft.config } }
  await assert.rejects(workbench.beforeChange(input), /at least two/)
  await workbench.runDraftTool('check_promotion_draft', { templateId: 4, config: draft.config, cases: [cases[0], cases[0]] })
  await assert.rejects(workbench.beforeChange(input), /at least two/)
  await workbench.runDraftTool('check_promotion_draft', { templateId: 4, config: draft.config, cases })
  const result = await workbench.beforeChange(input)
  assert.equal(result.dependency, 'template-v1')
  assert.equal(result.evidence.scope, 'single_rule')
  await assert.rejects(workbench.beforeChange({ ...input, changes: { ...input.changes, config: { ...draft.config, amount: 11 } } }), /at least two/)
})

test('freshly read acknowledged template can reuse exact inline evidence, never guessed IDs', async() => {
  const { workbench, draft } = fixture()
  await workbench.runDraftTool('check_promotion_draft', { template: draft.template, config: draft.config, cases })
  const result = await workbench.beforeChange({ entity: 'Promotion', action: 'create', record: {}, changes: { template: 4, config: draft.config } })
  assert.equal(result.evidence.scope, 'single_rule')
})

test('a later failing expectation invalidates earlier passing evidence for the same draft', async() => {
  const { workbench, draft, request } = fixture()
  await workbench.runDraftTool('check_promotion_draft', { templateId: 4, config: draft.config, cases })
  const original = request.post
  request.post = async(...args) => {
    const result = await original(...args)
    if (args[1].cases) result.data.passed = false
    return result
  }
  await workbench.runDraftTool('check_promotion_draft', { templateId: 4, config: draft.config, cases })
  await assert.rejects(workbench.beforeChange({ entity: 'Promotion', action: 'create', record: {}, changes: { template: 4, config: draft.config } }), /at least two/)
})

test('template revisions, session disposal, unavailable navigation and cancellation invalidate evidence', async() => {
  const { workbench, draft, setRevision, disable, requests } = fixture()
  await workbench.runDraftTool('check_promotion_draft', { templateId: 4, config: draft.config, cases })
  const input = { entity: 'Promotion', action: 'create', record: {}, changes: { template: 4, config: draft.config } }
  setRevision('template-v2')
  await assert.rejects(workbench.beforeChange(input), /stale/)
  await assert.rejects(workbench.beforeChange(input), /stale/)
  workbench.dispose()
  await assert.rejects(workbench.beforeChange(input), /at least two/)
  const count = requests.length
  await assert.rejects(workbench.runDraftTool('check_promotion_draft', { templateId: 4 }, AbortSignal.abort()), /cancelled/)
  assert.equal(requests.length, count)
  disable()
  assert.deepEqual(workbench.getDraftTools(), [])
  await assert.rejects(workbench.runDraftTool('check_promotion_draft', { templateId: 4 }), /not available/)
})

test('invalid drafts, oversized results and arbitrary endpoints cannot become evidence', async() => {
  const workbench = createPromotionWorkbench({ request: { post: async() => ({ data: { valid: false, errors: [{ code: 'missing_config' }] } }) }, path: '/fixed', isAvailable: () => true })
  await assert.rejects(workbench.beforeChange({ entity: 'Promotion', action: 'create', record: {}, changes: { template: 4, config: {} } }), /missing_config/)
  await assert.rejects(workbench.runDraftTool('check_promotion_draft', { templateId: 4, path: '/evil' }), /Provide template/)
  await assert.rejects(workbench.runDraftTool('check_promotion_draft', { templateId: 4, template: {} }), /Provide template/)
})

test('existing campaigns have a fixed bounded inspection tool without generic JSON reads', async() => {
  const { workbench, requests } = fixture()
  await workbench.runDraftTool('read_promotion_draft', { campaignId: 7 })
  assert.deepEqual(requests[0].body, { campaignId: 7 })
  assert.equal(requests[0].path, '/api/v1/manage/promotion-drafts/check')
  await assert.rejects(workbench.runDraftTool('read_promotion_draft', { campaignId: 7, fields: ['settings'] }), /only an exact/)
  await assert.rejects(workbench.runDraftTool('read_promotion_draft', { campaignId: '7' }), /only an exact/)
})

test('generic validation hook retains UI evidence and rechecks dependencies before confirmed CRUD', async() => {
  const projection = buildAdminConfigProjection({ entities: { Promotion: { entity: { name: 'Promotion', plural: 'promotions' }, form: { fields: [{ property: 'name', required: true }] }, list: { disabled_actions: [] } } }, routes: [{ path: '/promotion/list', name: 'PromotionList' }] })
  let dependency = 'v1'
  let writes = 0
  const host = createConfigMutationHost({ getProjection: () => projection, policy: agentMutationPolicy,
    request: { post: async() => { writes++; return { data: { id: 1 } } } },
    beforeChange: async() => ({ dependency, evidence: { valid: true } }) })
  const preview = await host.prepareRecordChange({ entity: 'Promotion', action: 'create', changes: { name: 'Promotion' } })
  assert.deepEqual(preview.validation, { valid: true })
  assert.equal(writes, 0)
  dependency = 'v2'
  await assert.rejects(host.commitRecordChange(preview.operationId), /record changed/)
  assert.equal(writes, 0)
  const fresh = await host.prepareRecordChange({ entity: 'Promotion', action: 'create', changes: { name: 'Promotion' } })
  await host.commitRecordChange(fresh.operationId)
  assert.equal(writes, 1)
})

test('batch previews retain validation evidence and changed dependencies block all rows before writes', async() => {
  const projection = buildAdminConfigProjection({ entities: { Promotion: { entity: { name: 'Promotion', plural: 'promotions' }, form: { fields: [{ property: 'name', required: true }] }, list: { disabled_actions: [] } } }, routes: [{ path: '/promotion/list', name: 'PromotionList' }] })
  let dependency = 'v1'
  let writes = 0
  const host = createConfigMutationHost({ getProjection: () => projection, policy: agentMutationPolicy,
    request: { post: async() => { writes++; return { data: { id: 1 } } } },
    beforeChange: async() => ({ dependency, evidence: { scope: 'fixture_only', cases: [{ passed: true }] } }) })
  const preview = await host.prepareRecordBatch({ entity: 'Promotion', action: 'create', rows: [{ name: 'One' }, { name: 'Two' }] })
  const normalized = normalizePreparedBatch(preview, { entity: 'Promotion', fields: [{ property: 'name' }], records: [
    { row: 1, values: { name: 'One' } }, { row: 2, values: { name: 'Two' } }
  ] })
  assert.equal(normalized.records[0].validation.cases[0].passed, true)
  dependency = 'v2'
  await assert.rejects(host.commitRecordBatch(preview.operationId), /dependency changed/)
  assert.equal(writes, 0)
})
