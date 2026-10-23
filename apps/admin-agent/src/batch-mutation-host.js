/**
 * Fixed-route transport for the explicit create-only Tag/Counter batch protocol.
 *
 * This module is not a generic batch HTTP tool and never exposes a model tool. It
 * accepts exactly one registered entity/action pair, validates every row with the
 * caller's existing single-record create policy, and rejects previews or receipts
 * that do not match the backend protocol before they can reach the UI.
 */

const BATCH_ENTITIES = Object.freeze(['Tag', 'Counter'])
const MAX_ROWS = 20
const REQUEST_TIMEOUT = 60000
const OPERATION_ID_PATTERN = /^[a-f0-9]{64}$/
// End-of-input lookahead instead of `$`: JavaScript `$` also matches before a
// trailing line terminator, which must not turn into an accepted database ID.
const TARGET_IDENTIFIER_PATTERN = /^[1-9][0-9]{0,9}(?![\s\S])/
const MAX_TARGET_IDENTIFIER = 2147483647
const PHP_TRIM_PATTERN = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g
const ISO_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/
const PREVIEW_KEYS = new Set(['operationId', 'entity', 'action', 'title', 'affectedRecords', 'expiresAt', 'rows', 'saved'])
const PREVIEW_ROW_KEYS = new Set(['row', 'title', 'identifier', 'differences'])
const PREVIEW_DIFFERENCE_KEYS = new Set(['property', 'before', 'after', 'changed', 'label'])
const RECEIPT_KEYS = new Set(['operationId', 'entity', 'action', 'status', 'rowCount', 'fields', 'results', 'createdAt', 'expiresAt', 'completedAt', 'verified'])
const RECEIPT_STATUSES = new Set(['pending', 'succeeded', 'cancelled', 'expired'])

export function createBatchMutationHost(request, path, capabilities = []) {
  const entries = buildEntries(capabilities)

  const operationPath = operationId => {
    if (typeof operationId !== 'string' || !OPERATION_ID_PATTERN.test(operationId)) throw new TypeError('Invalid operation identifier.')
    return `${path}/${operationId}`
  }

  return {
    getBatchCreateCapabilities() {
      return BATCH_ENTITIES.flatMap(entity => {
        const entry = entries.get(entity)
        if (!entry) return []
        const capability = entry.capability
        return [Object.freeze({
          entity,
          actions: Object.freeze(['create']),
          actionFields: Object.freeze({ create: entry.fields }),
          fields: entry.fields,
          validateChanges: (changes, action) => action === 'create' && capability.validateChanges(changes, 'create'),
          validatePreview: preview => capability.validatePreview(preview, capability)
        })]
      })
    },

    async prepareRecordBatch(input, signal) {
      const prepared = prepareInput(input, entries)
      assertActive(signal)
      let response
      try {
        response = await request.post(path, prepared, { signal, timeout: REQUEST_TIMEOUT })
      } catch (error) {
        throw batchError(error)
      }
      assertActive(signal)
      return normalizePreview(response?.data, { entity: prepared.entity, submittedRows: prepared.rows, entries })
    },

    async restoreRecordBatch(operationId, signal) {
      const target = operationPath(operationId)
      assertActive(signal)
      let response
      try {
        response = await request.get(`${target}/preview`, { signal, timeout: REQUEST_TIMEOUT })
      } catch (error) {
        throw batchError(error)
      }
      assertActive(signal)
      return normalizePreview(response?.data, { operationId, entries })
    },

    async commitRecordBatch(operationId, signal) {
      const target = operationPath(operationId)
      assertActive(signal)
      let response
      try {
        response = await request.post(`${target}/commit`, { confirmed: true }, { signal, timeout: REQUEST_TIMEOUT })
      } catch (error) {
        throw batchError(error)
      }
      assertActive(signal)
      return normalizeReceipt(response?.data, { entries, operationId, requireVerified: true })
    },

    async getRecordBatchStatus(operationId, signal) {
      const target = operationPath(operationId)
      assertActive(signal)
      let response
      try {
        response = await request.get(target, { signal, timeout: REQUEST_TIMEOUT })
      } catch (error) {
        throw batchError(error)
      }
      assertActive(signal)
      return normalizeReceipt(response?.data, { entries, operationId })
    },

    async cancelRecordBatch(operationId) {
      const target = operationPath(operationId)
      let response
      try {
        response = await request.post(`${target}/cancel`, {}, { timeout: REQUEST_TIMEOUT })
      } catch (error) {
        throw batchError(error)
      }
      return normalizeReceipt(response?.data, { entries, operationId })
    },

    async listRecordBatches(page = 1, signal) {
      if (!Number.isInteger(page) || page < 1 || page > 100) throw new TypeError('Invalid batch page.')
      assertActive(signal)
      let response
      try {
        response = await request.get(path, { params: { page }, signal, timeout: REQUEST_TIMEOUT })
      } catch (error) {
        throw batchError(error)
      }
      assertActive(signal)
      return normalizePage(response?.data, { page, entries })
    }
  }
}

