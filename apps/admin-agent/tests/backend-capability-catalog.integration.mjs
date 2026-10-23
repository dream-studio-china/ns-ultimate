import test from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { compileMutationCapabilities } from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'

const root = fileURLToPath(new URL('../../..', import.meta.url))
const actualExporter = path.join(root, 'apps/admin-agent/backend/tests/Contract/export-capability-catalog.php')
const legacyExporter = path.join(root, 'apps/admin-agent/backend/tests/Contract/export-legacy-capability-catalog.php')
const configuredFixture = path.join(root, 'apps/admin-agent/tests/fixtures/capabilities/configured-catalog.v1.json')
const legacyFixture = path.join(root, 'apps/admin-agent/tests/fixtures/capabilities/catalog.v1.json')

function runExporter(exporter) {
  const stdout = execFileSync('php', [exporter], {
    cwd: root,
    timeout: 60_000,
    maxBuffer: 4 * 1024 * 1024,
    env: { ...process.env, APP_ENV: 'test', APP_DEBUG: '0' },
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  })
  return JSON.parse(stdout)
}

function assertCommonEnvelope(response, expectedCount) {
  const compiled = compileMutationCapabilities(response)
  assert.equal(compiled.schemaVersion, 1)
  assert.match(compiled.revision, /^[0-9a-f]{64}$/)
  assert.equal(compiled.capabilities.length, expectedCount)
  assert.deepEqual(compiled.batchCapabilities.map(item => item.entity), ['Counter', 'Tag'])
  for (const capability of response.capabilities) {
    if (!capability.actions.includes('delete')) continue
    assert.ok(capability.deletePreviewFields && Object.keys(capability.deletePreviewFields).length > 0,
      `${capability.entity} must publish explicit delete preview fields.`)
  }
  for (const batch of response.batchCapabilities) {
    assert.equal(Object.hasOwn(batch, 'deletePreviewFields'), false,
      `${batch.entity} batch descriptors must not publish delete preview fields.`)
  }
  const serialized = JSON.stringify(compiled.capabilities)
  assert.equal(serialized.includes('validateChanges'), false)
  assert.equal(serialized.includes('validatePreview'), false)
  assert.equal(serialized.includes('deletePreviewFields'), false)
}

test('the actual manifest exporter matches the configured capability snapshot', () => {
  const exported = runExporter(actualExporter)
  const checkedIn = JSON.parse(readFileSync(configuredFixture, 'utf8'))
  assert.deepEqual(exported, checkedIn,
    'Regenerate tests/fixtures/capabilities/configured-catalog.v1.json from the actual backend exporter.')
  assertCommonEnvelope(exported, 9)

  assert.deepEqual(exported.capabilities.map(capability => capability.entity), [
    'Content', 'Counter', 'Store', 'Category', 'ProductCategory',
    'Tag', 'Product', 'StorePrinting', 'ProductPrinting'
  ])
  const byEntity = Object.fromEntries(exported.capabilities.map(capability => [capability.entity, capability]))

  assert.equal(Object.hasOwn(byEntity.Specification || {}, 'entity'), false)
  assert.deepEqual(Object.keys(byEntity.Content.actionFields.create), ['title', 'body', 'category', 'tags'])
  assert.deepEqual(Object.keys(byEntity.Content.actionFields.update), ['title', 'body', 'category', 'tags'])
  assert.ok(byEntity.ProductCategory.actionFields.create.name)
  assert.ok(byEntity.ProductCategory.actionFields.update.name)
  assert.ok(byEntity.StorePrinting)
  assert.ok(byEntity.ProductPrinting)
  assert.deepEqual(Object.keys(byEntity.StorePrinting.actionFields.update), ['order', 'perItem', 'filteredList', 'fullList', 'settlement'])
  assert.deepEqual(Object.keys(byEntity.ProductPrinting.actionFields.update), ['kitchenPrinterEnable'])

  const tag = byEntity.Tag
  assert.deepEqual(Object.keys(tag.deletePreviewFields), ['name', 'slug', 'color'])
})

test('the legacy all-ten safety fixture matches the explicit synthetic legacy exporter', () => {
  const exported = runExporter(legacyExporter)
  const checkedIn = JSON.parse(readFileSync(legacyFixture, 'utf8'))
  assert.deepEqual(exported, checkedIn,
    'Regenerate tests/fixtures/capabilities/catalog.v1.json from the legacy safety exporter.')
  assertCommonEnvelope(exported, 10)

  const byEntity = Object.fromEntries(exported.capabilities.map(capability => [capability.entity, capability]))
  assert.deepEqual(exported.capabilities.map(capability => capability.entity), [
    'Content', 'Counter', 'Store', 'Category', 'ProductCategory',
    'Tag', 'Product', 'Specification', 'StorePrinting', 'ProductPrinting'
  ])
  assert.ok(Object.keys(byEntity.Content.actionFields.create).includes('metadata'))
  assert.ok(Object.keys(byEntity.Content.actionFields.create).includes('sortOrder'))
  assert.ok(byEntity.Specification)
  assert.ok(byEntity.StorePrinting)
  assert.ok(byEntity.ProductPrinting)
})
