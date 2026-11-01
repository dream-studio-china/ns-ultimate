/* global globalThis */

export const DINING_MODES_VERSION = 1

export const DINING_RULE_TYPES = ['fixed_item', 'per_guest_item', 'choice_group']
export const CHECKOUT_RULE_TYPES = ['confirm_participants', 'confirm_required_items', 'suggest_category']
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7]

const DAYS = new Set(WEEKDAYS)
const RULES = new Set(DINING_RULE_TYPES)
const CHECKOUT_RULES = new Set(CHECKOUT_RULE_TYPES)
const TIME_PATTERN = /^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/

const clone = value => value == null ? value : JSON.parse(JSON.stringify(value))
const uniqueStrings = value => [...new Set((Array.isArray(value) ? value : []).filter(item => typeof item === 'string' && item.trim()).map(item => item.trim()))]
const integer = (value, fallback = 0) => Number.isInteger(Number(value)) ? Number(value) : fallback

export function newIdentifier() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
    const random = Math.floor(Math.random() * 16)
    const value = character === 'x' ? random : (random & 0x3) | 0x8
    return value.toString(16)
  })
}
export function createTimeWindow(overrides = {}) {
  return { start: '08:00', end: '10:00', ...clone(overrides) }
}

export function createDiningRule(type = 'fixed_item', overrides = {}) {
  const normalizedType = RULES.has(type) ? type : 'fixed_item'
  const base = {
    uuid: newIdentifier(),
    name: '',
    type: normalizedType,
    required: normalizedType !== 'fixed_item',
    allowRemove: normalizedType === 'fixed_item'
  }

  if (normalizedType === 'choice_group') {
    return {
      ...base,
      targetType: 'category',
      categoryUuid: '',
      specificationUuids: [],
      minSelections: 1,
      maxSelections: 1,
      quantityPerSelection: 1,
      ...clone(overrides)
    }
  }

  return {
    ...base,
    specificationUuid: '',
    ...(normalizedType === 'per_guest_item' ? { quantityPerGuest: 1 } : { quantity: 1 }),
    ...clone(overrides)
  }
}

export function changeDiningRuleType(rule, type) {
  const next = RULES.has(type) ? type : 'fixed_item'
  return createDiningRule(next, {
    uuid: rule?.uuid || newIdentifier(),
    name: typeof rule?.name === 'string' ? rule.name : ''
  })
}

export function createCheckoutRule(type = 'confirm_participants', overrides = {}) {
  const normalizedType = CHECKOUT_RULES.has(type) ? type : 'confirm_participants'
  return {
    uuid: newIdentifier(),
    name: '',
    title: '',
    type: normalizedType,
    required: normalizedType !== 'suggest_category',
    categoryUuid: '',
    ...clone(overrides)
  }
}

export function changeCheckoutRuleType(rule, type) {
  return createCheckoutRule(CHECKOUT_RULES.has(type) ? type : 'confirm_participants', {
    uuid: rule?.uuid || newIdentifier(),
    name: typeof rule?.name === 'string' ? rule.name : '',
    title: typeof rule?.title === 'string' ? rule.title : ''
  })
}

export function createDiningMode(overrides = {}) {
  return {
    uuid: newIdentifier(),
    name: '',
    enabled: true,
    priority: 10,
    switchButtonLabel: '',
    availability: {
      allDay: true,
      daysOfWeek: [...WEEKDAYS],
      timeWindows: []
    },
    catalog: {
      categoryUuids: [],
      productUuids: []
    },
    rules: [],
    checkoutRules: [],
    ...clone(overrides)
  }
}

export function createDiningModesConfig() {
  return { version: DINING_MODES_VERSION, modes: [] }
}

function normalizeRule(value) {
  const type = RULES.has(value?.type) ? value.type : 'fixed_item'
  const rule = {
    ...clone(value || {}),
    uuid: typeof value?.uuid === 'string' && value.uuid ? value.uuid : newIdentifier(),
    name: typeof value?.name === 'string' ? value.name : '',
    title: typeof value?.title === 'string' ? value.title : '',
    type,
    required: Boolean(value?.required),
    allowRemove: value?.allowRemove === undefined ? type === 'fixed_item' : Boolean(value.allowRemove)
  }

  if (type === 'choice_group') {
    return {
      ...rule,
      targetType: value?.targetType === 'specifications' ? 'specifications' : 'category',
      categoryUuid: typeof value?.categoryUuid === 'string' ? value.categoryUuid : '',
      specificationUuids: uniqueStrings(value?.specificationUuids),
      minSelections: integer(value?.minSelections, 1),
      maxSelections: integer(value?.maxSelections, 1),
      quantityPerSelection: integer(value?.quantityPerSelection, 1)
    }
  }

  return {
    ...rule,
    specificationUuid: typeof value?.specificationUuid === 'string' ? value.specificationUuid : '',
    ...(type === 'per_guest_item'
      ? { quantityPerGuest: integer(value?.quantityPerGuest, 1) }
      : { quantity: integer(value?.quantity, 1) })
  }
}