function buildEntries(capabilities) {
  const source = Array.isArray(capabilities) ? capabilities : []
  const entries = new Map()
  for (const entity of BATCH_ENTITIES) {
    const capability = source.find(item => isPlainRecord(item) && item.entity === entity)
    if (!capability
      || !Array.isArray(capability.actions) || !capability.actions.includes('create')
      || typeof capability.validateChanges !== 'function'
      || typeof capability.validatePreview !== 'function'
      || !isPlainRecord(capability.actionFields)
      || !isPlainRecord(capability.actionFields.create)
      || hasUnsafeKeys(capability.actionFields.create)
      || Object.keys(capability.actionFields.create).length === 0) continue
    entries.set(entity, { capability, fields: capability.actionFields.create })
  }
  return entries
}

function prepareInput(input, entries) {
  if (!isPlainRecord(input) || hasUnsafeKeys(input)) throw new TypeError('Invalid batch input.')
  if (Object.keys(input).some(key => !['entity', 'action', 'rows'].includes(key))) throw new TypeError('Invalid batch input.')
  if (typeof input.entity !== 'string' || !entries.has(input.entity)) throw new TypeError('Only Tag and Counter create batches are enabled.')
  if (input.action !== 'create') throw new TypeError('Only the create action is enabled for batches.')
  if (!Array.isArray(input.rows) || input.rows.length < 1 || input.rows.length > MAX_ROWS) {
    throw new TypeError('A batch must contain between 1 and 20 rows.')
  }
  const entry = entries.get(input.entity)
  const rows = []
  for (let index = 0; index < input.rows.length; index += 1) {
    if (!Object.hasOwn(input.rows, index)) throw new TypeError('Each batch row must be a field object.')
    const row = input.rows[index]
    if (!isPlainRecord(row) || hasUnsafeKeys(row)) throw new TypeError('Each batch row must be a field object.')
    if (Object.keys(row).some(key => !Object.hasOwn(entry.fields, key))) {
      throw new TypeError('Changes contain unsupported fields or values.')
    }
    let valid = false
    try {
      valid = entry.capability.validateChanges(row, 'create')
    } catch {
      valid = false
    }
    if (!valid) throw new TypeError('Changes contain unsupported fields or values.')
    rows.push(Object.fromEntries(Object.keys(row).map(key => [key, row[key]])))
  }
  if (input.entity === 'Counter') {
    if (typeof rows[0].storeUuid !== 'string') throw new TypeError('Changes contain unsupported fields or values.')
    const scope = rows[0].storeUuid.toLowerCase()
    if (rows.some(row => typeof row.storeUuid !== 'string' || row.storeUuid.toLowerCase() !== scope)) {
      throw new TypeError('A Counter batch must use one explicit Store UUID.')
    }
  }
  // Freeze the snapshot before the request so neither the caller nor the
  // transport can change what the returned preview is compared against.
  const snapshot = Object.freeze(rows.map(row => Object.freeze({ ...row })))
  return Object.freeze({ entity: input.entity, action: 'create', rows: snapshot })
}

