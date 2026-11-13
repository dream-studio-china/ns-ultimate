import assert from 'node:assert/strict'
import test from 'node:test'
import {
  createPrinterWorkbench,
  printerTools,
  validatePrinterChange,
  validatePrinterRule
} from '../adapters/ns-ultimate/admin/printer-workbench.mjs'
import { agentMutationPolicy, isAgentActionAllowed, isAgentFieldAllowed } from '../adapters/ns-ultimate/admin/write-policy.mjs'

const uuid = '123e4567-e89b-42d3-a456-426614174000'
const otherUuid = '123e4567-e89b-42d3-a456-426614174001'

function validRule(overrides = {}) {
  return {
    name: 'Kitchen ticket',
    enabled: true,
    events: ['paid'],
    match: 'all',
    conditions: [],
    deviceUuid: uuid,
    templateUuid: otherUuid,
    copies: 1,
    ...overrides
  }
}

function fakeRequest({ get, post } = {}) {
  const calls = []
  return {
    calls,
    async get(path, options) {
      calls.push({ method: 'get', path, options })
      return get ? get(path, options) : { data: {} }
    },
    async post(path, body, options) {
      calls.push({ method: 'post', path, body, options })
      return post ? post(path, body, options) : { data: {} }
    }
  }
}

test('bounded print rules mirror the backend events, conditions and output constraints', () => {
  assert.doesNotThrow(() => validatePrinterRule(validRule()))
  assert.doesNotThrow(() => validatePrinterRule(validRule({
    match: 'any',
    conditions: [
      { field: 'status', operator: 'equals', value: 'paid' },
      { field: 'metadata.channel', operator: 'exists' },
      { field: 'metadata.count', operator: 'not_equals', value: 3 },
      { field: 'metadata.note', operator: 'equals', value: null, valueKind: 'null' }
    ]
  })))

  for (const events of [[], ['paid', 'paid'], ['unknown']]) {
    assert.throws(() => validatePrinterRule(validRule({ events })), /events/)
  }
  assert.throws(() => validatePrinterRule(validRule({ match: 'any', conditions: [] })), /any-mode/)
  assert.throws(() => validatePrinterRule(validRule({ match: 'both' })), /match mode/)
  assert.throws(() => validatePrinterRule(validRule({ conditions: [{ field: 'name', operator: 'equals', value: 'x' }] })), /condition field/)
  assert.throws(() => validatePrinterRule(validRule({ conditions: [{ field: 'status', operator: 'exists', value: true }] })), /Exists conditions/)
  assert.throws(() => validatePrinterRule(validRule({ conditions: [{ field: 'status', operator: 'equals' }] })), /require a value/)
  assert.throws(() => validatePrinterRule(validRule({ conditions: [{ field: 'status', operator: 'equals', value: 'x', extra: 1 }] })), /only field/)
  assert.throws(() => validatePrinterRule(validRule({ conditions: [{ field: 'status', operator: 'equals', value: null, valueKind: 'string' }] })), /valueKind/)
  assert.throws(() => validatePrinterRule(validRule({ conditions: [{ field: 'status', operator: 'contains', value: 'x' }] })), /operator/)
  assert.throws(() => validatePrinterRule(validRule({ conditions: new Array(21).fill({ field: 'status', operator: 'exists' }) })), /at most 20/)
  assert.throws(() => validatePrinterRule(validRule({ copies: 0 })), /copies/)
  assert.throws(() => validatePrinterRule(validRule({ deviceUuid: 'not-a-uuid' })), /device and template/)
  assert.throws(() => validatePrinterRule(validRule({ name: ' ' })), /name/)
  assert.throws(() => validatePrinterRule(validRule({ enabled: 'true' })), /enabled/)
})

