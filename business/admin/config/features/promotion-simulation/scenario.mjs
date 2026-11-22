export const SCENARIO_VERSION = 1
export const MAX_WORLDS = 4
export const MAX_CASES = 30
export const MAX_CAMPAIGNS = 30
export const MAX_ITEMS = 30
export const CONFLICT_MODES = ['stackable', 'exclusive', 'lock_item', 'best_price']
export const MEMBER_LEVELS = ['bronze', 'silver', 'gold', 'platinum', 'diamond']
export const PRICING_MODES = ['snapshot', 'current_catalog']
export const USER_MODES = ['anonymous', 'level', 'user']
const SAFE_KEY = /^[A-Za-z0-9_.:-]{1,80}$/

const object = value => value !== null && typeof value === 'object' && !Array.isArray(value)
const text = (value, fallback = '') => typeof value === 'string' ? value : fallback
const clone = value => JSON.parse(JSON.stringify(value))

export function newKey(prefix, existing = []) {
  let index = 1
  while (existing.includes(`${prefix}${index}`)) index++
  return `${prefix}${index}`
}

export function localDateTime(value = new Date()) {
  const date = value instanceof Date ? value : new Date(value)
  const pad = number => String(number).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
}

export function newCampaign(overrides = {}) {
  return {
    key: overrides.key || 'campaign:new',
    name: overrides.name || 'New campaign',
    enabled: overrides.enabled ?? true,
    templateEnabled: overrides.templateEnabled ?? true,
    storeCode: overrides.storeCode || '',
    startTime: overrides.startTime ?? null,
    endTime: overrides.endTime ?? null,
    conflictMode: CONFLICT_MODES.includes(overrides.conflictMode) ? overrides.conflictMode : 'stackable',
    config: object(overrides.config) ? clone(overrides.config) : {},
    template: {
      type: overrides.template?.type || 'full_reduction',
      phase: overrides.template?.phase === 1 ? 1 : 0,
      dsl: overrides.template?.dsl || 'type: full_reduction\nwhen:\n  cart.subtotal >= config.threshold\ndo:\n  discount order config.amount\n',
      fields: overrides.template?.fields ?? undefined
    }
  }
}

export function newWorld(overrides = {}, existingKeys = []) {
  return {
    key: overrides.key || newKey('world', existingKeys),
    name: overrides.name || 'Baseline',
    source: overrides.source === 'import' ? 'import' : 'manual',
    revision: overrides.revision ?? null,
    loadedAt: overrides.loadedAt ?? null,
    campaigns: Array.isArray(overrides.campaigns) ? overrides.campaigns.map(normalizeCampaign) : []
  }
}

export function newCase(overrides = {}, existingKeys = []) {
  const user = object(overrides.user) ? overrides.user : {}
  return {
    key: overrides.key || newKey('case', existingKeys),
    name: overrides.name || 'Cart case',
    storeCode: overrides.storeCode || '',
    simulatedAt: overrides.simulatedAt || localDateTime(),
    currency: overrides.currency || 'CNY',
    pricingMode: PRICING_MODES.includes(overrides.pricingMode) ? overrides.pricingMode : 'snapshot',
    items: Array.isArray(overrides.items) && overrides.items.length ? overrides.items.map(newItem) : [newItem()],
    user: {
      mode: USER_MODES.includes(user.mode) ? user.mode : 'anonymous',
      level: user.level || 'bronze',
      userId: Number.isSafeInteger(user.userId) && user.userId > 0 ? user.userId : null
    },
    expected: normalizeExpected(overrides.expected),
    expectedByWorld: normalizeExpectedByWorld(overrides.expectedByWorld),
    boundaryThreshold: overrides.boundaryThreshold ?? ''
  }
}

export function newItem(overrides = {}) {
  return {
    specificationId: Number.isSafeInteger(overrides.specificationId) && overrides.specificationId > 0
      ? overrides.specificationId
      : (typeof overrides.specificationId === 'string' && overrides.specificationId.trim() ? overrides.specificationId.trim() : 1),
    specificationUuid: typeof overrides.specificationUuid === 'string' ? overrides.specificationUuid : '',
    name: typeof overrides.name === 'string' ? overrides.name : '',
    quantity: Number.isSafeInteger(overrides.quantity) && overrides.quantity > 0 ? overrides.quantity : 1,
    unitPrice: Number.isSafeInteger(overrides.unitPrice) && overrides.unitPrice >= 0 ? overrides.unitPrice : 0
  }
}

