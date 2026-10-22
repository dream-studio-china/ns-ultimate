import assert from 'node:assert/strict'
import test from 'node:test'
import { toSafeCsv } from '../src/export.js'

test('exports selected safe values with CSV escaping and spreadsheet formula protection', () => {
  const csv = toSafeCsv([
    { name: 'Tea, "green"', amount: 25, note: '=HYPERLINK("https://evil")' },
    { name: 'Coffee', amount: -10, note: 'ordinary text' }
  ], ['name', 'amount', 'note'], new Map([['name', 'Product name']]))
  assert.equal(csv, '\uFEFF"Product name","amount","note"\r\n"Tea, ""green""","25","\'=HYPERLINK(""https://evil"")"\r\n"Coffee","\'-10","ordinary text"')
})

test('rejects non-array export inputs', () => {
  assert.throws(() => toSafeCsv({}, []), /requires records/)
})
