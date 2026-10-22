const SAFE_PROPERTY = /^[A-Za-z][A-Za-z0-9_]*$/
const SENSITIVE_PROPERTY = /password|secret|token|private.?key|certificate|credential|api.?key|openid|unionid|authorization|email|phone|mobile|address|birth|legal.?name|real.?name|nick.?name|user.?name|screen.?name|display.?name|author.?name|recipient.?name|contact.?name|national.?id|identity.?number|passport|bank.?account|account.?number|身份证|姓名|护照/i
const GENERIC_SENSITIVE_PROPERTY = /^(value|metadata|config|payload|body|content|extradata)$/i
const MAX_FILTERS = 5
const MAX_PAGE = 100
const MAX_LIMIT = 20
const MAX_SELECT_FIELDS = 30
const MAX_RESULT_CHARACTERS = 8000

function safeProperty(property) {
  return typeof property === 'string'
    && SAFE_PROPERTY.test(property)
    && !SENSITIVE_PROPERTY.test(property)
    && !GENERIC_SENSITIVE_PROPERTY.test(property)
}

function getProperty(field) {
  if (typeof field === 'string') return field
  return field && typeof field === 'object' && typeof field.property === 'string' ? field.property : null
}

function safeLabel(field, property) {
  const label = field && typeof field === 'object'
    ? field.label ?? field.field_options?.label
    : null
  return typeof label === 'string' ? label.slice(0, 120) : property
}

function normalizeField(field, requiredByForm = false) {
  const property = getProperty(field)
  if (!safeProperty(property) || (field && typeof field === 'object' && (field.hidden === true || typeof field.hidden === 'function'))) return null
  const type = field && typeof field === 'object' && typeof field.type === 'string' ? field.type.slice(0, 50) : 'text'
  if (/password|secret|credential|file|image|hidden/i.test(type)) return null
  const fieldOptions = field && typeof field === 'object' ? field.field_options : null
  return {
    property,
    label: safeLabel(field, property),
    type,
    required: requiredByForm || field?.required === true,
    readOnly: field?.readOnly === true || field?.readonly === true || fieldOptions?.disabled === true || field?.type_options?.disabled === true,
    relation: Boolean(field?.relation),
    relationValueKey: typeof field?.relation?.valueKey === 'string' ? field.relation.valueKey.slice(0, 30) : null
  }
}

function normalizeListColumns(columns = []) {
  if (!Array.isArray(columns)) return []
  return columns.map(field => {
    if (field && typeof field === 'object' && field.relation) return null
    return normalizeField(field)
  }).filter(Boolean)
}

function normalizeFilters(filters = {}, fields = []) {
  if (!filters || typeof filters !== 'object' || Array.isArray(filters)) return []
  return Object.entries(filters).flatMap(([property, definition]) => {
    if (!safeProperty(property)) return []
    const field = fields.find(item => item.property === property)
    if (typeof definition === 'function') return []
    if (definition && typeof definition === 'object' && definition.expression) return []
    const optionValues = definition && typeof definition === 'object'
      ? Object.keys(definition).filter(key => !key.startsWith('__') && !['label', 'type', 'expression'].includes(key) && typeof definition[key] === 'string' && definition[key].length <= 200)
      : []
    // Dynamic relation choices are intentionally not evaluated in the agent.
    // Permit relation filtering only when configuration contains static options.
    if (field?.relation && optionValues.length === 0) return []
    const fieldType = definition && typeof definition === 'object' && typeof definition.type === 'string' ? definition.type : field?.type || 'text'
    const normalizedType = fieldType.toLowerCase()
    const isNumeric = /integer|number|float|decimal|currency|money/.test(normalizedType)
    const isDate = /date|time/.test(normalizedType)
    const isBoolean = /bool/.test(normalizedType)
    const isRelation = Boolean(field?.relation)
    const isInput = typeof definition === 'string' && !isRelation && !isNumeric && !isDate && !isBoolean
    const operators = isInput ? ['contains', 'equals']
      : isNumeric || isDate ? ['equals', 'gt', 'gte', 'lt', 'lte']
        : ['equals']
    return [{
      property,
      label: String(typeof definition === 'string' ? definition : definition?.__label ?? definition?.label ?? field?.label ?? property).slice(0, 120),
      type: fieldType.slice(0, 50),
      operator: isInput ? 'contains' : 'equals',
      operators,
      options: optionValues.slice(0, 50),
      dynamicOptions: false,
      relationValueKey: field?.relationValueKey || null
    }]
  })
}

function normalizeDefaultOrder(query, fields) {
  if (!query || typeof query !== 'object' || Array.isArray(query) || typeof query['@order'] !== 'string') return ''
  const allowed = new Set(fields.map(field => field.property))
  const terms = query['@order'].split(',').map(term => {
    const [rawField, rawDirection] = term.trim().split('|')
    const property = rawField?.trim().replace(/^entity\./, '')
    const direction = rawDirection?.trim()
    return safeProperty(property) && allowed.has(property) && ['ASC', 'DESC'].includes(direction)
      ? `entity.${property}|${direction}`
      : null
  }).filter(Boolean)
  return terms.join(', ')
}

