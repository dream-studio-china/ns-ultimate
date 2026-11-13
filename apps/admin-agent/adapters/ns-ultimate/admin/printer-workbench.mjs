/**
 * Printer workbench adapter.
 *
 * Order print rules and printer templates are ordinary configured CRUD, but
 * their admin forms use custom editors. The small validator in this adapter
 * mirrors the bounded rule/template shapes the backend accepts, and the tools
 * cover the non-persistent paper preview and provider-status refresh actions.
 *
 * Live print submission and reprints are deliberately absent: they produce
 * physical output and stay in the ordinary admin UI.
 */

const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
const UUID = new RegExp(UUID_PATTERN, 'i')
const EVENTS = Object.freeze(['confirmed', 'paid', 'accepted', 'manual'])
const OPERATORS = Object.freeze(['equals', 'not_equals', 'exists', 'not_exists'])
const CONDITION_FIELD = /^(?:status|metadata(?:\.[A-Za-z_][A-Za-z0-9_-]{0,63}){1,4})$/
const MAX_DOCUMENT_CHARACTERS = 9000
const MAX_DATA_CHARACTERS = 20000
const MAX_SETTING_VALUE_CHARACTERS = 12000
const MAX_SETTING_TOTAL_CHARACTERS = 14000
const SETTINGS_COLLECTIONS = Object.freeze({
  PrinterAccount: 'accounts',
  PrinterDevice: 'devices',
  PrinterTemplate: 'templates',
  PrinterOrderRule: 'order-rules',
  PrinterJob: 'jobs'
})
const SETTINGS_FIELDS = Object.freeze({
  PrinterAccount: ['uuid', 'provider', 'name', 'developerId', 'enabled', 'createdAt'],
  PrinterDevice: ['uuid', 'account', 'name', 'serialNumber', 'kind', 'storeUuid', 'model', 'widthMm', 'heightMm', 'enabled', 'providerStatus', 'statusCheckedAt', 'createdAt'],
  PrinterTemplate: ['uuid', 'name', 'kind', 'ticketMode', 'itemFilter', 'definition', 'enabled', 'createdAt'],
  PrinterOrderRule: ['uuid', 'storeUuid', 'name', 'enabled', 'events', 'match', 'conditions', 'deviceUuid', 'templateUuid', 'copies'],
  PrinterJob: ['uuid', 'storeUuid', 'device', 'kind', 'copies', 'status', 'providerOrderId', 'errorCode', 'createdAt']
})

const plain = value => value !== null && typeof value === 'object' && !Array.isArray(value)
  && [Object.prototype, null].includes(Object.getPrototypeOf(value))
const safeJson = value => {
  try { return JSON.stringify(value).length <= MAX_DATA_CHARACTERS } catch { return false }
}
const uuid = value => typeof value === 'string' && UUID.test(value)
const tool = (name, description, properties, required) => ({ type: 'function', function: {
  name, description, parameters: { type: 'object', properties, required, additionalProperties: false }
} })

export const printerTools = Object.freeze([
  tool('read_printer_setting', 'Read one exact printer settings record after locating it with query_records. Returns only bounded, non-secret settings for accounts, devices, templates, order print rules and jobs. Account credentials are never returned. This is read-only and does not contact the provider.', {
    entity: { type: 'string', enum: ['PrinterAccount', 'PrinterDevice', 'PrinterTemplate', 'PrinterOrderRule', 'PrinterJob'] },
    identifier: { type: 'string', pattern: UUID_PATTERN, description: 'Exact record UUID from a query result.' }
  }, ['entity', 'identifier']),
  tool('preview_printer_paper', 'Render a printer paper document without printing it. Use jobUuid to preview the exact stored document of an existing print job, or templateUuid plus a sample data object to render an unsaved template preview. This calls the same non-persistent preview renderer as the admin paper preview. It never contacts the printer, never creates a job and never produces physical output. The result is an approximation: fonts, wrapping and QR sizing may differ from the physical printer.', {
    jobUuid: { type: 'string', pattern: UUID_PATTERN, description: 'Existing print job UUID. Use alone.' },
    templateUuid: { type: 'string', pattern: UUID_PATTERN, description: 'Existing printer template UUID. Requires data.' },
    data: { type: 'object', description: 'JSON sample data used by the template expressions. Required with templateUuid; ignored with jobUuid.' }
  }, []),
  tool('refresh_printer_status', 'Ask the configured printer provider for the current status of one device or print job, then report the stored result. This only queries provider status; it never submits a print, never reprints and never changes paper content. The device or job must be enabled/queryable, and provider failures are reported as errors.', {
    entity: { type: 'string', enum: ['PrinterDevice', 'PrinterJob'] },
    identifier: { type: 'string', pattern: UUID_PATTERN, description: 'Exact device or job UUID.' }
  }, ['entity', 'identifier'])
])

