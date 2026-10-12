import assert from 'node:assert/strict'
import test from 'node:test'
import { permissionDescription, permissionLabel, roleLabel } from './labels.mjs'

const translations = {
  'Authorization role:store_catalog_manager': '门店商品管理员',
  'Authorization permission:store:product:create': '新增商品',
  'Authorization permission description': '{0}所需权限'
}
const translate = (key, value) => {
  const message = translations[key] ?? key
  return message.replace('{0}', String(value ?? ''))
}

test('translates built-in role names without changing role codes', () => {
  assert.equal(roleLabel({ code: 'store_catalog_manager', name: 'Store Catalog Manager' }, 'Store Catalog Manager', translate), '门店商品管理员')
  assert.equal(roleLabel({ code: 'custom_role', name: '门店自定义角色' }, '门店自定义角色', translate), '门店自定义角色')
})

test('translates seeded permission names by stable permission code', () => {
  assert.equal(permissionLabel({ code: 'store:product:create', name: 'Create store products' }, translate), '新增商品')
  assert.equal(permissionLabel('unknown:resource:action', translate), 'unknown:resource:action')
})

test('uses a localized permission explanation while preserving its stored description fallback', () => {
  assert.equal(permissionDescription({ code: 'store:product:create' }, translate), '新增商品所需权限')
  assert.equal(permissionDescription({ code: 'unknown', description: 'Stored description' }, key => key), 'Stored description')
})
