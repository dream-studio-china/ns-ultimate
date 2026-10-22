import assert from 'node:assert/strict'
import test from 'node:test'
import { formatInspectionOmissions } from '../src/inspection-display.js'
import en from '../src/locales/en.js'
import zh from '../src/locales/zh.js'

test('formats only bounded supported omission diagnostics for the UI', () => {
  const translate = key => `translated:${key}`
  assert.deepEqual(formatInspectionOmissions([
    { path: 'settings.printing.order.enabled', reason: 'missing' },
    { path: 'settings.printing.perItem', reason: 'null-ancestor' },
    { path: 'code', reason: 'invalid' },
    { path: 'name', reason: 'oversized' },
    { path: 'credentials.secret', reason: 'missing' },
    { path: 'bad', reason: 'arbitrary' },
    { path: 'x'.repeat(201), reason: 'missing' }
  ], translate), [
    'settings.printing.order.enabled: translated:Missing from response',
    'settings.printing.perItem: translated:Parent setting is null',
    'code: translated:Present but not a scalar value',
    'name: translated:Value exceeds inspection limit',
    'credentials.secret: translated:Missing from response'
  ])
  assert.equal(formatInspectionOmissions(Array.from({ length: 50 }, (_, i) => ({ path: `field${i}`, reason: 'missing' })), translate).length, 40)
  assert.deepEqual(formatInspectionOmissions(null, translate), [])
  for (const reason of ['constructor', 'toString', '__proto__']) {
    assert.deepEqual(formatInspectionOmissions([{ path: 'safe', reason }], translate), [])
  }
  assert.deepEqual(formatInspectionOmissions([{ path: 'safe', reason: 1 }], translate), [])
})

test('renders omission labels and truncation note using both shipped locales', () => {
  for (const locale of [en, zh]) {
    const t = key => locale[key] || key
    const displayed = formatInspectionOmissions([{ path: 'settings.value', reason: 'missing' }], t)
    assert.equal(displayed[0], `settings.value: ${t('Missing from response')}`)
    assert.ok(t('Some allowlisted fields were absent or invalid; this is not a permission failure or a disabled setting.').length > 20)
    assert.ok(t('Additional omission details were bounded and not shown.').length > 10)
    // Legacy count-only tool results have no diagnostic rows; the UI's count note remains translatable.
    assert.deepEqual(formatInspectionOmissions(undefined, t), [])
  }
})
