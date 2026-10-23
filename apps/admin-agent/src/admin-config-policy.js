/**
 * Pure projection of the composed crud-admin configuration.
 *
 * crud-admin configuration is the first capability boundary for the assistant.
 * This module never imports Vue, browser globals, request clients, or host
 * modules. It turns already-merged effective entity/route configuration into
 * serializable standard action metadata for tools and CRUD transports.
 *
 * Configuration projection is not authorization. The authenticated backend
 * CRUD endpoints remain the execution and authorization authority.
 */

import { validateRuleValue } from './capability-validation.js'
import { isAgentActionAllowed } from './write-policy.js'

const PROJECTION_SCHEMA_VERSION = 1
const STANDARD_ACTIONS = Object.freeze(['create', 'update', 'delete'])
const KNOWN_DISABLED_ACTIONS = new Set([
  'new', 'detail', 'edit', 'delete', 'batch_edit', 'batch_delete',
  'lines', 'pager', 'export'
])
const UNSAFE_KEYS = new Set(['__proto__', 'prototype', 'constructor'])
const SAFE_ENTITY_NAME = /^[A-Z][A-Za-z0-9]{0,63}$/
const SAFE_PROPERTY = /^[A-Za-z][A-Za-z0-9_]{0,63}$/
const SAFE_PATH_SEGMENT = /^[A-Za-z0-9._~-]+$/
const MAX_NESTED_PATH_DEPTH = 8
const MAX_NESTED_PATHS = 128
const EMPTY_CAPABILITIES = Object.freeze([])

const TYPE_ALIASES = Object.freeze({
  input: 'string',
  text: 'string',
  string: 'string',
  textarea: 'string',
  email: 'string',
  code: 'string',
  password: 'string',
  float: 'number',
  decimal: 'number',
  smallint: 'integer',
  bigint: 'integer',
  integer: 'integer',
  number: 'number',
  currency: 'number',
  boolean: 'boolean',
  select: 'enum',
  date: 'date',
  datetime: 'datetime',
  datetime_immutable: 'datetime',
  json: 'object',
  json_schema: 'object',
  array: 'array',
  relation: 'relation',
  relationtoone: 'relation',
  relationtomany: 'relation'
})

// File/image/password controls need a dedicated host flow; they must not be
// offered to the model as ordinary field writes.
const UNSUPPORTED_FIELD_TYPES = new Set(['file', 'image', 'images', 'transfer'])

function isPlainRecord(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const prototype = Object.getPrototypeOf(value)
  return (prototype === Object.prototype || prototype === null)
    && Reflect.ownKeys(value).every(key => typeof key === 'string')
}

function hasUnsafeKeys(value) {
  return Object.keys(value).some(key => UNSAFE_KEYS.has(key))
}

function safeText(value, maximum = 120) {
  if (typeof value !== 'string') return ''
  return value.replace(/[\u0000-\u001f\u007f]/g, ' ').trim().slice(0, maximum)
}

function safeDefault(value) {
  if (value === null || typeof value === 'boolean') return true
  if (typeof value === 'number') return Number.isFinite(value)
  if (typeof value === 'string') return value.length <= 1000 && !/[\u0000-\u001f\u007f]/.test(value)
  if (Array.isArray(value) && value.length <= 20) {
    return value.every(item => item === null
      || typeof item === 'boolean'
      || (typeof item === 'number' && Number.isFinite(item))
      || (typeof item === 'string' && item.length <= 200 && !/[\u0000-\u001f\u007f]/.test(item)))
  }
  return false
}

function defaultParameterize(name) {
  const underscored = String(name)
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1_$2')
    .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
    .toLowerCase()
  const plural = /(?:s|x|z|ch|sh)$/.test(underscored)
    ? `${underscored}es`
    : /[^aeiou]y$/.test(underscored)
      ? `${underscored.slice(0, -1)}ies`
      : `${underscored}s`
  return plural.replace(/_/g, '-')
}

function normalizeManageRoot(value) {
  if (typeof value !== 'string' || !value.startsWith('/')) return null
  if (value.includes('?') || value.includes('#') || value.includes('\\') || value.includes('//')) return null
  const segments = value.split('/').filter(Boolean)
  if (!segments.length) return null
  if (segments.some(segment => segment === '.' || segment === '..' || !SAFE_PATH_SEGMENT.test(segment))) return null
  return `/${segments.join('/')}`
}

function safeAbsolutePath(value) {
  if (typeof value !== 'string' || !value.startsWith('/')) return null
  if (value.includes('?') || value.includes('#') || value.includes('\\') || value.includes('//')) return null
  const segments = value.split('/').filter(Boolean)
  if (!segments.length) return null
  if (segments.some(segment => segment === '.' || segment === '..' || !SAFE_PATH_SEGMENT.test(segment))) return null
  return `/${segments.join('/')}`
}

function normalizePrefix(value, defaultPrefix) {
  const root = normalizeManageRoot(defaultPrefix)
  if (!root) return null
  const candidate = value === undefined || value === null ? root : value
  const normalized = safeAbsolutePath(candidate)
  if (!normalized) return null
  if (normalized !== root && !normalized.startsWith(`${root}/`)) return null
  return normalized
}

function safePlural(value) {
  if (typeof value !== 'string' || !value) return null
  if (value === '.' || value === '..' || !SAFE_PATH_SEGMENT.test(value) || value.includes('/')) return null
  return value
}

function safeRoutePath(path, parentPath) {
  if (typeof path !== 'string' || !path) return null
  if (path.includes('?') || path.includes('#') || path.includes('\\') || path.includes('//')) return null
  const combined = path.startsWith('/') ? path : `${parentPath.replace(/\/$/, '')}/${path}`
  const segments = combined.split('/').filter(Boolean)
  if (!segments.length) return combined === '/' ? '/' : null
  if (segments.some(segment => segment === '.' || segment === '..' || !SAFE_PATH_SEGMENT.test(segment))) return null
  return `/${segments.join('/')}`
}

function routeAvailabilitySet(value) {
  if (value === undefined) return null
  const resolved = typeof value === 'function' ? value() : value
  if (resolved instanceof Set) {
    return new Set([...resolved].filter(item => typeof item === 'string'))
  }
  if (Array.isArray(resolved)) {
    return new Set(resolved.filter(item => typeof item === 'string'))
  }
  return new Set()
}

