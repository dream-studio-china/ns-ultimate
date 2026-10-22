// Explicit app-owned write policy. The backend remains authoritative; this policy
// validates the app boundary and is also serialized into the model tool description.
const string = (maxLength, requiredOnCreate = false, nonEmpty = requiredOnCreate, extra = {}) => ({
  type: 'string', maxLength, requiredOnCreate, nonEmpty, ...extra
})
const integer = (minimum = 0, maximum = 2147483647, extra = {}) => ({ type: 'integer', minimum, maximum, ...extra })
const bool = { type: 'boolean' }
const nullableString = (maxBytes, extra = {}) => ({ type: 'utf8-string', maxBytes, nullable: true, ...extra })
const uuidPattern = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
const printingCaseKeys = ['order', 'perItem', 'filteredList', 'fullList', 'settlement']
const printingCaseFieldKeys = ['enabled', 'deviceUuid', 'templateUuid', 'copies']
// Stored printing references are canonical RFC 4122 UUID text, not arbitrary
// device codes or numeric IDs. Case-insensitivity matches the host identifier
// convention; values are still bounded to the version/variant shape above.
const printingCaseRules = {
  enabled: {
    type: 'boolean',
    description: 'Whether this fixed automatic order-printing case is enabled for FUTURE orders only.'
  },
  deviceUuid: {
    type: 'uuid', pattern: uuidPattern, patternFlags: 'i', nullable: true,
    description: 'Canonical UUID of an existing printer device, or null to clear it. Initial partial patches may omit it so the backend merges the stored reference; an explicit null with enabled true is rejected. An effective restored case requires it, non-null, when enabled is true.'
  },
  templateUuid: {
    type: 'uuid', pattern: uuidPattern, patternFlags: 'i', nullable: true,
    description: 'Canonical UUID of an existing printer template, or null to clear it. Initial partial patches may omit it so the backend merges the stored reference; an explicit null with enabled true is rejected. An effective restored case requires it, non-null, when enabled is true.'
  },
  copies: integer(1, 100, {
    description: 'Copies for this case from 1 through 100; the backend effective configuration defaults to 1.'
  })
}
const recordReference = (extra = {}) => ({
  type: 'union', nullable: true,
  anyOf: [
    { type: 'integer', minimum: 1, maximum: 2147483647 },
    { type: 'numeric-id-string', minimum: 1, maximum: 2147483647 },
    { type: 'string', format: 'uuid', pattern: uuidPattern, patternFlags: 'i' }
  ],
  ...extra
})

const contentFields = {
  title: string(255, true),
  body: nullableString(20000),
  category: { type: 'positive-id', nullable: true },
  tags: { type: 'positive-id-list', maxItems: 50 },
  metadata: {
    type: 'object', nullable: true, additionalProperties: false,
    properties: { featured: bool, layout: { type: 'enum', values: ['article', 'landing'] } }
  },
  sortOrder: integer(-2147483648, 2147483647)
}

export const contentAgentCapabilities = {
  entity: 'Content', actions: ['create', 'update', 'delete'],
  actionFields: { create: contentFields, update: contentFields },
  fields: contentFields,
  validateChanges(changes, action) {
    if (action === 'delete') return changes === undefined
    return validateChanges(changes, contentFields, action)
  },
  validatePreview: validateRestoredPreview
}

