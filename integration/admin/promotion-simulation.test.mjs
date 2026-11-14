import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'
import * as scenarioHelpers from '../../business/admin/simulation/scenario.mjs'
import { fetchAllPages } from '../../business/admin/config/components/catalog-copy.mjs'
import en from '../../business/admin/i18n/en.js'
import zh from '../../business/admin/i18n/zh.js'
import zhHant from '../../business/admin/i18n/zh-Hant.js'
import ja from '../../business/admin/i18n/ja.js'
import {
  BOUNDARY_KINDS, boundaryPlan, resultOverview, boundaryCases, buildMatrix, buildRunRequest, createScenario, formatMinor, importWorldSnapshot,
  localDateTime, newCampaign, newCase, normalizeScenario, scenarioExport, validateScenario
} from '../../business/admin/simulation/scenario.mjs'
import { storeOptions, filterStores } from '../../business/admin/simulation/stores.mjs'

const repository = new URL('../../../', import.meta.url)
const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))

function simulationModel(request) {
  const { parse } = require('@vue/compiler-sfc')
  const source = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')
  const script = parse(source).descriptor.script.content
    .replace(/^import '\.\/theme\.css'\n/m, '')
    .replace(/^import[\s\S]*?from '[^']+'\n/gm, '')
    .replace('export default', 'globalThis.component =')
  const context = { ...scenarioHelpers, fetchAllPages, storeOptions, request, StoreSelect: {}, SimulationCharts: {}, localStorage: { setItem() {} } }
  runInNewContext(script, context)
  const component = context.component
  const model = { ...component.data(), $t: key => key }
  for (const [key, method] of Object.entries(component.methods)) model[key] = method.bind(model)
  for (const [key, getter] of Object.entries(component.computed)) Object.defineProperty(model, key, { get: getter.bind(model) })
  return { component, model }
}

test('store list supports failed-load retries, pagination and cached dropdown opens', async () => {
  let attempts = 0
  const pages = []
  const { model } = simulationModel({ get: async (path, { params }) => {
    assert.equal(path, '/api/v1/manage/stores')
    if (++attempts === 1) throw new Error('unavailable')
    pages.push(params.page)
    return { data: [{ id: params.page, code: `S${params.page}`, name: 'Store' }], paginator: { has_next: params.page === 1 } }
  } })
  await model.loadStores()
  assert.ok(model.storeError)
  assert.equal(model.storesLoaded, false)
  assert.equal(model.loadingStores, false)
  await model.loadStores()
  assert.equal(model.storeError, '')
  assert.deepEqual(pages, [1, 2])
  assert.deepEqual(Array.from(model.stores, item => item.code), ['S1', 'S2'])
  await model.loadStores()
  assert.equal(attempts, 3)
})

test('running opens results and input invalidation always returns to configuration', async () => {
  const { component, model } = simulationModel({ post: async (path, body) => {
    assert.equal(path, '/api/v1/manage/promotion-simulation/run')
    assert.equal(body.cases.length, 1)
    return { data: { summary: { passed: 0, failed: 0, errors: 0 } } }
  } })
  await model.run()
  assert.equal(model.workspaceTab, 'results')
  assert.ok(model.result)
  // Imports/removal methods may already have cleared result before the deep
  // watcher runs; the configuration must still become visible.
  model.result = null
  component.watch.scenario.handler.call(model)
  assert.equal(model.workspaceTab, 'setup')
  assert.equal(model.selectedResult, null)
})