function validateCondition(condition) {
  if (!plain(condition) || Object.keys(condition).some(key => !['field', 'operator', 'value', 'valueKind'].includes(key))) {
    throw new TypeError('Each print rule condition must contain only field, operator and value data.')
  }
  if (typeof condition.field !== 'string' || condition.field.length > 128
    || /[\u0000-\u001f\u007f]/.test(condition.field) || !CONDITION_FIELD.test(condition.field)) {
    throw new TypeError('A print rule condition field must be status or a bounded metadata path.')
  }
  if (!OPERATORS.includes(condition.operator)) throw new TypeError('A print rule condition operator is invalid.')
  const hasValue = Object.hasOwn(condition, 'value')
  if (condition.operator === 'exists' || condition.operator === 'not_exists') {
    if (hasValue || Object.hasOwn(condition, 'valueKind')) throw new TypeError('Exists conditions must not contain a value.')
    return
  }
  if (!hasValue) throw new TypeError('Equals and not-equals conditions require a value.')
  const value = condition.value
  const valid = value === null || typeof value === 'boolean'
    || (typeof value === 'number' && Number.isSafeInteger(value))
    || (typeof value === 'string' && value.length <= 256 && !/[\u0000-\u001f\u007f]/.test(value))
  if (!valid) throw new TypeError('A print rule condition value must be bounded text, an integer, a boolean or null.')
  if (Object.hasOwn(condition, 'valueKind') && (condition.valueKind !== 'null' || value !== null)) {
    throw new TypeError('valueKind is only supported for an explicit null condition value.')
  }
}

export function validatePrinterRule(rule) {
  if (!plain(rule)) throw new TypeError('A print rule object is required.')
  if (typeof rule.name !== 'string' || !rule.name.trim() || rule.name.length > 120) {
    throw new TypeError('A print rule name is required and must not exceed 120 characters.')
  }
  if (typeof rule.enabled !== 'boolean') throw new TypeError('A print rule enabled flag must be boolean.')
  if (!Array.isArray(rule.events) || rule.events.length < 1 || rule.events.length > EVENTS.length
    || new Set(rule.events).size !== rule.events.length
    || rule.events.some(event => !EVENTS.includes(event))) {
    throw new TypeError(`Print rule events must be unique values from: ${EVENTS.join(', ')}.`)
  }
  if (!['all', 'any'].includes(rule.match)) throw new TypeError('A print rule match mode must be all or any.')
  if (!Array.isArray(rule.conditions) || rule.conditions.length > 20) {
    throw new TypeError('Print rule conditions must be a list of at most 20 entries.')
  }
  if (rule.match === 'any' && rule.conditions.length === 0) {
    throw new TypeError('An any-mode print rule requires at least one condition.')
  }
  for (const condition of rule.conditions) validateCondition(condition)
  if (!uuid(rule.deviceUuid) || !uuid(rule.templateUuid)) {
    throw new TypeError('A print rule requires exact device and template UUIDs.')
  }
  if (!Number.isSafeInteger(rule.copies) || rule.copies < 1 || rule.copies > 100) {
    throw new TypeError('Print rule copies must be an integer between 1 and 100.')
  }
}