const contactFields = {
  phone: string(20, false, true, { pattern: '^(\\+?[0-9\\- ]{7,20}|1[3-9][0-9]{9})$' }),
  managerPhone: string(20, false, true, { pattern: '^(\\+?[0-9\\- ]{7,20}|1[3-9][0-9]{9})$' }),
  email: string(255, false, true, { format: 'email' }),
  managerEmail: string(255, false, true, { format: 'email' }),
  managerName: string(40, false, true, { minLength: 1 }),
  managerUserUuid: string(36, false, true, { pattern: uuidPattern, patternFlags: 'i' }),
  wechat: string(64), serviceHours: string(100),
  subTitle: string(100, false, true, { minLength: 1 }),
  tags: { type: 'string-list', maxItems: 20, minItemLength: 1, maxItemLength: 30, uniqueItems: true }
}
const addressFields = {
  province: string(40, false, true, { minLength: 1 }),
  city: string(40, false, true, { minLength: 1 }),
  district: string(40, false, true, { minLength: 1 }),
  street: string(100, false, true, { minLength: 1 }),
  detail: string(255, false, true, { minLength: 1 }),
  building: string(100), floor: string(40),
  postalCode: string(20, false, true, { pattern: '^[0-9A-Za-z\\- ]{3,20}$' }),
  formattedAddress: string(255),
  latitude: { type: 'number', minimum: -90, maximum: 90 },
  longitude: { type: 'number', minimum: -180, maximum: 180 },
  geohash: string(12, false, true, { pattern: '^[0-9bcdefghjkmnpqrstuvwxyz]{1,12}$' }),
  poiId: string(64)
}
const objectField = (properties, constraints = {}) => ({ type: 'object', nullable: true, additionalProperties: false, properties, ...constraints })

const definitions = [
  { entity: 'Counter', create: {
    storeUuid: { type: 'uuid', pattern: uuidPattern, patternFlags: 'i', requiredOnCreate: true },
    code: string(50, true, true, { trimForValidation: true }), name: string(120, true, true, { trimForValidation: true }),
    capacity: integer(1, 2147483647, { nullable: true }), sort: integer(), enabled: bool
  }, update: {
    code: string(50, false, true, { trimForValidation: true }), name: string(120, false, true, { trimForValidation: true }),
    capacity: integer(1, 2147483647, { nullable: true }), sort: integer(), enabled: bool
  } },
  { entity: 'Store', update: { name: string(255, false, true, { trimForValidation: true }), contact: objectField(contactFields), address: objectField(addressFields, { pairedProperties: [['latitude', 'longitude']] }) } },
  { entity: 'Category', create: categoryFields(), update: categoryFields() },
  { entity: 'ProductCategory', create: {
    ...categoryFields(100, true), store: { ...recordReference(), default: null, description: 'Null or omitted means global; otherwise select an existing store ID or UUID.' }
  }, update: categoryFields(100, true) },
  { entity: 'Tag', create: tagFields(), update: tagFields() },
  { entity: 'Product', create: productFields(true), update: productFields(false) },
  { entity: 'Specification', create: {
    productId: { type: 'positive-id', minimum: 1, maximum: 2147483647, requiredOnCreate: true, immutableScope: true },
    name: string(255, true, true, { trimExact: true, disallowLessThan: true }),
    price: integer(0, 2147483647, { requiredOnCreate: true }),
    status: { type: 'enum', values: ['active', 'inactive'] }, sort: integer(-2147483648, 2147483647)
  }, update: {
    productId: { type: 'positive-id', minimum: 1, maximum: 2147483647, requiredOnUpdate: true, immutableScope: true },
    name: string(255, false, true, { trimExact: true, disallowLessThan: true }),
    price: integer(0, 2147483647), status: { type: 'enum', values: ['active', 'inactive'] }, sort: integer(-2147483648, 2147483647)
  } }
]

function categoryFields(slugMaxLength = 255, productCategory = false) {
  return {
    name: string(255, true), slug: string(slugMaxLength, true),
    description: nullableString(65535), parent: recordReference(productCategory ? { mustMatchStoreScope: true } : {}),
    sortOrder: integer(), enabled: bool
  }
}
function tagFields() { return { name: string(255, true), slug: string(255, true), color: { type: 'color', nullable: true } } }
function productFields(create) {
  return {
    name: string(255, create, true, { trimExact: true, disallowLessThan: true }),
    description: nullableString(65535, { disallowHtmlTags: true }),
    status: { type: 'enum', values: ['active', 'inactive'] },
    ...(create ? { store: { type: 'uuid', pattern: uuidPattern, patternFlags: 'i', nullable: true, default: null, description: 'Omit or pass null for a global product; otherwise pass an existing store UUID.' } } : {}),
    category: { type: 'positive-id', nullable: true, mustBeEnabled: true, mustMatchProductStore: true },
    sortOrder: integer(-2147483648, 2147483647), recommendationLevel: integer(-2147483648, 2147483647), isSoldout: bool
  }
}

