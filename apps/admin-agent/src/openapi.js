const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024
const MAX_OPERATIONS = 5000
const MAX_DESCRIPTION_LENGTH = 1200

function dereferenceSchema(document, schema) {
  if (!schema?.$ref || typeof schema.$ref !== 'string' || !schema.$ref.startsWith('#/')) return schema
  const pointer = schema.$ref.slice(2).split('/').map(part => part.replaceAll('~1', '/').replaceAll('~0', '~'))
  return pointer.reduce((value, key) => value?.[key], document)
}

function schemaSummary(schema, document, depth = 0, visited = new Set()) {
  if (!schema || typeof schema !== 'object') return ''
  const reference = String(schema.$ref || '').slice(0, 240)
  const resolved = dereferenceSchema(document, schema)
  if (reference && (!resolved || visited.has(reference) || depth >= 3)) {
    return { type: String(schema.type || '').slice(0, 80), format: String(schema.format || '').slice(0, 80), reference, properties: [], enum: [] }
  }
  const nextVisited = new Set(visited)
  if (reference) nextVisited.add(reference)
  const effective = resolved && typeof resolved === 'object' ? resolved : schema
  const properties = effective.properties && typeof effective.properties === 'object'
    ? Object.entries(effective.properties).slice(0, 24).map(([name, property]) => ({
      name: name.slice(0, 120),
      required: Array.isArray(effective.required) && effective.required.includes(name),
      description: String(property?.description || '').slice(0, 240),
      schema: schemaSummary(property, document, depth + 1, nextVisited)
    }))
    : []
  const enumValues = Array.isArray(effective.enum) ? effective.enum.slice(0, 12).map(value => String(value).slice(0, 80)) : []
  return {
    type: String(effective.type || '').slice(0, 80),
    format: String(effective.format || '').slice(0, 80),
    reference,
    properties,
    enum: enumValues
  }
}

function normalizeParameter(parameter, document) {
  if (!parameter || typeof parameter !== 'object') return null
  return {
    name: String(parameter.name || '').slice(0, 120),
    in: String(parameter.in || '').slice(0, 40),
    required: parameter.required === true,
    description: String(parameter.description || '').slice(0, 400),
    schema: schemaSummary(parameter.schema, document)
  }
}

function normalizeContent(content, document) {
  if (!content || typeof content !== 'object') return []
  return Object.entries(content).slice(0, 8).map(([mediaType, value]) => ({
    mediaType: mediaType.slice(0, 100),
    schema: schemaSummary(value?.schema, document)
  }))
}

function searchableValues(value) {
  if (typeof value === 'string' || typeof value === 'number') return String(value)
  if (Array.isArray(value)) return value.map(searchableValues).join(' ')
  if (value && typeof value === 'object') return Object.values(value).map(searchableValues).join(' ')
  return ''
}

export function normalizeOpenApi(document) {
  if (!document || typeof document !== 'object' || typeof document.paths !== 'object') {
    throw new TypeError('The API document has no valid paths object.')
  }

  const operations = []
  for (const [path, pathItem] of Object.entries(document.paths)) {
    if (!path.startsWith('/') || !pathItem || typeof pathItem !== 'object') continue
    for (const method of ['get', 'post', 'put', 'patch', 'delete', 'options', 'head']) {
      const operation = pathItem[method]
      if (!operation || typeof operation !== 'object') continue
      const parameters = [...(Array.isArray(pathItem.parameters) ? pathItem.parameters : []), ...(Array.isArray(operation.parameters) ? operation.parameters : [])]
        .map(parameter => normalizeParameter(parameter, document))
        .filter(Boolean)
        .slice(0, 50)
      const responses = operation.responses && typeof operation.responses === 'object'
        ? Object.entries(operation.responses).slice(0, 12).map(([status, response]) => ({
          status: status.slice(0, 30),
          description: String(response?.description || '').slice(0, 300),
          content: normalizeContent(response?.content, document)
        }))
        : []
      operations.push({
        id: String(operation.operationId || `${method.toUpperCase()} ${path}`).slice(0, 180),
        method: method.toUpperCase(),
        path: path.slice(0, 500),
        summary: String(operation.summary || operation.description || '').slice(0, MAX_DESCRIPTION_LENGTH),
        description: String(operation.description || '').slice(0, MAX_DESCRIPTION_LENGTH),
        tags: Array.isArray(operation.tags) ? operation.tags.filter(tag => typeof tag === 'string').slice(0, 12) : [],
        parameters,
        requestBody: normalizeContent(operation.requestBody?.content, document),
        responses
      })
      if (operations.length > MAX_OPERATIONS) throw new RangeError('The API document contains too many operations.')
    }
  }
  return operations
}