test('printer change annotations cover rule impact, provider registration and template sample data', () => {
  const ruleEvidence = validatePrinterChange({
    entity: 'PrinterOrderRule',
    action: 'update',
    record: validRule({ enabled: false }),
    changes: { enabled: true }
  })
  assert.equal(ruleEvidence.evidence.scope, 'future_automatic_printing')
  assert.equal(ruleEvidence.evidence.automaticPrintingEnabled, true)
  assert.deepEqual(ruleEvidence.evidence.events, ['paid'])
  assert.equal(ruleEvidence.evidence.manualPrintingEnabled, false)
  const manualEvidence = validatePrinterChange({
    entity: 'PrinterOrderRule',
    action: 'update',
    record: validRule({ enabled: true, events: ['manual'] }),
    changes: { events: ['manual'] }
  })
  assert.equal(manualEvidence.evidence.automaticPrintingEnabled, false)
  assert.equal(manualEvidence.evidence.manualPrintingEnabled, true)

  const deviceEvidence = validatePrinterChange({ entity: 'PrinterDevice', action: 'create', record: {}, changes: { name: 'Device' } })
  assert.match(deviceEvidence.evidence.externalRegistration, /provider/)
  assert.equal(validatePrinterChange({ entity: 'PrinterDevice', action: 'update', record: {}, changes: {} }), null)

  assert.doesNotThrow(() => validatePrinterChange({
    entity: 'PrinterTemplate',
    action: 'update',
    record: { kind: 'receipt', ticketMode: 'single', itemFilter: null },
    changes: { definition: { kind: 'receipt', nodes: [] }, sampleData: { order: {} } }
  }))
  assert.throws(() => validatePrinterChange({
    entity: 'PrinterTemplate',
    action: 'update',
    record: { kind: 'receipt' },
    changes: { definition: { kind: 'receipt', nodes: [] } }
  }), /sampleData/)
  assert.throws(() => validatePrinterChange({
    entity: 'PrinterTemplate',
    action: 'update',
    record: { kind: 'receipt' },
    changes: { definition: { kind: 'label', nodes: [] }, sampleData: {} }
  }), /kind/)
  assert.throws(() => validatePrinterChange({
    entity: 'PrinterTemplate',
    action: 'update',
    record: { kind: 'receipt' },
    changes: { itemFilter: 42 }
  }), /item filter/)
  assert.equal(validatePrinterChange({ entity: 'PrinterDevice', action: 'delete', record: {}, changes: {} }), null)
  assert.equal(validatePrinterChange({ entity: 'Product', action: 'update', record: {}, changes: {} }), null)
})

test('printer tools preview a stored job without submitting anything', async() => {
  const request = fakeRequest({
    get: path => {
      assert.equal(path, `/api/v1/manage/printer/jobs/${uuid}/preview`)
      return { data: { document: [{ command: 'text', value: 'Hello' }], copies: 2 } }
    }
  })
  const workbench = createPrinterWorkbench({ request, isAvailable: () => true, path: '/api/v1/manage/printer' })
  assert.deepEqual(workbench.getDraftTools().map(item => item.function.name), ['read_printer_setting', 'preview_printer_paper', 'refresh_printer_status'])
  const result = await workbench.runDraftTool('preview_printer_paper', { jobUuid: uuid })
  assert.equal(result.mode, 'job')
  assert.equal(result.copies, 2)
  assert.deepEqual(result.document, [{ command: 'text', value: 'Hello' }])
  assert.equal(request.calls.length, 1)
  assert.equal(request.calls[0].method, 'get')
  await assert.rejects(workbench.runDraftTool('preview_printer_paper', { jobUuid: uuid, templateUuid: otherUuid }), /exactly one/)
  await assert.rejects(workbench.runDraftTool('preview_printer_paper', { jobUuid: uuid, data: {} }), /only an exact job/)
})

test('printer template preview loads the saved definition and renders bounded sample data', async() => {
  const request = fakeRequest({
    get: path => {
      assert.equal(path, `/api/v1/manage/printer/templates/${uuid}`)
      return { data: { uuid, kind: 'receipt', ticketMode: 'single', definition: { kind: 'receipt', nodes: [] } } }
    },
    post: (path, body) => {
      assert.equal(path, '/api/v1/manage/printer/preview')
      assert.deepEqual(body, { template: { kind: 'receipt', nodes: [] }, data: { order: { number: 'A1' } } })
      return { data: { document: 'receipt document', warnings: ['approximate'] } }
    }
  })
  const workbench = createPrinterWorkbench({ request, isAvailable: () => true })
  const result = await workbench.runDraftTool('preview_printer_paper', { templateUuid: uuid, data: { order: { number: 'A1' } } })
  assert.equal(result.mode, 'template')
  assert.equal(result.kind, 'receipt')
  assert.equal(result.document, 'receipt document')
  assert.deepEqual(result.warnings, ['approximate'])
  await assert.rejects(workbench.runDraftTool('preview_printer_paper', { templateUuid: uuid }), /requires an exact template UUID/)
})

