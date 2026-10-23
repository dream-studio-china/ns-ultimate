/**
 * ns-ultimate mutation capability adapter.
 *
 * The field/action policy is no longer written here. The authenticated backend
 * publishes the versioned runtime catalog, and this adapter only compiles that
 * response through the generic validator and adds the ns-ultimate-specific
 * preview safety checks that cannot be expressed as a single field rule.
 */

import {
  compileCapabilityCatalogResponse,
  createCapabilityCatalogRuntime
} from '../../../src/capability-catalog.js'
import {
  PREVIEW_DIFFERENCE_KEYS,
  isExactTargetIdentifier,
  isPlainRecord,
  validateEffectivePrintingCase,
  validatePreviewDifferences,
  validatePreviewEnvelope,
  validateRuleValue,
  validateStoredPrintingCase
} from '../../../src/capability-validation.js'

const UUID_SCOPE_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'
const PRODUCT_TITLE_PATTERN = new RegExp(`^\\[Product #(new|[1-9]\\d{0,9}); Store (global|${UUID_SCOPE_PATTERN})\\] `, 'i')
const SPECIFICATION_TITLE_PATTERN = new RegExp(`^\\[Product #([1-9]\\d{0,9}); Specification #(new|[1-9]\\d{0,9}); Store (global|${UUID_SCOPE_PATTERN})\\] `, 'i')
const NUMERIC_IDENTIFIER_PATTERN = /^[1-9]\d{0,9}$/
const MAX_TARGET_ID = 2147483647
const SAFE_DIFFERENCE_KEYS = new Set(PREVIEW_DIFFERENCE_KEYS)

function hasOnlySafeDifferenceKeys(value) {
  return Object.keys(value).every(key => SAFE_DIFFERENCE_KEYS.has(key))
}

function hasSafeDifferenceMetadata(difference) {
  if (difference.changed !== undefined && typeof difference.changed !== 'boolean') return false
  if (difference.label !== undefined && (typeof difference.label !== 'string' || difference.label.length > 120)) return false
  return true
}

function expectedScope(scope) {
  return scope.toLowerCase() === 'global' ? null : scope
}

/**
 * Product titles encode the exact product and store scope. Keeping this check
 * here prevents a preview from silently changing scope while all per-field
 * values still match the descriptor.
 */
function validateProductPreview(preview, capability) {
  if (!validatePreviewEnvelope(preview, capability) || !capability.actions.includes(preview.action)) return false
  const prefix = typeof preview.title === 'string' ? preview.title.match(PRODUCT_TITLE_PATTERN) : null
  if (!prefix) return false
  const [, productId, scope] = prefix
  if (productId !== 'new' && Number(productId) > MAX_TARGET_ID) return false
  if (preview.action === 'create') {
    if (productId !== 'new' || preview.identifier !== null) return false
  } else if (productId === 'new' || typeof preview.identifier !== 'string'
    || !NUMERIC_IDENTIFIER_PATTERN.test(preview.identifier)
    || Number(preview.identifier) > MAX_TARGET_ID
    || Number(productId) !== Number(preview.identifier)) return false
  const storeDifference = preview.differences.find(item => isPlainRecord(item) && item.property === 'store')
  const store = expectedScope(scope)
  if (preview.action === 'create') {
    if ((storeDifference?.after ?? null) !== store
      || (storeDifference?.after === undefined && store !== null)
      || (storeDifference?.after !== undefined && storeDifference.after !== store)) return false
  } else if (storeDifference && (storeDifference.before !== store || storeDifference.after !== store)) {
    return false
  }
  return validatePreviewDifferences(preview, capability)
}

/**
 * Specification titles encode both the immutable owning product and the store
 * scope. The productId difference may be omitted on update but can never change.
 */
function validateSpecificationPreview(preview, capability) {
  if (!validatePreviewEnvelope(preview, capability) || !capability.actions.includes(preview.action)) return false
  const prefix = typeof preview.title === 'string' ? preview.title.match(SPECIFICATION_TITLE_PATTERN) : null
  if (!prefix) return false
  const [, productId, specificationId] = prefix
  const scopedProductId = Number(productId)
  if (!Number.isSafeInteger(scopedProductId) || scopedProductId > MAX_TARGET_ID) return false
  if (preview.action === 'create') {
    if (specificationId !== 'new' || preview.identifier !== null) return false
  } else if (specificationId === 'new' || typeof preview.identifier !== 'string'
    || !NUMERIC_IDENTIFIER_PATTERN.test(preview.identifier)
    || Number(preview.identifier) > MAX_TARGET_ID
    || Number(specificationId) !== Number(preview.identifier)) return false
  const productDifference = preview.differences.find(item => isPlainRecord(item) && item.property === 'productId')
  if (preview.action === 'create' && (!productDifference || productDifference.after !== scopedProductId)) return false
  if (productDifference) {
    if ((productDifference.before !== undefined && productDifference.before !== null && productDifference.before !== scopedProductId)
      || (productDifference.after !== undefined && productDifference.after !== scopedProductId)) return false
  }
  return validatePreviewDifferences(preview, capability)
}