test('importing a store snapshot warns about mismatched carts without changing test contexts', async () => {
  const { model } = simulationModel({ get: async () => ({ data: { campaigns: [newCampaign({ key: 'store-rate', storeCode: 'S1' })] } }) })
  model.scenario.storeCode = 'S1'
  const before = JSON.stringify(model.scenario.cases)
  await model.importCurrent()
  assert.equal(JSON.stringify(model.scenario.cases), before)
  assert.equal(model.storeMismatchCount, 1)
  model.scenario.cases[0].storeCode = 'S1'
  assert.equal(model.storeMismatchCount, 0)
  model.scenario.cases.push(newCase({ key: 'intentional-mismatch', storeCode: 'OTHER' }))
  assert.equal(model.storeMismatchCount, 1)
  model.scenario.worlds[0].campaigns[0].storeCode = ''
  assert.equal(model.storeMismatchCount, 0)
})

test('scenario defaults are safe, read-only and convertible to the backend request', () => {
  const scenario = createScenario(new Date('2026-11-12T10:00:00+08:00'))
  assert.equal(scenario.worlds.length, 1)
  assert.equal(scenario.cases.length, 1)
  assert.equal(scenario.cases[0].pricingMode, 'snapshot')
  assert.deepEqual(validateScenario(scenario), [])
  const request = buildRunRequest(scenario)
  assert.equal(request.worlds[0].campaigns.length, 0)
  assert.equal(request.cases[0].items[0].unitPrice, 10000)
  assert.equal(request.cases[0].user.mode, 'anonymous')
  assert.equal(Object.hasOwn(request, 'importMeta'), false)
})

test('world snapshots preserve template, schedule, flags and source revision', () => {
  const world = importWorldSnapshot({
    revision: 'a'.repeat(64), loadedAt: '2026-11-12T10:00:00+08:00',
    campaigns: [{
      key: 'campaign:7', name: 'Ten percent', enabled: false, templateEnabled: true,
      storeCode: 'S1', startTime: '2026-11-01T00:00:00+08:00', endTime: null,
      conflictMode: 'exclusive', config: { threshold: 10000, rate: 90 },
      template: { type: 'discount', phase: 0, dsl: 'type: discount\ndo:\n  discount order config.rate%' }
    }]
  }, 'baseline', 'Imported')
  assert.equal(world.source, 'import')
  assert.equal(world.revision, 'a'.repeat(64))
  assert.equal(world.campaigns[0].enabled, false)
  assert.equal(world.campaigns[0].conflictMode, 'exclusive')
  assert.equal(world.campaigns[0].template.type, 'discount')
})

test('expected values are omitted when unset and expectedByWorld is validated', () => {
  const scenario = createScenario()
  scenario.cases[0].expected = { totalAmount: 8500, freeShipping: false, appliedCampaignKeys: ['a'] }
  scenario.cases[0].expectedByWorld = { baseline: { totalAmount: 8500 }, removed: { totalAmount: 1 } }
  const request = buildRunRequest(scenario)
  assert.deepEqual(request.cases[0].expected, { totalAmount: 8500, freeShipping: false, appliedCampaignKeys: ['a'] })
  assert.deepEqual(request.cases[0].expectedByWorld, { baseline: { totalAmount: 8500 } })
  scenario.cases[0].expected.freeShipping = undefined
  const next = buildRunRequest(scenario)
  assert.equal(Object.hasOwn(next.cases[0].expected, 'freeShipping'), false)
})

test('snapshot export round-trips through normalization and rejects unknown versions', () => {
  const scenario = createScenario()
  scenario.worlds[0].campaigns.push(newCampaign({ key: 'campaign:1', name: 'Rate', template: { type: 'discount', dsl: 'type: discount\ndo:\n  discount order 90%' } }))
  const restored = normalizeScenario(JSON.parse(scenarioExport(scenario)))
  assert.equal(restored.worlds[0].campaigns.length, 1)
  assert.equal(restored.worlds[0].campaigns[0].name, 'Rate')
  assert.throws(() => normalizeScenario({ ...scenario, version: 999 }), /Unsupported/)
})

