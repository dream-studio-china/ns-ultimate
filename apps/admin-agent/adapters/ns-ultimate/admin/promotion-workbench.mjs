import { safeJson } from '../../../src/config-mutation-host.js'

const object = value => value !== null && typeof value === 'object' && !Array.isArray(value)
const canonical = value => JSON.stringify(value, (_, item) => object(item)
  ? Object.fromEntries(Object.keys(item).sort().map(key => [key, item[key]])) : item)
const templateValue = value => Object.fromEntries(['type', 'phase', 'dsl', 'fields'].filter(key => value[key] !== undefined).map(key => [key, value[key]]))
const tool = (name, description, properties, required) => ({ type: 'function', function: {
  name, description, parameters: { type: 'object', properties, required, additionalProperties: false }
} })

const stackItemSchema = { type: 'object', properties: {
  specificationId: { type: 'integer', minimum: 1 }, quantity: { type: 'integer', minimum: 1, maximum: 1000 },
  unitPrice: { type: 'integer', minimum: 0, maximum: 100000000, description: 'Unit price in integer MINOR units.' }
}, required: ['specificationId', 'quantity', 'unitPrice'], additionalProperties: false }
const stackExpectedSchema = { type: 'object', properties: {
  totalAmount: { type: 'integer', minimum: 0, description: 'Expected final total in integer MINOR units.' },
  freeShipping: { type: 'boolean' },
  appliedCampaignIds: { type: 'array', maxItems: 40, items: { type: 'integer', minimum: 1 }, description: 'Expected exact application order; existing campaigns only.' }
}, additionalProperties: false }
const stackCaseSchema = { type: 'object', properties: {
  name: { type: 'string', maxLength: 80 },
  items: { type: 'array', minItems: 1, maxItems: 20, items: stackItemSchema },
  memberLevel: { type: 'string', enum: ['bronze', 'silver', 'gold', 'platinum', 'diamond'] },
  expected: stackExpectedSchema
}, required: ['items'], additionalProperties: false }
const stackDraftSchema = { type: 'object', properties: {
  name: { type: 'string', maxLength: 120 },
  type: { type: 'string', enum: ['full_reduction', 'discount', 'member_discount', 'nth_discount', 'gift', 'free_shipping'] },
  phase: { type: 'integer', enum: [0, 1] },
  dsl: { type: 'string' }, config: { type: 'object' },
  conflictMode: { type: 'string', enum: ['stackable', 'exclusive', 'lock_item', 'best_price'] }
}, required: ['type', 'dsl', 'config'], additionalProperties: false }