function buildRouteIndex(routes, availability = null) {
  const index = new Map()
  const visit = (entries, parentPath, hiddenParent) => {
    if (!Array.isArray(entries)) return
    for (const route of entries) {
      if (!isPlainRecord(route)) continue
      const hidden = hiddenParent || route.hidden === true
      const path = safeRoutePath(route.path, parentPath)
      if (!hidden && typeof route.name === 'string' && route.name.endsWith('List') && path && path.endsWith('/list')
        && (!availability || availability.has(route.name))) {
        if (index.has(route.name)) index.set(route.name, null)
        else index.set(route.name, Object.freeze({ name: route.name, path }))
      }
      if (Array.isArray(route.children)) visit(route.children, path || parentPath, hidden)
    }
  }
  visit(routes, '/', false)
  return index
}

function parseDisabledActions(value, label, unsupported) {
  if (value === undefined) return { ok: true, values: new Set() }
  if (!Array.isArray(value) || value.some(action => typeof action !== 'string' || !KNOWN_DISABLED_ACTIONS.has(action))) {
    unsupported.push({ entityKey: label.entityKey, entityName: label.entityName, feature: 'config', reason: 'invalid_disabled_actions' })
    return { ok: false, values: new Set() }
  }
  return { ok: true, values: new Set(value) }
}

function resolveEntityIdentity(key, config, { defaultPrefix, parameterize }) {
  if (!isPlainRecord(config)) return null
  const raw = config.entity
  let name = key
  let plural
  let prefix
  if (typeof raw === 'string') {
    name = raw
  } else if (raw !== undefined) {
    if (!isPlainRecord(raw) || typeof raw.name !== 'string') return null
    name = raw.name
    plural = raw.plural
    prefix = raw.prefix
  }
  if (typeof name !== 'string' || !SAFE_ENTITY_NAME.test(name) || UNSAFE_KEYS.has(name)) return null
  const normalizedPrefix = normalizePrefix(prefix, defaultPrefix)
  const normalizedPlural = plural === undefined
    ? (typeof parameterize === 'function' ? safePlural(parameterize(name)) : null)
    : safePlural(plural)
  if (!normalizedPrefix || !normalizedPlural) return { name, prefix: normalizedPrefix, plural: normalizedPlural, path: null }
  return { name, prefix: normalizedPrefix, plural: normalizedPlural, path: `${normalizedPrefix}/${normalizedPlural}` }
}

/**
 * Collect configured JSON-schema property paths. Container and leaf paths are
 * distinct entries. Supplying a prefix (normally the top-level field property)
 * produces absolute, qualified source paths for build-time manifest use.
 */
export function collectNestedPaths(schema, prefix = '', depth = 0, output = []) {
  if (!isPlainRecord(schema) || depth > MAX_NESTED_PATH_DEPTH || output.length >= MAX_NESTED_PATHS) return output
  if (!isPlainRecord(schema.properties)) return output
  for (const [property, child] of Object.entries(schema.properties)) {
    if (output.length >= MAX_NESTED_PATHS) break
    if (!SAFE_PROPERTY.test(property) || UNSAFE_KEYS.has(property)) continue
    const path = prefix ? `${prefix}.${property}` : property
    const isContainer = isPlainRecord(child) && (isPlainRecord(child.properties) || child.type === 'object')
    if (!isContainer) {
      output.push(path)
      continue
    }
    const descendants = collectNestedPaths(child, path, depth + 1, [])
    // An empty container is not a writable leaf and must not survive as a
    // standalone nested path.
    if (!descendants.length) continue
    output.push(path)
    for (const descendant of descendants) {
      if (output.length >= MAX_NESTED_PATHS) break
      output.push(descendant)
    }
  }
  return output
}

function normalizeFieldType(rawType, hasRelation) {
  if (rawType !== undefined && typeof rawType !== 'string') return null
  if (rawType === undefined) return hasRelation ? 'relation' : 'string'
  const normalized = rawType.toLowerCase()
  if (UNSUPPORTED_FIELD_TYPES.has(normalized)) return null
  return TYPE_ALIASES[normalized] || null
}

function projectRelation(rawRelation, normalizedType, rawType) {
  if (rawRelation === undefined || rawRelation === null) {
    return normalizedType === 'relation' ? { ok: false } : { ok: true, relation: null }
  }
  if (!isPlainRecord(rawRelation) || hasUnsafeKeys(rawRelation)) return { ok: false }
  const target = rawRelation.target ?? rawRelation.entity ?? rawRelation.entity_name
  let targetName = null
  let targetPlural
  let targetPrefix
  if (typeof target === 'string') {
    targetName = target
  } else if (isPlainRecord(target) && typeof target.name === 'string') {
    targetName = target.name
    targetPlural = target.plural
    targetPrefix = target.prefix
  }
  if (typeof targetName !== 'string' || !SAFE_PROPERTY.test(targetName) || UNSAFE_KEYS.has(targetName)) return { ok: false }
  const valueKey = rawRelation.valueKey
  if (valueKey !== undefined && valueKey !== 'id' && valueKey !== 'uuid') return { ok: false }
  const plural = targetPlural === undefined ? null : safePlural(targetPlural)
  const prefix = targetPrefix === undefined ? null : safeAbsolutePath(targetPrefix)
  if (targetPlural !== undefined && !plural) return { ok: false }
  if (targetPrefix !== undefined && !prefix) return { ok: false }
  const multiple = rawRelation.multiple === true
    || rawRelation.cardinality === 'many'
    || normalizedType === 'relation' && /many/i.test(String(rawType || ''))
  return {
    ok: true,
    relation: Object.freeze({
      target: targetName,
      valueKey: valueKey || 'id',
      multiple,
      ...(plural ? { plural } : {}),
      ...(prefix ? { prefix } : {})
    })
  }
}

function staticHidden(rawHidden) {
  if (rawHidden === undefined || rawHidden === null) return { ok: true, create: false, update: false }
  if (typeof rawHidden === 'boolean') return { ok: true, create: rawHidden, update: rawHidden }
  if (typeof rawHidden === 'function') return { ok: false }
  if (typeof rawHidden === 'string') {
    if (rawHidden === 'create') return { ok: true, create: true, update: false }
    if (rawHidden === 'update' || rawHidden === 'edit') return { ok: true, create: false, update: true }
    return { ok: false }
  }
  if (Array.isArray(rawHidden)) {
    if (rawHidden.some(value => typeof value !== 'string' || !['create', 'update', 'edit'].includes(value))) return { ok: false }
    return { ok: true, create: rawHidden.includes('create'), update: rawHidden.includes('update') || rawHidden.includes('edit') }
  }
  return { ok: false }
}

function containsFunction(value, depth = 0) {
  if (typeof value === 'function') return true
  if (depth >= 4) return false
  if (Array.isArray(value)) return value.some(item => containsFunction(item, depth + 1))
  if (isPlainRecord(value)) return Object.values(value).some(item => containsFunction(item, depth + 1))
  return false
}

