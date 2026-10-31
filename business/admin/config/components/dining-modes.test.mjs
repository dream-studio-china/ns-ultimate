import assert from 'node:assert/strict'
import test from 'node:test'
import {
  createCheckoutRule,
  createDiningMode,
  createDiningModesConfig,
  createDiningRule,
  normalizeDiningModesConfig,
  sortedModes,
  validateDiningModesConfig
} from './dining-modes.mjs'

const configuredMode = overrides => createDiningMode({
  name: 'Hotpot',
  catalog: { categoryUuids: ['category-hotpot'], productUuids: [] },
  ...overrides
})

test('hotpot and breakfast modes can be configured without invalid data', () => {
  const config = createDiningModesConfig()
  const hotpot = configuredMode({
    priority: 100,
    switchButtonLabel: 'Only breakfast',
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
      }),
      createDiningRule('fixed_item', {
        name: 'Tissue',
        specificationUuid: 'spec-tissue',
        quantity: 1
      })
    ],
    checkoutRules: [
      createCheckoutRule('confirm_participants', { name: 'Confirm guest count', title: 'Please confirm the guest count' }),
      createCheckoutRule('confirm_required_items', { name: 'Confirm tissue', title: 'Please confirm these items' }),
      createCheckoutRule('suggest_category', { name: 'Offer drinks', title: 'Does the table need drinks?', categoryUuid: 'category-drinks' })
    ]
  })
  const breakfast = configuredMode({
    uuid: 'breakfast',
    name: 'Breakfast',
    priority: 10,
    availability: {
      allDay: false,
      daysOfWeek: [1, 2, 3, 4, 5, 6, 7],
      timeWindows: [{ start: '08:00', end: '10:00' }]
    },
    catalog: { categoryUuids: ['category-breakfast'], productUuids: [] },
    rules: []
  })
  config.modes.push(breakfast, hotpot)

  assert.deepEqual(validateDiningModesConfig(config), [])
  assert.deepEqual(sortedModes(config).map(mode => mode.name), ['Hotpot', 'Breakfast'])
  assert.deepEqual(sortedModes(config)[0].checkoutRules.map(rule => rule.type), [
    'confirm_participants',
    'confirm_required_items',
    'suggest_category'
  ])
})

test('validation ignores disabled drafts but reports enabled incomplete modes', () => {
  const config = createDiningModesConfig()
  config.modes.push(createDiningMode({ name: 'Draft', enabled: false }))
  assert.deepEqual(validateDiningModesConfig(config), [])

  config.modes.push(createDiningMode({ name: 'Draft', enabled: true }))
  config.modes.push(createDiningMode({ name: 'Draft', enabled: true }))
  const errors = validateDiningModesConfig(config)
  assert.ok(errors.some(error => error.key === 'diningModeCatalogRequired'))
  assert.ok(errors.some(error => error.key === 'diningModeNameDuplicate'))
})

test('normalization creates stable defaults for persisted JSON', () => {
  const normalized = normalizeDiningModesConfig({
    version: 99,
    modes: [{
      uuid: 'mode-id',
      name: 'Direct products',
      availability: { daysOfWeek: [8, 2, 2], timeWindows: [{}] },
      catalog: { productUuids: ['product-1', 'product-1'] },
      rules: [{ uuid: 'rule-id', type: 'choice_group', specificationUuids: ['spec-1', 'spec-1'] }],
      checkoutRules: [
        { uuid: 'checkout-id', name: 'Offer drinks', title: 'Drinks check', type: 'suggest_category', required: true, categoryUuid: 'category-drinks' },
        { uuid: 'guest-id', name: 'Confirm guests', title: 'Guest check', type: 'confirm_participants' }
      ]
    }]
  })

  assert.equal(normalized.version, 1)
  assert.deepEqual(normalized.modes[0].availability.daysOfWeek, [2])
  assert.deepEqual(normalized.modes[0].availability.timeWindows, [{ start: '08:00', end: '10:00' }])
  assert.deepEqual(normalized.modes[0].catalog.productUuids, ['product-1'])
  assert.deepEqual(normalized.modes[0].rules[0].specificationUuids, ['spec-1'])
  assert.equal(normalized.modes[0].checkoutRules[0].required, false)
  assert.equal(normalized.modes[0].checkoutRules[1].required, true)
  assert.equal(normalized.modes[0].checkoutRules[0].title, 'Drinks check')
})

test('checkout rule validation rejects missing names, categories and duplicate blocking checks', () => {
  const config = createDiningModesConfig()
  config.modes.push(configuredMode({
    name: 'Hotpot',
    checkoutRules: [
      createCheckoutRule('confirm_participants', { name: '' }),
      createCheckoutRule('confirm_participants', { name: 'Confirm again', title: 'Confirm again' }),
      createCheckoutRule('suggest_category', { name: 'Offer drinks', title: 'Offer drinks' })
    ]
  }))

  const errors = validateDiningModesConfig(config)
  assert.ok(errors.some(error => error.key === 'checkoutRuleNameRequired'))
  assert.ok(errors.some(error => error.key === 'checkoutRuleTitleRequired'))
  assert.ok(errors.some(error => error.key === 'checkoutRuleTypeDuplicate'))
  assert.ok(errors.some(error => error.key === 'checkoutRuleCategoryRequired'))
})