function normalizePrefix(prefix, defaultPrefix) {
  if (typeof prefix !== 'string' || !prefix.startsWith('/') || prefix.includes('?') || prefix.includes('#') || prefix.includes('..')) return null
  const normalized = `/${prefix.split('/').filter(Boolean).join('/')}`
  const manageRoot = `/${defaultPrefix.split('/').filter(Boolean).join('/')}`
  return normalized === manageRoot || normalized.startsWith(`${manageRoot}/`) ? normalized : null
}

export function buildCapabilityCatalog(entities, operations, { parameterize, defaultPrefix = '/api/v1/manage' } = {}) {
  if (!entities || typeof entities !== 'object' || typeof parameterize !== 'function') return []
  const getOperations = new Set((Array.isArray(operations) ? operations : [])
    .filter(operation => operation.method === 'GET')
    .map(operation => operation.path))

  return Object.entries(entities).map(([key, config]) => {
    if (!config || typeof config !== 'object') return null
    const entity = config.entity && typeof config.entity === 'object' ? config.entity : {}
    const name = typeof entity.name === 'string' ? entity.name : key
    const plural = typeof entity.plural === 'string' ? entity.plural : parameterize(name)
    const prefix = normalizePrefix(typeof entity.prefix === 'string' ? entity.prefix : defaultPrefix, defaultPrefix)
    const path = prefix && /^[A-Za-z0-9_-]+$/.test(plural) ? `${prefix}/${plural}` : null
    const formFields = Array.isArray(config.form?.fields) ? config.form.fields.map(field => normalizeField(field, field?.required === true)).filter(Boolean) : []
    const listFields = normalizeListColumns(config.list?.list_display)
    const fieldMap = new Map()
    for (const field of [...formFields, ...listFields]) {
      const existing = fieldMap.get(field.property)
      fieldMap.set(field.property, existing ? {
        ...existing,
        ...field,
        type: field.type === 'text' ? existing.type : field.type,
        required: existing.required || field.required,
        relation: existing.relation || field.relation,
        relationValueKey: existing.relationValueKey || field.relationValueKey
      } : field)
    }
    const fields = [...fieldMap.values()]
    const filterFields = normalizeFilters(config.list?.list_filter, formFields)
    const readable = Boolean(path && getOperations.has(path))
    return {
      key,
      name: name.slice(0, 120),
      path: readable ? path : null,
      readable,
      fields,
      filters: filterFields,
      select: [...new Set(['id', 'uuid', ...listFields.map(field => field.property)])].slice(0, MAX_SELECT_FIELDS),
      defaultOrder: normalizeDefaultOrder(config.list?.query, listFields)
    }
  }).filter(Boolean)
}

export function prepareRecordQuery(input, catalog) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) throw new TypeError('A record query object is required.')
  if (Object.keys(input).some(key => !['entity', 'page', 'limit', 'filters'].includes(key))) throw new TypeError('The query contains unsupported parameters.')
  const capability = catalog.find(item => item.key === input.entity)
  if (!capability?.readable || !capability.path) throw new Error('This entity has no approved, documented read endpoint.')

  const page = input.page === undefined ? 1 : input.page
  const limit = input.limit === undefined ? 10 : input.limit
  if (!Number.isInteger(page) || page < 1 || page > MAX_PAGE) throw new RangeError(`Page must be between 1 and ${MAX_PAGE}.`)
  if (!Number.isInteger(limit) || limit < 1 || limit > MAX_LIMIT) throw new RangeError(`Limit must be between 1 and ${MAX_LIMIT}.`)

  const filterInput = input.filters === undefined ? [] : input.filters
  if (!Array.isArray(filterInput) || filterInput.length > MAX_FILTERS) throw new RangeError(`At most ${MAX_FILTERS} filters are allowed.`)
  const allowedFilters = new Map(capability.filters.map(filter => [filter.property, filter]))
  const seen = new Set()
  const comparisonOperators = { contains: 'matches', equals: '==', gt: '>', gte: '>=', lt: '<', lte: '<=' }
  const expressions = filterInput.map(filter => {
    if (!filter || typeof filter !== 'object' || Array.isArray(filter) || !allowedFilters.has(filter.field)) {
      throw new TypeError('A filter field is not allowed for this entity.')
    }
    if (Object.keys(filter).some(key => !['field', 'value', 'operator'].includes(key))) throw new TypeError('A filter contains unsupported parameters.')
    const definition = allowedFilters.get(filter.field)
    const filterOperator = filter.operator ?? definition.operator
    if (!definition.operators.includes(filterOperator)) throw new TypeError(`Filter operator is not allowed for ${filter.field}.`)
    const duplicateKey = `${filter.field}:${filterOperator}`
    if (seen.has(duplicateKey)) throw new TypeError('Duplicate filter field/operator pairs are not allowed.')
    seen.add(duplicateKey)
    const value = filter.value
    if (!(value === null || typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value)) || (typeof value === 'string' && value.length <= 200 && !/[\u0000-\u001f\u007f]/.test(value)))) {
      throw new TypeError('Filter values must be short strings, finite numbers, booleans, or null.')
    }
    if (definition.options.length && filterOperator === 'equals' && !definition.options.includes(String(value))) {
      throw new TypeError(`Filter value is not an allowed option for ${filter.field}.`)
    }
    const normalizedType = definition.type.toLowerCase()
    if (filterOperator === 'contains' && (typeof value !== 'string' || !value.trim())) {
      throw new TypeError(`Filter value for ${filter.field} must be a non-empty string.`)
    }
    if (definition.relationValueKey === 'uuid' && (typeof value !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value))) {
      throw new TypeError(`Filter value for ${filter.field} must be a UUID.`)
    }
    if (definition.relationValueKey === 'id' && (!Number.isSafeInteger(value) || value < 1)) {
      throw new TypeError(`Filter value for ${filter.field} must be a positive numeric ID.`)
    }
    if (/integer|number|float|decimal|currency|money/.test(normalizedType) && (typeof value !== 'number' || !Number.isFinite(value))) {
      throw new TypeError(`Filter value for ${filter.field} must be a finite number.`)
    }
    if (/integer/.test(normalizedType) && !Number.isInteger(value)) {
      throw new TypeError(`Filter value for ${filter.field} must be an integer.`)
    }
    if (/date|time/.test(normalizedType)) {
      const validDate = typeof value === 'string'
        && /^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,3})?)?(?:Z|[+-]\d{2}:\d{2}))?$/.test(value)
        && Number.isFinite(Date.parse(value))
        && (!/^\d{4}-\d{2}-\d{2}$/.test(value) || new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value)
      if (!validDate) throw new TypeError(`Filter value for ${filter.field} must be an ISO date or datetime.`)
    }
    const getter = `entity.get${filter.field[0].toUpperCase()}${filter.field.slice(1)}()`
    return `${getter} ${comparisonOperators[filterOperator]} ${JSON.stringify(value)}`
  })

  // Do not push list_display into Doctrine's @select DSL. Admin display
  // configs may include virtual or computed columns, and not every entity
  // has uuid; either can turn an otherwise valid list request into a 500.
  // The host sanitizes the returned records to this allowlist before exposing
  // them to the model provider.
  const params = { page, limit }
  if (expressions.length) params['@filter'] = expressions.join(' && ')
  if (capability.defaultOrder) params['@order'] = capability.defaultOrder
  return { entity: capability.key, path: capability.path, params, limit, page, fields: capability.select }
}