function hasFieldCallback(field) {
  for (const key of [
    'rules', 'validator', 'field_events', 'type_events', 'relation_filter',
    'type_options', 'field_options', 'readOnly', 'readonly', 'disabled', 'editable'
  ]) {
    if (!Object.hasOwn(field, key) || field[key] === undefined) continue
    if (containsFunction(field[key])) return true
  }
  return false
}

function fieldLabel(field, property) {
  const label = field.label ?? field.field_options?.label
  const safe = safeText(label, 120)
  return safe || property
}

/**
 * Resolve an explicit host binding for a scalar UI component. The binding must
 * match the exact configured component name and field property. Matching never
 * executes a component callback; it only permits a configured scalar value.
 */
function boundComponent(raw, entityLabel, property, componentBindings) {
  if (!Object.hasOwn(raw, 'component') || raw.component === undefined || raw.component === null) {
    return { present: false, ok: true }
  }
  const componentName = raw.component && typeof raw.component.name === 'string' ? raw.component.name : null
  if (!componentName || !isPlainRecord(componentBindings) || hasUnsafeKeys(componentBindings)) {
    return { present: true, ok: false }
  }
  const entityBinding = Object.hasOwn(componentBindings, entityLabel.entityName)
    ? componentBindings[entityLabel.entityName]
    : null
  if (!isPlainRecord(entityBinding) || hasUnsafeKeys(entityBinding)) return { present: true, ok: false }
  const fieldBinding = Object.hasOwn(entityBinding, property) ? entityBinding[property] : null
  if (!isPlainRecord(fieldBinding) || hasUnsafeKeys(fieldBinding)) return { present: true, ok: false }
  if (Object.keys(fieldBinding).some(key => !['componentName', 'type'].includes(key))) {
    return { present: true, ok: false }
  }
  if (fieldBinding.componentName !== componentName) return { present: true, ok: false }
  const type = normalizeFieldType(fieldBinding.type, false)
  if (!type || type === 'relation') return { present: true, ok: false }
  if (raw.type !== undefined && normalizeFieldType(raw.type, false) !== type) return { present: true, ok: false }
  return { present: true, ok: true, componentName, type }
}

function policyBoolean(value) {
  if (value === undefined || value === null || value === false) return { ok: true, value: false }
  if (value === true) return { ok: true, value: true }
  return { ok: false }
}

function fieldDisablePolicy(field) {
  let disabled = false
  for (const options of [field, field.field_options, field.type_options]) {
    if (!isPlainRecord(options)) continue
    if (options.editable === false) disabled = true
    for (const key of ['disabled', 'readOnly', 'readonly']) {
      if (!Object.hasOwn(options, key) || options[key] === undefined) continue
      const parsed = policyBoolean(options[key])
      if (!parsed.ok) return { ok: false }
      if (parsed.value) disabled = true
    }
  }
  return { ok: true, disabled }
}

const MAX_SCHEMA_FORM_DEPTH = 8

function resolveSchemaForm(schema, configuredFields, schemaFormResolver) {
  if (configuredFields !== undefined && !Array.isArray(configuredFields)) return { ok: false }
  if (typeof schemaFormResolver !== 'function') {
    return configuredFields === undefined ? { ok: true, fields: null } : { ok: false }
  }
  let resolved
  try {
    resolved = schemaFormResolver(schema, configuredFields === undefined ? [] : configuredFields)
  } catch {
    return { ok: false }
  }
  if (isPlainRecord(resolved) && Array.isArray(resolved.fields)) {
    return { ok: true, fields: resolved.fields }
  }
  return configuredFields === undefined ? { ok: true, fields: null } : { ok: false }
}

function orderedSchemaFields(schema, fields) {
  const byProperty = new Map(fields.map(field => [field.property, field]))
  const properties = isPlainRecord(schema.properties) ? Object.keys(schema.properties) : []
  return properties.map(property => byProperty.get(property)).filter(Boolean)
}

function collectSchemaFormPaths(fields, prefix, action, schemaFormResolver, depth, output) {
  if (!Array.isArray(fields) || depth > MAX_SCHEMA_FORM_DEPTH) return false
  const seen = new Set()
  for (const field of fields) {
    if (!isPlainRecord(field)) return false
    const property = field.property
    if (typeof property !== 'string' || !SAFE_PROPERTY.test(property) || UNSAFE_KEYS.has(property) || seen.has(property)) {
      return false
    }
    seen.add(property)
    const hidden = staticHidden(field.hidden)
    if (!hidden.ok) return false
    if (hidden[action]) continue
    const disablePolicy = fieldDisablePolicy(field)
    if (!disablePolicy.ok) return false
    if (disablePolicy.disabled) continue
    if (hasFieldCallback(field)) return false
    if (Object.hasOwn(field, 'component') && field.component !== undefined && field.component !== null) return false
    const type = normalizeFieldType(field.type, Boolean(field.relation))
    if (!type) return false
    const path = prefix ? `${prefix}.${property}` : property

    if (type === 'object') {
      const schema = field.type_options?.schema
      if (!isPlainRecord(schema)) return false
      const configuredFields = field.type_options?.fields
      const resolved = resolveSchemaForm(schema, configuredFields, schemaFormResolver)
      if (!resolved.ok) return false
      if (resolved.fields === null) {
        const descendants = collectNestedPaths(schema)
        if (!descendants.length) continue
        output.add(path)
        for (const descendant of descendants) output.add(`${path}.${descendant}`)
        continue
      }
      const descendants = new Set()
      if (!collectSchemaFormPaths(orderedSchemaFields(schema, resolved.fields), path, action, schemaFormResolver, depth + 1, descendants)) return false
      if (!descendants.size) continue
      output.add(path)
      for (const descendant of descendants) output.add(descendant)
      continue
    }
    output.add(path)
  }
  return true
}

function nestedPathsForAction(field, action, schemaFormResolver) {
  const schema = field.type_options?.schema
  if (!isPlainRecord(schema)) return new Set()
  const configuredFields = field.type_options?.fields
  const resolved = resolveSchemaForm(schema, configuredFields, schemaFormResolver)
  if (!resolved.ok) return null
  if (resolved.fields === null) return new Set(collectNestedPaths(schema))
  const output = new Set()
  return collectSchemaFormPaths(orderedSchemaFields(schema, resolved.fields), '', action, schemaFormResolver, 0, output) ? output : null
}

