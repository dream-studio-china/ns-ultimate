import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  commandIntent, decimalToMinor, draftLines, draftTotals, editorLines, lineError, listParams, localToday,
  minorToDecimal, newCommandId, reportParams, reportReady
} from './ledger-ui.mjs'

test('preserves exact money strings and rejects excess scale and overflow', () => {
  assert.equal(decimalToMinor('92233720368547758.07', 2), '9223372036854775807')
  assert.equal(minorToDecimal('9007199254740993', 2), '90071992547409.93')
  assert.equal(minorToDecimal('-5', 2), '-0.05')
  assert.throws(() => decimalToMinor('1.001', 2), /invalid_amount/)
  assert.throws(() => decimalToMinor('92233720368547758.08', 2), /invalid_amount/)
  assert.deepEqual(draftTotals([{ side: 'debit', amount: '1.10' }, { side: 'credit', amount: '1.1' }], 2), { debit: '1.10', credit: '1.10' })
})

test('a failed transport can replay the exact command, not replace its payload', () => {
  const crypto = { getRandomValues: bytes => bytes.fill(255) }
  assert.match(newCommandId(crypto), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
  const first = commandIntent(null, 'post', { id: 'a', revision: 1 }, () => 'one')
  assert.deepEqual(first.body, { id: 'a', revision: 1, command_id: 'one' })
  assert.strictEqual(commandIntent(first, 'post', { id: 'a', revision: 1 }, () => 'two'), first)
  assert.throws(() => commandIntent(first, 'post', { id: 'a', revision: 2 }, () => 'two'), /finance_pending_intent/)
})

test('journal lines round-trip between stored minor units and editable decimals', () => {
  const stored = [
    { position: 1, account_code: 'CASH', side: 'debit', amount: '9007199254740993', description: null },
    { position: 2, account_code: 'EQUITY', side: 'credit', amount: '9007199254740993', description: 'Opening' }
  ]
  const rows = editorLines(stored, 2)
  assert.deepEqual(rows.map(({ key, ...row }) => row), [
    { account_code: 'CASH', side: 'debit', amount: '90071992547409.93', description: '' },
    { account_code: 'EQUITY', side: 'credit', amount: '90071992547409.93', description: 'Opening' }
  ])
  assert.notEqual(rows[0].key, rows[1].key)
  // The form value is the command payload: no positions, exact minor units.
  assert.deepEqual(draftLines(rows, 2), stored.map(({ position, ...line }) => ({ ...line, description: line.description ?? '' })))
  assert.equal(lineError(rows, 2), '')
  assert.equal(lineError([], 2), 'invalid_lines')
  assert.equal(lineError([{ ...rows[0], account_code: '' }], 2), 'Check the highlighted fields')
  assert.equal(lineError([{ ...rows[0], amount: '1.001' }], 2), 'invalid_amount')
})

test('list parameters forward only whitelisted filters, never EasyAdmin @filter', () => {
  const params = listParams({
    filterData: { from: ' 2026-01-01 ', state: 'draft', account: '', journal: 'x', '@filter': 'entity.getId() > 0' },
    pager: { page: 2, limit: 50 },
    sort: { '@order': 'entity.code|DESC' },
    query: { '@filter': 'raw', fixed: '1' },
    filters: ['from', 'to', 'state', 'account']
  })
  assert.deepEqual(params, { fixed: '1', from: '2026-01-01', state: 'draft', page: '2', limit: '50' })
  assert.equal(listParams({ sort: { '@order': 'entity.code|ASC' }, sortable: true })['@order'], 'entity.code|ASC')
})

test('report pages reuse the first page cutoff only for the same filters', () => {
  assert.equal(reportReady('ledger', { from: '2026-01-01', to: '2026-01-31' }), false)
  assert.equal(reportReady('trial', { from: '2026-01-01', to: '2026-01-31', account: 'CASH' }), true)
  const first = reportParams('trial', { from: '2026-01-01', to: '2026-01-31', account: 'CASH', page: '1', limit: '20' }, null)
  assert.deepEqual(first.request, { from: '2026-01-01', to: '2026-01-31', page: '1', limit: '20' })
  const snapshot = { key: first.key, cutoff: '7' }
  assert.equal(reportParams('trial', { from: '2026-01-01', to: '2026-01-31', page: '2', limit: '20' }, snapshot).request.cutoff, '7')
  // A new first page or changed filters capture a fresh snapshot.
  assert.equal(reportParams('trial', { from: '2026-01-01', to: '2026-01-31', page: '1', limit: '20' }, snapshot).request.cutoff, undefined)
  assert.equal(reportParams('trial', { from: '2026-01-02', to: '2026-01-31', page: '2', limit: '20' }, snapshot).request.cutoff, undefined)
  assert.equal(reportParams('ledger', { from: '2026-01-01', to: '2026-01-31', account: 'CASH', page: '1', limit: '20' }, null).request.account, 'CASH')
})

test('book-local dates follow the book timezone', () => {
  assert.equal(localToday('Asia/Shanghai', new Date('2026-01-31T18:00:00Z')), '2026-02-01')
  assert.equal(localToday('UTC', new Date('2026-01-31T18:00:00Z')), '2026-01-31')
})