function safeValue(value) {
  if (value === null || typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value))) return value
  if (typeof value === 'string') return value.slice(0, 500)
  if (Array.isArray(value) && value.length <= 20 && value.every(item => item === null || ['string', 'number', 'boolean'].includes(typeof item))) {
    return value.map(item => typeof item === 'string' ? item.slice(0, 200) : item)
  }
  return undefined
}

export function sanitizeRecordResults(records, query, catalog) {
  const capability = catalog.find(item => item.key === query.entity)
  if (!capability) throw new TypeError('The read endpoint returned an unsupported result.')
  let collection = records
  let paginator = null
  // Support both the admin client's unwrapped `data` value and the documented
  // raw collection envelope `{ data: [...], paginator: {...} }`.
  for (let depth = 0; depth < 3 && !Array.isArray(collection); depth += 1) {
    if (!collection || typeof collection !== 'object' || Array.isArray(collection)) break
    if (collection.paginator && typeof collection.paginator === 'object') paginator = collection.paginator
    if (Array.isArray(collection.data)) {
      collection = collection.data
      break
    }
    collection = collection.data
  }
  if (!Array.isArray(collection)) throw new TypeError('The read endpoint returned an unsupported collection response.')
  const fields = new Set(query.fields)
  const sanitized = collection.slice(0, query.limit).map(record => {
    if (!record || typeof record !== 'object' || Array.isArray(record)) return {}
    const output = {}
    for (const key of fields) {
      if (!safeProperty(key) && !['id', 'uuid'].includes(key)) continue
      const value = safeValue(record[key])
      if (value !== undefined) output[key] = typeof value === 'string' ? value.slice(0, 300) : value
    }
    return output
  })
  let truncated = collection.length > sanitized.length
  while (sanitized.length && JSON.stringify(sanitized).length > MAX_RESULT_CHARACTERS) {
    sanitized.pop()
    truncated = true
  }
  const total = Number.isSafeInteger(paginator?.total) && paginator.total >= 0 ? paginator.total : null
  return {
    entity: capability.name,
    page: query.page,
    count: sanitized.length,
    ...(total === null ? {} : { total, hasMore: query.page * query.limit < total }),
    truncated,
    records: sanitized
  }
}

export function formatAdminReadError(error) {
  const status = Number.isInteger(error?.response?.status) ? ` (${error.response.status})` : ''
  const payload = error?.response?.data
  const detail = typeof payload === 'string'
    ? payload
    : typeof payload?.detail === 'string'
      ? payload.detail
      : typeof payload?.message === 'string'
        ? payload.message
        : typeof error?.message === 'string'
          ? error.message
          : 'Unknown server error.'
  const safeDetail = detail.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400)
  return `Admin read query failed${status}: ${safeDetail}`
}