function plain(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    && (Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null)
    && Reflect.ownKeys(value).every(key => typeof key === 'string' && !['__proto__', 'prototype', 'constructor'].includes(key))
}

function validateValue(rule, value) {
  if (!rule) return false
  if (value === null) return rule.nullable === true
  switch (rule.type) {
    case 'string': {
      if (typeof value !== 'string' || /[\u0000-\u001f\u007f]/.test(value)) return false
      const checked = rule.trimForValidation ? value.trim() : value
      if ([...checked].length > rule.maxLength || (rule.minLength !== undefined && [...value].length < rule.minLength)) return false
      if ((rule.nonEmpty && value.trim().length === 0) || (rule.trimExact && value.trim() !== value) || (rule.disallowLessThan && value.includes('<')) || (rule.disallowHtmlTags && /<\/?[a-z][^>]*>/i.test(value))) return false
      if (rule.pattern && !new RegExp(rule.pattern, rule.patternFlags).test(value)) return false
      if (rule.format === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return false
      return true
    }
    case 'utf8-string': return typeof value === 'string' && !/[\u0000-\u001f\u007f]/.test(value)
      && new TextEncoder().encode(value).length <= rule.maxBytes
      && (!rule.disallowHtmlTags || !/<\/?[a-z][^>]*>/i.test(value))
    case 'integer': return Number.isSafeInteger(value) && value >= rule.minimum && value <= rule.maximum
    case 'number': return typeof value === 'number' && Number.isFinite(value) && value >= rule.minimum && value <= rule.maximum
    case 'positive-id': return Number.isSafeInteger(value) && value >= (rule.minimum ?? 1) && value <= (rule.maximum ?? 2147483647)
    case 'relation-id': return Number.isSafeInteger(value) && value > 0
    case 'numeric-id-string': return typeof value === 'string' && /^[1-9]\d{0,9}$/.test(value) && Number(value) >= rule.minimum && Number(value) <= rule.maximum
    case 'union': return rule.anyOf.some(alternative => validateValue(alternative, value))
    case 'uuid': return typeof value === 'string' && !/[\u0000-\u001f\u007f]/.test(value)
      && new RegExp(rule.pattern, rule.patternFlags).test(value)
    case 'boolean': return typeof value === 'boolean'
    case 'enum': return rule.values.includes(value)
    case 'color': return typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value)
    case 'positive-id-list': return Array.isArray(value) && value.length <= rule.maxItems && value.every(item => Number.isSafeInteger(item) && item > 0 && item <= 2147483647)
    case 'string-list': return Array.isArray(value) && value.length <= rule.maxItems && new Set(value).size === value.length
      && value.every(item => typeof item === 'string' && [...item].length >= rule.minItemLength && [...item].length <= rule.maxItemLength && !/[\u0000-\u001f\u007f]/.test(item))
    case 'object': return validateObject(value, rule)
    default: return false
  }
}

function validateObject(value, rule) {
  if (!plain(value) || !rule.properties || Object.keys(value).some(key => !Object.hasOwn(rule.properties, key))) return false
  if ((rule.pairedProperties || []).some(([left, right]) => Object.hasOwn(value, left) !== Object.hasOwn(value, right))) return false
  return Object.entries(value).every(([key, item]) => validateValue(rule.properties[key], item))
}

function validateChanges(changes, fields, action) {
  if (!plain(changes) || Object.keys(changes).length === 0) return false
  if (Object.keys(changes).some(key => !Object.hasOwn(fields, key) || !validateValue(fields[key], changes[key]))) return false
  return Object.entries(fields).every(([key, rule]) => {
    const required = action === 'create' && rule.requiredOnCreate || action === 'update' && rule.requiredOnUpdate
    return !required || Object.hasOwn(changes, key)
  })
}