export function createScenario(now = new Date()) {
  return {
    version: SCENARIO_VERSION,
    name: 'Promotion simulation',
    storeCode: '',
    worlds: [newWorld({ key: 'baseline', name: 'Baseline', source: 'manual' }, [])],
    cases: [newCase({ key: 'case1', name: '100.00 cart', simulatedAt: localDateTime(now), items: [{ specificationId: 1, quantity: 1, unitPrice: 10000 }] }, [])]
  }
}

export function normalizeCampaign(value) {
  if (!object(value)) throw new TypeError('Campaign must be an object.')
  const template = object(value.template) ? value.template : {}
  const campaign = newCampaign({
    ...value,
    key: SAFE_KEY.test(String(value.key)) ? value.key : `campaign:${Math.random().toString(36).slice(2, 10)}`,
    template: {
      type: text(template.type, text(value.type, 'full_reduction')),
      phase: template.phase === 1 ? 1 : 0,
      dsl: text(template.dsl, text(value.dsl, '')),
      fields: template.fields
    }
  })
  if (!campaign.name.trim()) campaign.name = 'Campaign'
  return campaign
}

export function importWorldSnapshot(snapshot, key, name) {
  if (!object(snapshot) || !Array.isArray(snapshot.campaigns)) throw new TypeError('The current-campaign response is invalid.')
  return newWorld({
    key, name, source: 'import', revision: snapshot.revision || null, loadedAt: snapshot.loadedAt || null,
    campaigns: snapshot.campaigns.map(normalizeCampaign)
  })
}

export function normalizeExpected(value) {
  if (!object(value)) return emptyExpected()
  const output = emptyExpected()
  for (const key of ['totalAmount', 'discountAmount', 'giftQuantity']) {
    if (Number.isSafeInteger(value[key]) && value[key] >= 0) output[key] = value[key]
  }
  if (typeof value.freeShipping === 'boolean') output.freeShipping = value.freeShipping
  if (Array.isArray(value.items)) output.items = value.items.filter(item => object(item)).map(item => ({
    specificationId: item.specificationId,
    quantity: Number.isSafeInteger(item.quantity) ? item.quantity : 0,
    price: Number.isSafeInteger(item.price) ? item.price : 0,
    ...(typeof item.isGift === 'boolean' ? { isGift: item.isGift } : {})
  }))
  if (Array.isArray(value.appliedCampaignKeys)) output.appliedCampaignKeys = value.appliedCampaignKeys.filter(item => typeof item === 'string')
  else if (typeof value.appliedCampaignKeys === 'string') output.appliedCampaignKeys = value.appliedCampaignKeys.split(',').map(item => item.trim()).filter(Boolean)
  return output
}

export function normalizeExpectedByWorld(value) {
  if (!object(value)) return {}
  const output = {}
  for (const [key, expected] of Object.entries(value)) if (SAFE_KEY.test(key)) output[key] = normalizeExpected(expected)
  return output
}

export function emptyExpected() {
  return { totalAmount: null, discountAmount: null, freeShipping: null, giftQuantity: null, appliedCampaignKeys: [], items: [] }
}

export function normalizeCase(value) {
  if (!object(value)) throw new TypeError('Case must be an object.')
  return newCase(value, [])
}

