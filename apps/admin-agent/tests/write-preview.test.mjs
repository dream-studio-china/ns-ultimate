import assert from 'node:assert/strict'
import test from 'node:test'
const policy = { Counter: { name: { label: 'Name', type: 'string', required: true, maxLength: 120 } } }
import { createWritePreview } from '../src/write-preview.js'
import { createMutationHost } from '../src/mutation-host.js'
import { createAssistantTools, executeAssistantTool } from '../src/tools.js'
import previewPolicy from '../adapters/ns-ultimate/admin/preview-policy.mjs'
import mutationCapabilities from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'

const snapshots = new Map([['Counter', { observedAt: '2026-10-22T00:00:00Z', records: [{ id: 7, name: 'Before' }] }]])
const input = { entity: 'Counter', identifier: '7', changes: { name: 'After' } }

test('previews an exact observed record without calling any host operation', async() => {
  const call = { type: 'function', function: { name: 'preview_record_update', arguments: JSON.stringify(input) } }
  const result = await executeAssistantTool({ call, catalog: [], previewPolicy: policy, snapshots, host: new Proxy({}, { get() { throw new Error('Host must not be called') } }) })
  assert.equal(result.saved, false)
  assert.equal(result.previewOnly, true)
  assert.equal(result.affectedRecords, 1)
  assert.deepEqual(result.differences, [{ property: 'name', label: 'Name', before: 'Before', after: 'After', changed: true }])
  assert.equal(snapshots.get('Counter').records[0].name, 'Before')
})

test('requires opt-in entity/field policy and an unambiguous observed identifier', () => {
  for (const invalid of [
    { ...input, entity: 'Product' }, { ...input, identifier: 'Before' }, { ...input, identifier: '8' },
    { ...input, changes: { enabled: false } }, { ...input, changes: { name: '' } },
    { ...input, changes: { name: 123 } }, { ...input, changes: { name: 'x'.repeat(121) } },
    { ...input, changes: { name: { nested: 'value' } } }, { ...input, url: '/arbitrary' }
  ]) assert.throws(() => createWritePreview(invalid, policy, snapshots))
  assert.throws(() => createWritePreview(input, {}, snapshots))
  assert.throws(() => createWritePreview(input, policy, new Map()))
  assert.throws(() => createWritePreview(input, policy, new Map([['Counter', { records: [{ id: 7, name: 'A' }, { id: 7, name: 'B' }] }]])))
  assert.throws(() => createWritePreview(input, policy, new Map([['Counter', { records: [{ id: 7, name: 'x'.repeat(300) }] }]])))
})

test('uses host-declared trimmed Unicode codepoint limits for local previews', () => {
  const emojiPolicy = { Counter: { name: { label: 'Name', type: 'string', required: true, maxLength: 120, lengthUnit: 'codePoints', trimForValidation: true } } }
  const makeInput = name => ({ ...input, changes: { name } })
  assert.doesNotThrow(() => createWritePreview(makeInput('😀'.repeat(120)), emojiPolicy, snapshots))
  assert.throws(() => createWritePreview(makeInput('😀'.repeat(121)), emojiPolicy, snapshots), /invalid/)
  assert.doesNotThrow(() => createWritePreview(makeInput(`  ${'😀'.repeat(120)}  `), emojiPolicy, snapshots))
  assert.throws(() => createWritePreview(makeInput('   '), emojiPolicy, snapshots), /invalid/)
})

test('local Counter preview and host preparation agree on Unicode length and whitespace rules', async() => {
  const counterCapability = mutationCapabilities.find(item => item.entity === 'Counter')
  const host = createMutationHost({
    post: async(_path, payload) => ({ data: { saved: false, ...payload } })
  }, '/operations', [counterCapability])
  const localAccepts = name => assert.doesNotThrow(() => createWritePreview(
    { ...input, changes: { name } }, previewPolicy, snapshots
  ))
  const localRejects = name => assert.throws(() => createWritePreview(
    { ...input, changes: { name } }, previewPolicy, snapshots
  ))
  const hostAccepts = name => host.prepareRecordChange({ entity: 'Counter', action: 'update', identifier: '7', changes: { name } })
  const hostRejects = name => assert.rejects(
    host.prepareRecordChange({ entity: 'Counter', action: 'update', identifier: '7', changes: { name } }),
    /Changes contain unsupported/
  )

  localAccepts('😀'.repeat(120))
  await hostAccepts('😀'.repeat(120))
  localRejects('😀'.repeat(121))
  await hostRejects('😀'.repeat(121))
  localAccepts(`  ${'😀'.repeat(120)}  `)
  await hostAccepts(`  ${'😀'.repeat(120)}  `)
  localRejects('   ')
  await hostRejects('   ')
})

test('preview tool is opt-in and never exposes a submit tool', () => {
  assert.equal(createAssistantTools([]).some(tool => tool.function.name === 'preview_record_update'), false)
  assert.deepEqual(createAssistantTools([], [], policy).map(tool => tool.function.name), ['list_entities', 'preview_record_update'])
})