function projectField(raw, entityLabel, seen, unsupported, componentBindings, schemaFormResolver) {
  if (typeof raw === 'string') {
    if (raw === '__all__') return { wildcard: true }
    raw = { property: raw }
  }
  if (!isPlainRecord(raw)) {
    unsupported.push({ ...entityLabel, feature: 'field', reason: 'invalid_field' })
    return null
  }
  if (hasUnsafeKeys(raw)) {
    unsupported.push({ ...entityLabel, feature: 'field', reason: 'reserved_property' })
    return null
  }
  const property = raw.property
  if (typeof property !== 'string' || !SAFE_PROPERTY.test(property) || UNSAFE_KEYS.has(property)) {
    unsupported.push({ ...entityLabel, feature: 'field', property: typeof property === 'string' ? safeText(property) : undefined, reason: 'unsafe_property' })
    return null
  }
  if (seen.has(property)) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'duplicate_property' })
    return null
  }
  seen.add(property)
  const component = boundComponent(raw, entityLabel, property, componentBindings)
  if (!component.ok) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'custom_component' })
    return null
  }
  if (hasFieldCallback(raw)) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'callback_field' })
    return null
  }
  const hidden = staticHidden(raw.hidden)
  if (!hidden.ok) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'dynamic_hidden' })
    return null
  }
  const disablePolicy = fieldDisablePolicy(raw)
  if (!disablePolicy.ok) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'invalid_policy_value' })
    return null
  }
  if (disablePolicy.disabled) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'disabled_field' })
    return null
  }
  const type = component.present
    ? component.type
    : normalizeFieldType(raw.type, Boolean(raw.relation))
  if (!type) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'unsupported_type' })
    return null
  }
  const relation = projectRelation(raw.relation, type, raw.type)
  if (!relation.ok) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'unsafe_relation' })
    return null
  }
  if (Object.hasOwn(raw, 'default_value') && !safeDefault(raw.default_value)) {
    unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'unsafe_default' })
    return null
  }
  const actions = []
  if (!hidden.create) actions.push('create')
  if (!hidden.update) actions.push('update')
  if (!actions.length) return null
  const nestedByAction = { create: new Set(), update: new Set() }
  if (raw.type_options?.schema) {
    for (const action of ['create', 'update']) {
      const paths = nestedPathsForAction(raw, action, schemaFormResolver)
      if (paths === null) {
        unsupported.push({ ...entityLabel, feature: 'field', property, reason: 'unsupported_schema_form' })
        return null
      }
      nestedByAction[action] = paths
    }
  }
  const nestedPaths = new Set([...nestedByAction.create, ...nestedByAction.update])
  const sourcePaths = new Set([...nestedPaths].map(path => `${property}.${path}`))
  let options
  if (type === 'enum' && Array.isArray(raw.type_options?.options)) {
    const normalized = raw.type_options.options.flatMap(option => {
      if (!isPlainRecord(option)) return []
      const value = option.value
      const label = option.label
      if (!(typeof value === 'string' || (typeof value === 'number' && Number.isFinite(value)) || typeof value === 'boolean')) return []
      return [{ value, label: safeText(label, 120) || String(value) }]
    })
    if (normalized.length) options = Object.freeze(normalized)
  }
  const projected = {
    property,
    label: fieldLabel(raw, property),
    type,
    required: raw.required === true,
    actions: Object.freeze(actions),
    relation: relation.relation,
    ...(component.present ? { componentName: component.componentName } : {}),
    ...(Object.hasOwn(raw, 'default_value') ? { defaultValue: raw.default_value } : {}),
    ...(nestedPaths.size ? { nestedPaths: Object.freeze([...nestedPaths]) } : {}),
    ...(sourcePaths.size ? { sourcePaths: Object.freeze([...sourcePaths]) } : {}),
    ...(raw.type_options?.schema ? {
      nestedPathsByAction: Object.freeze({
        create: Object.freeze([...nestedByAction.create]),
        update: Object.freeze([...nestedByAction.update])
      }),
      sourcePathsByAction: Object.freeze({
        create: Object.freeze([...nestedByAction.create].map(path => `${property}.${path}`)),
        update: Object.freeze([...nestedByAction.update].map(path => `${property}.${path}`))
      })
    } : {}),
    ...(options ? { options } : {})
  }
  return Object.freeze(projected)
}

function projectForm(form, entityLabel, unsupported, componentBindings, schemaFormResolver) {
  if (form === undefined || form === null) {
    unsupported.push({ ...entityLabel, feature: 'form', reason: 'missing_form' })
    return { supported: false, fields: [], reasons: ['missing_form'] }
  }
  if (!isPlainRecord(form) || hasUnsafeKeys(form)) {
    unsupported.push({ ...entityLabel, feature: 'form', reason: 'invalid_form' })
    return { supported: false, fields: [], reasons: ['invalid_form'] }
  }
  if (!Array.isArray(form.fields)) {
    unsupported.push({ ...entityLabel, feature: 'form', reason: 'wildcard_form' })
    return { supported: false, fields: [], reasons: ['wildcard_form'] }
  }
  const seen = new Set()
  const fields = []
  let wildcard = false
  for (const raw of form.fields) {
    const previousUnsupported = unsupported.length
    const projected = projectField(raw, entityLabel, seen, unsupported, componentBindings, schemaFormResolver)
    if (!projected) {
      if (isPlainRecord(raw) && raw.required === true) {
        for (const item of unsupported.slice(previousUnsupported)) item.required = true
      }
      continue
    }
    if (projected.wildcard) {
      wildcard = true
      continue
    }
    fields.push(projected)
  }
  if (form.batch_edit !== undefined) {
    unsupported.push({ ...entityLabel, feature: 'customAction', action: 'batch_edit', reason: 'not_implemented' })
  }
  if (wildcard) {
    unsupported.push({ ...entityLabel, feature: 'form', reason: 'wildcard_form' })
    return { supported: false, fields, reasons: ['wildcard_form'] }
  }
  if (!fields.length) {
    unsupported.push({ ...entityLabel, feature: 'form', reason: 'no_fields' })
    return { supported: false, fields, reasons: ['no_fields'] }
  }
  return { supported: true, fields, reasons: [] }
}

function fieldForAction(field, action) {
  if (!field.nestedPathsByAction) return field
  const { nestedPathsByAction, sourcePathsByAction, nestedPaths, sourcePaths, ...rest } = field
  const actionNested = nestedPathsByAction[action] || []
  // A schema-known field with no writable descendants for this action must be
  // omitted. Exporting only {property} would remove the nested proof and invite
  // an unrestricted raw-JSON fallback on the backend.
  if (!actionNested.length) return null
  const output = { ...rest }
  const actionSource = sourcePathsByAction?.[action] || []
  output.nestedPaths = Object.freeze([...actionNested])
  if (actionSource.length) output.sourcePaths = Object.freeze([...actionSource])
  return Object.freeze(output)
}

