import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import {
  createCheckoutRule,
  createDiningMode,
  createDiningModesConfig,
  createDiningRule,
  normalizeDiningModesConfig,
  validateDiningModesConfig
} from './dining-modes.mjs'

const componentPaths = ['./StoreDiningModesAction.vue', './DiningModesEditor.vue']
const plain = value => JSON.parse(JSON.stringify(value))

function readJson(path) {
  return JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'))
}

function loadStoreOverride() {
  const source = readFileSync(new URL('../overrides/store/store.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const context = {
    t: key => key,
    orderByIdDesc: {},
    StoreContactSchema: {},
    StoreAddressSchema: {},
    StoreSettingsSchema: {},
    StoreDiningModesAction: { name: 'StoreDiningModesAction' }
  }
  runInNewContext(source, context)
  return context.config
}

function loadMessages(locale) {
  const context = {}
  const source = readFileSync(new URL(`../../i18n/${locale}.js`, import.meta.url), 'utf8')
    .replace('export default', 'globalThis.messages =')
  runInNewContext(source, context)
  return context.messages
}

test('stores ordering and checkout rules in the Store settings contract', () => {
  const config = createDiningModesConfig()
  const mode = createDiningMode({
    name: 'Hotpot',
    catalog: { categoryUuids: ['category-hotpot'], productUuids: [] },
    rules: [
      createDiningRule('per_guest_item', {
        name: 'Tableware',
        specificationUuid: 'spec-tableware',
        quantityPerGuest: 1
      }),
      createDiningRule('choice_group', {
        name: 'Soup base',
        targetType: 'category',
        categoryUuid: 'category-soup',
        minSelections: 1,
        maxSelections: 1
      })
    ],
    checkoutRules: [
      createCheckoutRule('confirm_participants', {
        name: 'Confirm guests',
        title: 'Confirm the current guest count'
      }),
      createCheckoutRule('confirm_required_items', {
        name: 'Confirm items',
        title: 'Confirm required items'
      })
    ]
  })
  config.modes.push(mode)

  const persisted = JSON.parse(JSON.stringify(config))
  const normalized = normalizeDiningModesConfig(persisted)
  assert.deepEqual(validateDiningModesConfig(normalized), [])
  assert.equal(normalized.modes[0].rules[0].type, 'per_guest_item')
  assert.deepEqual(normalized.modes[0].checkoutRules.map(rule => rule.type), [
    'confirm_participants',
    'confirm_required_items'
  ])

  const schema = readJson('../overrides/store/StoreSettings.json')
  const diningModeProperties = schema.properties.diningModes.properties.modes.items.properties
  assert.deepEqual(diningModeProperties.rules.items.properties.type.enum, [
    'fixed_item',
    'per_guest_item',
    'choice_group'
  ])
  assert.deepEqual(diningModeProperties.checkoutRules.items.properties.type.enum, [
    'confirm_participants',
    'confirm_required_items',
    'suggest_category'
  ])
})

test('adds a dedicated Store list action while keeping settings editing safe', () => {
  const config = loadStoreOverride()
  assert.equal(config.list.actions.length, 1)
  assert.equal(config.list.actions[0].name, 'store-dining-modes')
  assert.equal(config.list.actions[0].position, 'list')

  const settings = config.form.fields.find(field => field.property === 'settings')
  assert.equal(settings.type, 'json_schema')
  assert.deepEqual(plain(settings.type_options.fields), [{ property: 'diningModes', hidden: true }])
})

test('all dining mode UI labels exist in every business locale', () => {
  const keys = new Set()
  for (const path of componentPaths) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8')
    for (const match of source.matchAll(/(?:\$t|\bt)\(['"]([^'"]+)['"]\)/g)) keys.add(match[1])
  }
  keys.add('checkoutRuleNameRequired')
  keys.add('checkoutRuleTitleRequired')
  keys.add('checkoutRuleCategoryRequired')
  keys.add('checkoutRuleTypeDuplicate')

  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const messages = loadMessages(locale)
    for (const key of keys) {
      if (['Close', 'Retry', 'Save'].includes(key)) continue
      assert.equal(typeof messages[key], 'string', `${locale}: missing ${key}`)
    }
  }
})
