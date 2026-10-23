import test from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { compileMutationCapabilities } from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import { catalogResponse } from './support/capability-fixture.mjs'

const root = fileURLToPath(new URL('../../..', import.meta.url))
const fixture = path.join(root, 'apps/admin-agent/backend/tests/Contract/export-capability-catalog.php')

test('the checked-in capability fixture is the actual backend catalog export', async() => {
  const stdout = execFileSync('php', [fixture], {
    cwd: root, timeout: 60_000, maxBuffer: 4 * 1024 * 1024,
    env: { ...process.env, APP_ENV: 'test', APP_DEBUG: '0' },
    encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']
  })
  const exported = JSON.parse(stdout)
  assert.deepEqual(exported, catalogResponse, 'Regenerate tests/fixtures/capabilities/catalog.v1.json from the backend exporter.')
  const compiled = compileMutationCapabilities(exported)
  assert.equal(compiled.schemaVersion, 1)
  assert.match(compiled.revision, /^[0-9a-f]{64}$/)
  assert.ok(compiled.capabilities.length > 0)
  assert.deepEqual(compiled.batchCapabilities.map(item => item.entity), ['Counter', 'Tag'])
  for (const capability of exported.capabilities) {
    if (!capability.actions.includes('delete')) continue
    assert.ok(capability.deletePreviewFields && Object.keys(capability.deletePreviewFields).length > 0, `${capability.entity} must publish explicit delete preview fields.`)
  }
  for (const batch of exported.batchCapabilities) {
    assert.equal(Object.hasOwn(batch, 'deletePreviewFields'), false, `${batch.entity} batch descriptors must not publish delete preview fields.`)
  }
  const tag = exported.capabilities.find(item => item.entity === 'Tag')
  assert.deepEqual(Object.keys(tag.deletePreviewFields), ['name', 'slug', 'color'])
  const serialized = JSON.stringify(compiled.capabilities)
  assert.equal(serialized.includes('validateChanges'), false)
  assert.equal(serialized.includes('validatePreview'), false)
  assert.equal(serialized.includes('deletePreviewFields'), false)
})
