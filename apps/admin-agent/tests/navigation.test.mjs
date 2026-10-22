import assert from 'node:assert/strict'
import test from 'node:test'
import { buildAdminPageCatalog, resolveAdminNavigation } from '../src/navigation.js'

const routes = [
  {
    path: '/catalog',
    children: [
      { path: '/dummy/products/list', redirect: '/products/list', name: 'ProductList', meta: { title: 'Products' } },
      { path: '/dummy/products/create', redirect: '/products/create', name: 'ProductCreate', hidden: true },
      { path: '/reports', name: 'Reports', meta: { title: 'Reports' } },
      { path: '/remote/list', redirect: 'https://evil.example', name: 'RemoteList' },
      { path: '/:pathMatch(.*)*', name: 'CatchAllList' }
    ]
  },
  { path: '/hidden', hidden: true, children: [{ path: '/dummy/secret/list', redirect: '/secret/list', name: 'SecretList' }] }
]

test('catalogs only visible registered list pages and excludes arbitrary destinations', () => {
  assert.deepEqual(buildAdminPageCatalog(routes), [{ key: 'ProductList', title: 'Products', path: '/products/list' }])
})

test('resolves list and record-detail navigation only for allowlisted pages and safe identifiers', () => {
  const pages = buildAdminPageCatalog(routes)
  assert.deepEqual(resolveAdminNavigation(pages, 'ProductList'), {
    key: 'ProductList', title: 'Products', path: '/products/list'
  })
  assert.deepEqual(resolveAdminNavigation(pages, 'ProductList', '42'), {
    key: 'ProductList', title: 'Products', path: '/products/42/detail', identifier: '42'
  })
  assert.equal(resolveAdminNavigation(pages, 'ProductList', '123e4567-e89b-42d3-a456-426614174000').path, '/products/123e4567-e89b-42d3-a456-426614174000/detail')
  assert.throws(() => resolveAdminNavigation(pages, 'RemoteList'), /allowlist/)
  assert.throws(() => resolveAdminNavigation(pages, 'ProductList', '../login'), /positive numeric ID or UUID/)
  assert.throws(() => resolveAdminNavigation(pages, 'ProductList', 'https://evil.example'), /positive numeric ID or UUID/)
})