function normalizePreview(preview, { entity, operationId, submittedRows, entries }) {
  if (!isPlainRecord(preview) || hasUnsafeKeys(preview)) throw new TypeError('Invalid batch preview.')
  if (Object.keys(preview).some(key => !PREVIEW_KEYS.has(key)) || [...PREVIEW_KEYS].some(key => !Object.hasOwn(preview, key))) {
    throw new TypeError('The batch preview contains unsupported keys.')
  }
  if (typeof preview.operationId !== 'string' || !OPERATION_ID_PATTERN.test(preview.operationId)) throw new TypeError('Invalid batch operation identifier.')
  if (operationId !== undefined && preview.operationId !== operationId) throw new TypeError('The batch operation identity changed.')
  if (typeof preview.entity !== 'string' || !entries.has(preview.entity)) throw new TypeError('The batch entity is not enabled.')
  if (entity !== undefined && preview.entity !== entity) throw new TypeError('The batch entity does not match.')
  if (preview.action !== 'create') throw new TypeError('The batch action must be create.')
  if (preview.saved !== false) throw new TypeError('The batch preview must not be saved.')
  if (typeof preview.title !== 'string' || !preview.title.trim() || [...preview.title].length > 1024) throw new TypeError('Invalid batch preview title.')
  if (!Array.isArray(preview.rows) || preview.rows.length < 1 || preview.rows.length > MAX_ROWS) throw new TypeError('The batch preview row count changed.')
  if (!Number.isInteger(preview.affectedRecords) || preview.affectedRecords !== preview.rows.length) throw new TypeError('The batch preview row count changed.')
  if (!isValidDate(preview.expiresAt)) throw new TypeError('The batch preview expiry is invalid.')

  const entry = entries.get(preview.entity)
  const rows = preview.rows.map((row, index) => {
    if (!isPlainRecord(row) || hasUnsafeKeys(row)) throw new TypeError('Invalid batch preview row.')
    if (Object.keys(row).some(key => !PREVIEW_ROW_KEYS.has(key))) throw new TypeError('The batch preview contains unsupported row keys.')
    if (row.row !== index + 1) throw new TypeError('The batch preview row order changed.')
    if (row.identifier !== null) throw new TypeError('A create preview must not have a target identifier.')
    if (typeof row.title !== 'string' || !row.title.trim() || [...row.title].length > 1024) throw new TypeError('Invalid batch preview title.')
    if (!Array.isArray(row.differences) || row.differences.length === 0) throw new TypeError('Invalid batch row preview.')
    for (const difference of row.differences) {
      if (!isPlainRecord(difference) || hasUnsafeKeys(difference)) throw new TypeError('Invalid batch row preview.')
      if (Object.keys(difference).some(key => !PREVIEW_DIFFERENCE_KEYS.has(key))) throw new TypeError('The batch preview contains unsupported difference keys.')
    }

    // Reuse the single-record create preview policy row by row. This catches
    // unknown fields, invalid values, duplicate properties, missing required
    // fields and oversized values without inventing a second field policy.
    const synthesized = {
      operationId: preview.operationId,
      entity: preview.entity,
      action: 'create',
      identifier: null,
      title: row.title,
      affectedRecords: 1,
      expiresAt: preview.expiresAt,
      differences: row.differences,
      saved: false
    }
    let valid = false
    try {
      valid = entry.capability.validatePreview(synthesized, entry.capability) === true
    } catch {
      valid = false
    }
    if (!valid) throw new TypeError('Invalid batch row preview.')

    return {
      row: index + 1,
      title: row.title,
      identifier: null,
      differences: row.differences.map(difference => ({
        property: difference.property,
        before: difference.before,
        after: difference.after
      }))
    }
  })

  if (preview.entity === 'Counter') {
    const scopes = rows.map(row => row.differences.find(difference => difference.property === 'storeUuid')?.after)
    if (typeof scopes[0] !== 'string' || scopes.some(scope => typeof scope !== 'string' || scope.toLowerCase() !== scopes[0].toLowerCase())) {
      throw new TypeError('A Counter batch must use one explicit Store UUID.')
    }
  }

  if (submittedRows !== undefined) assertPreviewMatchesSubmitted(rows, submittedRows, preview.entity)

  return {
    operationId: preview.operationId,
    entity: preview.entity,
    action: 'create',
    title: preview.title,
    affectedRecords: rows.length,
    expiresAt: preview.expiresAt,
    rows,
    saved: false
  }
}

