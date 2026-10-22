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
  for (const privateValue of [
    'contract-private-payment-rate',
    'contract-private-custom-root',
    'contract-private-printing-note',
    'contract-private-unknown-case',
    'contract-private-product-note',
    'contract-synthetic-cipher-not-a-credential'
  ]) {
    assert.equal(serializedCases.includes(privateValue), false, `Private fixture value must not be serialized into previews: ${privateValue}`)
  }
  for (const privateKey of ['paymentRate', 'customRoot', 'legacyNote', 'unknownCase', 'customProductNote']) {
    assert.equal(serializedCases.includes(privateKey), false, `Unrelated settings field must not be serialized into previews: ${privateKey}`)
  }
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
    'Specification:create','Specification:update','StorePrinting:update','ProductPrinting:update',
  ]) {
    assert.ok(coverage.has(expected), `Missing backend preview coverage: ${expected}`)
    assert.ok(counts.get(expected) >= 2, `Expected prepared and restored previews for ${expected}`)
  }
  const entities = new Set(cases.map(({ preview }) => preview.entity))
  assert.ok(coverage.size >= 22, `Expected at least 22 entity/action combinations, got ${coverage.size}`)
  assert.ok(entities.size >= 10, `Expected at least 10 entities, got ${entities.size}`)
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

  const printingCaseFields = ['enabled', 'deviceUuid', 'templateUuid', 'copies']
  const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
  const storePrintingCases = cases.filter(({ preview }) => preview.entity === 'StorePrinting' && preview.action === 'update')
  assert.ok(storePrintingCases.length >= 4, `Expected prepared and restored StorePrinting:update previews, got ${storePrintingCases.length}`)
  const storePrintingDifferences = storePrintingCases.flatMap(({ preview }) => preview.differences)
  for (const difference of storePrintingDifferences) {
    assert.deepEqual(Object.keys(difference.after), printingCaseFields, 'StorePrinting after must expose the stable four-field effective case')
    if (difference.before !== null) {
      assert.deepEqual(Object.keys(difference.before), printingCaseFields, 'StorePrinting before must be null or a bounded stored case')
    }
    assert.notDeepEqual(difference.before, difference.after, `StorePrinting ${difference.property} must be an effective change`)
    assert.equal(typeof difference.after.enabled, 'boolean')
    assert.ok(Number.isInteger(difference.after.copies) && difference.after.copies >= 1 && difference.after.copies <= 100)
    if (difference.after.enabled) {
      assert.equal(typeof difference.after.deviceUuid, 'string', 'An enabled StorePrinting case requires a device UUID')
      assert.equal(typeof difference.after.templateUuid, 'string', 'An enabled StorePrinting case requires a template UUID')
      assert.match(difference.after.deviceUuid, uuidPattern)
      assert.match(difference.after.templateUuid, uuidPattern)
    } else {
      assert.equal(difference.after.deviceUuid, null, 'A disabled StorePrinting case may clear its device reference')
      assert.equal(difference.after.templateUuid, null, 'A disabled StorePrinting case may clear its template reference')
    }
  }
  assert.deepEqual(
    [...new Set(storePrintingDifferences.map(({ property }) => property))].sort(),
    ['filteredList', 'fullList', 'order', 'perItem', 'settlement'].sort(),
    'StorePrinting fixture must cover all five fixed cases'
  )
  const storePrintingInputs = storePrintingCases.map(({ input }) => input.changes)
  assert.ok(storePrintingInputs.some(changes => JSON.stringify(changes.order) === JSON.stringify({ enabled: true })), 'StorePrinting must cover enabled-only partial patching on existing references')
  assert.ok(storePrintingInputs.some(changes => JSON.stringify(changes.perItem) === JSON.stringify({ copies: 3 })), 'StorePrinting must cover copies-only partial patching on an enabled case')
  assert.ok(storePrintingInputs.some(changes => changes.filteredList?.enabled === false
    && changes.filteredList?.deviceUuid === null && changes.filteredList?.templateUuid === null), 'StorePrinting must cover explicit disable-and-clear')
  const orderDifference = storePrintingDifferences.find(({ property }) => property === 'order')
  assert.equal(orderDifference.before.enabled, false)
  assert.equal(orderDifference.after.enabled, true)
  assert.equal(orderDifference.before.deviceUuid, orderDifference.after.deviceUuid)
  assert.equal(orderDifference.before.templateUuid, orderDifference.after.templateUuid)
  const perItemDifference = storePrintingDifferences.find(({ property }) => property === 'perItem')
  assert.equal(perItemDifference.before.copies, 2)
  assert.equal(perItemDifference.after.copies, 3)
  assert.equal(perItemDifference.after.enabled, true)
  const filteredDifference = storePrintingDifferences.find(({ property }) => property === 'filteredList')
  assert.equal(filteredDifference.before.enabled, true)
  assert.equal(filteredDifference.after.enabled, false)
  assert.equal(filteredDifference.after.deviceUuid, null)
  assert.equal(filteredDifference.after.templateUuid, null)
  for (const property of ['fullList', 'settlement']) {
    const difference = storePrintingDifferences.find(item => item.property === property)
    assert.ok(difference, `StorePrinting fixture must cover ${property}`)
    assert.equal(difference.before, null)
    assert.equal(difference.after.enabled, true)
    assert.equal(typeof difference.after.deviceUuid, 'string')
    assert.equal(typeof difference.after.templateUuid, 'string')
  }

  const productPrintingCases = cases.filter(({ preview }) => preview.entity === 'ProductPrinting' && preview.action === 'update')
  assert.ok(productPrintingCases.length >= 2, `Expected prepared and restored ProductPrinting:update previews, got ${productPrintingCases.length}`)
  for (const { preview, input } of productPrintingCases) {
    assert.deepEqual(preview.differences, [{ property: 'kitchenPrinterEnable', before: false, after: true }], 'ProductPrinting must expose only the boolean kitchen flag change')
    assert.deepEqual(input.changes, { kitchenPrinterEnable: true }, 'ProductPrinting must transport only the boolean kitchen flag')
  }
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
