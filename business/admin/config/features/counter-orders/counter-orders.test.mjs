import assert from 'node:assert/strict'
import test from 'node:test'
import { counterListQuery, counterRouteUuid, createGraphPan, createLatestRequest, graphFitScale, groupHistory, mergeLayout, orderParams, states, statusTone } from './counter-orders.mjs'

test('fits small overflows without shrinking wide graphs past the readable floor', () => {
  assert.equal(graphFitScale(1000, 900), 1)
  assert.equal(graphFitScale(900, 1000), 0.9)
  assert.equal(graphFitScale(400, 1000), 0.8)
  assert.equal(graphFitScale(0, 1000), 1)
})

test('each supported Trade status has its own badge tone, with cancelled in gray', () => {
  assert.equal(new Set(states.status.map(statusTone)).size, states.status.length)
  assert.equal(statusTone('cancelled'), 'gray')
  assert.equal(statusTone('refunded'), 'brick')
  assert.equal(statusTone('unknown'), 'slate')
})

test('pans the canvas on both axes without capturing receipts or touch gestures', () => {
  const changes = []
  const pan = createGraphPan(value => changes.push(value))
  const viewport = {
    clientWidth: 300, clientHeight: 200, scrollWidth: 600, scrollHeight: 450, scrollLeft: 10, scrollTop: 20,
    setPointerCapture: id => assert.equal(id, 1)
  }
  const down = {
    currentTarget: viewport, target: { closest: () => null }, pointerType: 'mouse',
    pointerId: 1, button: 0, clientX: 100, clientY: 100, preventDefault() {}
  }
  pan.start({ ...down, target: { closest: () => ({}) } })
  pan.start({ ...down, pointerType: 'touch' })
  assert.deepEqual(changes, [])
  pan.start(down)
  pan.move({ ...down, pointerId: 2, clientX: 0 })
  assert.equal(viewport.scrollLeft, 10)
  pan.move({ ...down, clientX: 70, clientY: 50 })
  assert.equal(viewport.scrollLeft, 40)
  assert.equal(viewport.scrollTop, 70)
  pan.end(down)
  assert.deepEqual(changes, [true, false])
})

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
  assert.ok(layout.positions.get('a').y < layout.positions.get('b').y)
  assert.ok(layout.positions.get('b').y < layout.positions.get('c').y)
  assert.deepEqual(layout.standalone.map(node => node.uuid), ['d'])
  assert.equal(layout.paths.length, 2)
})

test('the final receipt is centered under its sources, including a late-added order', () => {
  const nodes = ['A', 'B', 'C', 'D', 'E'].map(uuid => ({ uuid }))
  const edges = [{ source: 'A', target: 'C' }, { source: 'B', target: 'C' }, { source: 'C', target: 'E' }, { source: 'D', target: 'E' }]
  const { positions } = mergeLayout(nodes, edges)
  const [a, b, c, d, e] = ['A', 'B', 'C', 'D', 'E'].map(uuid => positions.get(uuid))
  assert.equal(a.y, b.y)
  assert.equal(c.y, d.y)
  assert.ok(a.y < c.y && c.y < e.y)
  assert.equal(e.x, (c.x + d.x) / 2)
})

test('expanding a receipt reserves space and starts its arrow below the item list', () => {
  const nodes = [{ uuid: 'a', itemCount: 3 }, { uuid: 'b', itemCount: 1 }]
  const edges = [{ source: 'a', target: 'b' }]
  const collapsed = mergeLayout(nodes, edges)
  const expanded = mergeLayout(nodes, edges, new Set(['a']))
  const source = expanded.positions.get('a')
  assert.ok(expanded.positions.get('b').y > collapsed.positions.get('b').y)
  assert.ok(expanded.positions.get('b').y > source.y + source.height)
  assert.ok(expanded.paths[0].startsWith(`M${source.x + expanded.width / 2} ${source.y + source.height} `))
  assert.equal(mergeLayout(nodes, edges, new Set(['b'])).positions.get('b').height, collapsed.positions.get('b').height)
})

test('each merge chain inherits its own terminal order tone along every arrow', () => {
  const nodes = [
    { uuid: 'a', status: 'merged' }, { uuid: 'b', status: 'merged' }, { uuid: 'c', status: 'cancelled' },
    { uuid: 'd', status: 'merged' }, { uuid: 'e', status: 'paid' }, { uuid: 'f', status: 'merged' }
  ]
  const edges = [
    { source: 'b', target: 'c' }, { source: 'd', target: 'e' }, { source: 'a', target: 'b' }, { source: 'f', target: 'b' }
  ]
  const layout = mergeLayout(nodes, edges)
  assert.deepEqual(layout.pathTones, ['gray', 'green', 'gray', 'gray'])
  assert.equal(layout.chainTones.get('a'), 'gray')
  assert.equal(layout.chainTones.get('e'), 'green')
  nodes.find(node => node.uuid === 'c').status = 'refunded'
  assert.deepEqual(mergeLayout(nodes, edges).pathTones, ['brick', 'green', 'brick', 'brick'])
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
