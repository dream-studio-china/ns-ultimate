import test from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import capabilities from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import { createMutationHost } from '../src/mutation-host.js'

const root = fileURLToPath(new URL('../../..', import.meta.url))
const fixture = path.join(root, 'apps/admin-agent/backend/tests/Contract/export-mutation-previews.php')

test('real backend mutation previews round-trip through frontend capabilities', async () => {
  const stdout = execFileSync('php', [fixture], {
    cwd: root, timeout: 60_000, maxBuffer: 4 * 1024 * 1024,
    env: { ...process.env, APP_ENV: 'test', APP_DEBUG: '0' },
    encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
  })
  const cases = JSON.parse(stdout)
  assert.ok(Array.isArray(cases) && cases.length > 0, 'PHP fixture exporter must return preview cases')
  const coverage = new Set()
  const counts = new Map()
  const serializedCases = JSON.stringify(cases)
  assert.equal(serializedCases.includes('private@example.com'), false, 'Store contact data must not be serialized into previews')
  assert.equal(serializedCases.includes('Private City'), false, 'Store address data must not be serialized into previews')
  for (const entry of cases) {
    const { preview, input } = entry
    const key = `${preview.entity}:${preview.action}`
    coverage.add(key)
    counts.set(key, (counts.get(key) ?? 0) + 1)
    const capability = capabilities.find(item => item.entity === preview.entity)
    assert.ok(capability, `No host capability for ${key}`)
    assert.ok(capability.validatePreview(preview, capability), `Backend preview rejected: ${key} ${JSON.stringify(preview)}`)
    const requests = []
    const request = {
      post: async (...args) => { requests.push(['post', ...args]); return { data: preview } },
      get: async (...args) => { requests.push(['get', ...args]); return { data: preview } },
    }
    const host = createMutationHost(request, '/operations', capabilities)
    const restoreHost = createMutationHost({ ...request, post: async (...args) => { requests.push(['post', ...args]); throw new Error('Submission forbidden in preview contract test') } }, '/operations', capabilities)
    const operationId = preview.operationId
    await returnHostCases(host, restoreHost, preview, input, operationId, requests)
  }
  for (const expected of [
    'Content:create','Content:update','Content:delete', 'Counter:create','Counter:update','Counter:delete',
    'Store:update','Category:create','Category:update','Category:delete','Tag:create','Tag:update','Tag:delete',
    'ProductCategory:create','ProductCategory:update','ProductCategory:delete','Product:create','Product:update',
    'Specification:create','Specification:update',
  ]) {
    assert.ok(coverage.has(expected), `Missing backend preview coverage: ${expected}`)
    assert.ok(counts.get(expected) >= 2, `Expected prepared and restored previews for ${expected}`)
  }
  const allDifferences = cases.flatMap(({ preview }) => preview.differences)
  const assertLongName = (entity, action) => {
    const matching = cases.filter(({ preview }) => preview.entity === entity && preview.action === action
      && preview.differences.some(({ property, after }) => property === 'name' && typeof after === 'string' && after.startsWith('Product #5 size ')))
    assert.ok(matching.length >= 2, `Expected prepared and restored ${entity}:${action} previews`)
    for (const { preview } of matching) {
      const name = preview.differences.find(({ property }) => property === 'name')?.after
      assert.equal(typeof name, 'string', `${entity}:${action} must expose its actual changed name`)
      assert.ok(name.startsWith('Product #5 size '), `${entity}:${action} must preserve the Product #5 size text`)
      assert.equal([...name].length, 255, `${entity}:${action} name must be exactly 255 Unicode code points`)
      assert.ok([...preview.title].length <= 1024, `${entity}:${action} title must satisfy the frontend preview bound`)
      assert.ok(preview.title.includes(name), `${entity}:${action} title must preserve its Unicode name`)
    }
  }
  assertLongName('Product', 'create')
  assertLongName('Specification', 'create')
  assertLongName('Specification', 'update')
  assert.ok(allDifferences.some(({ property, after }) => property === 'category' && Number.isInteger(after)), 'Content preview must carry its real Category relation')
  assert.ok(allDifferences.some(({ property, after }) => property === 'tags' && Array.isArray(after) && after.length === 1), 'Content preview must carry its real Tag relation')
  assert.ok(allDifferences.some(({ property, before }) => property === 'contact' && before === null), 'Store contact preview must redact its before snapshot')
  assert.ok(allDifferences.some(({ property, before, after }) => property === 'address' && before === null && after?.city === 'New City'), 'Store address preview must redact its before snapshot')
  assert.ok(allDifferences.some(({ property, after }) => property === 'address' && after === null), 'Store address clearing preview must be covered')
  assert.ok(allDifferences.some(({ property, after }) => property === 'contact' && after === null), 'Store contact clearing preview must be covered')
  const productCategoryScopes = cases.filter(({ preview }) => preview.entity === 'ProductCategory' && preview.action === 'create')
    .flatMap(({ preview }) => preview.differences).filter(({ property }) => property === 'store').map(({ after }) => after)
  assert.ok(productCategoryScopes.includes(null) && productCategoryScopes.some(value => Number.isInteger(value) && value > 0), 'ProductCategory previews must cover global and store-scoped assignment')
  const productScopes = cases.filter(({ preview }) => preview.entity === 'Product' && preview.action === 'create')
    .flatMap(({ preview }) => preview.differences).filter(({ property }) => property === 'store').map(({ after }) => after)
  assert.ok(productScopes.includes(null) && productScopes.some(value => typeof value === 'string' && /^[0-9a-f-]{36}$/i.test(value)), 'Product previews must cover global and store-scoped creation')
})

async function returnHostCases(host, restoreHost, preview, input, operationId, requests) {
  // Restore is validated by the real host; prepare is exercised against a fake
  // authenticated transport returning the backend's exact response shape.
  const restored = await restoreHost.restoreRecordChange(operationId)
  assert.deepEqual(restored, preview)
  const preparedInput = preview.action === 'delete' ? { ...input } : input
  if (preview.action === 'delete') delete preparedInput.changes
  let prepared
  try { prepared = await host.prepareRecordChange(preparedInput) }
  catch (error) { error.message = `${preview.entity}:${preview.action} ${error.message}`; throw error }
  assert.deepEqual(prepared, preview)
  assert.equal(requests.some(([method, url]) => method === 'post' && String(url).includes('/commit')), false)
}