function validateTemplateChange(action, record, changes) {
  if (changes.definition !== undefined) {
    if (!plain(changes.definition)) throw new TypeError('A printer template definition must be a JSON object.')
    const kind = action === 'update' ? record.kind : changes.kind
    if (changes.definition.kind !== kind) throw new TypeError('Template kind must match the definition kind.')
    if (!plain(changes.sampleData)) {
      throw new TypeError('Template definition changes require sampleData in the same change.')
    }
  }
  if (action === 'create' && (!plain(changes.definition) || !plain(changes.sampleData))) {
    throw new TypeError('Creating a printer template requires definition and sampleData objects.')
  }
  if (changes.itemFilter !== undefined && changes.itemFilter !== null
    && (typeof changes.itemFilter !== 'string' || changes.itemFilter.length > 2000)) {
    throw new TypeError('A template item filter must be bounded text or null.')
  }
}

/**
 * Validate and annotate printer changes before a preview is shown. Returns
 * evidence for the preview card or null for unrelated entities.
 */
export function validatePrinterChange({ entity, action, record = {}, changes = {} }) {
  if (entity === 'PrinterOrderRule' && (action === 'create' || action === 'update')) {
    const rule = { ...record, ...changes }
    validatePrinterRule(rule)
    return {
      evidence: {
        scope: 'future_automatic_printing',
        automaticPrintingEnabled: rule.enabled === true && rule.events.some(event => event !== 'manual'),
        manualPrintingEnabled: rule.enabled === true && rule.events.includes('manual'),
        events: rule.events,
        match: rule.match,
        conditionCount: rule.conditions.length,
        copies: rule.copies
      }
    }
  }
  if (entity === 'PrinterDevice' && action === 'create') {
    return {
      evidence: {
        externalRegistration: 'Creating this device calls the configured printer provider to register the serial number before the local record is saved. Verify the account, serial number, kind and store; the provider registration is an external side effect.'
      }
    }
  }
  if (entity === 'PrinterTemplate' && (action === 'create' || action === 'update')) {
    validateTemplateChange(action, record, changes)
    const template = { ...record, ...changes }
    return {
      evidence: {
        scope: 'future_automatic_printing',
        ticketMode: template.ticketMode,
        itemScope: template.itemFilter ? 'filtered' : 'all'
      }
    }
  }
  return null
}

function boundedDocument(value) {
  let text
  try { text = typeof value === 'string' ? value : JSON.stringify(value, null, 2) } catch { text = '' }
  if (text.length <= MAX_DOCUMENT_CHARACTERS) return { document: value, truncated: false }
  return { document: `${text.slice(0, MAX_DOCUMENT_CHARACTERS)}\n…`, truncated: true }
}

function boundedSetting(value) {
  if (value === null || typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value))) return { value, truncated: false }
  if (typeof value === 'string') {
    return value.length <= MAX_SETTING_VALUE_CHARACTERS
      ? { value, truncated: false }
      : { value: `${value.slice(0, MAX_SETTING_VALUE_CHARACTERS)}…`, truncated: true }
  }
  if (Array.isArray(value) || plain(value)) {
    let text
    try { text = JSON.stringify(value) } catch { return null }
    if (text === undefined) return null
    return text.length <= MAX_SETTING_VALUE_CHARACTERS
      ? { value, truncated: false }
      : { value: `${text.slice(0, MAX_SETTING_VALUE_CHARACTERS)}…`, truncated: true }
  }
  return null
}

function unwrap(response) {
  let node = response?.data
  for (let depth = 0; depth < 2; depth += 1) {
    if (!plain(node) || !Object.hasOwn(node, 'data')) break
    if (Object.hasOwn(node, 'id') || Object.hasOwn(node, 'uuid')) break
    node = node.data
  }
  return node
}

