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