function safeBefore(rule, value) {
  // Store contact/address snapshots are deliberately redacted as null by the backend.
  return value === null && rule.type === 'object' && rule.nullable || validateValue(rule, value)
}

const previewKeys = ['operationId', 'entity', 'action', 'identifier', 'title', 'affectedRecords', 'expiresAt', 'differences', 'saved']
const previewDifferenceKeys = ['property', 'before', 'after', 'changed', 'label']
const exactTargetPattern = /^(?:[1-9]\d{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i

function isExactTargetIdentifier(value) {
  return typeof value === 'string' && !/[\u0000-\u001f\u007f]/.test(value) && exactTargetPattern.test(value)
}

function validatePreviewEnvelope(preview, capability) {
  if (!plain(preview) || preview.entity !== capability.entity || !['create', 'update', 'delete'].includes(preview.action) || !Array.isArray(preview.differences) || preview.differences.length === 0) return false
  if (new TextEncoder().encode(JSON.stringify(preview)).length > 60000) return false
  if (Object.keys(preview).some(key => !previewKeys.includes(key))) return false
  if (preview.saved !== undefined && preview.saved !== false) return false
  if (preview.affectedRecords !== undefined && preview.affectedRecords !== 1) return false
  if (preview.title !== undefined && (typeof preview.title !== 'string' || [...preview.title].length > 1024)) return false
  return true
}

function validateStoredPrintingCase(value) {
  if (!plain(value) || Object.keys(value).some(key => !printingCaseFieldKeys.includes(key))) return false
  return Object.entries(value).every(([key, item]) => validateValue(printingCaseRules[key], item))
}

function validateEffectivePrintingCase(value) {
  if (!plain(value) || Object.keys(value).some(key => !printingCaseFieldKeys.includes(key))) return false
  if (Object.keys(value).length !== printingCaseFieldKeys.length) return false
  if (!printingCaseFieldKeys.every(key => Object.hasOwn(value, key))) return false
  if (typeof value.enabled !== 'boolean') return false
  if (!Object.entries(value).every(([key, item]) => validateValue(printingCaseRules[key], item))) return false
  if (value.enabled === true) {
    return value.deviceUuid !== null && value.templateUuid !== null
  }
  return true
}

function validateStorePrintingPreview(preview, capability) {
  if (!validatePreviewEnvelope(preview, capability) || preview.action !== 'update' || !isExactTargetIdentifier(preview.identifier)) return false
  const seen = new Set()
  for (const difference of preview.differences) {
    if (!plain(difference) || Object.keys(difference).some(key => !previewDifferenceKeys.includes(key))) return false
    const { property } = difference
    if (typeof property !== 'string' || !printingCaseKeys.includes(property) || seen.has(property)) return false
    seen.add(property)
    if (!Object.hasOwn(difference, 'before') || !Object.hasOwn(difference, 'after')) return false
    if (difference.before !== null && !validateStoredPrintingCase(difference.before)) return false
    if (!validateEffectivePrintingCase(difference.after)) return false
    if (difference.changed !== undefined && typeof difference.changed !== 'boolean') return false
    if (difference.label !== undefined && (typeof difference.label !== 'string' || difference.label.length > 120)) return false
  }
  return true
}

function validateProductPrintingPreview(preview, capability) {
  if (!validatePreviewEnvelope(preview, capability) || preview.action !== 'update' || !isExactTargetIdentifier(preview.identifier)) return false
  const seen = new Set()
  for (const difference of preview.differences) {
    if (!plain(difference) || Object.keys(difference).some(key => !previewDifferenceKeys.includes(key))) return false
    if (difference.property !== 'kitchenPrinterEnable' || seen.has(difference.property)) return false
    seen.add(difference.property)
    if (!Object.hasOwn(difference, 'before') || !Object.hasOwn(difference, 'after')) return false
    if (difference.before !== null && typeof difference.before !== 'boolean') return false
    if (typeof difference.after !== 'boolean') return false
    if (difference.changed !== undefined && typeof difference.changed !== 'boolean') return false
    if (difference.label !== undefined && (typeof difference.label !== 'string' || difference.label.length > 120)) return false
  }
  return true
}

function validateRestoredPreview(preview, capability) {
  if (!validatePreviewEnvelope(preview, capability)) return false
  if ((preview.action === 'create' || preview.action === 'delete') && (typeof preview.title !== 'string' || !preview.title.trim())) return false
  if (capability.entity === 'Product' || capability.entity === 'Specification') {
    const productPrefix = /^\[Product #(new|[1-9]\d{0,9}); Store (global|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\] /i
    const specificationPrefix = /^\[Product #([1-9]\d{0,9}); Specification #(new|[1-9]\d{0,9}); Store (global|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\] /i
    const prefix = typeof preview.title === 'string'
      ? preview.title.match(capability.entity === 'Product' ? productPrefix : specificationPrefix)
      : null
    if (!prefix) return false
    const productId = capability.entity === 'Product' ? prefix[1] : prefix[1]
    const scope = capability.entity === 'Product' ? prefix[2] : prefix[3]
    if (productId !== 'new' && Number(productId) > 2147483647) return false
    if (capability.entity === 'Product') {
      if (preview.action === 'create') {
        if (productId !== 'new' || preview.identifier !== null) return false
      } else if (productId === 'new' || typeof preview.identifier !== 'string'
        || !/^[1-9]\d{0,9}$/.test(preview.identifier) || Number(preview.identifier) > 2147483647
        || Number(productId) !== Number(preview.identifier)) return false
      const storeDifference = preview.differences.find(item => item?.property === 'store')
      if (preview.action === 'create') {
        const expectedStore = scope === 'global' ? null : scope
        if ((storeDifference?.after ?? null) !== expectedStore
          || storeDifference?.after === undefined && scope !== 'global'
          || storeDifference?.after !== undefined && storeDifference.after !== expectedStore) return false
      } else if (storeDifference) {
        const expectedStore = scope === 'global' ? null : scope
        if (storeDifference.before !== expectedStore || storeDifference.after !== expectedStore) return false
      }
    } else {
      const specificationId = prefix[2]
      const scopedProductId = Number(productId)
      if (preview.action === 'create') {
        if (specificationId !== 'new' || preview.identifier !== null) return false
      } else if (specificationId === 'new' || typeof preview.identifier !== 'string'
        || !/^[1-9]\d{0,9}$/.test(preview.identifier) || Number(preview.identifier) > 2147483647
        || Number(specificationId) !== Number(preview.identifier)) return false
      const scopedDifference = preview.differences.find(item => item?.property === 'productId')
      if (preview.action === 'create' && (!scopedDifference || scopedDifference.after !== scopedProductId)) return false
      if (scopedDifference && (scopedDifference.before !== undefined && scopedDifference.before !== null && scopedDifference.before !== scopedProductId
        || scopedDifference.after !== undefined && scopedDifference.after !== scopedProductId)) return false
    }
  }

  const fields = preview.action === 'delete' ? capability.fields : capability.actionFields?.[preview.action]
  if (!fields) return false
  const seen = new Set()
  for (const difference of preview.differences) {
    if (!plain(difference) || Object.keys(difference).some(key => !['property', 'before', 'after', 'changed', 'label'].includes(key))) return false
    const { property } = difference
    if (typeof property !== 'string' || !Object.hasOwn(fields, property) || seen.has(property)) return false
    seen.add(property)
    if (!Object.hasOwn(difference, 'before') || !Object.hasOwn(difference, 'after')) return false
    const rule = fields[property]
    if (preview.action === 'create') {
      if (difference.before !== null || !validateValue(rule, difference.after)) return false
    } else if (preview.action === 'delete') {
      if (difference.after !== null || !safeBefore(rule, difference.before)) return false
    } else {
      if (!safeBefore(rule, difference.before) || !validateValue(rule, difference.after)) return false
      if (rule.immutableScope && difference.before !== difference.after) return false
    }
    if (difference.changed !== undefined && typeof difference.changed !== 'boolean') return false
    if (difference.label !== undefined && (typeof difference.label !== 'string' || difference.label.length > 120)) return false
  }
  if (preview.action === 'create' && Object.entries(fields).some(([property, rule]) => rule.requiredOnCreate && !seen.has(property))) return false
  return true
}

for (const capability of definitions) {
  capability.actionFields = Object.fromEntries(['create', 'update', 'delete'].filter(action => capability[action]).map(action => [action, capability[action]]))
  capability.actions = Object.keys(capability.actionFields)
  if (['Counter', 'Category', 'ProductCategory', 'Tag'].includes(capability.entity)) capability.actions.push('delete')
  capability.fields = Object.assign({}, ...Object.values(capability.actionFields))
  capability.validateChanges = (changes, action) => action === 'delete'
    ? changes === undefined
    : Boolean(capability.actionFields[action] && validateChanges(changes, capability.actionFields[action], action))
  capability.validatePreview = preview => validateRestoredPreview(preview, capability)
}

const printingCapabilityDescription = 'FUTURE automatic order printing configuration only: this changes stored settings for orders processed after the change. It never prints now, never creates a print job, and never contacts a printer provider.'
const printingCaseFields = Object.fromEntries(printingCaseKeys.map(caseKey => [caseKey, {
  type: 'object',
  nullable: false,
  additionalProperties: false,
  properties: printingCaseRules,
  description: `${printingCapabilityDescription} Case: ${caseKey} for the exact stored Store.`
}]))
const productPrintingFields = {
  kitchenPrinterEnable: {
    type: 'boolean',
    description: `FUTURE automatic kitchen-list printing for this exact stored Product only. ${printingCapabilityDescription}`
  }
}

function validatePrintingCasePatch(value) {
  // An initial patch is merged with the stored case by the backend, so omitted
  // references are valid: the backend revalidates the resulting effective case.
  // Still reject an explicit contradictory clear such as enabled:true + null.
  if (!plain(value) || Object.keys(value).length === 0) return false
  if (Object.keys(value).some(key => !printingCaseFieldKeys.includes(key))) return false
  if (!Object.entries(value).every(([key, item]) => validateValue(printingCaseRules[key], item))) return false
  return !(value.enabled === true && (value.deviceUuid === null || value.templateUuid === null))
}

function validateStorePrintingChanges(changes) {
  if (!plain(changes)) return false
  const cases = Object.keys(changes)
  if (cases.length === 0 || cases.some(key => !printingCaseKeys.includes(key))) return false
  return cases.every(key => validatePrintingCasePatch(changes[key]))
}

function validateProductPrintingChanges(changes) {
  return plain(changes) && Object.keys(changes).length === 1
    && Object.hasOwn(changes, 'kitchenPrinterEnable')
    && typeof changes.kitchenPrinterEnable === 'boolean'
}

const printingCapabilities = [{
  entity: 'StorePrinting',
  description: 'Update the five fixed automatic order-printing cases on the exact stored Store identified by numeric ID or UUID. Only update is supported; settings roots and unrelated settings fields are not accepted.',
  actions: ['update'],
  actionFields: { update: printingCaseFields },
  fields: printingCaseFields,
  validateChanges: (changes, action) => action === 'update' && validateStorePrintingChanges(changes),
  validatePreview: validateStorePrintingPreview
}, {
  entity: 'ProductPrinting',
  description: 'Update only kitchenPrinterEnable on the exact stored Product identified by numeric ID or UUID. Only update is supported; arbitrary settings, metadata, and other fields are not accepted.',
  actions: ['update'],
  actionFields: { update: productPrintingFields },
  fields: productPrintingFields,
  validateChanges: (changes, action) => action === 'update' && validateProductPrintingChanges(changes),
  validatePreview: validateProductPrintingPreview
}]

// Product and Specification deletion remain disabled pending final backend safety review.
export default [contentAgentCapabilities, ...definitions, ...printingCapabilities]
