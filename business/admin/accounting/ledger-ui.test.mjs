import { test } from 'node:test'
import assert from 'node:assert/strict'
import { commandIntent, decimalToMinor, draftTotals, minorToDecimal, newCommandId } from './ledger-ui.mjs'

test('preserves exact money strings and rejects excess scale and overflow', () => {
  assert.equal(decimalToMinor('92233720368547758.07', 2), '9223372036854775807')
  assert.equal(minorToDecimal('-9223372036854775807', 2), '-92233720368547758.07')
  assert.equal(decimalToMinor('1.2', 2), '120')
  for (const input of ['0.00', '1.001', '-1', '1e2', '92233720368547758.08']) {
    assert.throws(() => decimalToMinor(input, 2), /invalid_amount/)
  }
  assert.deepEqual(draftTotals([{ side: 'debit', amount: '0.10' }, { side: 'debit', amount: '0.20' }], 2), { debit: '0.30', credit: '0.00' })
})

test('generates RFC 4122 command IDs without randomUUID', () => {
  assert.equal(newCommandId({ getRandomValues: bytes => bytes.fill(255) }), 'ffffffff-ffff-4fff-bfff-ffffffffffff')
})

test('a failed transport can replay the exact command, not replace its payload', () => {
  const first = commandIntent(null, 'post', { id: 'a', revision: 1 }, () => 'one')
  assert.strictEqual(commandIntent(first, 'post', { id: 'a', revision: 1 }, () => 'two'), first)
  assert.throws(() => commandIntent(first, 'post', { id: 'a', revision: 2 }, () => 'two'), /finance_pending_intent/)
})