function actionProjection(action, { enabled, form, fields, reasons }) {
  const actionFields = action === 'delete'
    ? []
    : fields
      .filter(field => field.actions.includes(action))
      .map(field => fieldForAction(field, action))
      .filter(Boolean)
  const actionReasons = [...reasons]
  if (!enabled) actionReasons.push(action === 'create' ? 'create_disabled' : action === 'update' ? 'update_disabled' : 'delete_disabled')
  if (action !== 'delete' && !form.supported && !actionReasons.includes('missing_form')) {
    actionReasons.push(...form.reasons)
  }
  if (action !== 'delete' && !actionFields.length && actionReasons.length === 0) {
    actionReasons.push('no_writable_fields')
  }
  const configured = enabled
    && (action === 'delete' || (form.supported && actionFields.length > 0))
  return Object.freeze({
    configured,
    fields: Object.freeze(action === 'delete' ? [] : actionFields),
    reasons: Object.freeze([...new Set(actionReasons)])
  })
}

function projectEntity(key, config, context) {
  const { routeIndex, defaultPrefix, parameterize, unsupported, componentBindings, schemaFormResolver } = context
  if (typeof key !== 'string' || !SAFE_ENTITY_NAME.test(key) || UNSAFE_KEYS.has(key)) {
    unsupported.push({ entityKey: typeof key === 'string' ? safeText(key) : undefined, feature: 'entity', reason: 'unsafe_entity_key' })
    return null
  }
  const identity = resolveEntityIdentity(key, config, { defaultPrefix, parameterize })
  if (!identity) {
    unsupported.push({ entityKey: key, feature: 'entity', reason: 'invalid_entity' })
    return null
  }
  const entityLabel = { entityKey: key, entityName: identity.name }
  const route = routeIndex.get(`${identity.name}List`) || null
  if (!route) unsupported.push({ ...entityLabel, feature: 'route', reason: 'missing_list_route' })
  if (!identity.path) unsupported.push({ ...entityLabel, feature: 'path', reason: 'unsafe_or_missing_path' })
  if (!isPlainRecord(config.list)) {
    unsupported.push({ ...entityLabel, feature: 'config', reason: 'missing_list_config' })
  } else if (Array.isArray(config.list.actions)) {
    for (const action of config.list.actions) {
      const name = isPlainRecord(action) && typeof action.name === 'string' ? safeText(action.name, 80) : undefined
      unsupported.push({ ...entityLabel, feature: 'customAction', ...(name ? { action: name } : {}), reason: 'not_implemented' })
    }
  } else if (config.list.actions !== undefined) {
    unsupported.push({ ...entityLabel, feature: 'customAction', reason: 'not_implemented' })
  }

  const listDisabled = parseDisabledActions(config.list?.disabled_actions, entityLabel, unsupported)
  const detailDisabled = parseDisabledActions(config.detail?.disabled_actions, entityLabel, unsupported)
  const enabled = Boolean(route && identity.path && isPlainRecord(config.list) && listDisabled.ok && detailDisabled.ok)
  const disabled = new Set([...listDisabled.values, ...detailDisabled.values])
  const form = projectForm(config.form, entityLabel, unsupported, componentBindings, schemaFormResolver)
  const baseReasons = []
  if (!route) baseReasons.push('missing_list_route')
  if (!identity.path) baseReasons.push('unsafe_or_missing_path')
  if (!isPlainRecord(config.list)) baseReasons.push('missing_list_config')
  if (!listDisabled.ok || !detailDisabled.ok) baseReasons.push('invalid_disabled_actions')

  return Object.freeze({
    key,
    name: identity.name,
    prefix: identity.prefix,
    plural: identity.plural,
    path: identity.path,
    route,
    actions: Object.freeze({
      create: actionProjection('create', {
        enabled: enabled && !disabled.has('new'),
        form,
        fields: form.fields,
        reasons: [...baseReasons, ...form.reasons]
      }),
      update: actionProjection('update', {
        enabled: enabled && !disabled.has('edit'),
        form,
        fields: form.fields,
        reasons: [...baseReasons, ...form.reasons]
      }),
      delete: actionProjection('delete', {
        enabled: enabled && !disabled.has('delete'),
        form,
        fields: [],
        reasons: baseReasons
      })
    })
  })
}

function splitSafeNestedPath(value) {
  if (typeof value !== 'string' || value.length < 1 || value.length > 256) return null
  const segments = value.split('.')
  if (!segments.length || segments.length > MAX_NESTED_PATH_DEPTH) return null
  if (segments.some(segment => !SAFE_PROPERTY.test(segment) || UNSAFE_KEYS.has(segment))) return null
  return segments
}

function projectSemanticBindings(rawBindings, entitiesByName, unsupported) {
  const result = {}
  if (rawBindings === undefined) return result
  if (!isPlainRecord(rawBindings) || hasUnsafeKeys(rawBindings)) {
    unsupported.push({ feature: 'semantic', reason: 'invalid_semantic_bindings' })
    return result
  }
  for (const [name, binding] of Object.entries(rawBindings)) {
    if (!SAFE_ENTITY_NAME.test(name) || UNSAFE_KEYS.has(name)) {
      unsupported.push({ feature: 'semantic', entityName: safeText(name), reason: 'unsafe_semantic_entity' })
      continue
    }
    if (!isPlainRecord(binding) || hasUnsafeKeys(binding)) {
      unsupported.push({ feature: 'semantic', entityName: name, reason: 'invalid_semantic_binding' })
      continue
    }
    if (Object.keys(binding).some(key => !['entity', 'actions', 'fields'].includes(key))) {
      unsupported.push({ feature: 'semantic', entityName: name, reason: 'unsupported_semantic_key' })
      continue
    }
    const target = typeof binding.entity === 'string' ? entitiesByName.get(binding.entity) : null
    if (!target) {
      unsupported.push({ feature: 'semantic', entityName: name, reason: 'unknown_semantic_target' })
      continue
    }
    const actions = binding.actions === undefined ? ['update'] : binding.actions
    if (!Array.isArray(actions) || !actions.length || actions.some(action => !STANDARD_ACTIONS.includes(action))
      || new Set(actions).size !== actions.length || actions.includes('delete')) {
      unsupported.push({ feature: 'semantic', entityName: name, reason: 'invalid_semantic_actions' })
      continue
    }
    if (!isPlainRecord(binding.fields) || hasUnsafeKeys(binding.fields) || !Object.keys(binding.fields).length) {
      unsupported.push({ feature: 'semantic', entityName: name, reason: 'invalid_semantic_fields' })
      continue
    }
    const fields = {}
    let valid = true
    for (const [runtimeField, configuredPath] of Object.entries(binding.fields)) {
      if (!SAFE_PROPERTY.test(runtimeField) || UNSAFE_KEYS.has(runtimeField)) {
        valid = false
        break
      }
      const segments = splitSafeNestedPath(configuredPath)
      if (!segments) {
        valid = false
        break
      }
      const topLevelProperty = segments[0]
      const qualifiedPath = segments.join('.')
      const remainder = segments.slice(1).join('.')
      for (const action of actions) {
        const configuredAction = target.actions[action]
        if (!configuredAction?.configured) {
          valid = false
          break
        }
        const configuredField = configuredAction.fields.find(field => field.property === topLevelProperty)
        if (!configuredField) {
          valid = false
          break
        }
        // Exact field-relative path only. A surviving container does not
        // implicitly allow a removed descendant.
        if (remainder && !(configuredField.nestedPaths || []).includes(remainder)) {
          valid = false
          break
        }
      }
      if (!valid) break
      fields[runtimeField] = qualifiedPath
    }
    if (!valid) {
      unsupported.push({ feature: 'semantic', entityName: name, reason: 'unmatched_semantic_path' })
      continue
    }
    result[name] = Object.freeze({
      entity: target.name,
      actions: Object.freeze([...actions]),
      fields: Object.freeze({ ...fields })
    })
  }
  return result
}

