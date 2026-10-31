import { describe, expect, it } from 'vitest'
import { availableDiningModes, diningModeLabel, inDiningCatalog, isChoiceSelectionBlocked, normalizeDiningModes, ruleQuantity, validateDiningRules, type DiningMode } from '../../src/staff/dining-mode'

const modes = normalizeDiningModes({
  version: 1,
  modes: [{
    uuid: 'breakfast', name: '早餐', enabled: true, priority: 5, switchButtonLabel: '不打火锅',
    availability: { allDay: false, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [{ start: '22:00', end: '10:00' }] },
    catalog: { categoryUuids: ['drinks'], productUuids: [] }, rules: [],
  }, {
    uuid: 'hotpot', name: '火锅', enabled: true, priority: 10,
    availability: { allDay: true, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [] },
    catalog: { categoryUuids: ['meat'], productUuids: [] },
    rules: [
      { uuid: 'tea', type: 'per_guest_item', specificationUuid: 'tea-spec', quantityPerGuest: 1, required: true, allowRemove: false },
      { uuid: 'tissue', type: 'fixed_item', specificationUuid: 'tissue-spec', quantity: 1, required: true, allowRemove: false },
      { uuid: 'soup', type: 'choice_group', targetType: 'category', categoryUuid: 'soup', minSelections: 1, maxSelections: 1, quantityPerSelection: 1, required: true, allowRemove: false },
    ],
    checkoutRules: [
      { uuid: 'check-guests', name: '人数规则', title: '请确认用餐人数', type: 'confirm_participants', required: true },
      { uuid: 'check-tissue', name: '纸巾规则', title: '请确认纸巾', type: 'confirm_required_items', required: true },
      { uuid: 'suggest-drinks', name: '饮料规则', title: '是否需要饮料', type: 'suggest_category', required: false, categoryUuid: 'drinks' },
    ],
  }],
})
describe('store dining modes', () => {
  it('selects the highest-priority active mode and uses its switch label', () => {
    const active = availableDiningModes(modes, new Date('2026-10-30T14:50:00Z'), 'Asia/Shanghai')
    expect(active.map((mode) => mode.name)).toEqual(['火锅', '早餐'])
    expect(diningModeLabel(active[0]!)).toBe('火锅')
    expect(diningModeLabel(active[1]!)).toBe('不打火锅')
  })

  it('honors store-local weekdays and exclusive end times for overnight windows', () => {
    expect(availableDiningModes(modes, new Date('2026-10-30T13:59:00Z'), 'Asia/Shanghai').map((mode) => mode.uuid)).toEqual(['hotpot'])
    expect(availableDiningModes(modes, new Date('2026-10-30T14:00:00Z'), 'Asia/Shanghai').map((mode) => mode.uuid)).toEqual(['hotpot', 'breakfast'])
    expect(availableDiningModes(modes, new Date('2026-10-31T01:59:00Z'), 'Asia/Shanghai').map((mode) => mode.uuid)).toEqual(['hotpot', 'breakfast'])
    expect(availableDiningModes(modes, new Date('2026-10-31T02:00:00Z'), 'Asia/Shanghai').map((mode) => mode.uuid)).toEqual(['hotpot'])
  })

  it('filters the product catalog by category or individual product', () => {
    const mode = modes.find((item) => item.uuid === 'hotpot')!
    expect(inDiningCatalog({ productUuid: 'product', categoryUuid: 'meat' }, mode.catalog)).toBe(true)
    expect(inDiningCatalog({ productUuid: 'product', categoryUuid: 'drinks' }, mode.catalog)).toBe(false)
    expect(inDiningCatalog({ productUuid: 'exception', categoryUuid: 'drinks' }, { categoryUuids: ['meat'], productUuids: ['exception'] })).toBe(true)
  })

  it('preserves checkout rule order and treats category suggestions as optional', () => {
    const hotpot = modes.find((item) => item.uuid === 'hotpot')!
    expect(hotpot.checkoutRules.map((rule) => rule.type)).toEqual([
      'confirm_participants',
      'confirm_required_items',
      'suggest_category',
    ])
    expect(hotpot.checkoutRules[1]).toMatchObject({ name: '纸巾规则', title: '请确认纸巾', required: true })
    expect(hotpot.checkoutRules[2]).toMatchObject({ categoryUuid: 'drinks', required: false })
  })

  it('calculates defaults and enforces choice groups', () => {
    const hotpot = modes.find((item) => item.uuid === 'hotpot')!
    expect(ruleQuantity(hotpot.rules[0] as Extract<DiningMode['rules'][number], { type: 'per_guest_item' }>, 4)).toBe(4)
    const defaults = [
      { specificationUuid: 'tea-spec', quantity: 4 },
      { specificationUuid: 'tissue-spec', quantity: 1 },
    ]
    expect(validateDiningRules(hotpot, defaults)).toEqual([{ ruleUuid: 'soup', kind: 'minimum', actual: 0, expected: 1 }])
    expect(validateDiningRules(hotpot, [...defaults, { specificationUuid: 'soup-spec', categoryUuid: 'soup', quantity: 1 }])).toEqual([])
    expect(isChoiceSelectionBlocked(hotpot, [{ specificationUuid: 'soup-spec', categoryUuid: 'soup', quantity: 1 }], { specificationUuid: 'other-spec', categoryUuid: 'soup', quantity: 1 })).toBe(true)
    expect(isChoiceSelectionBlocked(hotpot, [{ specificationUuid: 'soup-spec', categoryUuid: 'soup', quantity: 1 }], { specificationUuid: 'soup-spec', categoryUuid: 'soup', quantity: 1 })).toBe(false)
  })

  it('ignores disabled and malformed modes without substituting demo data', () => {
    const normalized = normalizeDiningModes({ modes: [{ uuid: 'disabled', enabled: false }, { name: 'missing uuid' }] })
    expect(normalized).toHaveLength(1)
    expect(availableDiningModes(normalized, new Date(), 'UTC')).toEqual([])
  })

  it('reads dining modes from the JSON string shape returned by legacy stores', () => {
    const normalized = normalizeDiningModes(JSON.stringify({
      version: 1,
      modes: [{
        uuid: '00000000-0000-4000-8000-000000000001', name: '火锅', enabled: true, priority: 10,
        availability: { allDay: true, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [] },
        catalog: { categoryUuids: [], productUuids: [] }, rules: [], checkoutRules: [],
      }],
    }))

    expect(normalized).toHaveLength(1)
    expect(normalized[0]).toMatchObject({ name: '火锅', uuid: '00000000-0000-4000-8000-000000000001' })
  })
})