export const promotionDraftTools = [
  tool('read_promotion_draft', 'Inspect an exact known campaign numeric ID before editing its parameters. Returns only validated consumed config values, templateId and parameter contracts; unsupported legacy configurations fail closed. This reads current data but saves nothing. Never guess IDs.', { campaignId: { type: 'integer', minimum: 1 } }, ['campaignId']),
  tool('simulate_promotion_stack', 'Run the REAL existing promotion calculator on sample carts and report which campaigns apply, in order, with totals after each step. Read-only: nothing is saved. Modes: environment (all currently eligible campaigns for storeCode), selected (only exact existing campaignIds), drafts (unsaved validated campaigns with synthetic negative IDs; assumes enabled, no schedule, this storeCode). Cases use integer MINOR units, exactly like the draft checker. For verification, supply expected.totalAmount from the user intent for EVERY case; include boundary and non-matching carts and expected.appliedCampaignIds when application order matters and all campaigns exist. Supply 1-4 cases (two distinct passing cases are the minimum practical evidence). This does NOT simulate base price, tax/shipping fees, payment, catalog existence, concurrent changes or the checkout pipeline; campaign time/enabled/store eligibility is a single evaluation-time snapshot. Report failures, appliedCount/appliedTruncated and notAppliedCampaignIds honestly; never infer why a runtime campaign did not match from this result.', {
    mode: { type: 'string', enum: ['environment', 'selected', 'drafts'] },
    storeCode: { type: 'string', maxLength: 50, description: 'Exact store code; empty string means global-only. Required for environment/selected.' },
    currency: { type: 'string', pattern: '^[A-Z]{3}$' },
    campaignIds: { type: 'array', minItems: 1, maxItems: 20, items: { type: 'integer', minimum: 1 } },
    drafts: { type: 'array', minItems: 1, maxItems: 20, items: stackDraftSchema },
    cases: { type: 'array', minItems: 1, maxItems: 4, items: stackCaseSchema }
  }, ['mode', 'cases']),
  tool('build_promotion_draft', 'Build a deterministic unsaved template, parameter contract and campaign config. Money inputs use major units; rates mean percent KEPT (90 = 10% off). For advanced rules author DSL then use check_promotion_draft. Never invent specification IDs. This saves nothing.', {
    kind: { type: 'string', enum: ['full_reduction', 'discount', 'item_discount', 'member_discount', 'nth_discount', 'gift', 'free_shipping'] },
    name: { type: 'string', maxLength: 120 },
    threshold: { type: 'number', minimum: 0, description: 'Optional cart threshold in major units.' },
    amount: { type: 'number', minimum: 0 }, rate: { type: 'number', minimum: 0, maximum: 100 },
    specificationIds: { type: 'array', items: { type: 'integer', minimum: 1 }, maxItems: 100 },
    minLevel: { type: 'string', enum: ['bronze', 'silver', 'gold', 'platinum', 'diamond'] },
    position: { type: 'integer', minimum: 1 }, giftSpecId: { type: 'integer', minimum: 1 }, giftQuantity: { type: 'integer', minimum: 1, maximum: 99 }
  }, ['kind', 'name']),
  tool('check_promotion_draft', 'Validate unsaved DSL and campaign parameters with the backend parser and strategy constraints. Supply template OR an existing numeric templateId. Omit config only to inspect a template contract. Optional 1-8 cases execute REAL single-rule strategies, NOT live eligibility/stacking. Each case: {name?,items:[{specificationId,quantity,unitPrice}],memberLevel?,expected?:{matched,totalAmount,freeShipping,items}}. Item unitPrice and expected totalAmount use integer MINOR units. Fix structured errors and recheck. Before preparing a campaign run at least two cases with expected totalAmount, including boundary/non-match cases. Expected values must reflect user intent, not copy actual output. Saves nothing.', {
    template: { type: 'object', description: 'Draft {type,phase,dsl,fields?}. fields is the parameterSchema object returned by this tool, optionally narrowed with bounds.' },
    templateId: { type: 'integer', minimum: 1 },
    config: { type: 'object', description: 'Complete parameter values, including implicit strategy keys. Follow parameterSchema; never guess keys.' },
    cases: { type: 'array', minItems: 1, maxItems: 8, items: { type: 'object' } }
  }, [])
]

export function buildPromotionDraft(input) {
  if (!object(input) || !safeJson(input) || !['full_reduction', 'discount', 'item_discount', 'member_discount', 'nth_discount', 'gift', 'free_shipping'].includes(input.kind)
    || typeof input.name !== 'string' || !input.name.trim() || input.name.length > 120) throw new TypeError('Provide a supported kind and a bounded name.')
  const required = { full_reduction: ['amount'], discount: ['rate'], item_discount: ['rate', 'specificationIds'], member_discount: ['rate', 'minLevel'], nth_discount: ['rate', 'position'], gift: ['giftSpecId', 'giftQuantity'], free_shipping: [] }[input.kind]
  if (Object.keys(input).some(key => !['kind', 'name', 'threshold', ...required].includes(key)) || required.some(key => input[key] === undefined)) throw new TypeError('Missing required recipe values or unsupported recipe fields.')
  const number = (key, minimum, maximum, integer = false) => {
    const value = input[key]
    if (typeof value !== 'number' || !Number.isFinite(value) || value < minimum || value > maximum || integer && !Number.isSafeInteger(value)) throw new TypeError(`Invalid ${key}.`)
    return value
  }
  const config = {}
  const type = input.kind === 'item_discount' ? 'discount' : input.kind
  const lines = [`type: ${type}`, 'phase: inner']
  if (input.threshold !== undefined) {
    const value = number('threshold', 0, 1000000)
    if (Math.abs(value * 100 - Math.round(value * 100)) > 1e-7) throw new TypeError('Threshold accepts at most two decimal places.')
    config.threshold = Math.round(value * 100)
    lines.push('when:', '  cart.subtotal >= config.threshold')
  }
  let action
  switch (input.kind) {
    case 'full_reduction': config.amount = number('amount', 0, 1000000); action = 'discount order config.amount'; break
    case 'discount': config.rate = number('rate', 0, 100); action = 'discount order config.rate%'; break
    case 'item_discount':
      config.rate = number('rate', 0, 100)
      if (!Array.isArray(input.specificationIds) || !input.specificationIds.length || input.specificationIds.length > 100 || input.specificationIds.some(id => !Number.isSafeInteger(id) || id < 1)) throw new TypeError('Provide known positive specificationIds.')
      config.specification_ids = input.specificationIds
      action = 'discount items config.rate%'
      break
    case 'member_discount':
      if (!['bronze', 'silver', 'gold', 'platinum', 'diamond'].includes(input.minLevel)) throw new TypeError('Invalid minLevel.')
      config.min_level = input.minLevel
      action = `member discount ${number('rate', 0, 100)}%`
      break
    case 'nth_discount': action = `discount item ${number('position', 1, 1000, true)} ${number('rate', 0, 100)}%`; break
    case 'gift': action = `add gift spec ${number('giftSpecId', 1, 2147483647, true)} count ${number('giftQuantity', 1, 99, true)}`; break
    case 'free_shipping': action = 'free shipping'; break
  }
  lines.push('do:', `  ${action}`)
  return { template: { name: input.name, type, phase: 0, enabled: false, dsl: `${lines.join('\n')}\n` }, config, saved: false }
}

