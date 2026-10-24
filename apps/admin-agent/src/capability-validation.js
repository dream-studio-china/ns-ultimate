/**
 * Generic, bounded capability rule validation.
 *
 * Capabilities are received from the host as versioned JSON. This module owns
 * the only field/action validation engine used by the browser; it never imports
 * business-specific field tables. Callers compile a server descriptor once and
 * then reuse the resulting validators for preparation and preview recovery.
 */

const ACTIONS = Object.freeze(['create', 'update', 'delete'])
const ENTITY_PATTERN = /^[A-Z][A-Za-z0-9]{0,63}$/
const FIELD_PATTERN = /^[A-Za-z][A-Za-z0-9_]{0,63}$/
const UNSAFE_KEYS = new Set(['__proto__', 'prototype', 'constructor'])
const MAX_DESCRIPTION_LENGTH = 1000
const MAX_ENTITY_DESCRIPTION_LENGTH = 2000
const MAX_FIELDS_PER_MAP = 64
const MAX_RULES_PER_CAPABILITY = 1000
const MAX_RULE_DEPTH = 8
const MAX_PATTERN_LENGTH = 200
const MAX_BRANCH_QUANTIFIERS = 8
const MAX_VALUE_LENGTH = 1_000_000
const MAX_STRING_INPUT = 200_000
const MAX_NUMERIC_BOUND = 1e15

const RULE_TYPES = new Set([
  'string', 'utf8-string', 'integer', 'number', 'positive-id', 'relation-id',
  'numeric-id-string', 'union', 'uuid', 'boolean', 'enum', 'color',
  'positive-id-list', 'string-list', 'object'
])

const COMMON_RULE_KEYS = Object.freeze([
  'type', 'description', 'nullable', 'requiredOnCreate', 'requiredOnUpdate',
  'default', 'immutableScope', 'mustMatchStoreScope', 'mustMatchProductStore',
  'mustBeEnabled'
])
const TYPE_RULE_KEYS = Object.freeze({
  string: ['maxLength', 'minLength', 'nonEmpty', 'trimForValidation', 'trimExact', 'disallowLessThan', 'disallowHtmlTags', 'pattern', 'patternFlags', 'format'],
  'utf8-string': ['maxBytes', 'disallowHtmlTags'],
  integer: ['minimum', 'maximum'],
  number: ['minimum', 'maximum'],
  'positive-id': ['minimum', 'maximum'],
  'relation-id': ['minimum', 'maximum'],
  'numeric-id-string': ['minimum', 'maximum'],
  union: ['anyOf'],
  uuid: ['pattern', 'patternFlags'],
  boolean: [],
  enum: ['values'],
  color: [],
  'positive-id-list': ['maxItems'],
  'string-list': ['maxItems', 'minItemLength', 'maxItemLength', 'uniqueItems'],
  object: ['properties', 'additionalProperties', 'pairedProperties']
})
const ALLOWED_FORMATS = new Set(['email', 'uuid'])

