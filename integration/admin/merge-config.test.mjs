import assert from 'node:assert/strict'
import test from 'node:test'
import { mergeEntities, mergeRoutes } from './merge-config.mjs'

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

  assert.throws(() => mergeRoutes([{ path: '/notes/:id', name: 'CoreNote' }], {
    add: [{ path: '/notes/:slug', name: 'BusinessNote' }]
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