function printingCaseRules(capability, property) {
  const caseRule = capability.fields[property]
  if (!caseRule || caseRule.type !== 'object' || !caseRule.properties) return null
  const rules = caseRule.properties
  if (typeof rules.enabled !== 'object' || typeof rules.deviceUuid !== 'object' || typeof rules.templateUuid !== 'object' || typeof rules.copies !== 'object') return null
  return rules
}

/**
 * StorePrinting deliberately supports partial patches before storage, but the
 * restored `after` value must be a complete effective case. The case names and
 * four field rules come from the descriptor; only the effective-case semantics
 * remain adapter-owned.
 */
function validateStorePrintingPreview(preview, capability) {
  if (!validatePreviewEnvelope(preview, capability) || preview.action !== 'update') return false
  if (!isExactTargetIdentifier(preview.identifier)) return false
  const cases = Object.keys(capability.fields)
  if (cases.length === 0) return false
  const seen = new Set()
  for (const difference of preview.differences) {
    if (!isPlainRecord(difference) || !hasOnlySafeDifferenceKeys(difference)) return false
    const { property } = difference
    if (typeof property !== 'string' || !Object.hasOwn(capability.fields, property) || seen.has(property)) return false
    seen.add(property)
    if (!Object.hasOwn(difference, 'before') || !Object.hasOwn(difference, 'after')) return false
    const rules = printingCaseRules(capability, property)
    if (!rules) return false
    if (difference.before !== null && !validateStoredPrintingCase(difference.before, rules)) return false
    if (!validateEffectivePrintingCase(difference.after, rules)) return false
    if (!hasSafeDifferenceMetadata(difference)) return false
  }
  return true
}

function validateProductPrintingPreview(preview, capability) {
  if (!validatePreviewEnvelope(preview, capability) || preview.action !== 'update') return false
  if (!isExactTargetIdentifier(preview.identifier)) return false
  const properties = Object.keys(capability.fields)
  if (properties.length !== 1) return false
  const [property] = properties
  const rule = capability.fields[property]
  if (rule?.type !== 'boolean') return false
  const seen = new Set()
  for (const difference of preview.differences) {
    if (!isPlainRecord(difference) || !hasOnlySafeDifferenceKeys(difference)) return false
    if (difference.property !== property || seen.has(difference.property)) return false
    seen.add(difference.property)
    if (!Object.hasOwn(difference, 'before') || !Object.hasOwn(difference, 'after')) return false
    if (difference.before !== null && typeof difference.before !== 'boolean') return false
    if (typeof difference.after !== 'boolean') return false
    if (!hasSafeDifferenceMetadata(difference)) return false
  }
  return true
}

/** Entity-level safety hooks only; no field/action tables live here. */
export const previewSemantics = Object.freeze({
  Product: validateProductPreview,
  Specification: validateSpecificationPreview,
  StorePrinting: validateStorePrintingPreview,
  ProductPrinting: validateProductPrintingPreview
})

function validatePrintingCasePatch(value, rules) {
  if (!isPlainRecord(value) || Object.keys(value).length === 0) return false
  if (Object.keys(value).some(key => !Object.hasOwn(rules, key))) return false
  if (!Object.entries(value).every(([key, item]) => validateRuleValue(rules[key], item))) return false
  // An initial patch is merged with the stored case by the backend. Reject an
  // explicit contradiction such as enabled:true + deviceUuid:null; omitted
  // references remain valid because the backend revalidates the merged case.
  return !(value.enabled === true && (value.deviceUuid === null || value.templateUuid === null))
}

function validateStorePrintingChanges(changes, action, capability) {
  if (action !== 'update' || !isPlainRecord(changes)) return false
  const cases = Object.keys(changes)
  if (cases.length === 0 || cases.some(key => !Object.hasOwn(capability.fields, key))) return false
  return cases.every(key => {
    const rules = printingCaseRules(capability, key)
    return Boolean(rules) && validatePrintingCasePatch(changes[key], rules)
  })
}

export const changeSemantics = Object.freeze({
  StorePrinting: validateStorePrintingChanges
})

export function compileMutationCapabilities(response) {
  return compileCapabilityCatalogResponse(response, { previewSemantics, changeSemantics })
}

export function createNsUltimateMutationRuntime(options = {}) {
  return createCapabilityCatalogRuntime({
    ...options,
    compile: response => compileMutationCapabilities(response)
  })
}