export function normalizeScenario(value) {
  if (!object(value) || value.version !== SCENARIO_VERSION) throw new TypeError('Unsupported promotion simulation scenario version.')
  if (!Array.isArray(value.worlds) || value.worlds.length < 1 || value.worlds.length > MAX_WORLDS) throw new TypeError('Scenario requires 1-4 worlds.')
  if (!Array.isArray(value.cases) || value.cases.length < 1 || value.cases.length > MAX_CASES) throw new TypeError('Scenario requires 1-30 cases.')
  const worlds = value.worlds.map(world => newWorld({
    ...world,
    campaigns: Array.isArray(world.campaigns) ? world.campaigns.map(normalizeCampaign) : []
  }))
  const cases = value.cases.map(normalizeCase)
  const scenario = {
    version: SCENARIO_VERSION,
    name: text(value.name, 'Promotion simulation').slice(0, 120),
    storeCode: text(value.storeCode, '').slice(0, 50),
    worlds, cases
  }
  const errors = validateScenario(scenario)
  if (errors.length) throw new TypeError(errors.join(' '))
  return scenario
}

export function validateScenario(scenario) {
  const errors = []
  if (!object(scenario) || !Array.isArray(scenario.worlds) || !Array.isArray(scenario.cases)) return ['Scenario is malformed.']
  if (scenario.worlds.length < 1 || scenario.worlds.length > MAX_WORLDS) errors.push('Scenario requires 1-4 worlds.')
  if (scenario.cases.length < 1 || scenario.cases.length > MAX_CASES) errors.push('Scenario requires 1-30 cases.')
  const worldKeys = new Set()
  for (const world of scenario.worlds) {
    if (!SAFE_KEY.test(String(world.key)) || worldKeys.has(world.key)) errors.push('World keys must be unique safe identifiers.')
    worldKeys.add(world.key)
    if (!world.name?.trim()) errors.push(`World ${world.key} requires a name.`)
    if (!Array.isArray(world.campaigns) || world.campaigns.length > MAX_CAMPAIGNS) errors.push(`World ${world.key} accepts at most ${MAX_CAMPAIGNS} campaigns.`)
    const campaignKeys = new Set()
    for (const campaign of world.campaigns || []) {
      if (!SAFE_KEY.test(String(campaign.key)) || campaignKeys.has(campaign.key)) errors.push(`World ${world.key} has duplicate or invalid campaign keys.`)
      campaignKeys.add(campaign.key)
      if (!campaign.name?.trim()) errors.push(`Campaign ${campaign.key} requires a name.`)
      if (!object(campaign.config)) errors.push(`Campaign ${campaign.key} config must be an object.`)
      if (!campaign.template?.type || !campaign.template?.dsl?.trim()) errors.push(`Campaign ${campaign.key} requires template type and DSL.`)
      if (!CONFLICT_MODES.includes(campaign.conflictMode)) errors.push(`Campaign ${campaign.key} has an invalid conflict mode.`)
    }
  }
  const caseKeys = new Set()
  for (const item of scenario.cases) {
    if (!SAFE_KEY.test(String(item.key)) || caseKeys.has(item.key)) errors.push('Case keys must be unique safe identifiers.')
    caseKeys.add(item.key)
    if (!item.name?.trim()) errors.push(`Case ${item.key} requires a name.`)
    if (!PRICING_MODES.includes(item.pricingMode)) errors.push(`Case ${item.key} has an invalid pricing mode.`)
    if (!Array.isArray(item.items) || item.items.length < 1 || item.items.length > MAX_ITEMS) errors.push(`Case ${item.key} requires 1-${MAX_ITEMS} item groups.`)
    for (const line of item.items || []) {
      const specificationOk = (Number.isSafeInteger(line.specificationId) && line.specificationId > 0)
        || (typeof line.specificationId === 'string' && line.specificationId.trim().length > 0 && line.specificationId.length <= 64)
      if (!specificationOk) errors.push(`Case ${item.key} has an invalid specification ID.`)
      if (!Number.isSafeInteger(line.quantity) || line.quantity < 1 || line.quantity > 1000) errors.push(`Case ${item.key} has an invalid quantity.`)
      if (item.pricingMode === 'snapshot' && (!Number.isSafeInteger(line.unitPrice) || line.unitPrice < 0)) errors.push(`Case ${item.key} requires integer minor-unit prices.`)
    }
  }
  return errors
}