function canonicalValue(value) {
  if (Array.isArray(value)) return value.map(canonicalValue)
  if (isPlainRecord(value)) {
    const result = {}
    for (const key of Object.keys(value).sort()) {
      if (value[key] !== undefined) result[key] = canonicalValue(value[key])
    }
    return result
  }
  return value
}

function revisionOf(value) {
  const text = JSON.stringify(canonicalValue(value))
  let hash = 0xcbf29ce484222325n
  const prime = 0x100000001b3n
  const mask = 0xffffffffffffffffn
  for (let index = 0; index < text.length; index += 1) {
    hash ^= BigInt(text.charCodeAt(index))
    hash = (hash * prime) & mask
  }
  return hash.toString(16).padStart(16, '0')
}

function deepFreeze(value) {
  if (!value || typeof value !== 'object' || Object.isFrozen(value)) return value
  for (const child of Object.values(value)) deepFreeze(child)
  return Object.freeze(value)
}

/**
 * Build the serializable config projection. `actions.*.configured` means the
 * effective admin configuration appears to offer the standard action; it is
 * not execution authorization. Runtime capability intersection remains
 * mandatory while the separate operation adapters are authoritative.
 */
export function buildAdminConfigProjection(adminConfig, options = {}) {
  const unsupported = []
  const entities = []
  const routeAvailability = routeAvailabilitySet(options.routeAvailability ?? options.availableRouteNames)
  const routeIndex = buildRouteIndex(adminConfig?.routes, routeAvailability)
  const defaultPrefix = normalizeManageRoot(options.defaultPrefix || '/api/v1/manage')
  const parameterize = typeof options.parameterize === 'function' ? options.parameterize : defaultParameterize
  const componentBindings = isPlainRecord(options.componentBindings) ? options.componentBindings : null
  const schemaFormResolver = typeof options.schemaFormResolver === 'function' ? options.schemaFormResolver : null
  const rawEntities = adminConfig && isPlainRecord(adminConfig.entities) ? adminConfig.entities : null
  if (!rawEntities) {
    unsupported.push({ feature: 'config', reason: 'missing_entities' })
  } else {
    for (const [key, config] of Object.entries(rawEntities)) {
      const entity = projectEntity(key, config, {
        routeIndex,
        defaultPrefix,
        parameterize,
        unsupported,
        componentBindings,
        schemaFormResolver
      })
      if (entity) entities.push(entity)
    }
  }
  const entitiesByName = new Map()
  for (const entity of entities) {
    if (entitiesByName.has(entity.name)) entitiesByName.set(entity.name, null)
    else entitiesByName.set(entity.name, entity)
  }
  const semanticCapabilities = projectSemanticBindings(options.semanticBindings, entitiesByName, unsupported)
  const payload = {
    schemaVersion: PROJECTION_SCHEMA_VERSION,
    entities,
    semanticCapabilities,
    unsupported
  }
  return deepFreeze({ ...payload, revision: revisionOf(payload) })
}

function configFieldMap(entity, action) {
  const projected = entity.actions[action]
  if (!projected?.configured) return null
  return new Map(projected.fields.map(field => [field.property, field]))
}

function relativeNestedPaths(field) {
  return new Set(field.nestedPaths || [])
}

function relativeNestedPathsUnder(field, qualifiedPath) {
  const remainder = qualifiedPath.split('.').slice(1).join('.')
  const prefix = remainder ? `${remainder}.` : ''
  return new Set((field.nestedPaths || [])
    .filter(path => prefix ? path.startsWith(prefix) : true)
    .map(path => path.slice(prefix.length)))
}

/**
 * Restrict an object/union rule to exact configured nested paths. A container
 * path does not imply its descendants; an unlisted child is omitted.
 */
function restrictRule(rule, relativePaths, prefix = '') {
  if (!isPlainRecord(rule) || typeof rule.type !== 'string') return { rule: null, excluded: true }
  if (rule.type === 'object') {
    const properties = {}
    let excluded = false
    for (const [property, childRule] of Object.entries(rule.properties || {})) {
      const path = prefix ? `${prefix}.${property}` : property
      if (!relativePaths.has(path)) {
        excluded = true
        continue
      }
      const restricted = restrictRule(childRule, relativePaths, path)
      if (!restricted.rule) {
        excluded = true
        continue
      }
      if (restricted.excluded) excluded = true
      properties[property] = restricted.rule
    }
    if (!Object.keys(properties).length) return { rule: null, excluded: true }
    const clone = { ...rule, properties }
    // A narrowed container must never be cleared wholesale: doing so could
    // remove unapproved hidden descendants even when the parent was nullable.
    if (excluded) clone.nullable = false
    if (Array.isArray(rule.pairedProperties)) {
      const pairs = rule.pairedProperties.filter(pair => Array.isArray(pair)
        && pair.every(property => Object.hasOwn(properties, property)))
      if (pairs.length) clone.pairedProperties = pairs
      else delete clone.pairedProperties
    }
    return { rule: clone, excluded }
  }
  if (rule.type === 'union') {
    let excluded = false
    const alternatives = []
    for (const alternative of rule.anyOf || []) {
      const restricted = restrictRule(alternative, relativePaths, prefix)
      if (!restricted.rule) {
        excluded = true
        continue
      }
      if (restricted.excluded) excluded = true
      alternatives.push(restricted.rule)
    }
    if (!alternatives.length) return { rule: null, excluded: true }
    const clone = { ...rule, anyOf: alternatives }
    if (excluded) clone.nullable = false
    return { rule: clone, excluded }
  }
  return { rule, excluded: false }
}