/**
 * Bind a prepared preview to the exact frozen submission it came from. The
 * backend canonicalizes Counter code/name and Store UUID case, but must not
 * substitute, reorder, omit or invent rows or supplied fields.
 */
function assertPreviewMatchesSubmitted(previewRows, submittedRows, entity) {
  if (previewRows.length !== submittedRows.length) throw new TypeError('The batch preview does not match the submitted rows.')
  for (let index = 0; index < submittedRows.length; index += 1) {
    const submitted = submittedRows[index]
    const differences = previewRows[index].differences
    const fields = Object.keys(submitted)
    if (differences.length !== fields.length) throw new TypeError('The batch preview does not match the submitted rows.')
    const actual = new Map(differences.map(difference => [difference.property, difference.after]))
    for (const field of fields) {
      if (!actual.has(field) || actual.get(field) !== canonicalSubmittedValue(entity, field, submitted[field])) {
        throw new TypeError('The batch preview does not match the submitted rows.')
      }
    }
    if (!Object.hasOwn(submitted, 'name') || previewRows[index].title !== canonicalSubmittedValue(entity, 'name', submitted.name)) {
      throw new TypeError('The batch preview does not match the submitted rows.')
    }
  }
}

function canonicalSubmittedValue(entity, field, value) {
  if (entity !== 'Counter') return value
  if (field === 'storeUuid') return value.toLowerCase()
  if (field === 'code' || field === 'name') return value.replace(PHP_TRIM_PATTERN, '')
  return value
}

function normalizeReceipt(receipt, { entries, operationId, requireVerified = false }) {
  if (!isPlainRecord(receipt) || hasUnsafeKeys(receipt)) throw new TypeError('Invalid batch receipt.')
  if (Object.keys(receipt).some(key => !RECEIPT_KEYS.has(key))) throw new TypeError('The batch receipt contains unsupported keys.')
  if (typeof receipt.operationId !== 'string' || !OPERATION_ID_PATTERN.test(receipt.operationId)) throw new TypeError('Invalid batch operation identifier.')
  if (operationId !== undefined && receipt.operationId !== operationId) throw new TypeError('The batch operation identity changed.')
  if (typeof receipt.entity !== 'string' || !entries.has(receipt.entity)) throw new TypeError('The batch entity is not enabled.')
  if (receipt.action !== 'create') throw new TypeError('The batch action must be create.')
  if (!RECEIPT_STATUSES.has(receipt.status)) throw new TypeError('Invalid batch receipt status.')
  if (!Number.isInteger(receipt.rowCount) || receipt.rowCount < 1 || receipt.rowCount > MAX_ROWS) throw new TypeError('Invalid batch receipt row count.')
  if (!isValidDate(receipt.createdAt) || !isValidDate(receipt.expiresAt)) throw new TypeError('Invalid batch receipt time.')
  if (Date.parse(receipt.expiresAt) < Date.parse(receipt.createdAt)) throw new TypeError('Invalid batch receipt time.')
  if (receipt.completedAt !== null && !isValidDate(receipt.completedAt)) throw new TypeError('Invalid batch receipt time.')
  if (receipt.status === 'pending' && receipt.completedAt !== null) throw new TypeError('Invalid batch receipt time.')
  if ((receipt.status === 'succeeded' || receipt.status === 'cancelled') && receipt.completedAt === null) throw new TypeError('Invalid batch receipt time.')
  if (requireVerified && receipt.status !== 'succeeded') throw new TypeError('The batch commit did not succeed.')
  if (requireVerified && receipt.verified !== true) throw new TypeError('The batch commit was not verified.')
  if (!requireVerified && receipt.verified !== undefined) throw new TypeError('The batch receipt contains unsupported keys.')

  const fields = normalizeFields(receipt.fields, entries.get(receipt.entity).fields)
  const results = normalizeResults(receipt.results, receipt.status, receipt.rowCount)

  return {
    operationId: receipt.operationId,
    entity: receipt.entity,
    action: 'create',
    status: receipt.status,
    rowCount: receipt.rowCount,
    fields,
    results,
    createdAt: receipt.createdAt,
    expiresAt: receipt.expiresAt,
    completedAt: receipt.completedAt,
    ...(requireVerified ? { verified: true } : {})
  }
}