export function buildRunRequest(scenario) {
  const errors = validateScenario(scenario)
  if (errors.length) throw new TypeError(errors.join(' '))
  const scenarioWorldKeys = new Set(scenario.worlds.map(world => world.key))
  return {
    worlds: scenario.worlds.map(world => ({
      key: world.key,
      name: world.name,
      campaigns: world.campaigns.map(campaign => ({
        key: campaign.key,
        name: campaign.name,
        enabled: campaign.enabled,
        templateEnabled: campaign.templateEnabled,
        storeCode: campaign.storeCode || '',
        startTime: campaign.startTime || null,
        endTime: campaign.endTime || null,
        conflictMode: campaign.conflictMode,
        config: campaign.config,
        template: {
          type: campaign.template.type,
          phase: campaign.template.phase,
          dsl: campaign.template.dsl,
          ...(campaign.template.fields === undefined ? {} : { fields: campaign.template.fields })
        }
      }))
    })),
    cases: scenario.cases.map(item => ({
      key: item.key,
      name: item.name,
      storeCode: item.storeCode || '',
      simulatedAt: item.simulatedAt,
      currency: item.currency,
      pricingMode: item.pricingMode,
      items: item.items.map(line => ({
        specificationId: line.specificationId,
        quantity: line.quantity,
        ...(item.pricingMode === 'snapshot' ? { unitPrice: line.unitPrice } : {}),
        ...(line.specificationUuid ? { specificationUuid: line.specificationUuid } : {}),
        ...(line.name ? { name: line.name } : {})
      })),
      user: item.user.mode === 'level' ? { mode: 'level', level: item.user.level }
        : item.user.mode === 'user' ? { mode: 'user', userId: item.user.userId }
          : { mode: 'anonymous' },
      ...(hasExpected(item.expected) ? { expected: cleanExpected(item.expected) } : {}),
      ...(Object.keys(item.expectedByWorld || {}).length ? { expectedByWorld: Object.fromEntries(Object.entries(item.expectedByWorld).filter(([key, value]) => scenarioWorldKeys.has(key) && hasExpected(value)).map(([key, value]) => [key, cleanExpected(value)])) } : {})
    }))
  }
}

export function hasExpected(value) {
  if (!object(value)) return false
  return value.totalAmount !== null || value.discountAmount !== null || typeof value.freeShipping === 'boolean'
    || value.giftQuantity !== null || (Array.isArray(value.appliedCampaignKeys) && value.appliedCampaignKeys.length > 0)
    || (Array.isArray(value.items) && value.items.length > 0)
}

export function cleanExpected(value) {
  const output = {}
  for (const key of ['totalAmount', 'discountAmount', 'giftQuantity']) if (Number.isSafeInteger(value[key])) output[key] = value[key]
  if (typeof value.freeShipping === 'boolean') output.freeShipping = value.freeShipping
  if (Array.isArray(value.appliedCampaignKeys) && value.appliedCampaignKeys.length) output.appliedCampaignKeys = value.appliedCampaignKeys
  if (Array.isArray(value.items) && value.items.length) output.items = value.items.map(item => ({
    specificationId: item.specificationId,
    quantity: item.quantity,
    price: item.price,
    ...(typeof item.isGift === 'boolean' ? { isGift: item.isGift } : {})
  }))
  return output
}

export function buildMatrix(run) {
  if (!object(run) || !Array.isArray(run.worlds) || !Array.isArray(run.cases) || !Array.isArray(run.results)) throw new TypeError('Simulation result is malformed.')
  const lookup = new Map(run.results.map(result => [`${result.worldKey}\u0000${result.caseKey}`, result]))
  return run.cases.map(item => ({
    case: item,
    cells: run.worlds.map(world => ({ world, result: lookup.get(`${world.key}\u0000${item.key}`) || null }))
  }))
}

export function findResult(run, worldKey, caseKey) {
  return run?.results?.find(result => result.worldKey === worldKey && result.caseKey === caseKey) || null
}

