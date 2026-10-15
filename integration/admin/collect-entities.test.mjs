import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { collectEntities } from '../../business/admin/config/collect-entities.mjs'
import { mergeEntities, mergeRoutes, moveRouteAfter } from './merge-config.mjs'
import { watchBusinessAdmin } from './watch-business.mjs'
import { uuidFilter } from '../../business/admin/config/uuid-filter.mjs'

test('rejects duplicate business entities across collection directories', () => {
  assert.throws(() => collectEntities({
    './collections/first/Dummy.js': { default: { Dummy: { marker: 'first' } } },
    './collections/second/Dummy.js': { default: { Dummy: { marker: 'second' } } }
  }), /Duplicate business entity configuration "Dummy"/)
})

function loadCounterCollections() {
  return Object.fromEntries(['Counter', 'OrderCounter'].map(name => {
    // Evaluate the real configuration with translation/query dependencies stubbed.
    const source = readFileSync(new URL(
      `../../business/admin/config/collections/counter/${name}.js`, import.meta.url
    ), 'utf8').replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
    const context = {
      t: key => key, orderByIdDesc: {}, uuidFilter,
      loadRelationRecords: relation => Promise.resolve([
        { id: 17, uuid: `${relation.name}-uuid`, __toString: `${relation.name} display name` }
      ])
    }
    runInNewContext(source, context)
    return [`./collections/counter/${name}.js`, { default: context.config }]
  }))
}

test('registers the actual Counter configurations with their API identities', () => {
  const entities = mergeEntities({}, { add: collectEntities(loadCounterCollections()) })
  assert.deepEqual(Object.keys(entities), ['Counter', 'OrderCounter'])
  for (const [name, plural] of [['Counter', 'counters'], ['OrderCounter', 'order-counters']]) {
    assert.equal(entities[name].entity.name, name)
    assert.equal(`/manage/${entities[name].entity.plural}`, `/manage/${plural}`)
    assert.ok(Array.isArray(entities[name].form.fields))
    assert.ok(Array.isArray(entities[name].list.list_display))
  }
  assert.ok(entities.OrderCounter.list.disabled_actions.includes('edit'))
  assert.equal(entities.OrderCounter.form.fields[1].property, 'counter')
  assert.equal(entities.OrderCounter.form.fields[1].relation.valueKey, 'uuid')
})

test('overrides WechatUser display fields without changing core configuration', () => {
  const source = readFileSync(new URL(
    '../../business/admin/config/overrides/wechat/wechat-user.js', import.meta.url
  ), 'utf8').replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const coreWechatUser = {
    entity: { name: 'WechatUser' },
    list: { list_display: ['id', 'user', 'openid', 'nickname'] },
    detail: { detail_display: ['id', 'avatarUrl', 'openid'] }
  }
  const context = {
    coreWechatUserConfig: { WechatUser: coreWechatUser },
    h: (type, props, children) => ({ type, props, children }),
    SIP: { getPicture: value => `processed:${value}` }
  }
  runInNewContext(source, context)

  const configured = context.config
  assert.deepEqual(Array.from(configured.list.list_display, field => typeof field === 'string' ? field : field.property), [
    'id', 'user', 'appId', 'openid', 'avatar', 'nickname'
  ])
  const avatarColumn = configured.list.list_display.find(field => typeof field === 'object')
  assert.equal(avatarColumn.type, 'image')
  assert.equal(avatarColumn.field_options.className, 'wechat-user-avatar-column')
  const avatarDetail = configured.detail.detail_display.find(field => typeof field === 'object')
  assert.equal(avatarDetail.property, 'avatar')
  const rendered = avatarDetail.component.render.call({ data: 'avatar-url' })
  assert.equal(rendered.type, 'img')
  assert.equal(rendered.props.style.width, '256px')
  assert.equal(rendered.props.style.height, '256px')
  assert.equal(rendered.props.src, 'processed:avatar-url')
  assert.equal(configured.detail.detail_display[0], 'id')
  assert.deepEqual(coreWechatUser.list.list_display, ['id', 'user', 'openid', 'nickname'])
  assert.deepEqual(coreWechatUser.detail.detail_display, ['id', 'avatarUrl', 'openid'])
})

