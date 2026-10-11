import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { collectEntities } from '../../business/admin/config/collect-entities.mjs'
import { mergeEntities } from './merge-config.mjs'
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
  assert.deepEqual(Array.from(counters.children, route => route.name), ['Counter', 'OrderCounter'])
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
