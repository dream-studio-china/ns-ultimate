import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { mergeEntities, mergeRoutes, moveRouteAfter, moveRouteBefore } from './merge-config.mjs'

test('positions promotion management directly after orders without nesting or changing paths', () => {
  const source = readFileSync(new URL('./config.ts', import.meta.url), 'utf8')
  const core = [
    { name: 'ProductManage', path: '/product' },
    { name: 'OrderManage', path: '/order' },
    { name: 'CounterManagement', path: '/counter-management' },
    { name: 'PromotionManage', path: '/promotion', children: [{ path: 'simulation' }] },
    { name: 'ContentManage' },
    { name: 'WechatConfiguration' },
    { name: 'HuifuConfiguration' },
    { name: 'HuifuTransactions' },
    { name: 'PrinterManage' }
  ]
  let routes = core
  // Exercise the actual composition order rather than duplicating it in the test.
  for (const [, name, anchor] of source.matchAll(/routes = moveRouteAfter\(routes, '([^']+)', '([^']+)'\)/g)) {
    routes = moveRouteAfter(routes, name, anchor)
  }
  assert.deepEqual(routes.slice(0, 4).map(route => route.name), ['ProductManage', 'OrderManage', 'PromotionManage', 'CounterManagement'])
  assert.equal(routes[2], core[3])
  assert.equal(routes[2].path, '/promotion')
  assert.deepEqual(routes[2].children, [{ path: 'simulation' }])
})

test('positions store management before product management', () => {
  let routes = [
    { name: 'ProductManage' },
    { name: 'StoreManage' }
  ]
  routes = moveRouteBefore(routes, 'StoreManage', 'ProductManage')

  assert.deepEqual(routes.map(route => route.name), ['StoreManage', 'ProductManage'])
})

test('positions printer management after content and before WeChat configuration', () => {
  let routes = [
    { name: 'ContentManage' },
    { name: 'WechatConfiguration' },
    { name: 'PrinterManage' }
  ]
  routes = moveRouteAfter(routes, 'WechatConfiguration', 'ContentManage')
  routes = moveRouteAfter(routes, 'PrinterManage', 'ContentManage')

  assert.deepEqual(routes.map(route => route.name), ['ContentManage', 'PrinterManage', 'WechatConfiguration'])
})

test('adds business routes while preserving core route objects', () => {
  const core = [{ path: '/system', name: 'System', children: [{ path: 'health', name: 'Health' }] }]
  const business = [{ path: '/reports', name: 'Reports', children: [{ path: 'daily', name: 'DailyReport' }] }]
  const result = mergeRoutes(core, { add: business })

  assert.equal(result.length, 2)
  assert.equal(result[0], core[0])
  assert.equal(result[1], business[0])
})

test('replaces and removes only explicitly named top-level core routes', () => {
  const core = [
    { path: '/system', name: 'System' },
    { path: '/legacy', name: 'Legacy' }
  ]
  const replacement = { path: '/system-v2', name: 'System' }
  const result = mergeRoutes(core, { replace: [replacement], remove: ['Legacy'] })

  assert.deepEqual(result, [replacement])
})

test('rejects duplicate nested route names and normalized paths', () => {
  assert.throws(() => mergeRoutes([], {
    add: [{ path: '/reports', name: 'Reports', children: [{ path: 'daily', name: 'Reports' }] }]
  }), /Duplicate route name/)

  assert.throws(() => mergeRoutes([{ path: '/dummies/:id', name: 'CoreDummy' }], {
    add: [{ path: '/dummies/:slug', name: 'BusinessDummy' }]
  }), /Duplicate route path/)
})

test('rejects undeclared entity replacement and duplicate additions', () => {
  const core = { User: { fields: ['email'] } }
  assert.throws(() => mergeEntities(core, { add: { User: {} } }), /declare it as a replacement/)
  assert.throws(() => mergeEntities(core, { replace: { Missing: {} } }), /unknown core entity/)
})

test('supports explicit entity additions, replacements, and removals', () => {
  const core = { User: { fields: ['email'] }, Legacy: {} }
  const replacement = { fields: ['email', 'phone'] }
  const result = mergeEntities(core, {
    add: { Report: { fields: ['date'] } },
    replace: { User: replacement },
    remove: ['Legacy']
  })

  assert.deepEqual(result, { User: replacement, Report: { fields: ['date'] } })
  assert.deepEqual(core, { User: { fields: ['email'] }, Legacy: {} })
})