test('keeps immutable WeChat profile fields out of update payloads', () => {
  const loadWechatCollection = name => {
    const source = readFileSync(new URL(
      `../../business/admin/config/collections/wechat/${name}.js`, import.meta.url
    ), 'utf8').replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
    const context = { t: key => key, orderByIdDesc: {} }
    runInNewContext(source, context)
    return context.config[name]
  }

  const application = loadWechatCollection('WechatApplication')
  const merchant = loadWechatCollection('WechatMerchant')
  const storeSetting = loadWechatCollection('StoreWechatSetting')
  const fieldsByEntity = { application, merchant, storeSetting }

  for (const [entityName, fields] of Object.entries({
    application: ['type', 'appId'],
    merchant: ['merchantId', 'paymentMode'],
    storeSetting: ['storeUuid']
  })) {
    const configured = fieldsByEntity[entityName].form.fields
    for (const property of fields) {
      assert.equal(configured.find(field => field.property === property)?.hidden, 'update', `${entityName}.${property}`)
    }
  }

  assert.equal(application.entity.plural, 'wechat-applications')
  assert.equal(merchant.entity.plural, 'wechat-merchants')
  assert.equal(storeSetting.entity.plural, 'store-wechat-settings')

  for (const property of ['serviceProviderAppId', 'subMerchantId']) {
    const hidden = merchant.form.fields.find(field => field.property === property)?.hidden
    assert.equal(typeof hidden, 'function')
    assert.equal(hidden({ paymentMode: 'direct' }, null), true)
    assert.equal(hidden({ paymentMode: 'service_provider' }, null), false)
    assert.equal(hidden({ paymentMode: 'service_provider' }, 42), true)
  }
})

test('localizes WeChat form labels, placeholders and help in every business locale', () => {
  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const messagesContext = {}
    const messagesSource = readFileSync(new URL(`../../business/admin/i18n/${locale}.js`, import.meta.url), 'utf8')
      .replace('export default', 'globalThis.messages =')
    runInNewContext(messagesSource, messagesContext)
    const t = key => {
      if (['Name', 'Store'].includes(key)) return key
      assert.ok(messagesContext.messages[key], `Missing ${locale} translation: ${key}`)
      return messagesContext.messages[key]
    }

    for (const [name, properties] of [
      ['WechatApplication', ['secret', 'token', 'aesKey']],
      ['WechatMerchant', ['apiV3Key', 'privateKey', 'certificate', 'platformCertificate']],
      ['StoreWechatSetting', ['miniappUuid', 'officialUuid', 'merchantUuid']]
    ]) {
      const source = readFileSync(new URL(`../../business/admin/config/collections/wechat/${name}.js`, import.meta.url), 'utf8')
        .replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
      // Core labels used outside the fields under test are not business translations.
      const context = { t, orderByIdDesc: {} }
      runInNewContext(source, context)
      for (const property of properties) {
        const field = context.config[name].form.fields.find(field => field.property === property)
        for (const value of [field.field_options?.label, field.type_options?.placeholder, field.help]) {
          assert.ok(value && value !== property, `${locale} ${name}.${property} must explain its input`)
          assert.ok(Object.values(messagesContext.messages).includes(value), `${locale} ${name}.${property} must use business translations`)
        }
      }
        if (name === 'StoreWechatSetting') {
          assert.equal(context.config[name].form.fields.find(field => field.property === 'miniappUuid').relation_filter['@filter'], 'entity.getType() == "miniapp"')
          assert.equal(context.config[name].form.fields.find(field => field.property === 'officialUuid').relation_filter['@filter'], 'entity.getType() == "official"')
          assert.equal(context.config[name].form.fields.find(field => field.property === 'merchantUuid').required, false)
          for (const property of ['miniappUuid', 'officialUuid', 'merchantUuid']) {
          assert.ok(context.config[name].list.list_display.find(field => field.property === property)?.label)
          assert.ok(context.config[name].detail.detail_display.find(field => field.property === property)?.label)
        }
      }
    }
  }
})

test('Counter UUID filters load labels but submit UUIDs with exact getter expressions', async () => {
  const entities = collectEntities(loadCounterCollections())
  for (const [name, property, target] of [
    ['Counter', 'storeUuid', 'Store'],
    ['OrderCounter', 'storeUuid', 'Store'],
    ['OrderCounter', 'tradeOrderUuid', 'Order']
  ]) {
    const factory = entities[name].list.list_filter[property]
    assert.equal(typeof factory, 'function')
    const filter = await factory()
    assert.equal(filter.type, 'select')
    assert.equal(filter.expression, `entity.get${property[0].toUpperCase()}${property.slice(1)}() == ':value'`)
    assert.deepEqual(filter.data, [{ value: `${target}-uuid`, label: `${target} display name` }])
  }
})

test('UUID filters omit records without UUIDs and support empty results', async () => {
  const filter = await uuidFilter('storeUuid', 'Store', () => [{ id: 1 }, { uuid: '' }])()
  assert.deepEqual(filter.data, [])
  assert.equal(filter.default, null)
})

test('watches business directories outside the core Vite root', () => {
  const watched = []
  watchBusinessAdmin('/project/business/admin').configureServer({
    watcher: { add: directory => watched.push(directory) }
  })
  assert.deepEqual(watched, ['/project/business/admin'])
})

