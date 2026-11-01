import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { authorizationNameMessages, localizedAuthorizationName, translatedAuthorizationName } from '../../business/admin/i18n/authorization.js'

const locales = ['zh', 'zh-Hant', 'ja']

const seededPermissions = new Set()
for (const path of [
  '../../core/crud-skeleton/src/Authorization/Command/SeedAuthorizationCommand.php',
  '../../business/backend/Store/src/Command/SeedStaffAuthorizationCommand.php',
  '../../business/backend/Counter/src/Command/SeedCounterAuthorizationCommand.php',
]) {
  const source = readFileSync(new URL(path, import.meta.url), 'utf8')
  for (const match of source.matchAll(/'((?:authorization|common|store|wallet):[a-z_]+:[a-z_]+)'/g)) {
    seededPermissions.add(match[1])
  }
}
// These seeds compose codes from resource/action arrays.
for (const action of ['read', 'create', 'update', 'delete']) seededPermissions.add(`store:wechat_setting:${action}`)
for (const action of ['read', 'create', 'update', 'delete']) seededPermissions.add(`store:huifu_setting:${action}`)
for (const action of ['read', 'create']) {
  seededPermissions.add(`store:huifu_payment:${action}`)
  seededPermissions.add(`store:huifu_refund:${action}`)
}
seededPermissions.add(`store:huifu_pos_receipt:read`)

const seededRoles = [
  'store_content_editor',
  'store_content_metadata_editor',
  'store_catalog_manager',
  'authorization_administrator',
  'store_availability_operator',
  'store_order_reader',
  'store_order_operator',
  'store_catalog_operator',
  'store_operation_auditor',
  'store_counter_manager',
  'store_counter_session_operator',
  'store_counter_order_editor',
  'store_counter_order_canceller',
  'store_counter_order_merger',
  'store_wechat_manager',
  'store_huifu_manager',
]

test('translates seeded permission and role names by stable code', () => {
  assert.equal(localizedAuthorizationName('permission', 'store:order:read', 'Read store orders', 'zh'), '查看门店订单')
  assert.equal(localizedAuthorizationName('permission', 'store:order:read', 'Read store orders', 'zh-Hant'), '檢視門市訂單')
  assert.equal(localizedAuthorizationName('role', 'store_counter_manager', 'Store Counter Manager', 'ja'), '店舗カウンター管理者')
})

test('explicit lookup distinguishes a translated code from a fallback', () => {
  assert.equal(translatedAuthorizationName('permission', 'store:order:read', 'zh'), '查看门店订单')
  assert.equal(translatedAuthorizationName('permission', 'custom:thing:read', 'zh'), null)
  assert.equal(translatedAuthorizationName('role', 'custom_role', 'ja'), null)
  assert.equal(translatedAuthorizationName('permission', '', 'zh'), null)
})

test('falls back to the backend name for English, custom codes and empty values', () => {
  assert.equal(localizedAuthorizationName('permission', 'store:order:read', 'Read store orders', 'en'), 'Read store orders')
  assert.equal(localizedAuthorizationName('permission', 'custom:thing:read', 'Custom thing', 'zh'), 'Custom thing')
  assert.equal(localizedAuthorizationName('role', 'custom_role', 'Custom Role', 'zh'), 'Custom Role')
  assert.equal(localizedAuthorizationName('permission', 'custom:thing:read', '', 'zh'), 'custom:thing:read')
  assert.equal(localizedAuthorizationName('permission', '', '', 'zh'), '')
})

test('every seeded permission and role has a translation in each non-English locale', () => {
  for (const locale of locales) {
    const messages = authorizationNameMessages[locale]
    assert.ok(messages, `missing locale ${locale}`)
    for (const code of seededPermissions) {
      assert.equal(typeof messages[`permission.${code}`], 'string', `${locale} is missing permission.${code}`)
    }
    for (const code of seededRoles) {
      assert.equal(typeof messages[`role.${code}`], 'string', `${locale} is missing role.${code}`)
    }
  }
})