test('boundary cases use the exact minor-unit threshold and reset expectations', () => {
  const base = newCase({ key: 'base', name: 'Base', items: [{ specificationId: 4, quantity: 1, unitPrice: 10000 }], expected: { totalAmount: 9000 } })
  const cases = boundaryCases(base, 10000)
  assert.deepEqual(cases.map(item => item.items[0].unitPrice), [9999, 10000, 10001])
  assert.equal(cases.every(item => item.items[0].specificationId === 4), true)
  assert.equal(cases.every(item => item.expected.totalAmount === null), true)
  assert.deepEqual(cases.map(item => item.key), ['base-b1', 'base-b2', 'base-b3'])
})

test('matrix lookup and money formatting are deterministic', () => {
  const run = {
    worlds: [{ key: 'a', name: 'A' }, { key: 'b', name: 'B' }],
    cases: [{ key: 'c1', name: 'C1' }, { key: 'c2', name: 'C2' }],
    results: [
      { worldKey: 'a', caseKey: 'c1', afterAmount: 9000 },
      { worldKey: 'b', caseKey: 'c1', afterAmount: 8500 }
    ]
  }
  const matrix = buildMatrix(run)
  assert.equal(matrix[0].cells[0].result.afterAmount, 9000)
  assert.equal(matrix[0].cells[1].result.afterAmount, 8500)
  assert.equal(matrix[1].cells[0].result, null)
  assert.match(formatMinor(9000, 'CNY'), /90\.00/)
})

test('store search matches names, numeric IDs, UUIDs and codes but always selects exact codes', () => {
  const options = storeOptions([
    { name: 'Central Store', id: 42, uuid: 'ABC-123', code: 'STORE-01' },
    { name: 'No code', id: 1, uuid: 'missing' },
    { name: 'West Store', id: 43, uuid: 'DEF-456', code: 'STORE-02' }
  ])
  assert.equal(options.length, 2)
  for (const query of ['central', '42', 'abc-123', 'STORE-01', 'central 42']) {
    assert.equal(filterStores(options, query)[0].code, 'STORE-01')
  }
  assert.equal(filterStores(options, '').length, 2)
  assert.equal(filterStores(options, 'missing').length, 0)
})

function boundaryScenario() {
  const scenario = createScenario(new Date('2026-11-12T10:00:00'))
  scenario.cases[0].items[0].specificationUuid = 'keep-specification-uuid'
  scenario.cases[0].expected = { ...scenario.cases[0].expected, totalAmount: 1 }
  scenario.cases[0].expectedByWorld = { baseline: { totalAmount: 1 } }
  scenario.worlds[0].campaigns = [newCampaign({
    key: 'campaign:1', name: 'Boundary rule', storeCode: 'S1',
    startTime: '2026-11-10T10:00:00', endTime: '2026-11-20T10:00:00',
    config: { threshold: 15000, count: 4 },
    template: { type: 'nth_discount', dsl: 'when:\n  cart.subtotal >= config.threshold\n  cart.items.count >= config.count\ndo:\n  discount item 5 50%\n' }
  })]
  return scenario
}

test('boundary planner detects DSL thresholds and preserves identity while clearing all assertions', () => {
  const scenario = boundaryScenario()
  const before = JSON.stringify(scenario)
  const plan = boundaryPlan(scenario, scenario.cases[0])
  assert.deepEqual(Object.keys(plan.counts).sort(), [...BOUNDARY_KINDS].sort())
  for (const amount of [14999, 15000, 15001]) assert.ok(plan.cases.some(item => item.items[0].unitPrice === amount))
  for (const count of [3, 4, 5]) assert.ok(plan.cases.some(item => item.items.length === count))
  assert.ok(plan.cases.some(item => item.items[0].quantity === 5))
  const start = new Date('2026-11-10T10:00:00').getTime()
  for (const delta of [-1000, 0, 1000]) assert.ok(plan.cases.some(item => new Date(item.simulatedAt).getTime() === start + delta))
  assert.ok(plan.cases.some(item => new Date(item.simulatedAt).getTime() === new Date('2026-11-20T10:00:01').getTime()))
  assert.ok(plan.cases.some(item => item.user.level === 'diamond'))
  assert.ok(plan.cases.some(item => item.storeCode === 'S1'))
  assert.ok(plan.cases.some(item => item.storeCode === 'boundary-unmatched'))
  assert.equal(new Set(plan.cases.map(item => item.key)).size, plan.cases.length)
  assert.ok(plan.cases.every(item => item.expected.totalAmount === null && Object.keys(item.expectedByWorld).length === 0))
  assert.ok(plan.cases.every(item => item.items[0].specificationUuid === 'keep-specification-uuid'))
  assert.equal(JSON.stringify(scenario), before)
  scenario.cases.push(...plan.cases)
  assert.deepEqual(validateScenario(scenario), [])
  assert.equal(boundaryPlan(scenario, scenario.cases[0]).cases.length, 0)
})