test('puts counter pages in a standalone menu and hides Business management', () => {
  const source = readFileSync(new URL('../../business/admin/router.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace('export default', 'globalThis.routes =')
  const context = {
    t: key => key,
    Layout: {},
    r: (name, title) => [{ name, meta: { title } }]
  }
  runInNewContext(source, context)
  const routes = context.routes.add
  const business = routes.find(route => route.name === 'BusinessManage')
  const counters = routes.find(route => route.name === 'CounterManagement')
  assert.equal(business.hidden, true)
  assert.equal(counters.meta.title, 'Counter management')
  assert.equal(counters.meta.icon, 'counter')
  assert.deepEqual(Array.from(counters.children, route => route.name), ['Counter', 'OrderCounter'])
  assert.equal(routes.find(route => route.name === 'WechatConfiguration').meta.icon, 'wechat')
})

test('places Counter after Product and WeChat configuration after Content', () => {
  const coreRoutes = [
    { name: 'ProductManage' },
    { name: 'OrderManage' },
    { name: 'ContentManage' },
    { name: 'UserManage' }
  ]
  const businessRoutes = { add: [
    { name: 'WechatConfiguration' },
    { name: 'CounterManagement' }
  ] }
  let routes = mergeRoutes(coreRoutes, businessRoutes)
  routes = moveRouteAfter(routes, 'CounterManagement', 'ProductManage')
  routes = moveRouteAfter(routes, 'WechatConfiguration', 'ContentManage')

  assert.deepEqual(Array.from(routes, route => route.name), [
    'ProductManage', 'CounterManagement', 'OrderManage', 'ContentManage',
    'WechatConfiguration', 'UserManage'
  ])
  assert.deepEqual(Array.from(coreRoutes, route => route.name), [
    'ProductManage', 'OrderManage', 'ContentManage', 'UserManage'
  ])
})

test('enlarges legacy and custom sidebar icons consistently', () => {
  const styles = readFileSync(new URL('./menu-icons.css', import.meta.url), 'utf8')
  assert.match(styles, /#app \.sidebar-container \.sub-el-icon,\s*#app \.sidebar-container \.svg-icon\s*\{\s*width:\s*16px;\s*height:\s*16px;/)
  assert.match(styles, /#app \.sidebar-container li:has\(use\[href="#icon-wechat"\]\) \.svg-icon,\s*#app \.sidebar-container li:has\(use\[href="#icon-counter"\]\) \.svg-icon\s*\{\s*margin-right:\s*12px;\s*margin-left:\s*-2px;\s*vertical-align:\s*middle;/)
  assert.match(readFileSync(new URL('../../business/admin/icons/counter.svg', import.meta.url), 'utf8'), /<svg[^>]+viewBox="0 0 24 24"/)
})

test('adds a read-only counter number column to the StoreOrder list', () => {
  const source = readFileSync(new URL('../../business/admin/config/overrides/store/store-order.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace('export default', 'globalThis.config =')
  const base = {
    entity: { name: 'StoreOrder', plural: 'store-orders' },
    list: { list_display: ['id', 'storeCodeSnapshot', 'storeNameSnapshot', 'totalAmount'] }
  }
  const context = { StoreOrder: { StoreOrder: base }, t: key => key, StoreOrderCounterNumbers: {} }
  runInNewContext(source, context)
  const config = context.config
  assert.equal(config.entity, base.entity)
  assert.deepEqual(Array.from(config.list.list_display, field => typeof field === 'string' ? field : field.property), [
    'id', 'storeCodeSnapshot', 'storeNameSnapshot', 'tradeOrderUuid', 'totalAmount'
  ])
  const counterColumn = config.list.list_display[3]
  assert.equal(counterColumn.label, 'Counter number')
  assert.equal(counterColumn.property, 'tradeOrderUuid')
  assert.equal(counterColumn.field_options.sortable, false)
  assert.equal(counterColumn.field_options.fixed, undefined)
  assert.equal(counterColumn.component, context.StoreOrderCounterNumbers)
  assert.equal(config.detail.detail_display[0].property, 'tradeOrderUuid')
  assert.equal(config.detail.detail_display[0].label, 'Counter number')
  assert.equal(config.detail.detail_display[0].component, context.StoreOrderCounterNumbers)
  assert.equal(config.detail.detail_display[1], '__all__')
})

test('collects unique entities from collection files and nested entity files', () => {
  assert.deepEqual(collectEntities({
    './collections/common/User.js': { default: { User: { marker: 'user' } } },
    './collections/dummy/nested/Dummy.js': { default: { marker: 'dummy' } }
  }), {
    User: { marker: 'user' },
    Dummy: { marker: 'dummy' }
  })
})