test('translation resources contain no stale or duplicated authorization keys', () => {
  const expectedPermissions = new Set([...seededPermissions].map(code => `permission.${code}`))
  const expectedRoles = new Set(seededRoles.map(code => `role.${code}`))
  const expected = new Set([...expectedPermissions, ...expectedRoles])
  for (const locale of locales) {
    assert.deepEqual(new Set(Object.keys(authorizationNameMessages[locale])), expected, `${locale} key drift`)
  }
})

const plain = value => JSON.parse(JSON.stringify(value))

function loadOverride(path, context) {
  const source = readFileSync(new URL(path, import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace('export default', 'globalThis.config =')
  runInNewContext(source, context)
  return context.config
}

const permissionName = { marker: 'permission-name' }
const roleName = { marker: 'role-name' }
const permissionsDetail = { marker: 'permissions-detail' }
const permissionsAction = { marker: 'permissions-action' }
const assignmentRoleField = { marker: 'assignment-role-field' }

test('permission override localizes list and detail names without changing the core definition', () => {
  const core = {
    entity: { name: 'Permission', plural: 'permissions' },
    list: { list_display: ['id', 'code', 'name', { property: 'isSystem', type: 'boolean' }] },
    detail: { detail_display: ['id', 'code', 'name', { property: 'description', type: 'textarea' }] },
  }
  const config = loadOverride('../../business/admin/config/overrides/authorization/permission.js', {
    corePermissionConfig: { Permission: core },
    LocalizedPermissionName: permissionName,
  })

  assert.equal(config.entity, core.entity)
  assert.deepEqual(plain(config.list.list_display), [
    'id', 'code', { property: 'name', component: permissionName }, { property: 'isSystem', type: 'boolean' },
  ])
  assert.deepEqual(plain(config.detail.detail_display), [
    'id', 'code', { property: 'name', component: permissionName }, { property: 'description', type: 'textarea' },
  ])
  assert.deepEqual(plain(core.list.list_display), ['id', 'code', 'name', { property: 'isSystem', type: 'boolean' }])
})

test('role override localizes names, permission tags and the permissions dialog only', () => {
  const core = {
    entity: { name: 'Role', plural: 'roles' },
    form: { fields: [{ property: 'name', required: true }, { property: 'code', required: true }] },
    list: {
      actions: [{ name: 'role-permissions', position: 'list', component: { marker: 'core-action' } }],
      list_display: ['id', 'code', 'name', { property: 'permissions', component: { marker: 'core-detail' } }],
    },
    detail: { detail_display: ['id', 'code', 'name', { property: 'permissions', component: { marker: 'core-detail' } }] },
  }
  const config = loadOverride('../../business/admin/config/overrides/authorization/role.js', {
    coreRoleConfig: { Role: core },
    LocalizedRoleName: roleName,
    LocalizedRolePermissionsDetail: permissionsDetail,
    LocalizedRolePermissionsAction: permissionsAction,
  })

  assert.deepEqual(plain(config.list.actions), [{ name: 'role-permissions', position: 'list', component: permissionsAction }])
  assert.deepEqual(plain(config.list.list_display), [
    'id', 'code', { property: 'name', component: roleName }, { property: 'permissions', component: permissionsDetail },
  ])
  assert.deepEqual(plain(config.detail.detail_display), [
    'id', 'code', { property: 'name', component: roleName }, { property: 'permissions', component: permissionsDetail },
  ])
  // The edit form keeps the raw backend name so saving never writes a translation.
  assert.deepEqual(plain(config.form.fields), [{ property: 'name', required: true }, { property: 'code', required: true }])
})

test('assignment override swaps only the role selector field', () => {
  const core = {
    entity: { name: 'Assignment', plural: 'assignments' },
    form: { fields: [{ property: 'userUuid' }, { property: 'role', component: { marker: 'core-role' } }, { property: 'scopeType' }] },
    list: { list_display: ['id'] },
  }
  const config = loadOverride('../../business/admin/config/overrides/authorization/assignment.js', {
    coreAssignmentConfig: { Assignment: core },
    LocalizedAssignmentRoleField: assignmentRoleField,
  })

  assert.deepEqual(plain(config.form.fields), [
    { property: 'userUuid' },
    { property: 'role', component: assignmentRoleField },
    { property: 'scopeType' },
  ])
  assert.equal(config.list, core.list)
})