test('printer status refresh targets one exact device or job and reports selected fields', async() => {
  const request = fakeRequest({
    post: (path) => {
      assert.equal(path, `/api/v1/manage/printer/devices/${uuid}/status`)
      return { data: { uuid, name: 'Front printer', enabled: true, status: 'online', statusCheckedAt: '2026-11-12T00:00:00+00:00', secret: 'hidden' } }
    }
  })
  const workbench = createPrinterWorkbench({ request, isAvailable: () => true })
  const result = await workbench.runDraftTool('refresh_printer_status', { entity: 'PrinterDevice', identifier: uuid })
  assert.deepEqual(result, {
    entity: 'PrinterDevice',
    identifier: uuid,
    record: { uuid, name: 'Front printer', enabled: true, status: 'online', statusCheckedAt: '2026-11-12T00:00:00+00:00' }
  })
  assert.equal(request.calls[0].method, 'post')
  assert.deepEqual(request.calls[0].body, {})
  await assert.rejects(workbench.runDraftTool('refresh_printer_status', { entity: 'PrinterJob', identifier: '1' }), /exact UUID/)
  await assert.rejects(workbench.runDraftTool('refresh_printer_status', { entity: 'PrinterAccount', identifier: uuid }), /Status refresh requires/)
})

test('printer tools disappear when the printer pages are unavailable', async() => {
  const request = fakeRequest()
  const workbench = createPrinterWorkbench({ request, isAvailable: () => false })
  assert.deepEqual(workbench.getDraftTools(), [])
  await assert.rejects(workbench.runDraftTool('preview_printer_paper', { jobUuid: uuid }), /not available/)
  await assert.rejects(workbench.runDraftTool('refresh_printer_status', { entity: 'PrinterJob', identifier: uuid }), /not available/)
})

test('the tool list is fixed, bounded and free of live print or reprint tools', () => {
  assert.deepEqual(printerTools.map(item => item.function.name), ['read_printer_setting', 'preview_printer_paper', 'refresh_printer_status'])
  for (const item of printerTools) {
    assert.equal(item.type, 'function')
    assert.equal(item.function.parameters.additionalProperties, false)
    assert.equal(/print_job|reprint|submit_print/.test(item.function.name), false)
  }
})

test('printer policy opens settings writes without opening credentials, jobs, reprint or deletion', () => {
  assert.equal(isAgentActionAllowed('PrinterDevice', 'create'), true)
  assert.equal(isAgentActionAllowed('PrinterDevice', 'update'), true)
  assert.equal(isAgentActionAllowed('PrinterDevice', 'delete'), false)
  assert.equal(isAgentActionAllowed('PrinterTemplate', 'create'), true)
  assert.equal(isAgentActionAllowed('PrinterTemplate', 'update'), true)
  assert.equal(isAgentActionAllowed('PrinterTemplate', 'delete'), false)
  assert.equal(isAgentActionAllowed('PrinterOrderRule', 'create'), true)
  assert.equal(isAgentActionAllowed('PrinterOrderRule', 'update'), true)
  assert.equal(isAgentActionAllowed('PrinterOrderRule', 'delete'), false)
  assert.equal(isAgentActionAllowed('PrinterAccount', 'create'), false)
  assert.equal(isAgentActionAllowed('PrinterAccount', 'update'), false)
  assert.equal(isAgentActionAllowed('PrinterJob', 'create'), false)
  assert.equal(isAgentActionAllowed('PrinterJob', 'update'), false)
  assert.equal(isAgentFieldAllowed('PrinterDevice', 'enabled', 'create'), false)
  assert.equal(isAgentFieldAllowed('PrinterDevice', 'enabled', 'update'), true)
  assert.equal(isAgentFieldAllowed('PrinterDevice', 'account', 'update'), false)
  assert.equal(isAgentFieldAllowed('PrinterDevice', 'account', 'create'), true)
  assert.equal(isAgentFieldAllowed('PrinterTemplate', 'kind', 'update'), false)
  assert.equal(isAgentFieldAllowed('PrinterTemplate', 'itemFilter', 'create'), true)
  assert.equal(isAgentFieldAllowed('PrinterAccount', 'userKey', 'create'), false)
  assert.equal(agentMutationPolicy.isBatchAllowed('PrinterDevice', 'create'), false)
  assert.equal(agentMutationPolicy.isBatchAllowed('PrinterTemplate', 'create'), true)
})