function normalizeMode(value) {
  const availability = value?.availability || {}
  const catalog = value?.catalog || {}
  return {
    ...clone(value || {}),
    uuid: typeof value?.uuid === 'string' && value.uuid ? value.uuid : newIdentifier(),
    name: typeof value?.name === 'string' ? value.name : '',
    enabled: value?.enabled !== false,
    priority: integer(value?.priority, 10),
    switchButtonLabel: typeof value?.switchButtonLabel === 'string' ? value.switchButtonLabel : '',
    availability: {
      ...clone(availability),
      allDay: availability.allDay !== false,
      daysOfWeek: [...new Set((Array.isArray(availability.daysOfWeek) ? availability.daysOfWeek : WEEKDAYS)
        .map(Number).filter(day => DAYS.has(day)))],
      timeWindows: (Array.isArray(availability.timeWindows) ? availability.timeWindows : []).map(window => ({
        start: typeof window?.start === 'string' ? window.start : '08:00',
        end: typeof window?.end === 'string' ? window.end : '10:00'
      }))
    },
    catalog: {
      ...clone(catalog),
      categoryUuids: uniqueStrings(catalog.categoryUuids),
      productUuids: uniqueStrings(catalog.productUuids)
    },
    rules: (Array.isArray(value?.rules) ? value.rules : []).map(normalizeRule),
    checkoutRules: (Array.isArray(value?.checkoutRules) ? value.checkoutRules : []).map(normalizeCheckoutRule)
  }
}

function normalizeCheckoutRule(value) {
  const type = CHECKOUT_RULES.has(value?.type) ? value.type : 'confirm_participants'
  return {
    ...clone(value || {}),
    uuid: typeof value?.uuid === 'string' && value.uuid ? value.uuid : newIdentifier(),
    name: typeof value?.name === 'string' ? value.name : '',
    type,
    required: type === 'suggest_category' ? false : value?.required !== false,
    categoryUuid: type === 'suggest_category' && typeof value?.categoryUuid === 'string' ? value.categoryUuid : ''
  }
}

export function normalizeDiningModesConfig(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return createDiningModesConfig()
  return {
    ...clone(value),
    version: DINING_MODES_VERSION,
    modes: (Array.isArray(value.modes) ? value.modes : []).map(normalizeMode)
  }
}

export function validateDiningModesConfig(value) {
  const config = normalizeDiningModesConfig(value)
  const errors = []
  const names = new Map()

  for (const [modeIndex, mode] of config.modes.entries()) {
    if (!mode.enabled) continue
    const modeRef = { modeUuid: mode.uuid, modeIndex }
    const name = mode.name.trim()
    if (!name) errors.push({ key: 'diningModeNameRequired', ...modeRef })
    else {
      const key = name.toLocaleLowerCase()
      if (names.has(key)) errors.push({ key: 'diningModeNameDuplicate', ...modeRef, params: { name }})
      names.set(key, mode.uuid)
    }

    if (!Number.isInteger(mode.priority) || mode.priority < 0 || mode.priority > 9999) {
      errors.push({ key: 'diningModePriorityInvalid', ...modeRef })
    }
    if (mode.availability.daysOfWeek.length === 0) {
      errors.push({ key: 'diningModeDaysRequired', ...modeRef })
    }
    if (!mode.availability.allDay) {
      if (mode.availability.timeWindows.length === 0) {
        errors.push({ key: 'diningModeWindowsRequired', ...modeRef })
      }
      for (const [windowIndex, window] of mode.availability.timeWindows.entries()) {
        if (!TIME_PATTERN.test(window.start) || !TIME_PATTERN.test(window.end) || window.start === window.end) {
          errors.push({ key: 'diningModeWindowInvalid', ...modeRef, windowIndex })
        }
      }
    }
    if (mode.catalog.categoryUuids.length === 0 && mode.catalog.productUuids.length === 0) {
      errors.push({ key: 'diningModeCatalogRequired', ...modeRef })
    }

    for (const [ruleIndex, rule] of mode.rules.entries()) {
      const ruleRef = { ...modeRef, ruleUuid: rule.uuid, ruleIndex }
      if (rule.type === 'choice_group') {
        if (rule.targetType === 'category' && !rule.categoryUuid) {
          errors.push({ key: 'diningRuleCategoryRequired', ...ruleRef })
        }
        if (rule.targetType === 'specifications' && rule.specificationUuids.length === 0) {
          errors.push({ key: 'diningRuleProductsRequired', ...ruleRef })
        }
        if (rule.minSelections < 0 || rule.maxSelections < 1 || rule.maxSelections < rule.minSelections) {
          errors.push({ key: 'diningRuleSelectionRangeInvalid', ...ruleRef })
        }
        if (rule.required && rule.minSelections < 1) {
          errors.push({ key: 'diningRuleRequiredMinimum', ...ruleRef })
        }
        if (rule.quantityPerSelection < 1 || rule.quantityPerSelection > 99) {
          errors.push({ key: 'diningRuleQuantityInvalid', ...ruleRef })
        }
      } else {
        if (!rule.specificationUuid) errors.push({ key: 'diningRuleSpecificationRequired', ...ruleRef })
        const quantity = rule.type === 'per_guest_item' ? rule.quantityPerGuest : rule.quantity
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > 99) {
          errors.push({ key: 'diningRuleQuantityInvalid', ...ruleRef })
        }
      }
    }

    const checkoutTypes = new Set()
    for (const [checkoutRuleIndex, rule] of mode.checkoutRules.entries()) {
      const ruleRef = { ...modeRef, checkoutRuleIndex }
      if (!rule.name.trim()) errors.push({ key: 'checkoutRuleNameRequired', ...ruleRef })
      if (!rule.title.trim()) errors.push({ key: 'checkoutRuleTitleRequired', ...ruleRef })
      if (rule.type === 'suggest_category' && !rule.categoryUuid) {
        errors.push({ key: 'checkoutRuleCategoryRequired', ...ruleRef })
      }
      if (rule.type !== 'suggest_category' && checkoutTypes.has(rule.type)) {
        errors.push({ key: 'checkoutRuleTypeDuplicate', ...ruleRef })
      }
      checkoutTypes.add(rule.type)
    }
  }

  return errors
}

export function sortedModes(config) {
  return normalizeDiningModesConfig(config).modes
    .map((mode, index) => ({ ...mode, _index: index }))
    .sort((left, right) => right.priority - left.priority || left._index - right._index)
}
