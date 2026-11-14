import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { buildAdminConfigProjection } from '../../apps/admin-agent/src/admin-config-policy.js'
import { configuredCapabilities } from '../../apps/admin-agent/src/config-mutation-host.js'
import { componentBindings } from '../../apps/admin-agent/adapters/ns-ultimate/admin/config-bindings.mjs'
import { agentMutationPolicy } from '../../apps/admin-agent/adapters/ns-ultimate/admin/write-policy.mjs'

function load(relativePath, context = {}) {
  const source = readFileSync(new URL(`../../${relativePath}`, import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const sandbox = { t: key => key, ...context }
  runInNewContext(source, sandbox)
  return JSON.parse(JSON.stringify(sandbox.config))
}

function capability(config, name, plural) {
  const projection = buildAdminConfigProjection({
    entities: { [name]: config },
    routes: [{ path: `/${plural}/list`, name: `${name}List` }]
  }, { componentBindings })
  return configuredCapabilities(projection, agentMutationPolicy).find(item => item.entity === name)
}

test('device settings expose create and update without provider-controlled create fields', () => {
  const config = load('business/admin/config/collections/printer/PrinterDevice.js', {
    PrinterDeviceStatusAction: { name: 'PrinterDeviceStatusAction' }
  })
  const device = capability(config.PrinterDevice, 'PrinterDevice', 'devices')
  assert.deepEqual([...device.actions].sort(), ['create', 'update'])
  assert.deepEqual(Object.keys(device.actionFields.create).sort(), [
    'account', 'heightMm', 'kind', 'model', 'name', 'serialNumber', 'storeUuid', 'widthMm'
  ])
  assert.equal(Object.hasOwn(device.actionFields.create, 'enabled'), false)
  assert.deepEqual(Object.keys(device.actionFields.update).sort(), [
    'enabled', 'heightMm', 'model', 'name', 'storeUuid', 'widthMm'
  ])
  assert.equal(Object.hasOwn(device.actionFields.update, 'account'), false)
  assert.equal(Object.hasOwn(device.actionFields.update, 'serialNumber'), false)
  assert.equal(Object.hasOwn(device.actionFields.update, 'kind'), false)
})

test('template settings expose every editable field and keep kind immutable on update', () => {
  const config = load('business/admin/config/collections/printer/PrinterTemplate.js', {
    PrinterTemplateDesigner: { name: 'PrinterTemplateDesigner' },
    PrinterPaperAction: { name: 'PrinterPaperAction' }
  })
  const template = capability(config.PrinterTemplate, 'PrinterTemplate', 'templates')
  assert.deepEqual([...template.actions].sort(), ['create', 'update'])
  assert.deepEqual(Object.keys(template.actionFields.create).sort(), [
    'definition', 'itemFilter', 'kind', 'name', 'ticketMode'
  ])
  assert.deepEqual(Object.keys(template.actionFields.update).sort(), [
    'definition', 'itemFilter', 'name', 'ticketMode'
  ])
  assert.equal(Object.hasOwn(template.actionFields.update, 'kind'), false)
})

test('account and job configurations never become assistant writes', () => {
  const account = load('business/admin/config/collections/printer/PrinterAccount.js')
  assert.equal(capability(account.PrinterAccount, 'PrinterAccount', 'accounts'), undefined)
  const job = load('business/admin/config/collections/printer/PrinterJob.js', {
    PrinterJobAction: { name: 'PrinterJobAction' },
    PrinterPaperAction: { name: 'PrinterPaperAction' },
    PrinterJobReprintAction: { name: 'PrinterJobReprintAction' }
  })
  assert.equal(job.PrinterJob.list.query['@order'], 'entity.createdAt|DESC, entity.id|DESC')
  assert.equal(capability(job.PrinterJob, 'PrinterJob', 'jobs'), undefined)
})