function restrictRuleToNestedPaths(rule, relativePaths, prefix = '') {
  return restrictRule(rule, relativePaths, prefix).rule
}

function sameJsonValue(left, right) {
  if (left === right) return true
  if (Array.isArray(left) || Array.isArray(right)) {
    if (!Array.isArray(left) || !Array.isArray(right) || left.length !== right.length) return false
    return left.every((item, index) => sameJsonValue(item, right[index]))
  }
  if (isPlainRecord(left) && isPlainRecord(right)) {
    const leftKeys = Object.keys(left)
    const rightKeys = Object.keys(right)
    if (leftKeys.length !== rightKeys.length) return false
    return leftKeys.every(key => Object.hasOwn(right, key) && sameJsonValue(left[key], right[key]))
  }
  return false
}

/**
 * Preview differences may contain full canonical stored objects. Unapproved
 * nested fields are allowed only when their values are unchanged; any changed
 * unapproved path fails closed.
 */
function previewNestedChangesAllowed(rule, before, after) {
  if (!isPlainRecord(rule) || typeof rule.type !== 'string') return false
  if (rule.type === 'union') {
    return (rule.anyOf || []).some(alternative => previewNestedChangesAllowed(alternative, before, after))
  }
  if (rule.type !== 'object') return !isPlainRecord(after)
  if (after === null) {
    if (rule.nullable !== true) return false
    const beforeObject = isPlainRecord(before) ? before : {}
    return Object.keys(beforeObject).every(key => Object.hasOwn(rule.properties || {}, key))
  }
  if (!isPlainRecord(after)) return false
  const beforeObject = isPlainRecord(before) ? before : {}
  const keys = new Set([...Object.keys(beforeObject), ...Object.keys(after)])
  for (const key of keys) {
    const hasBefore = Object.hasOwn(beforeObject, key)
    const hasAfter = Object.hasOwn(after, key)
    if (hasBefore && hasAfter && sameJsonValue(beforeObject[key], after[key])) continue
    if (!Object.hasOwn(rule.properties || {}, key)) return false
    if (!previewNestedChangesAllowed(rule.properties[key], hasBefore ? beforeObject[key] : undefined, hasAfter ? after[key] : undefined)) {
      return false
    }
  }
  return true
}

function semanticAllowedFields(semanticBinding, semanticTarget, action) {
  if (!semanticTarget) return null
  const targetAction = semanticTarget.actions[action]
  if (!targetAction?.configured) return null
  const allowed = new Map()
  for (const [runtimeField, configuredPath] of Object.entries(semanticBinding.fields)) {
    const topLevelProperty = configuredPath.split('.')[0]
    const targetField = targetAction.fields.find(field => field.property === topLevelProperty)
    if (!targetField) return null
    allowed.set(runtimeField, relativeNestedPathsUnder(targetField, configuredPath))
  }
  return allowed
}

function narrowCapability(capability, entity, semanticBinding, semanticTarget) {
  const actions = []
  const actionFields = {}
  const allowedActions = semanticBinding ? new Set(semanticBinding.actions) : new Set(STANDARD_ACTIONS)
  for (const action of capability.actions) {
    if (!allowedActions.has(action)) continue
    if (action === 'delete') {
      if (semanticBinding) continue
      if (entity.actions.delete.configured && isAgentActionAllowed(capability.entity, action)) actions.push(action)
      continue
    }
    const runtimeFields = capability.actionFields?.[action]
    if (!isPlainRecord(runtimeFields) || hasUnsafeKeys(runtimeFields)) continue
    const allowedFields = semanticBinding
      ? semanticAllowedFields(semanticBinding, semanticTarget, action)
      : configFieldMap(entity, action)
    if (!allowedFields) continue
    const picked = {}
    let blocked = false
    for (const [property, rule] of Object.entries(runtimeFields)) {
      const allowed = allowedFields.get(property)
      const required = action === 'create' ? rule?.requiredOnCreate === true : rule?.requiredOnUpdate === true
      if (!allowed) {
        if (required) {
          blocked = true
          break
        }
        continue
      }
      const nestedPaths = semanticBinding ? allowed : relativeNestedPaths(allowed)
      const restricted = restrictRuleToNestedPaths(rule, nestedPaths)
      if (!restricted) {
        if (required) {
          blocked = true
          break
        }
        continue
      }
      picked[property] = restricted
    }
    if (blocked || Object.keys(picked).length === 0) continue
    actionFields[action] = Object.freeze(picked)
    actions.push(action)
  }
  if (!actions.length) return null
  const fields = {}
  for (const action of actions) {
    if (action === 'delete') continue
    for (const property of Object.keys(actionFields[action])) {
      if (!Object.hasOwn(fields, property)) fields[property] = actionFields[action][property]
    }
  }
  const frozenActionFields = Object.freeze(actionFields)
  const frozenFields = Object.freeze(fields)
  const frozenActions = Object.freeze(actions)
  const validateChanges = (changes, action) => {
    if (!frozenActions.includes(action)) return false
    if (action === 'delete') return changes === undefined && capability.validateChanges(undefined, 'delete') === true
    const allowed = frozenActionFields[action]
    if (!allowed || !isPlainRecord(changes) || Object.keys(changes).length === 0) return false
    if (Object.keys(changes).some(key => !Object.hasOwn(allowed, key))) return false
    try {
      const restrictedValuesValid = Object.entries(changes)
        .every(([property, value]) => validateRuleValue(allowed[property], value))
      return restrictedValuesValid && capability.validateChanges(changes, action) === true
    } catch {
      return false
    }
  }
  const validatePreview = preview => {
    if (!isPlainRecord(preview) || preview.entity !== capability.entity) return false
    if (!frozenActions.includes(preview.action)) return false
    if (preview.action !== 'delete') {
      const allowed = frozenActionFields[preview.action]
      if (!allowed || !Array.isArray(preview.differences)) return false
      if (preview.differences.some(difference => !isPlainRecord(difference)
        || typeof difference.property !== 'string'
        || !Object.hasOwn(allowed, difference.property)
        || !previewNestedChangesAllowed(allowed[difference.property], difference.before, difference.after))) return false
    }
    try {
      return capability.validatePreview(preview, capability) === true
    } catch {
      return false
    }
  }
  return Object.freeze({
    entity: capability.entity,
    actions: frozenActions,
    actionFields: frozenActionFields,
    fields: frozenFields,
    validateChanges,
    validatePreview
  })
}

