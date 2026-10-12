import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

function loadModule(relativePath, stubs = {}) {
  const source = readFileSync(new URL(relativePath, import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace('export default', 'globalThis.__module =')
  const context = { ...stubs }
  runInNewContext(source, context)
  return context.__module
}

const collectionStubs = {
  t: key => key,
  orderByIdDesc: { '@order': 'entity.id|DESC' },
  uuidFilter: (property, label) => ({ uuidFilter: property, label }),
  loadRelationRecords: () => []
}

const collections = [
  ['WechatApplication.js', 'WechatApplication', 'wechat-applications'],
  ['WechatApplicationRevision.js', 'WechatApplicationRevision', 'wechat-application-revisions'],
  ['WechatMerchant.js', 'WechatMerchant', 'wechat-merchants'],
  ['WechatMerchantRevision.js', 'WechatMerchantRevision', 'wechat-merchant-revisions'],
  ['WechatProfileGrant.js', 'WechatProfileGrant', 'wechat-profile-grants'],
  ['StoreWechatSetting.js', 'StoreWechatSetting', 'store-wechat-settings'],
  ['WechatRefund.js', 'WechatRefundAttempt', 'wechat-refunds']
]

for (const [file, name, plural] of collections) {
  test(`${name} collection maps to the /api/v1/manage/${plural} endpoint`, () => {
    const collection = loadModule(`../../business/admin/config/collections/wechat/${file}`, collectionStubs)

    assert.deepEqual(Object.keys(collection), [name])
    const config = collection[name]

    assert.equal(config.entity.name, name)
    assert.equal(config.entity.plural, plural)

    if (config.form !== undefined) {
      assert.ok(Array.isArray(config.form.fields) && config.form.fields.length > 0, 'form.fields must be non-empty')
      for (const field of config.form.fields) {
        assert.ok(
          typeof field === 'string' || (field && typeof field.property === 'string'),
          'every form field needs a property'
        )
      }
    } else {
      // Read-only collections (no create/update routes) expose no form.
      assert.ok(config.list.disabled_actions?.includes('new'), 'read-only collections must disable creation')
    }

    const query = config.list.query
    assert.ok(query && (query['@order'] || query['@filter']), 'list.query needs @order or @filter')
    assert.ok(Array.isArray(config.list.list_display) && config.list.list_display.length > 0, 'list_display must be non-empty')

    assert.ok(config.detail.detail_display === '__all__' || Array.isArray(config.detail.detail_display))
  })
}

test('the WeChat menu group exposes one list route per collection', () => {
  const router = loadModule('../../business/admin/router.js', {
    t: key => key,
    Layout: { name: 'Layout' },
    r: (entityName) => [{ path: `/${entityName}/list`, name: `${entityName}List` }]
  })

  const group = router.add.find(route => route.name === 'WechatManagement')
  assert.ok(group, 'WechatManagement route group is registered')
  assert.equal(group.children.length, 7)

  const names = group.children.map(route => route.name)
  assert.deepEqual(new Set(names).size, names.length, 'route names must be unique')
  for (const name of [
    'WechatApplicationList',
    'WechatApplicationRevisionList',
    'WechatMerchantList',
    'WechatMerchantRevisionList',
    'WechatProfileGrantList',
    'StoreWechatSettingList',
    'WechatRefundAttemptList'
  ]) {
    assert.ok(names.includes(name), `${name} is registered`)
  }
})