test('printer settings reads expose bounded rule and template data without account credentials', async() => {
  const request = fakeRequest({
    get: path => {
      if (path.endsWith(`/accounts/${uuid}`)) {
        return { data: { uuid, provider: 'xpyun', name: 'Account', developerId: 'dev', enabled: true, createdAt: '2026-11-12T00:00:00+00:00', userKey: 'secret', credentialsCipher: 'cipher' } }
      }
      if (path.endsWith(`/order-rules/${uuid}`)) {
        return { data: { uuid, storeUuid: otherUuid, name: 'Rule', enabled: true, events: ['paid'], match: 'all', conditions: [{ field: 'status', operator: 'equals', value: 'paid' }], deviceUuid: uuid, templateUuid: otherUuid, copies: 2 } }
      }
      if (path.endsWith(`/templates/${uuid}`)) {
        return { data: { uuid, name: 'Template', kind: 'receipt', ticketMode: 'single', itemFilter: null, definition: { kind: 'receipt', nodes: [] }, enabled: true } }
      }
      throw new Error(`Unexpected path: ${path}`)
    }
  })
  const workbench = createPrinterWorkbench({ request, isAvailable: () => true })
  const account = await workbench.runDraftTool('read_printer_setting', { entity: 'PrinterAccount', identifier: uuid })
  assert.deepEqual(account.fields, { uuid, provider: 'xpyun', name: 'Account', developerId: 'dev', enabled: true, createdAt: '2026-11-12T00:00:00+00:00' })
  assert.equal(JSON.stringify(account).includes('secret'), false)
  assert.equal(JSON.stringify(account).includes('cipher'), false)

  const rule = await workbench.runDraftTool('read_printer_setting', { entity: 'PrinterOrderRule', identifier: uuid })
  assert.deepEqual(rule.fields.events, ['paid'])
  assert.deepEqual(rule.fields.conditions, [{ field: 'status', operator: 'equals', value: 'paid' }])
  assert.equal(rule.fields.copies, 2)

  const template = await workbench.runDraftTool('read_printer_setting', { entity: 'PrinterTemplate', identifier: uuid })
  assert.deepEqual(template.fields.definition, { kind: 'receipt', nodes: [] })
  await assert.rejects(workbench.runDraftTool('read_printer_setting', { entity: 'Setting', identifier: uuid }), /supported entity/)
  await assert.rejects(workbench.runDraftTool('read_printer_setting', { entity: 'PrinterAccount', identifier: '1' }), /exact UUID/)
})

test('device creation refuses a disabled or unreadable printer account before preview', async() => {
  const disabled = createPrinterWorkbench({
    request: fakeRequest({ get: () => ({ data: { id: 7, name: 'Disabled', enabled: false } }) }),
    isAvailable: () => true
  })
  await assert.rejects(disabled.beforeChange({
    entity: 'PrinterDevice', action: 'create', record: {}, changes: { account: 7 }
  }), /enabled printer account/)

  const enabled = createPrinterWorkbench({
    request: fakeRequest({ get: () => ({ data: { id: 7, name: 'Enabled', enabled: true } }) }),
    isAvailable: () => true
  })
  const result = await enabled.beforeChange({
    entity: 'PrinterDevice', action: 'create', record: {}, changes: { account: 7 }
  })
  assert.match(result.evidence.externalRegistration, /provider/)
  await assert.rejects(enabled.beforeChange({
    entity: 'PrinterDevice', action: 'create', record: {}, changes: { account: 0 }
  }), /valid printer account/)
})

test('rule preparation checks store ownership and device/template kind before preview', async() => {
  const calls = []
  const request = fakeRequest({
    get: path => {
      calls.push(path)
      if (path.endsWith(`/devices/${uuid}`)) return { data: { uuid, storeUuid: otherUuid, kind: 'receipt' } }
      if (path.endsWith(`/templates/${otherUuid}`)) return { data: { uuid: otherUuid, kind: 'receipt' } }
      throw new Error(`Unexpected path: ${path}`)
    }
  })
  const workbench = createPrinterWorkbench({ request, isAvailable: () => true })
  const evidence = await workbench.beforeChange({
    entity: 'PrinterOrderRule',
    action: 'create',
    record: {},
    changes: validRule({ storeUuid: otherUuid, deviceUuid: uuid, templateUuid: otherUuid })
  })
  assert.equal(evidence.evidence.scope, 'future_automatic_printing')
  assert.deepEqual(calls.sort(), [`/api/v1/manage/printer/devices/${uuid}`, `/api/v1/manage/printer/templates/${otherUuid}`])

  const wrongStore = createPrinterWorkbench({
    request: fakeRequest({
      get: path => path.includes('/devices/')
        ? { data: { uuid, storeUuid: 'another-store', kind: 'receipt' } }
        : { data: { uuid: otherUuid, kind: 'receipt' } }
    }),
    isAvailable: () => true
  })
  await assert.rejects(wrongStore.beforeChange({
    entity: 'PrinterOrderRule', action: 'create', record: {}, changes: validRule({ storeUuid: otherUuid })
  }), /same store/)

  const wrongKind = createPrinterWorkbench({
    request: fakeRequest({
      get: path => path.includes('/devices/')
        ? { data: { uuid, storeUuid: otherUuid, kind: 'label' } }
        : { data: { uuid: otherUuid, kind: 'receipt' } }
    }),
    isAvailable: () => true
  })
  await assert.rejects(wrongKind.beforeChange({
    entity: 'PrinterOrderRule', action: 'create', record: {}, changes: validRule({ storeUuid: otherUuid })
  }), /kind matches/)
})