function normalizeFields(fields, allowed) {
  if (!Array.isArray(fields) || fields.length < 1 || fields.length > Object.keys(allowed).length) throw new TypeError('Invalid batch receipt fields.')
  const seen = new Set()
  return fields.map(field => {
    if (typeof field !== 'string' || !Object.hasOwn(allowed, field) || seen.has(field)) throw new TypeError('Invalid batch receipt fields.')
    seen.add(field)
    return field
  })
}

function normalizeResults(results, status, rowCount) {
  if (!Array.isArray(results)) throw new TypeError('Invalid batch receipt results.')
  if (status !== 'succeeded') {
    if (results.length !== 0) throw new TypeError('Invalid batch receipt results.')
    return []
  }
  if (results.length !== rowCount) throw new TypeError('Invalid batch receipt results.')
  const seenRows = new Set()
  const seenIdentifiers = new Set()
  return results.map((result, index) => {
    if (!isPlainRecord(result) || hasUnsafeKeys(result)) throw new TypeError('Invalid batch receipt results.')
    if (Object.keys(result).some(key => !['row', 'identifier'].includes(key))) throw new TypeError('Invalid batch receipt results.')
    if (result.row !== index + 1 || seenRows.has(result.row)) throw new TypeError('Invalid batch receipt results.')
    if (!isTargetIdentifier(result.identifier) || seenIdentifiers.has(result.identifier)) {
      throw new TypeError('Invalid batch receipt results.')
    }
    seenRows.add(result.row)
    seenIdentifiers.add(result.identifier)
    return { row: result.row, identifier: result.identifier }
  })
}

function normalizePage(page, { page: expected, entries }) {
  if (!isPlainRecord(page) || hasUnsafeKeys(page)) throw new TypeError('Invalid batch page.')
  if (Object.keys(page).some(key => !['items', 'page', 'hasMore'].includes(key))) throw new TypeError('The batch page contains unsupported keys.')
  if (page.page !== expected || !Number.isInteger(page.page)) throw new TypeError('The batch page number changed.')
  if (typeof page.hasMore !== 'boolean') throw new TypeError('Invalid batch page.')
  if (!Array.isArray(page.items) || page.items.length > MAX_ROWS) throw new TypeError('Invalid batch page items.')
  const seen = new Set()
  const items = page.items.map(item => {
    const receipt = normalizeReceipt(item, { entries })
    if (seen.has(receipt.operationId)) throw new TypeError('The batch page contains duplicate operations.')
    seen.add(receipt.operationId)
    return receipt
  })
  return { items, page: expected, hasMore: page.hasMore }
}

function isPlainRecord(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const prototype = Object.getPrototypeOf(value)
  return (prototype === Object.prototype || prototype === null)
    && Reflect.ownKeys(value).every(key => typeof key === 'string')
}

function hasUnsafeKeys(value) {
  return Object.keys(value).some(key => ['__proto__', 'prototype', 'constructor'].includes(key))
}

function isValidDate(value) {
  return typeof value === 'string' && ISO_DATE_PATTERN.test(value) && Number.isFinite(Date.parse(value))
}

function isTargetIdentifier(value) {
  return typeof value === 'string'
    && TARGET_IDENTIFIER_PATTERN.test(value)
    && !/[\u0000-\u001f\u007f]/.test(value)
    && Number(value) <= MAX_TARGET_IDENTIFIER
}

function assertActive(signal) {
  if (signal?.aborted) {
    const error = new Error('The batch request was cancelled.')
    error.name = 'AbortError'
    throw error
  }
}

function batchError(error) {
  if (error?.response?.status === 409 && error.response.data?.error === 'proposal_conflict') {
    return Object.assign(new Error('The batch conflicts with current data.'), { code: 'proposal_conflict' })
  }
  return error
}