const PREVIEW_KEYS = Object.freeze(['operationId', 'entity', 'action', 'identifier', 'title', 'affectedRecords', 'expiresAt', 'differences', 'saved'])
const PREVIEW_DIFFERENCE_KEYS = Object.freeze(['property', 'before', 'after', 'changed', 'label'])
const SAFE_PREVIEW_DIFFERENCE_KEY_SET = new Set(['property', 'before', 'after', 'changed', 'label'])
const EXACT_TARGET_PATTERN = /^(?:[1-9]\d{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i
const MAX_PREVIEW_CHARACTERS = 60000
const MAX_PREVIEW_DIFFERENCES = 100
const MAX_TITLE_LENGTH = 1024
const MAX_LABEL_LENGTH = 120

const compiledPatterns = new WeakMap()
// Delete preview metadata is deliberately kept out of the serializable
// capability object. It is display/validation policy, not writable fields, and
// must never be merged into model tool input descriptions or `fields`.
const compiledDeletePreviewFields = new WeakMap()

export class CapabilityCatalogError extends Error {
  constructor(code, message) {
    super(message)
    this.name = 'CapabilityCatalogError'
    this.code = code
  }
}

export function isPlainRecord(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const prototype = Object.getPrototypeOf(value)
  return (prototype === Object.prototype || prototype === null)
    && Reflect.ownKeys(value).every(key => typeof key === 'string')
}

export function hasUnsafeKeys(value) {
  return Object.keys(value).some(key => UNSAFE_KEYS.has(key))
}

function malformed(message) {
  return new CapabilityCatalogError('malformed', message)
}

function unsupported(message) {
  return new CapabilityCatalogError('unsupported_validation', message)
}

function isSafeKey(value) {
  return typeof value === 'string' && FIELD_PATTERN.test(value) && !UNSAFE_KEYS.has(value)
}

function isSafeEntity(value) {
  return typeof value === 'string' && ENTITY_PATTERN.test(value) && !UNSAFE_KEYS.has(value)
}

function isBoundedInteger(value, minimum = 0, maximum = Number.MAX_SAFE_INTEGER) {
  return Number.isSafeInteger(value) && value >= minimum && value <= maximum
}

function isBoundedNumber(value) {
  return typeof value === 'number' && Number.isFinite(value) && Math.abs(value) <= MAX_NUMERIC_BOUND
}

function assertBoundedString(value, maximum, message) {
  if (typeof value !== 'string' || value.length > maximum || /[\u0000-\u001f\u007f]/.test(value)) throw malformed(message)
}

const ESCAPABLE_PATTERN_CHARACTERS = new Set(['^', '$', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '\\', '-', '/'])
const SHORTHAND_CLASS_ESCAPES = new Set(['d', 'D', 's', 'S', 'w', 'W'])

function patternUnsupported(message = 'The capability catalog contains an unsupported regular expression.') {
  return unsupported(message)
}

function findClassEnd(source, index) {
  let cursor = index + 1
  if (source[cursor] === '^') cursor += 1
  let hasContent = false
  while (cursor < source.length) {
    const character = source[cursor]
    if (character === '\\') {
      if (cursor + 1 >= source.length) throw patternUnsupported('The capability catalog contains an invalid character class.')
      const escaped = source[cursor + 1]
      if (/[1-9]/.test(escaped) || escaped === 'k' || escaped === 'g') throw patternUnsupported('Backreferences are not supported in capability patterns.')
      cursor += 2
      hasContent = true
      continue
    }
    if (character === '[') throw patternUnsupported('Nested character classes are not supported in capability patterns.')
    if (character === ']') {
      if (!hasContent) throw patternUnsupported('Empty character classes are not supported in capability patterns.')
      return cursor
    }
    if (/[\u0000-\u001f\u007f]/.test(character)) throw patternUnsupported('Control characters are not supported in capability patterns.')
    cursor += 1
    hasContent = true
  }
  throw patternUnsupported('The capability catalog contains an unterminated character class.')
}

function findGroupEnd(source, index) {
  let depth = 0
  let cursor = index
  while (cursor < source.length) {
    const character = source[cursor]
    if (character === '\\') {
      cursor += 2
      continue
    }
    if (character === '[') {
      cursor = findClassEnd(source, cursor) + 1
      continue
    }
    if (character === '(') {
      depth += 1
      cursor += 1
      continue
    }
    if (character === ')') {
      depth -= 1
      if (depth === 0) return cursor
      cursor += 1
      continue
    }
    cursor += 1
  }
  throw patternUnsupported('The capability catalog contains an unterminated regular expression group.')
}

function splitAlternatives(source) {
  const alternatives = []
  let start = 0
  let cursor = 0
  while (cursor < source.length) {
    const character = source[cursor]
    if (character === '\\') {
      cursor += 2
      continue
    }
    if (character === '[') {
      cursor = findClassEnd(source, cursor) + 1
      continue
    }
    if (character === '(') {
      cursor = findGroupEnd(source, cursor) + 1
      continue
    }
    if (character === '|') {
      alternatives.push(source.slice(start, cursor))
      start = cursor + 1
    }
    cursor += 1
  }
  alternatives.push(source.slice(start))
  return alternatives
}

function parseQuantifier(source, index) {
  const character = source[index]
  if (character === '?') return { end: index + 1, variable: false }
  if (character === '*' || character === '+') return { end: index + 1, variable: true }
  if (character !== '{') return null
  const match = /^\{(\d{1,4})(,(\d{0,4})?)?\}/.exec(source.slice(index))
  if (!match) throw patternUnsupported('The capability catalog contains an invalid regexp quantifier.')
  const minimum = Number(match[1])
  const hasRange = match[2] !== undefined
  const maximum = !hasRange || match[3] === undefined || match[3] === '' ? (hasRange ? Infinity : minimum) : Number(match[3])
  if (!Number.isSafeInteger(minimum) || minimum > 1000) throw patternUnsupported('The capability catalog contains an unsupported regexp range.')
  if (maximum !== Infinity && (!Number.isSafeInteger(maximum) || maximum < minimum || maximum > 1000)) {
    throw patternUnsupported('The capability catalog contains an unsupported regexp range.')
  }
  return { end: index + match[0].length, variable: hasRange && maximum !== minimum }
}

function validatePatternBranch(source) {
  if (source.length === 0) throw patternUnsupported('Empty regexp alternatives are not supported in capability patterns.')
  let cursor = 0
  let atoms = 0
  let quantifiers = 0
  let variableQuantifiers = 0
  const applyQuantifier = () => {
    const quantifier = parseQuantifier(source, cursor)
    if (!quantifier) return
    if (quantifier.end < source.length && /[?*+{]/.test(source[quantifier.end])) {
      throw patternUnsupported('Nested regexp quantifiers are not supported in capability patterns.')
    }
    cursor = quantifier.end
    quantifiers += 1
    if (quantifier.variable) variableQuantifiers += 1
    if (quantifiers > MAX_BRANCH_QUANTIFIERS || variableQuantifiers > 1) {
      throw patternUnsupported('The capability catalog contains a regexp that is too complex.')
    }
  }
  while (cursor < source.length) {
    const character = source[cursor]
    if (character === ')' || character === '|') throw patternUnsupported('The capability catalog contains an unmatched regular expression group.')
    if (character === '(' || character === ')') throw patternUnsupported('Groups are only supported as one whole-pattern outer alternation.')
    if (character === '[') {
      cursor = findClassEnd(source, cursor) + 1
      atoms += 1
      applyQuantifier()
      continue
    }
    if (character === '\\') {
      if (cursor + 1 >= source.length) throw patternUnsupported('The capability catalog contains an invalid escape sequence.')
      const escaped = source[cursor + 1]
      if (/[1-9]/.test(escaped) || escaped === 'k' || escaped === 'g') throw patternUnsupported('Backreferences are not supported in capability patterns.')
      if (!SHORTHAND_CLASS_ESCAPES.has(escaped) && !ESCAPABLE_PATTERN_CHARACTERS.has(escaped)) {
        throw patternUnsupported('Unsupported escape sequence in a capability pattern.')
      }
      cursor += 2
      atoms += 1
      applyQuantifier()
      continue
    }
    if (character === '^' || character === '$') throw patternUnsupported('Capability patterns must be anchored only at the start and end.')
    if (character === '{' || character === '}' || character === '*' || character === '+' || character === '?') {
      throw patternUnsupported('The capability catalog contains a misplaced regexp quantifier.')
    }
    if (character === ']') throw patternUnsupported('The capability catalog contains an unmatched character class terminator.')
    if (/[\u0000-\u001f\u007f]/.test(character)) throw patternUnsupported('Control characters are not supported in capability patterns.')
    // Literal atom.
    cursor += 1
    atoms += 1
    applyQuantifier()
  }
  if (atoms === 0) throw patternUnsupported('Empty regexp branches are not supported in capability patterns.')
}

/**
 * Accept only a small, linearly safe regular-expression grammar: an anchored
 * pattern with literals, character classes, at most one whole-pattern outer
 * alternation group, and at most one variable-length quantifier per branch.
 * This is sufficient for the current server patterns and rejects overlapping
 * quantifier compositions that cause catastrophic backtracking.
 */
export function assertSafePattern(pattern, flags = '') {
  if (typeof pattern !== 'string' || pattern.length < 1 || pattern.length > MAX_PATTERN_LENGTH) {
    throw patternUnsupported()
  }
  if (flags !== '' && flags !== 'i') throw patternUnsupported('The capability catalog contains unsupported regular expression flags.')
  if (pattern.length < 3 || pattern[0] !== '^' || pattern[pattern.length - 1] !== '$') {
    throw patternUnsupported('Capability patterns must be anchored with ^ and $.')
  }
  const body = pattern.slice(1, -1)
  if (body.length === 0) throw patternUnsupported('Empty capability patterns are not supported.')
  if (body[0] === '(') {
    const end = findGroupEnd(body, 0)
    if (end !== body.length - 1) {
      throw patternUnsupported('Groups are only supported as one whole-pattern outer alternation.')
    }
    const inner = body.slice(1, -1)
    const alternatives = splitAlternatives(inner)
    if (alternatives.length < 2) throw patternUnsupported('A whole-pattern group must contain an alternation.')
    for (const alternative of alternatives) validatePatternBranch(alternative)
  } else {
    if (splitAlternatives(body).length !== 1) {
      throw patternUnsupported('Top-level regexp alternation must be grouped and anchored as a whole.')
    }
    validatePatternBranch(body)
  }
  try {
    return new RegExp(pattern, flags)
  } catch {
    throw patternUnsupported('The capability catalog contains an invalid regular expression.')
  }
}

function validateDefault(value) {
  return value === null || typeof value === 'string' || typeof value === 'boolean'
    || (typeof value === 'number' && Number.isFinite(value))
}

function validateRule(rule, depth, state) {
  if (depth > MAX_RULE_DEPTH) throw malformed('The capability rule nesting is too deep.')
  if (!isPlainRecord(rule)) throw malformed('A capability rule is not a plain object.')
  if (hasUnsafeKeys(rule)) throw malformed('A capability rule contains an unsafe property name.')
  state.rules += 1
  if (state.rules > MAX_RULES_PER_CAPABILITY) throw malformed('The capability catalog contains too many rules.')
  if (typeof rule.type !== 'string' || !RULE_TYPES.has(rule.type)) throw unsupported('The capability catalog contains an unsupported rule type.')
  const allowed = new Set([...COMMON_RULE_KEYS, ...(TYPE_RULE_KEYS[rule.type] || [])])
  for (const key of Object.keys(rule)) {
    if (!allowed.has(key)) throw unsupported(`Unsupported capability rule keyword: ${key}.`)
  }
  if (rule.description !== undefined) assertBoundedString(rule.description, MAX_DESCRIPTION_LENGTH, 'A capability rule description is invalid.')
  for (const flag of ['nullable', 'requiredOnCreate', 'requiredOnUpdate', 'immutableScope', 'mustMatchStoreScope', 'mustMatchProductStore', 'mustBeEnabled', 'nonEmpty', 'trimForValidation', 'trimExact', 'disallowLessThan', 'disallowHtmlTags', 'uniqueItems']) {
    if (rule[flag] !== undefined && typeof rule[flag] !== 'boolean') throw malformed(`Capability rule flag ${flag} must be boolean.`)
  }
  if (rule.default !== undefined && !validateDefault(rule.default)) throw unsupported('A capability default value is unsupported.')
  if (rule.maxLength !== undefined && !isBoundedInteger(rule.maxLength, 0, MAX_VALUE_LENGTH)) throw malformed('A capability maxLength is invalid.')
  if (rule.minLength !== undefined && !isBoundedInteger(rule.minLength, 0, MAX_VALUE_LENGTH)) throw malformed('A capability minLength is invalid.')
  if (rule.maxLength !== undefined && rule.minLength !== undefined && rule.minLength > rule.maxLength) throw malformed('A capability length range is invalid.')
  if (rule.maxBytes !== undefined && !isBoundedInteger(rule.maxBytes, 0, MAX_VALUE_LENGTH)) throw malformed('A capability maxBytes is invalid.')
  if (rule.maxItems !== undefined && !isBoundedInteger(rule.maxItems, 0, 1000)) throw malformed('A capability maxItems is invalid.')
  if (rule.minItemLength !== undefined && !isBoundedInteger(rule.minItemLength, 0, 1000)) throw malformed('A capability minimum item length is invalid.')
  if (rule.maxItemLength !== undefined && !isBoundedInteger(rule.maxItemLength, 0, 1000)) throw malformed('A capability maximum item length is invalid.')
  if (rule.minItemLength !== undefined && rule.maxItemLength !== undefined && rule.minItemLength > rule.maxItemLength) {
    throw malformed('A capability item length range is invalid.')
  }
  if (rule.minimum !== undefined && !isBoundedNumber(rule.minimum)) throw malformed('A capability minimum is invalid.')
  if (rule.maximum !== undefined && !isBoundedNumber(rule.maximum)) throw malformed('A capability maximum is invalid.')
  if (rule.minimum !== undefined && rule.maximum !== undefined && rule.minimum > rule.maximum) throw malformed('A capability numeric range is invalid.')
  if (rule.pattern !== undefined) assertSafePattern(rule.pattern, rule.patternFlags || '')
  if (rule.patternFlags !== undefined && rule.pattern === undefined) throw unsupported('Capability pattern flags require a pattern.')
  if (rule.format !== undefined && !ALLOWED_FORMATS.has(rule.format)) throw unsupported('Unsupported capability field format.')
  if (rule.format === 'uuid' && rule.pattern === undefined) throw unsupported('A uuid format requires a bounded pattern.')
  if (rule.type === 'uuid' && rule.pattern === undefined) throw unsupported('A uuid rule requires a bounded pattern.')
  if (rule.type === 'string' && rule.maxLength === undefined && rule.pattern === undefined) {
    throw unsupported('A string rule requires maxLength or a bounded pattern.')
  }
  if (rule.type === 'utf8-string' && rule.maxBytes === undefined) throw unsupported('A utf8-string rule requires maxBytes.')
  if (['integer', 'number', 'numeric-id-string'].includes(rule.type)) {
    if (rule.minimum === undefined || rule.maximum === undefined) throw unsupported('A numeric rule requires minimum and maximum bounds.')
  }
  if (rule.type === 'positive-id-list' && rule.maxItems === undefined) throw unsupported('A positive-id-list rule requires maxItems.')
  if (rule.type === 'string-list' && (rule.maxItems === undefined || rule.minItemLength === undefined || rule.maxItemLength === undefined)) {
    throw unsupported('A string-list rule requires maxItems and item length bounds.')
  }
  if (rule.values !== undefined) {
    if (!Array.isArray(rule.values) || rule.values.length < 1 || rule.values.length > 100
      || !rule.values.every(value => typeof value === 'string' && value.length <= 200)
      || new Set(rule.values).size !== rule.values.length) throw unsupported('Capability enum values are invalid.')
  } else if (rule.type === 'enum') {
    throw unsupported('An enum rule requires values.')
  }
  if (rule.anyOf !== undefined) {
    if (!Array.isArray(rule.anyOf) || rule.anyOf.length < 1 || rule.anyOf.length > 10) throw malformed('A capability union is invalid.')
    for (const alternative of rule.anyOf) validateRule(alternative, depth + 1, state)
  } else if (rule.type === 'union') {
    throw unsupported('A union rule requires alternatives.')
  }
  if (rule.properties !== undefined) {
    if (!isPlainRecord(rule.properties)) throw malformed('Capability object properties are invalid.')
    const keys = Object.keys(rule.properties)
    if (keys.length < 1 || keys.length > MAX_FIELDS_PER_MAP) throw malformed('Capability object properties are out of bounds.')
    for (const key of keys) {
      if (!isSafeKey(key)) throw malformed('Capability object properties contain an unsafe field name.')
      validateRule(rule.properties[key], depth + 1, state)
    }
    if (rule.additionalProperties !== undefined && rule.additionalProperties !== false) {
      throw unsupported('Additional object properties are not supported in capability rules.')
    }
    if (rule.pairedProperties !== undefined) {
      if (!Array.isArray(rule.pairedProperties) || rule.pairedProperties.length > 10) throw malformed('Capability paired properties are invalid.')
      for (const pair of rule.pairedProperties) {
        if (!Array.isArray(pair) || pair.length !== 2 || !pair.every(key => typeof key === 'string' && Object.hasOwn(rule.properties, key))) {
          throw malformed('Capability paired properties are invalid.')
        }
      }
    }
  } else if (rule.type === 'object') {
    throw unsupported('An object rule requires properties.')
  }
}

function validateFieldMap(fields, state, { allowEmpty = false } = {}) {
  if (!isPlainRecord(fields) || hasUnsafeKeys(fields)) throw malformed('Capability fields are invalid.')
  const keys = Object.keys(fields)
  if ((!allowEmpty && keys.length < 1) || keys.length > MAX_FIELDS_PER_MAP) throw malformed('Capability fields are out of bounds.')
  for (const key of keys) {
    if (!isSafeKey(key)) throw malformed('Capability fields contain an unsafe field name.')
    validateRule(fields[key], 0, state)
  }
}

/**
 * Validate and normalize one capability descriptor received from the server.
 * Unknown keys, unsupported rule keywords, excessive bounds and unsafe regexps
 * all fail closed.
 */
export function validateCapabilityDescriptor(descriptor, { batch = false } = {}) {
  if (!isPlainRecord(descriptor)) throw malformed('A capability descriptor is not a plain object.')
  if (hasUnsafeKeys(descriptor)) throw malformed('A capability descriptor contains an unsafe property name.')
  const allowedKeys = new Set(['entity', 'actions', 'actionFields', 'fields', 'deletePreviewFields', 'description'])
  for (const key of Object.keys(descriptor)) {
    if (!allowedKeys.has(key)) throw unsupported(`Unsupported capability descriptor keyword: ${key}.`)
  }
  if (!isSafeEntity(descriptor.entity)) throw malformed('A capability entity name is invalid.')
  if (!Array.isArray(descriptor.actions) || descriptor.actions.length < 1 || descriptor.actions.length > ACTIONS.length) {
    throw malformed('A capability action list is invalid.')
  }
  if (!descriptor.actions.every(action => ACTIONS.includes(action)) || new Set(descriptor.actions).size !== descriptor.actions.length) {
    throw malformed('A capability action list is invalid.')
  }
  if (batch && (descriptor.actions.length !== 1 || descriptor.actions[0] !== 'create')) {
    throw unsupported('Batch capabilities must be create-only.')
  }
  if (batch && descriptor.deletePreviewFields !== undefined) {
    throw unsupported('Batch capabilities must not publish delete preview fields.')
  }
  if (descriptor.description !== undefined) {
    assertBoundedString(descriptor.description, MAX_ENTITY_DESCRIPTION_LENGTH, 'A capability description is invalid.')
  }
  if (descriptor.deletePreviewFields !== undefined) {
    if (!descriptor.actions.includes('delete')) throw malformed('Delete preview fields require an authorized delete action.')
    if (!isPlainRecord(descriptor.deletePreviewFields) || hasUnsafeKeys(descriptor.deletePreviewFields)) {
      throw malformed('Delete preview fields are invalid.')
    }
  }
  if (!isPlainRecord(descriptor.actionFields) || hasUnsafeKeys(descriptor.actionFields)) throw malformed('Capability action fields are invalid.')
  const expectedActions = descriptor.actions.filter(action => action !== 'delete')
  const actualActions = Object.keys(descriptor.actionFields)
  if (actualActions.length !== expectedActions.length || actualActions.some(action => !expectedActions.includes(action))) {
    throw malformed('Capability action fields do not match the declared actions.')
  }
  const state = { rules: 0 }
  if (descriptor.deletePreviewFields !== undefined) {
    validateFieldMap(descriptor.deletePreviewFields, state)
  }
  if (expectedActions.length === 0) {
    // A delete-only descriptor intentionally carries no field rules. Requiring
    // both maps to be empty prevents denied create/update fields from silently
    // becoming delete-preview fields or model-visible field descriptions.
    if (actualActions.length !== 0 || Object.keys(descriptor.fields).length !== 0) {
      throw malformed('A delete-only capability must not publish field rules.')
    }
    validateFieldMap(descriptor.actionFields, state, { allowEmpty: true })
    validateFieldMap(descriptor.fields, state, { allowEmpty: true })
    return descriptor
  }
  for (const action of expectedActions) validateFieldMap(descriptor.actionFields[action], state)
  validateFieldMap(descriptor.fields, state)
  const union = new Set(Object.values(descriptor.actionFields).flatMap(fields => Object.keys(fields)))
  const declared = new Set(Object.keys(descriptor.fields))
  if ([...union].some(key => !declared.has(key))) {
    throw malformed('Capability fields must contain every action field.')
  }
  return descriptor
}

function compileRule(rule) {
  const cloned = {}
  for (const [key, value] of Object.entries(rule)) {
    if (key === 'properties') {
      cloned.properties = Object.freeze(Object.fromEntries(Object.entries(value).map(([name, child]) => [name, compileRule(child)])))
    } else if (key === 'anyOf') {
      cloned.anyOf = Object.freeze(value.map(compileRule))
    } else if (key === 'pairedProperties') {
      cloned.pairedProperties = Object.freeze(value.map(pair => Object.freeze([...pair])))
    } else if (key === 'values') {
      cloned.values = Object.freeze([...value])
    } else {
      cloned[key] = value
    }
  }
  const compiled = Object.freeze(cloned)
  if (compiled.pattern !== undefined) compiledPatterns.set(compiled, assertSafePattern(compiled.pattern, compiled.patternFlags || ''))
  return compiled
}

function compileFields(fields) {
  return Object.freeze(Object.fromEntries(Object.entries(fields).map(([key, rule]) => [key, compileRule(rule)])))
}

/**
 * Compile one wire descriptor into a capability object with self-contained
 * validators. The returned object has no reference to the wire response and no
 * serializable functions; JSON.stringify only exposes the descriptor fields.
 */
export function compileCapabilityDescriptor(descriptor, { previewValidator, changesValidator } = {}) {
  const validated = validateCapabilityDescriptor(descriptor)
  const actionFields = Object.freeze(Object.fromEntries(Object.entries(validated.actionFields).map(([action, fields]) => [action, compileFields(fields)])))
  const fields = compileFields(validated.fields)
  const capability = {
    entity: validated.entity,
    actions: Object.freeze([...validated.actions]),
    actionFields,
    fields,
    ...(validated.description === undefined ? {} : { description: validated.description })
  }
  if (validated.deletePreviewFields !== undefined) {
    compiledDeletePreviewFields.set(capability, compileFields(validated.deletePreviewFields))
  }
  capability.validateChanges = (changes, action) => {
    if (action === 'delete') return changes === undefined
    if (!Object.hasOwn(actionFields, action)) return false
    if (!validateChangesForAction(changes, actionFields[action], action)) return false
    return !changesValidator || changesValidator(changes, action, capability)
  }
  capability.validatePreview = preview => (previewValidator || validateRestoredPreview)(preview, capability)
  return Object.freeze(capability)
}

function regexpFor(rule) {
  if (rule.pattern === undefined) return null
  const compiled = compiledPatterns.get(rule)
  if (compiled) return compiled
  return assertSafePattern(rule.pattern, rule.patternFlags || '')
}

export function validateRuleValue(rule, value) {
  if (!rule || typeof rule.type !== 'string') return false
  if (value === null) return rule.nullable === true
  switch (rule.type) {
    case 'string': {
      if (typeof value !== 'string' || value.length > MAX_STRING_INPUT || /[\u0000-\u001f\u007f]/.test(value)) return false
      const checked = rule.trimForValidation ? value.trim() : value
      if ([...checked].length > rule.maxLength || (rule.minLength !== undefined && [...value].length < rule.minLength)) return false
      if ((rule.nonEmpty && value.trim().length === 0)
        || (rule.trimExact && value.trim() !== value)
        || (rule.disallowLessThan && value.includes('<'))
        || (rule.disallowHtmlTags && /<\/?[a-z][^>]*>/i.test(value))) return false
      const pattern = regexpFor(rule)
      if (pattern && !pattern.test(value)) return false
      if (rule.format === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return false
      return true
    }
    case 'utf8-string':
      return typeof value === 'string' && value.length <= MAX_STRING_INPUT && !/[\u0000-\u001f\u007f]/.test(value)
        && new TextEncoder().encode(value).length <= rule.maxBytes
        && (!rule.disallowHtmlTags || !/<\/?[a-z][^>]*>/i.test(value))
    case 'integer':
      return Number.isSafeInteger(value) && value >= rule.minimum && value <= rule.maximum
    case 'number':
      return typeof value === 'number' && Number.isFinite(value) && value >= rule.minimum && value <= rule.maximum
    case 'positive-id':
      return Number.isSafeInteger(value) && value >= (rule.minimum ?? 1) && value <= (rule.maximum ?? 2147483647)
    case 'relation-id':
      return Number.isSafeInteger(value) && value > 0
    case 'numeric-id-string':
      return typeof value === 'string' && /^[1-9]\d{0,9}$/.test(value)
        && Number(value) >= (rule.minimum ?? 1) && Number(value) <= (rule.maximum ?? 2147483647)
    case 'union':
      return rule.anyOf.some(alternative => validateRuleValue(alternative, value))
    case 'uuid': {
      if (typeof value !== 'string' || value.length > MAX_STRING_INPUT || /[\u0000-\u001f\u007f]/.test(value)) return false
      const pattern = regexpFor(rule)
      return Boolean(pattern && pattern.test(value))
    }
    case 'boolean':
      return typeof value === 'boolean'
    case 'enum':
      return rule.values.includes(value)
    case 'color':
      return typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value)
    case 'positive-id-list':
      return Array.isArray(value) && value.length <= rule.maxItems
        && value.every(item => Number.isSafeInteger(item) && item > 0 && item <= 2147483647)
    case 'string-list':
      return Array.isArray(value) && value.length <= rule.maxItems && new Set(value).size === value.length
        && value.every(item => typeof item === 'string'
          && [...item].length >= rule.minItemLength && [...item].length <= rule.maxItemLength
          && !/[\u0000-\u001f\u007f]/.test(item))
    case 'object':
      return validateObject(value, rule)
    default:
      return false
  }
}

function validateObject(value, rule) {
  if (!isPlainRecord(value) || !rule.properties || Object.keys(value).some(key => !Object.hasOwn(rule.properties, key))) return false
  if ((rule.pairedProperties || []).some(([left, right]) => Object.hasOwn(value, left) !== Object.hasOwn(value, right))) return false
  return Object.entries(value).every(([key, item]) => validateRuleValue(rule.properties[key], item))
}

export function validateChangesForAction(changes, fields, action) {
  if (!isPlainRecord(changes) || Object.keys(changes).length === 0) return false
  if (Object.keys(changes).some(key => !Object.hasOwn(fields, key) || !validateRuleValue(fields[key], changes[key]))) return false
  return Object.entries(fields).every(([key, rule]) => {
    const required = (action === 'create' && rule.requiredOnCreate) || (action === 'update' && rule.requiredOnUpdate)
    return !required || Object.hasOwn(changes, key)
  })
}

function describeInvalidRuleValue(field, rule, value) {
  if (value === null) return `${field} cannot be null`
  if (rule.type === 'utf8-string' && typeof value === 'string'
    && new TextEncoder().encode(value).length > rule.maxBytes) {
    return `${field} exceeds ${rule.maxBytes} UTF-8 bytes`
  }
  if (rule.type === 'string' && typeof value === 'string' && [...value].length > rule.maxLength) {
    return `${field} exceeds ${rule.maxLength} characters`
  }
  if (rule.type === 'positive-id' || rule.type === 'relation-id') return `${field} must be a positive numeric ID`
  if (rule.type === 'positive-id-list') return `${field} must be a list of up to ${rule.maxItems} positive numeric IDs`
  if (rule.type === 'uuid') return `${field} must be a UUID`
  if (rule.type === 'boolean') return `${field} must be true or false`
  if (rule.type === 'enum') return `${field} must be one of: ${rule.values.join(', ')}`
  if (rule.type === 'color') return `${field} must be #RRGGBB`
  return `${field} has an invalid value`
}

/** Explain a rejected change using field names and rule bounds, never values. */
export function describeChangeRejection(changes, fields, action) {
  if (!isPlainRecord(changes) || Object.keys(changes).length === 0) {
    return 'Changes contain unsupported fields or values. Changes must be a non-empty object.'
  }
  const unknown = Object.keys(changes).filter(key => !Object.hasOwn(fields, key))
  if (unknown.length) {
    return `Changes contain unsupported fields or values. Unsupported change fields: ${unknown.join(', ')}.`
  }
  const invalid = Object.entries(changes)
    .filter(([key, value]) => !validateRuleValue(fields[key], value))
    .map(([key, value]) => describeInvalidRuleValue(key, fields[key], value))
  if (invalid.length) {
    return `Changes contain unsupported fields or values. Invalid change values: ${invalid.join('; ')}.`
  }
  const missing = Object.entries(fields)
    .filter(([key, rule]) => (((action === 'create' && rule.requiredOnCreate) || (action === 'update' && rule.requiredOnUpdate))
      && !Object.hasOwn(changes, key)))
    .map(([key]) => key)
  if (missing.length) {
    return `Changes contain unsupported fields or values. Missing required change fields: ${missing.join(', ')}.`
  }
  return 'Changes contain unsupported fields or values.'
}

export function isExactTargetIdentifier(value) {
  return typeof value === 'string' && !/[\u0000-\u001f\u007f]/.test(value) && EXACT_TARGET_PATTERN.test(value)
}

export function validatePreviewEnvelope(preview, capability) {
  if (!isPlainRecord(preview) || preview.entity !== capability.entity) return false
  if (!capability.actions.includes(preview.action) || !Array.isArray(preview.differences)) return false
  if (preview.differences.length === 0 || preview.differences.length > MAX_PREVIEW_DIFFERENCES) return false
  if (Object.keys(preview).some(key => !PREVIEW_KEYS.includes(key))) return false
  if (preview.saved !== undefined && preview.saved !== false) return false
  if (preview.affectedRecords !== undefined && preview.affectedRecords !== 1) return false
  if (preview.title !== undefined && (typeof preview.title !== 'string' || [...preview.title].length > MAX_TITLE_LENGTH)) return false
  try {
    return new TextEncoder().encode(JSON.stringify(preview)).length <= MAX_PREVIEW_CHARACTERS
  } catch {
    return false
  }
}

export function safePreviewBefore(rule, value) {
  // Store contact/address snapshots are deliberately redacted as null by the backend.
  return (value === null && rule.type === 'object' && rule.nullable) || validateRuleValue(rule, value)
}

/**
 * Validate only the generic difference envelope for a compiled capability.
 * Specialized scope/title semantics are composed by the host adapter around
 * this function so no business field table is duplicated here.
 */
export function validatePreviewDifferences(preview, capability) {
  const fields = preview.action === 'delete'
    ? compiledDeletePreviewFields.get(capability)
    : capability.actionFields?.[preview.action]
  if (!fields) return false
  // Delete previews use only the explicit backend-owned deletePreviewFields
  // map. Write fields and private/fallback maps are never accepted; delete-only
  // capabilities without preview metadata fail closed.
  if (Object.keys(fields).length === 0) return false
  const seen = new Set()
  for (const difference of preview.differences) {
    if (!isPlainRecord(difference) || Object.keys(difference).some(key => !SAFE_PREVIEW_DIFFERENCE_KEY_SET.has(key))) return false
    const { property } = difference
    if (typeof property !== 'string' || !Object.hasOwn(fields, property) || seen.has(property)) return false
    seen.add(property)
    if (!Object.hasOwn(difference, 'before') || !Object.hasOwn(difference, 'after')) return false
    const rule = fields[property]
    if (preview.action === 'create') {
      if (difference.before !== null || !validateRuleValue(rule, difference.after)) return false
    } else if (preview.action === 'delete') {
      if (difference.after !== null || !safePreviewBefore(rule, difference.before)) return false
    } else {
      if (!safePreviewBefore(rule, difference.before) || !validateRuleValue(rule, difference.after)) return false
      if (rule.immutableScope && difference.before !== difference.after) return false
    }
    if (difference.changed !== undefined && typeof difference.changed !== 'boolean') return false
    if (difference.label !== undefined && (typeof difference.label !== 'string' || difference.label.length > MAX_LABEL_LENGTH)) return false
  }
  if (preview.action === 'create' && Object.entries(fields).some(([property, rule]) => rule.requiredOnCreate && !seen.has(property))) return false
  return true
}

export function validateRestoredPreview(preview, capability) {
  return validatePreviewEnvelope(preview, capability) && validatePreviewDifferences(preview, capability)
}

export function validateStoredPrintingCase(value, rules) {
  if (!isPlainRecord(value) || Object.keys(value).some(key => !Object.hasOwn(rules, key))) return false
  return Object.entries(value).every(([key, item]) => validateRuleValue(rules[key], item))
}

export function validateEffectivePrintingCase(value, rules) {
  const keys = Object.keys(rules)
  if (!isPlainRecord(value) || Object.keys(value).some(key => !Object.hasOwn(rules, key))) return false
  if (Object.keys(value).length !== keys.length || !keys.every(key => Object.hasOwn(value, key))) return false
  if (!Object.entries(value).every(([key, item]) => validateRuleValue(rules[key], item))) return false
  if (typeof value.enabled !== 'boolean') return false
  return value.enabled === false || (value.deviceUuid !== null && value.templateUuid !== null)
}

export { ACTIONS, PREVIEW_DIFFERENCE_KEYS }