test('schedule boundaries retain absolute instants from offset-bearing snapshots', () => {
  const scenario = createScenario()
  scenario.worlds[0].campaigns = [newCampaign({ startTime: '2026-11-10T10:00:00+08:00' })]
  const plan = boundaryPlan(scenario, scenario.cases[0], { kinds: ['Schedule boundaries'] })
  assert.deepEqual(plan.cases.map(item => item.simulatedAt), ['2026-11-10T01:59:59.000Z', '2026-11-10T02:00:00.000Z', '2026-11-10T02:00:01.000Z'])
})

test('boundary planner supports zero, current catalog seeds, repeated clicks, disabled groups and case limits', () => {
  const scenario = createScenario()
  const base = scenario.cases[0]
  base.pricingMode = 'current_catalog'
  const plan = boundaryPlan(scenario, base, { thresholdMinor: 0, kinds: ['Amount boundaries'] })
  assert.deepEqual(plan.cases.map(item => item.items[0].unitPrice), [0, 1])
  assert.ok(plan.cases.every(item => item.pricingMode === 'snapshot'))
  scenario.cases.push(...plan.cases)
  assert.equal(boundaryPlan(scenario, base, { thresholdMinor: 0, kinds: ['Amount boundaries'] }).available, 0)
  assert.equal(boundaryPlan(scenario, base, { kinds: [] }).available, 0)
  while (scenario.cases.length < 29) scenario.cases.push(newCase({ key: `padding${scenario.cases.length}` }))
  const limited = boundaryPlan(scenario, base)
  assert.equal(limited.cases.length, 1)
  assert.ok(limited.omitted > 0)
  scenario.cases.push(...limited.cases)
  assert.equal(boundaryPlan(scenario, base).cases.length, 0)
})

test('overview separates currencies, errors, failures and unverified results without inventing passes', () => {
  const overview = resultOverview({
    worlds: [{ key: 'w', name: 'World' }],
    cases: [{ key: 'a', currency: 'CNY' }, { key: 'b', currency: 'USD' }, { key: 'c', currency: 'CNY' }, { key: 'd', currency: 'CNY' }],
    results: [
      { worldKey: 'w', caseKey: 'a', status: 'ok', beforeAmount: 10000, afterAmount: 8000, passed: null },
      { worldKey: 'w', caseKey: 'b', status: 'ok', beforeAmount: 500, afterAmount: 400, passed: true },
      { worldKey: 'w', caseKey: 'c', status: 'error', beforeAmount: 999999, passed: false },
      { worldKey: 'w', caseKey: 'd', status: 'ok', beforeAmount: 2000, afterAmount: 2000, passed: false }
    ]
  })
  assert.equal(overview.length, 2)
  assert.deepEqual(overview[0], { key: 'w:CNY', name: 'World', currency: 'CNY', before: 12000, after: 10000, passed: 0, failed: 1, errors: 1, unverified: 1, count: 3, valid: 2 })
  assert.equal(overview[1].before, 500)
  assert.equal(overview[1].passed, 1)
})