export function boundaryCases(baseCase, thresholdMinor) {
  if (!Number.isSafeInteger(thresholdMinor) || thresholdMinor < 1) throw new TypeError('A positive integer minor-unit threshold is required.')
  const amounts = [thresholdMinor - 1, thresholdMinor, thresholdMinor + 1]
  return amounts.map((amount, index) => {
    const next = clone(baseCase)
    next.key = `${baseCase.key}-b${index + 1}`
    next.name = `${baseCase.name} @ ${formatMinor(amount)}`
    next.pricingMode = 'snapshot'
    next.items = [{ ...newItem(baseCase.items[0]), quantity: 1, unitPrice: amount }]
    next.expected = emptyExpected()
    next.expectedByWorld = {}
    return next
  })
}

export const BOUNDARY_KINDS = ['Amount boundaries', 'Quantity boundaries', 'Schedule boundaries', 'Member coverage', 'Store coverage']

// Recognize only explicit, supported condition paths; do not infer units from
// arbitrary config names or pretend to parse/validate the complete DSL here.
function conditionValues(campaign, path) {
  const values = []
  const pattern = new RegExp(`${path.replaceAll('.', '\\.')}\\s*(?:>=|<=|==|>|<|!=)\\s*(config\\.[A-Za-z_][A-Za-z0-9_]*|[0-9]+)(?=\\s|$)`, 'g')
  for (const match of (campaign.template?.dsl || '').matchAll(pattern)) {
    const value = match[1].startsWith('config.') ? campaign.config?.[match[1].slice(7)] : Number(match[1])
    if (Number.isSafeInteger(value) && value >= 0) values.push(value)
  }
  return values
}

function caseFingerprint(item) {
  return JSON.stringify([item.storeCode, item.simulatedAt, item.currency, item.pricingMode, item.items, item.user])
}

export function boundaryPlan(scenario, baseCase, { thresholdMinor = null, kinds = BOUNDARY_KINDS } = {}) {
  if (!baseCase) return { cases: [], available: 0, omitted: 0, counts: {} }
  const campaigns = scenario.worlds.flatMap(world => world.campaigns)
  const groups = Object.fromEntries(BOUNDARY_KINDS.map(kind => [kind, []]))
  const add = (kind, label, change) => {
    if (!kinds.includes(kind)) return
    const item = clone(baseCase)
    item.name = `${baseCase.name} · ${label}`.slice(0, 120)
    item.expected = emptyExpected()
    item.expectedByWorld = {}
    change(item)
    groups[kind].push(item)
  }
  const amounts = new Set(campaigns.flatMap(campaign => conditionValues(campaign, 'cart.subtotal')))
  if (Number.isSafeInteger(thresholdMinor) && thresholdMinor >= 0) amounts.add(thresholdMinor)
  for (const threshold of amounts) {
    for (const amount of [threshold - 1, threshold, threshold + 1].filter(value => value >= 0 && value <= 100000000)) {
      add('Amount boundaries', `subtotal ${amount} minor`, item => {
        item.pricingMode = 'snapshot'
        item.items = [{ ...newItem(baseCase.items[0]), quantity: 1, unitPrice: amount }]
      })
    }
  }
  // cart.items.count counts item groups, not the sum of quantities.
  const groupCounts = new Set(campaigns.flatMap(campaign => conditionValues(campaign, 'cart.items.count')))
  for (const threshold of groupCounts) {
    for (const count of [threshold - 1, threshold, threshold + 1].filter(value => value >= 1 && value <= MAX_ITEMS)) {
      add('Quantity boundaries', `item groups ${count}`, item => {
        item.items = Array.from({ length: count }, (_, index) => newItem(baseCase.items[index % baseCase.items.length]))
      })
    }
  }
  const quantities = new Set([1, 2])
  for (const campaign of campaigns) {
    for (const match of (campaign.template?.dsl || '').matchAll(/discount\s+item\s+([0-9]+)\s/g)) quantities.add(Number(match[1]))
  }
  for (const threshold of quantities) {
    for (const quantity of [threshold - 1, threshold, threshold + 1].filter(value => value >= 1 && value <= 1000)) {
      add('Quantity boundaries', `quantity ${quantity}`, item => { item.items = [{ ...newItem(baseCase.items[0]), quantity }] })
    }
  }
  for (const campaign of campaigns) {
    for (const field of ['startTime', 'endTime']) {
      if (!campaign[field]) continue
      const time = new Date(campaign[field]).getTime()
      if (!Number.isFinite(time)) continue
      for (const delta of [-1000, 0, 1000]) {
        add('Schedule boundaries', `${campaign.name} ${field} ${delta / 1000}s`, item => {
          // Preserve the actual instant even when the browser and server use
          // different timezones or the imported schedule contains an offset.
          item.simulatedAt = new Date(time + delta).toISOString()
        })
      }
    }
  }
  for (const level of ['anonymous', ...MEMBER_LEVELS]) {
    add('Member coverage', level, item => { item.user = { mode: level === 'anonymous' ? 'anonymous' : 'level', level: level === 'anonymous' ? 'bronze' : level, userId: null } })
  }
  const stores = new Set(['', ...campaigns.map(campaign => campaign.storeCode).filter(Boolean)])
  let unmatched = 'boundary-unmatched'
  while (stores.has(unmatched) || unmatched === baseCase.storeCode) unmatched += '-x'
  stores.add(unmatched)
  for (const store of stores) add('Store coverage', `store ${store || 'global'}`, item => { item.storeCode = store })

  const seen = new Set(scenario.cases.map(caseFingerprint))
  const candidates = []
  const counts = {}
  // Round-robin dimensions so the 30-case budget cannot starve later groups.
  const length = Math.max(0, ...Object.values(groups).map(group => group.length))
  for (let index = 0; index < length; index++) {
    for (const kind of BOUNDARY_KINDS) {
      const item = groups[kind][index]
      if (!item) continue
      const fingerprint = caseFingerprint(item)
      if (seen.has(fingerprint)) continue
      seen.add(fingerprint)
      candidates.push({ item, kind })
    }
  }
  const selected = candidates.slice(0, Math.max(0, MAX_CASES - scenario.cases.length))
  const keys = scenario.cases.map(item => item.key)
  for (const { item, kind } of selected) {
    item.key = newKey('case', keys)
    keys.push(item.key)
    counts[kind] = (counts[kind] || 0) + 1
  }
  return { cases: selected.map(({ item }) => item), available: candidates.length, omitted: candidates.length - selected.length, counts }
}