/**
 * Narrow runtime capabilities to entities/actions/fields present in the config
 * projection. Returned capability objects are the only instances that should be
 * passed to model-tool generation and mutation transports for the same epoch.
 */
export function intersectCapabilityCatalog(projection, capabilities) {
  if (!projection || !Array.isArray(capabilities) || capabilities.length === 0) return EMPTY_CAPABILITIES
  const entitiesByName = new Map()
  for (const entity of projection.entities || []) {
    if (entitiesByName.has(entity.name)) entitiesByName.set(entity.name, null)
    else entitiesByName.set(entity.name, entity)
  }
  const semantic = isPlainRecord(projection.semanticCapabilities) ? projection.semanticCapabilities : {}
  const result = []
  for (const capability of capabilities) {
    if (!isPlainRecord(capability) || typeof capability.entity !== 'string'
      || !Array.isArray(capability.actions)
      || typeof capability.validateChanges !== 'function'
      || typeof capability.validatePreview !== 'function') continue
    const entity = entitiesByName.get(capability.entity)
    const semanticBinding = Object.hasOwn(semantic, capability.entity) ? semantic[capability.entity] : null
    if (!entity && !semanticBinding) continue
    const semanticTarget = semanticBinding ? entitiesByName.get(semanticBinding.entity) : null
    if (semanticBinding && !semanticTarget) continue
    const narrowed = narrowCapability(capability, entity, semanticBinding, semanticTarget)
    if (narrowed) result.push(narrowed)
  }
  return result.length ? Object.freeze(result) : EMPTY_CAPABILITIES
}

/**
 * Effective revision = runtime catalog revision + configuration projection
 * revision. A config-only change therefore rejects an in-flight preparation and
 * can invalidate pending UI without relying on a server catalog revision bump.
 */
function commitPreflightConflict(message) {
  const error = new TypeError(message)
  error.code = 'proposal_conflict'
  return error
}

/**
 * Refresh the effective configuration both before and after an awaited catalog
 * refresh, then require the opaque identity to be unchanged. A config edit that
 * lands while the network request is pending cannot authorize the POST.
 */
export async function ensureFreshCommitCapability({
  getIdentity,
  refreshProjection,
  refreshCatalog,
  signal,
  onRefreshed = () => {}
} = {}) {
  if (typeof getIdentity !== 'function'
    || typeof refreshProjection !== 'function'
    || typeof refreshCatalog !== 'function') {
    throw new TypeError('Commit preflight providers are required.')
  }
  const expectedIdentity = getIdentity()
  try {
    refreshProjection()
    await refreshCatalog(signal)
    refreshProjection()
    onRefreshed()
  } catch (error) {
    if (signal?.aborted) throw error
    throw commitPreflightConflict('The current capability policy could not be refreshed. Nothing was submitted; request a fresh preview.')
  }
  if (!expectedIdentity || getIdentity() !== expectedIdentity) {
    throw commitPreflightConflict('The capability policy changed. Nothing was submitted; review a fresh preview before confirming.')
  }
}

export function combineCapabilityRevision(runtimeRevision, projectionRevision, runtimeEpoch, projectionEpoch) {
  if (typeof runtimeRevision !== 'string' || !runtimeRevision) return null
  if (typeof projectionRevision !== 'string' || !projectionRevision) return null
  const hasRuntimeEpoch = Number.isSafeInteger(runtimeEpoch)
  const hasProjectionEpoch = Number.isSafeInteger(projectionEpoch)
  if (!hasRuntimeEpoch && !hasProjectionEpoch) return `${runtimeRevision}:${projectionRevision}`
  return `${runtimeRevision}:${hasRuntimeEpoch ? runtimeEpoch : '-'}:${projectionRevision}:${hasProjectionEpoch ? projectionEpoch : '-'}`
}

/**
 * Memoized capability source shared by tool generation and transport. The same
 * narrowed capability instances are returned until either revision changes.
 */
export function createAdminConfigCapabilitySource({
  getProjection,
  getCapabilities,
  getRuntimeRevision = () => null,
  getRuntimeEpoch = () => null,
  getProjectionEpoch = () => null
} = {}) {
  if (typeof getProjection !== 'function' || typeof getCapabilities !== 'function') {
    throw new TypeError('A projection provider and capability provider are required.')
  }
  const runtimeEpoch = () => (typeof getRuntimeEpoch === 'function' ? getRuntimeEpoch() : null)
  const projectionEpoch = () => (typeof getProjectionEpoch === 'function' ? getProjectionEpoch() : null)
  const currentIdentity = () => combineCapabilityRevision(
    getRuntimeRevision(),
    getProjection()?.revision,
    runtimeEpoch(),
    projectionEpoch()
  )
  let cachedProjectionRevision
  let cachedRuntimeRevision
  let cachedRuntimeEpoch
  let cachedProjectionEpoch
  let cachedCapabilities
  let cachedResult
  return Object.freeze({
    getProjection() { return getProjection() },
    getCapabilities() {
      const projection = getProjection()
      const projectionRevision = projection?.revision
      const runtimeRevision = getRuntimeRevision()
      const currentRuntimeEpoch = runtimeEpoch()
      const currentProjectionEpoch = projectionEpoch()
      const capabilities = getCapabilities()
      if (!Array.isArray(capabilities) || capabilities.length === 0) return EMPTY_CAPABILITIES
      if (cachedResult
        && cachedProjectionRevision === projectionRevision
        && cachedRuntimeRevision === runtimeRevision
        && cachedRuntimeEpoch === currentRuntimeEpoch
        && cachedProjectionEpoch === currentProjectionEpoch
        && cachedCapabilities === capabilities) {
        return cachedResult
      }
      const result = intersectCapabilityCatalog(projection, capabilities)
      cachedProjectionRevision = projectionRevision
      cachedRuntimeRevision = runtimeRevision
      cachedRuntimeEpoch = currentRuntimeEpoch
      cachedProjectionEpoch = currentProjectionEpoch
      cachedCapabilities = capabilities
      cachedResult = result
      return result
    },
    getIdentity: currentIdentity,
    getRevision: currentIdentity
  })
}