test('simulation store and chart components compile and their labels are localized', () => {
  const { parse, compileTemplate, compileScript } = require('@vue/compiler-sfc')
  for (const name of ['StoreSelect', 'SimulationCharts', 'PromotionSimulation']) {
    const source = readFileSync(new URL(`../../business/admin/simulation/${name}.vue`, import.meta.url), 'utf8')
    const { descriptor, errors } = parse(source)
    assert.deepEqual(errors, [])
    assert.doesNotThrow(() => compileScript(descriptor, { id: name }))
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: `${name}.vue`, id: name }).errors, [])
    for (const match of source.matchAll(/\$t\('([^']+)'/g)) {
      // Shared labels come from the core locale; business-owned labels must
      // exist in every business overlay.
      if (!Object.hasOwn(en, match[1])) continue
      for (const messages of [en, zh, zhHant, ja]) assert.ok(messages[match[1]], match[1])
    }
  }
  for (const kind of BOUNDARY_KINDS) for (const messages of [en, zh, zhHant, ja]) assert.ok(messages[kind], kind)
})

test('promotion simulation route is nested under the existing promotion menu', () => {
  const source = readFileSync(new URL('../../business/admin/router.js', import.meta.url), 'utf8')
  assert.match(source, /route\.name === 'PromotionManage'/)
  assert.match(source, /name: 'PromotionSimulation'/)
  assert.match(source, /path: 'simulation'/)
  assert.match(source, /import\('\.\/simulation\/PromotionSimulation\.vue'\)/)
  assert.match(source, /meta: \{ title: t\('Promotion simulation'\), icon: 'el-icon-caret-right' \}/)
})

test('promotion simulation Vue template and script compile', () => {
  const { parse, compileTemplate, compileScript } = require('@vue/compiler-sfc')
  const source = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')
  const { descriptor, errors } = parse(source, { filename: 'PromotionSimulation.vue' })
  assert.deepEqual(errors, [])
  assert.doesNotThrow(() => compileScript(descriptor, { id: 'promotion-simulation-test' }))
  const template = compileTemplate({ source: descriptor.template.content, filename: 'PromotionSimulation.vue', id: 'promotion-simulation-test' })
  assert.deepEqual(template.errors, [])
})

test('promotion simulation UI labels are localized in every business locale', () => {
  const keys = [
    'Promotion simulation', 'Promotion simulation help', 'Run simulation', 'Import current campaigns',
    'Import order', 'Test carts', 'Result matrix', 'Diagnostics', 'Expected differences',
    'Scenario data is kept in this browser only. Simulation is read-only.',
    'Promotion-only snapshot. Catalog, tax, shipping fees, payment and concurrency are not verified.'
  ]
  for (const messages of [en, zh, zhHant, ja]) {
    for (const key of keys) {
      assert.equal(typeof messages[key], 'string', key)
      assert.notEqual(messages[key], '', key)
    }
  }
})