/** Host-owned endpoints only. Evidence is bound to exact draft/config values. */
export function createPromotionWorkbench({ request, path, stackPath, isAvailable }) {
  const evidence = new Map()
  const available = () => { if (!isAvailable()) throw new Error('Promotion authoring is not available in the current admin navigation.') }
  async function check(input, signal, remember = true) {
    available()
    if (!object(input) || !safeJson(input) || JSON.stringify(input).length > 60000
      || Object.keys(input).some(key => !['template', 'templateId', 'config', 'cases'].includes(key))
      || Number(Object.hasOwn(input, 'template')) + Number(Object.hasOwn(input, 'templateId')) !== 1
      || object(input.template) === (Number.isSafeInteger(input.templateId) && input.templateId > 0)) throw new TypeError('Provide template or a positive templateId, optional config and bounded cases.')
    if (signal?.aborted) throw new Error('Draft checking was cancelled.')
    const body = { ...input, ...(input.template ? { template: templateValue(input.template) } : {}) }
    const response = await request.post(path, body, { signal, timeout: 60000 })
    if (signal?.aborted) throw new Error('Draft checking was cancelled.')
    const result = response?.data
    if (!object(result) || typeof result.valid !== 'boolean' || !Array.isArray(result.errors) || !safeJson(result) || JSON.stringify(result).length > 60000) throw new Error('The draft check response is unavailable or oversized.')
    const key = canonical({ ...body, cases: undefined })
    // A later explicit failed/unchecked run supersedes earlier passing evidence.
    if (remember) evidence.delete(key)
    if (remember && result.valid && result.passed === true && Array.isArray(input.cases) && input.cases.length >= 2
      && new Set(input.cases.map(item => canonical({ items: item.items, memberLevel: item.memberLevel }))).size >= 2
      && input.cases.every(item => object(item.expected) && Object.hasOwn(item.expected, 'totalAmount'))) {
      evidence.set(key, JSON.parse(JSON.stringify({ cases: input.cases, templateRevision: result.templateRevision, summary: result.cases })))
      if (evidence.size > 20) evidence.delete(evidence.keys().next().value)
    }
    return result
  }
  async function simulateStack(input, signal) {
    available()
    if (!object(input) || !safeJson(input) || JSON.stringify(input).length > 60000
      || Object.keys(input).some(key => !['mode', 'storeCode', 'currency', 'campaignIds', 'drafts', 'cases'].includes(key))) throw new TypeError('Unsupported stack simulation parameters.')
    if (!['environment', 'selected', 'drafts'].includes(input.mode)) throw new TypeError('Invalid simulation mode.')
    if (!Array.isArray(input.cases) || input.cases.length < 1 || input.cases.length > 4) throw new TypeError('Provide 1-4 sample cases.')
    const storeCode = input.storeCode
    if (input.mode !== 'drafts' && (typeof storeCode !== 'string' || storeCode.length > 50)) throw new TypeError('storeCode is required for existing-campaign simulation.')
    if (storeCode !== undefined && (typeof storeCode !== 'string' || storeCode.length > 50)) throw new TypeError('Invalid storeCode.')
    if (input.currency !== undefined && (typeof input.currency !== 'string' || !/^[A-Z]{3}$/.test(input.currency))) throw new TypeError('Invalid currency.')
    if (input.mode === 'selected' ? !Array.isArray(input.campaignIds) || Object.hasOwn(input, 'drafts') : input.mode === 'drafts' ? !Array.isArray(input.drafts) || Object.hasOwn(input, 'campaignIds') : Object.hasOwn(input, 'campaignIds') || Object.hasOwn(input, 'drafts')) throw new TypeError('Use campaignIds only in selected mode and drafts only in drafts mode.')
    if (input.mode === 'selected' && (input.campaignIds.length < 1 || input.campaignIds.length > 20 || input.campaignIds.some(id => !Number.isSafeInteger(id) || id < 1) || new Set(input.campaignIds).size !== input.campaignIds.length)) throw new TypeError('Invalid campaignIds.')
    if (input.mode === 'drafts' && (input.drafts.length < 1 || input.drafts.length > 20)) throw new TypeError('Invalid drafts.')
    if (signal?.aborted) throw new Error('Stack simulation was cancelled.')
    const response = await request.post(stackPath, input, { signal, timeout: 60000 })
    if (signal?.aborted) throw new Error('Stack simulation was cancelled.')
    const result = response?.data
    if (!object(result) || !Array.isArray(result.cases) || typeof result.verified !== 'boolean' || !Array.isArray(result.warnings)
      || !safeJson(result) || JSON.stringify(result).length > 60000) throw new Error('The stack simulation response is unavailable or oversized.')
    return result
  }
  async function runDraftTool(name, input, signal) {
    if (name === 'simulate_promotion_stack') return simulateStack(input, signal)
    if (name === 'read_promotion_draft') {
      available()
      if (!object(input) || Object.keys(input).length !== 1 || !Number.isSafeInteger(input.campaignId) || input.campaignId < 1) throw new TypeError('Provide only an exact positive campaignId.')
      if (signal?.aborted) throw new Error('Draft inspection was cancelled.')
      const response = await request.post(path, input, { signal, timeout: 60000 })
      if (signal?.aborted) throw new Error('Draft inspection was cancelled.')
      if (!object(response?.data) || typeof response.data.valid !== 'boolean' || !safeJson(response.data) || JSON.stringify(response.data).length > 60000) throw new Error('Draft inspection is unavailable or oversized.')
      return response.data
    }
    if (name === 'check_promotion_draft') return check(input, signal)
    if (name !== 'build_promotion_draft') throw new Error('Unknown draft tool.')
    available()
    const draft = buildPromotionDraft(input)
    const result = await check({ template: draft.template, config: draft.config }, signal)
    if (!result.valid) return result
    draft.template.fields = result.parameterSchema
    return { ...draft, parameterSchema: result.parameterSchema, next: 'Run check_promotion_draft with this exact template, config and at least two expected-result cases. Confirm template creation first, then use its acknowledged numeric ID for the campaign.' }
  }
  async function beforeChange({ entity, action, record, changes, signal }) {
    if (!['Promotion', 'PromotionTemplate'].includes(entity) || action === 'delete') return null
    const effective = { ...record, ...changes }
    const input = entity === 'PromotionTemplate' ? { template: templateValue(effective) }
      : { templateId: Number(object(effective.template) ? effective.template.id : effective.template), config: effective.config ?? {} }
    const checked = await check(input, signal, false)
    if (!checked.valid) throw new Error(JSON.stringify(checked.errors).slice(0, 2000))
    let verified = evidence.get(canonical(input))
    // Reuse inline evidence only after reading the acknowledged numeric relation.
    if (entity === 'Promotion' && !verified) {
      const response = await request.get(`${path.replace(/promotion-drafts\/check$/, 'promotion-templates')}/${input.templateId}`, { signal, timeout: 60000 })
      const template = response?.data
      if (!object(template) || template.id !== input.templateId) throw new Error('The template detail response is unavailable.')
      verified = evidence.get(canonical({ template: templateValue(template), config: input.config }))
    }
    if (entity === 'Promotion') {
      if (!verified) throw new Error('Run check_promotion_draft with the exact template/config and at least two passing expected-total cases before preparing this campaign.')
      const tested = await check({ ...input, cases: verified.cases }, signal, false)
      if (!tested.valid || tested.passed !== true || verified.templateRevision && tested.templateRevision !== verified.templateRevision) throw new Error('Draft evidence is stale or failed. Recheck the current template and config.')
      return { dependency: tested.templateRevision, evidence: { scope: 'single_rule', cases: tested.cases, warnings: tested.warnings } }
    }
    return { dependency: canonical(input.template), evidence: { scope: 'template_syntax_and_parameters', parameterSchema: checked.parameterSchema, warnings: checked.warnings } }
  }
  return { getDraftTools: () => isAvailable() ? promotionDraftTools : [], runDraftTool, beforeChange, dispose: () => evidence.clear() }
}