export function resultOverview(run) {
  if (!run) return []
  return run.worlds.flatMap(world => {
    const currencies = [...new Set(run.cases.map(item => item.currency || 'CNY'))]
    return currencies.map(currency => {
      const keys = new Set(run.cases.filter(item => (item.currency || 'CNY') === currency).map(item => item.key))
      const results = run.results.filter(item => item.worldKey === world.key && keys.has(item.caseKey))
      const valid = results.filter(item => item.status !== 'error' && Number.isSafeInteger(item.beforeAmount) && Number.isSafeInteger(item.afterAmount))
      return {
        key: `${world.key}:${currency}`, name: world.name, currency,
        before: valid.reduce((sum, item) => sum + item.beforeAmount, 0),
        after: valid.reduce((sum, item) => sum + item.afterAmount, 0),
        passed: results.filter(item => item.status !== 'error' && item.passed === true).length,
        failed: results.filter(item => item.status !== 'error' && item.passed === false).length,
        errors: results.filter(item => item.status === 'error').length,
        unverified: results.filter(item => item.status !== 'error' && item.passed !== true && item.passed !== false).length,
        count: results.length, valid: valid.length
      }
    })
  })
}

export function cloneWorldAs(baseWorld, key, name) {
  return newWorld({ key, name, source: 'manual', campaigns: baseWorld.campaigns.map((campaign, index) => ({ ...clone(campaign), key: `${key}-${index + 1}` })) })
}

export function formatMinor(value, currency = 'CNY') {
  if (!Number.isSafeInteger(value)) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(value / 100)
  } catch {
    return `${(value / 100).toFixed(2)} ${currency}`
  }
}

export function campaignTypeLabel(campaign) {
  return `${campaign.template?.type || '—'} · ${campaign.template?.phase === 1 ? 'outer' : 'inner'}`
}

export function scenarioExport(scenario) {
  return JSON.stringify({ ...scenario, exportedAt: new Date().toISOString() }, null, 2)
}