function availableMessage() {
  return 'Printer tools are not available in the current admin navigation.'
}

export function createPrinterWorkbench({ request, isAvailable, path = '/api/v1/manage/printer' }) {
  if (!request || typeof request.get !== 'function' || typeof request.post !== 'function') {
    throw new TypeError('Printer tools require the admin request client.')
  }
  if (typeof isAvailable !== 'function') throw new TypeError('Printer tools require a navigation availability check.')
  if (typeof path !== 'string' || !path.startsWith('/api/v1/manage/') || path.endsWith('/')) {
    throw new TypeError('Printer tools require a bounded management path.')
  }
  const available = () => { if (!isAvailable()) throw new Error(availableMessage()) }

  async function previewPaper(input, signal) {
    available()
    if (!plain(input) || Object.keys(input).some(key => !['jobUuid', 'templateUuid', 'data'].includes(key))) {
      throw new TypeError('Unsupported paper preview parameters.')
    }
    const hasJob = Object.hasOwn(input, 'jobUuid')
    const hasTemplate = Object.hasOwn(input, 'templateUuid')
    if (hasJob === hasTemplate) throw new TypeError('Provide exactly one of jobUuid or templateUuid.')
    if (hasJob) {
      if (!uuid(input.jobUuid) || input.data !== undefined) throw new TypeError('A job preview requires only an exact job UUID.')
      if (signal?.aborted) throw new Error('The paper preview was cancelled.')
      const response = await request.get(`${path}/jobs/${encodeURIComponent(input.jobUuid)}/preview`, { signal, timeout: 60000 })
      const body = unwrap(response)
      if (!plain(body) || body.document === undefined) throw new Error('The job preview response is unavailable.')
      return { mode: 'job', jobUuid: input.jobUuid, copies: body.copies ?? null, ...boundedDocument(body.document) }
    }
    if (!uuid(input.templateUuid) || !plain(input.data) || !safeJson(input.data)) {
      throw new TypeError('A template preview requires an exact template UUID and a bounded JSON data object.')
    }
    if (signal?.aborted) throw new Error('The paper preview was cancelled.')
    const detail = unwrap(await request.get(`${path}/templates/${encodeURIComponent(input.templateUuid)}`, { signal, timeout: 60000 }))
    if (!plain(detail) || !plain(detail.definition)) throw new Error('The printer template detail response is unavailable.')
    const response = await request.post(`${path}/preview`, { template: detail.definition, data: input.data }, { signal, timeout: 60000 })
    const body = unwrap(response)
    if (!plain(body) || body.document === undefined) throw new Error('The paper preview response is unavailable.')
    return {
      mode: 'template',
      templateUuid: input.templateUuid,
      kind: detail.kind,
      ticketMode: detail.ticketMode,
      ...boundedDocument(body.document),
      warnings: Array.isArray(body.warnings) ? body.warnings.filter(item => typeof item === 'string').slice(0, 5) : []
    }
  }

  async function refreshStatus(input, signal) {
    available()
    if (!plain(input) || Object.keys(input).some(key => !['entity', 'identifier'].includes(key))
      || !['PrinterDevice', 'PrinterJob'].includes(input.entity) || !uuid(input.identifier)) {
      throw new TypeError('Status refresh requires PrinterDevice or PrinterJob and an exact UUID.')
    }
    if (signal?.aborted) throw new Error('The status refresh was cancelled.')
    const collection = input.entity === 'PrinterDevice' ? 'devices' : 'jobs'
    const response = await request.post(`${path}/${collection}/${encodeURIComponent(input.identifier)}/status`, {}, { signal, timeout: 60000 })
    const body = unwrap(response)
    if (!plain(body)) throw new Error('The printer status response is unavailable.')
    const record = input.entity === 'PrinterDevice'
      ? { uuid: body.uuid, name: body.name, enabled: body.enabled, status: body.status, statusCheckedAt: body.statusCheckedAt }
      : { uuid: body.uuid, status: body.status, providerOrderId: body.providerOrderId, errorCode: body.errorCode, copies: body.copies, createdAt: body.createdAt }
    return { entity: input.entity, identifier: input.identifier, record }
  }

  async function readSetting(input, signal) {
    available()
    if (!plain(input) || Object.keys(input).some(key => !['entity', 'identifier'].includes(key))
      || !Object.hasOwn(SETTINGS_COLLECTIONS, input.entity) || !uuid(input.identifier)) {
      throw new TypeError('Printer settings reads require a supported entity and an exact UUID.')
    }
    if (signal?.aborted) throw new Error('The printer settings read was cancelled.')
    const response = await request.get(`${path}/${SETTINGS_COLLECTIONS[input.entity]}/${encodeURIComponent(input.identifier)}`, { signal, timeout: 60000 })
    const record = unwrap(response)
    if (!plain(record)) throw new Error('The printer settings response is unavailable.')
    const fields = {}
    const omitted = []
    const truncatedFields = []
    for (const property of SETTINGS_FIELDS[input.entity]) {
      if (!Object.hasOwn(record, property)) { omitted.push(property); continue }
      let value = record[property]
      if (input.entity === 'PrinterDevice' && property === 'account' && plain(value)) {
        value = { id: value.id, name: value.name }
      }
      if (input.entity === 'PrinterJob' && property === 'device' && plain(value)) {
        value = { uuid: value.uuid, name: value.name }
      }
      const bounded = boundedSetting(value)
      if (bounded === null) { omitted.push(property); continue }
      if (bounded.truncated) truncatedFields.push(property)
      const next = { ...fields, [property]: bounded.value }
      if (JSON.stringify(next).length > MAX_SETTING_TOTAL_CHARACTERS) { omitted.push(property); continue }
      fields[property] = bounded.value
    }
    return {
      entity: input.entity,
      identifier: input.identifier,
      fields,
      omitted,
      truncatedFields,
      truncated: omitted.length > 0 || truncatedFields.length > 0
    }
  }

  async function beforeChange(input) {
    const result = validatePrinterChange(input)
    if (input?.entity === 'PrinterDevice' && input.action === 'create') {
      const accountId = input.changes?.account
      if (!Number.isSafeInteger(accountId) || accountId < 1) throw new TypeError('Select a valid printer account.')
      const account = unwrap(await request.get(`${path}/accounts/${accountId}`, { signal: input.signal, timeout: 60000 }))
      if (!plain(account) || account.enabled !== true) throw new TypeError('Select an enabled printer account.')
    }
    if (input?.entity === 'PrinterOrderRule' && (input.action === 'create' || input.action === 'update')) {
      const rule = { ...input.record, ...input.changes }
      const [deviceResponse, templateResponse] = await Promise.all([
        request.get(`${path}/devices/${encodeURIComponent(rule.deviceUuid)}`, { signal: input.signal, timeout: 60000 }),
        request.get(`${path}/templates/${encodeURIComponent(rule.templateUuid)}`, { signal: input.signal, timeout: 60000 })
      ])
      const device = unwrap(deviceResponse)
      const template = unwrap(templateResponse)
      if (!plain(device) || device.storeUuid !== rule.storeUuid) {
        throw new TypeError('A print rule requires a device owned by the same store.')
      }
      if (!plain(template) || device.kind !== template.kind) {
        throw new TypeError('A print rule requires a template whose kind matches the device.')
      }
    }
    return result
  }

  return {
    getDraftTools: () => isAvailable() ? printerTools : [],
    async runDraftTool(name, input, signal) {
      if (name === 'read_printer_setting') return readSetting(input, signal)
      if (name === 'preview_printer_paper') return previewPaper(input, signal)
      if (name === 'refresh_printer_status') return refreshStatus(input, signal)
      throw new Error('Unknown printer tool.')
    },
    beforeChange,
    dispose() {}
  }
}
