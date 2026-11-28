import assert from 'node:assert/strict'
import test from 'node:test'
import { counterListQuery, counterRouteUuid, createLatestRequest, groupHistory, mergeLayout, orderParams } from './counter-orders.mjs'

test('counter deep links accept only a single UUID and parent links retain other query values', () => {
  const uuid = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE'
  assert.equal(counterRouteUuid(uuid), uuid.toLowerCase())
  assert.equal(counterRouteUuid([uuid]), '')
  assert.equal(counterRouteUuid('not-a-uuid'), '')
  assert.deepEqual(counterListQuery({ counter: uuid, tab: 'history' }), { tab: 'history' })
})

test('serializes combined filters with an exclusive end date', () => {
  const params = orderParams({ order: ' #42 ', user: '', status: ['cancelled', 'merged'], start: '2026-11-01', end: '2026-11-01', anonymous: 'yes' })
  assert.equal(params.order, '#42')
  assert.equal(params.status, 'cancelled,merged')
  assert.equal(params.anonymous, 'yes')
  assert.equal(params.user, undefined)
  assert.ok(Date.parse(params.before) > Date.parse(params.from))
  assert.equal(params.before, new Date(2026, 10, 2).toISOString().replace('.000Z', 'Z'))
})

test('joins history across pages by session without losing row order', () => {
  const rows = [{ uuid: 'a', sessionUuid: 's1' }, { uuid: 'b', sessionUuid: 's2' }, { uuid: 'c', sessionUuid: 's1' }, { uuid: 'd', sessionUuid: null }]
  assert.deepEqual(groupHistory(rows).map(group => group.orders.map(row => row.uuid)), [['a', 'c'], ['b'], ['d']])
})

test('lays out each merge generation and preserves standalone receipts', () => {
  const nodes = ['a', 'b', 'c', 'd'].map(uuid => ({ uuid }))
  const layout = mergeLayout(nodes, [{ source: 'a', target: 'b' }, { source: 'b', target: 'c' }])
  assert.ok(layout.positions.get('a').x < layout.positions.get('b').x)
  assert.ok(layout.positions.get('b').x < layout.positions.get('c').x)
  assert.ok(layout.positions.has('d'))
  assert.equal(layout.paths.length, 2)
})

test('does not apply a response after the user selects a different order', async () => {
  const channel = createLatestRequest()
  const results = []
  let resolve
  const first = channel.run(() => new Promise(done => { resolve = done }), value => results.push(value), assert.fail, () => {})
  await channel.run(async () => 'new', value => results.push(value), assert.fail, () => {})
  resolve('old')
  await first
  assert.deepEqual(results, ['new'])
})
