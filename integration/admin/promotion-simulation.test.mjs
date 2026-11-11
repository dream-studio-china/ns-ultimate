import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import en from '../../business/admin/i18n/en.js'
import zh from '../../business/admin/i18n/zh.js'
import zhHant from '../../business/admin/i18n/zh-Hant.js'
import ja from '../../business/admin/i18n/ja.js'
import {
  boundaryCases, buildMatrix, buildRunRequest, createScenario, formatMinor, importWorldSnapshot,
  localDateTime, newCampaign, newCase, normalizeScenario, scenarioExport, validateScenario
} from '../../business/admin/simulation/scenario.mjs'

const repository = new URL('../../../', import.meta.url)
const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))

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

test('promotion simulation route is nested under the existing promotion menu', () => {
  const source = readFileSync(new URL('../../business/admin/router.js', import.meta.url), 'utf8')
  assert.match(source, /route\.name === 'PromotionManage'/)
  assert.match(source, /name: 'PromotionSimulation'/)
  assert.match(source, /path: 'simulation'/)
  assert.match(source, /import\('\.\/simulation\/PromotionSimulation\.vue'\)/)
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
