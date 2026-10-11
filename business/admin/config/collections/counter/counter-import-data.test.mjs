import assert from 'node:assert/strict'
import test from 'node:test'
import { nextCounterCode, projectCounterRecords } from './counter-import-data.mjs'

test('projects source rows to only name and sort while ignoring unrelated properties', () => {
  const rows = projectCounterRecords([
    { id: 23, name: ' 内1(小) ', listOrder: 1, enabled: false, store: { id: 5 }, metadata: { secret: true } }
  ])

  assert.deepEqual(rows, [{
    key: '0-内1(小)',
    name: '内1(小)',
    sort: 1,
    valid: true,
    duplicateName: false,
    issue: ''
  }])
})

test('marks invalid and duplicate source entries as unavailable', () => {
  const rows = projectCounterRecords([
    { name: 'A', listOrder: 1 },
    { name: 'a', listOrder: 2 },
    { name: 'B', listOrder: -1 },
    { name: '', listOrder: 4 }
  ])

  assert.deepEqual(rows.map(({ valid, issue }) => [valid, issue]), [
    [true, ''],
    [false, 'duplicate-source-name'],
    [false, 'invalid-sort'],
    [false, 'invalid-name']
  ])
})

test('rejects an empty or non-array source', () => {
  assert.throws(() => projectCounterRecords([]), /non-empty JSON array/)
  assert.throws(() => projectCounterRecords({}), /non-empty JSON array/)
})

test('generates store-unique codes and skips existing codes', () => {
  const usedCodes = new Set(['T001', 'CUSTOM', 'T003', 't004'])
  assert.equal(nextCounterCode(usedCodes), 'T002')
  usedCodes.add('T002')
  assert.equal(nextCounterCode(usedCodes), 'T005')
})