export function searchOpenApi(operations, query, limit = 12) {
  const words = String(query).toLocaleLowerCase().split(/[^\p{L}\p{N}_-]+/u).filter(Boolean)
  if (words.length === 0) return []
  return operations
    .map(operation => {
      const text = `${operation.id} ${operation.method} ${operation.path} ${operation.summary} ${operation.description} ${operation.tags.join(' ')} ${searchableValues(operation.parameters)} ${searchableValues(operation.requestBody)} ${searchableValues(operation.responses)}`.toLocaleLowerCase()
      const score = words.reduce((total, word) => total + (text.includes(word) ? 1 : 0), 0)
      return { operation, score }
    })
    .filter(item => item.score > 0)
    .sort((a, b) => b.score - a.score || a.operation.path.localeCompare(b.operation.path))
    .slice(0, Math.max(1, Math.min(30, limit)))
    .map(item => item.operation)
}

export async function loadOpenApi(url = '/api/doc.json', fetchImpl = fetch) {
  const target = new URL(url, globalThis.location?.origin || 'http://localhost')
  const current = new URL(globalThis.location?.origin || target.origin)
  if (target.origin !== current.origin || target.username || target.password || target.hash) {
    throw new TypeError('API documentation must be loaded from this application origin.')
  }
  const response = await fetchImpl(target.href, { method: 'GET', credentials: 'same-origin', redirect: 'error', headers: { Accept: 'application/json' } })
  if (!response.ok) throw new Error(`API documentation request failed (${response.status}).`)
  if (response.url && new URL(response.url, target).origin !== current.origin) {
    throw new TypeError('API documentation redirected outside this application origin.')
  }
  const declaredSize = Number(response.headers?.get?.('content-length'))
  if (Number.isFinite(declaredSize) && declaredSize > MAX_DOCUMENT_BYTES) throw new RangeError('The API document exceeds the size limit.')
  const text = await response.text()
  if (new TextEncoder().encode(text).byteLength > MAX_DOCUMENT_BYTES) throw new RangeError('The API document exceeds the size limit.')
  let document
  try { document = JSON.parse(text) } catch { throw new TypeError('The API documentation response was not valid JSON.') }
  return { info: document.info || {}, operations: normalizeOpenApi(document) }
}

export function formatOperations(operations, maxCharacters = 18000) {
  const schemaText = schema => {
    if (!schema) return 'any'
    const properties = schema.properties.map(property => `${property.name}${property.required ? '*' : ''}: ${schemaText(property.schema)}`)
    const values = schema.enum.length ? ` enum(${schema.enum.join(', ')})` : ''
    return `${schema.type || schema.reference || 'any'}${schema.format ? `/${schema.format}` : ''}${properties.length ? ` { ${properties.join('; ')} }` : ''}${values}`
  }
  const formatted = operations.map(operation => {
    const parameters = operation.parameters.map(parameter =>
      `${parameter.in} ${parameter.name}${parameter.required ? ' (required)' : ''}: ${schemaText(parameter.schema)}${parameter.description ? ` — ${parameter.description}` : ''}`
    )
    const bodies = operation.requestBody.map(body => `${body.mediaType}: ${schemaText(body.schema)}`)
    const responses = operation.responses.map(response => `${response.status}: ${response.description || response.content.map(item => `${item.mediaType} ${schemaText(item.schema)}`).join(', ')}`)
    return [
      `${operation.method} ${operation.path} — ${operation.summary} [${operation.tags.join(', ')}]`,
      operation.description,
      parameters.length ? `Parameters:\n${parameters.join('\n')}` : '',
      bodies.length ? `Request body:\n${bodies.join('\n')}` : '',
      responses.length ? `Responses:\n${responses.join('\n')}` : ''
    ].filter(Boolean).join('\n')
  }).join('\n\n')
  return formatted.slice(0, Math.max(0, maxCharacters))
}