test('simulation controls use SVG icons instead of legacy Element UI icon names', () => {
  const source = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')
  assert.doesNotMatch(source, /icon="el-icon-/)
  for (const icon of ['VideoPlay', 'Download', 'Refresh', 'Tickets', 'Upload', 'Plus', 'Remove']) {
    assert.ok(source.includes(`:icon="icons.${icon}"`), icon)
  }
})

test('simulation dialog sizing survives teleport and JSON fields span the form grid', () => {
  const { parse, compileStyle } = require('@vue/compiler-sfc')
  const source = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const style = compileStyle({ source: descriptor.styles[0].content, filename: 'PromotionSimulation.vue', id: 'data-v-simulation-test', scoped: true })
  assert.deepEqual(style.errors, [])
  // ElDialog does not forward the parent's scope attribute to its teleported panel.
  assert.match(style.code, /\.promotion-simulation__dialog \.el-input-number,\s*\.promotion-simulation__dialog \.el-select,\s*\.promotion-simulation__dialog \.el-date-editor\.el-input\s*\{\s*width: 100%; min-width: 0;/)
  assert.match(style.code, /\.promotion-simulation__full\[data-v-simulation-test\]\s*\{\s*grid-column: 1 \/ -1;/)
  assert.match(style.code, /@media \(max-width: 760px\)/)
})

test('simulation editors display validation errors inside each modal', () => {
  const { parse } = require('@vue/compiler-sfc')
  const source = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const dialogs = descriptor.template.content.match(/<el-dialog\b[\s\S]*?<\/el-dialog>/g)
  assert.equal(dialogs.length, 3)
  for (const dialog of dialogs) {
    assert.match(dialog, /<el-alert v-if="error" :title="error" type="error"/)
    assert.match(dialog, /align-center/)
  }
})

test('centered simulation dialogs keep their body scrollable within the viewport', () => {
  const { parse, compileStyle } = require('@vue/compiler-sfc')
  const source = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const style = compileStyle({ source: descriptor.styles[0].content, filename: 'PromotionSimulation.vue', id: 'data-v-simulation-test', scoped: true })
  assert.deepEqual(style.errors, [])
  assert.match(style.code, /\.promotion-simulation__dialog\s*\{\s*display: flex; flex-direction: column; max-height: calc\(100vh - 32px\);\s*\}/)
  assert.match(style.code, /\.promotion-simulation__dialog \.el-dialog__body\s*\{\s*overflow-y: auto;\s*\}/)
})

test('simulation dark theme bridges host tokens without changing other pages or light themes', () => {
  const { compileStyle } = require('@vue/compiler-sfc')
  const source = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')
  const css = readFileSync(new URL('../../business/admin/simulation/theme.css', import.meta.url), 'utf8')
  assert.match(source, /import '\.\/theme\.css'/)
  const style = compileStyle({ source: css, filename: 'simulation-theme.css', id: 'simulation-theme' })
  assert.deepEqual(style.errors, [])
  for (const rule of style.rawResult.root.nodes.filter(node => node.type === 'rule')) {
    for (const selector of rule.selector.split(/,\s*(?=html)/)) {
      assert.ok(selector.startsWith("html[data-theme='dark']"), selector)
      assert.ok(selector.includes('.promotion-simulation'), selector)
    }
  }
  for (const [token, host] of [
    ['bg-color', 'surface'], ['bg-color-overlay', 'surface'], ['text-color-primary', 'text-primary'],
    ['text-color-secondary', 'text-secondary'], ['fill-color-blank', 'surface'], ['border-color', 'border']
  ]) {
    assert.ok(css.includes(`--el-${token}: var(--${host});`), token)
  }
  for (const state of ['success', 'warning', 'danger', 'info']) {
    assert.ok(css.includes(`--el-color-${state}-light-9: color-mix(`), state)
  }
})

test('every simulation picker and select uses the namespaced teleported dark theme', () => {
  const source = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')
  const controls = source.match(/<el-(?:select|date-picker)\b[^>]*>/g)
  assert.ok(controls.length > 0)
  for (const control of controls) {
    assert.ok(control.includes('popper-class="promotion-simulation__popper"'), control)
  }
})

test('business simulation imports resolve core-installed dependencies during production builds', () => {
  const config = readFileSync(new URL('./vite.config.ts', import.meta.url), 'utf8')
  // Business files sit outside core/crud-admin, so Rollup cannot walk up to the
  // core node_modules and every such bare import needs an explicit alias.
  assert.match(config, /\{ find: \/\^@element-plus\\\/icons-vue\$\/, replacement: coreRequire\.resolve\('@element-plus\/icons-vue\/dist\/index\.js'\) \}/)
  assert.match(require.resolve('@element-plus/icons-vue/dist/index.js'), /dist[\\/]index\.js$/)
})
