import assert from 'node:assert/strict'
import test from 'node:test'
import { createRecordRead } from '../adapters/ns-ultimate/admin/record-read.mjs'

const catalog = [
  { key: 'Content', name: 'Content', readable: true, path: '/api/v1/manage/contents' },
  { key: 'Category', name: 'Category', readable: true, path: '/api/v1/manage/categories' }
]
const operations = [
  { method: 'GET', path: '/api/v1/manage/contents' },
  { method: 'GET', path: '/api/v1/manage/contents/{id}' }
]

function adapter({ response, pages = ['Content'] } = {}) {
  const requests = []
  const read = createRecordRead({
    request: { get: async(...args) => { requests.push(args); return { data: response ?? { id: 12, title: 'Article', body: '<p>Body</p>', createdAt: '2026-10-23T00:00:00+00:00', updatedAt: null } } } },
    getCatalog: () => catalog,
    isRecordPageAvailable: entity => pages.includes(entity)
  })
  return { read, requests }
}

test('reads only allowlisted scalar fields from the exact documented detail route', async() => {
  const { read, requests } = adapter()
  const result = await read({ entity: 'Content', identifier: '12' }, operations)
  assert.equal(requests[0][0], '/api/v1/manage/contents/12')
  assert.equal(requests[0][1].timeout, 30000)
  assert.ok(requests[0][1].signal)
  assert.equal(result.entity, 'Content')
  assert.equal(result.identifier, '12')
  assert.deepEqual(Object.keys(result.fields).sort(), ['body', 'createdAt', 'title', 'updatedAt'])
  assert.equal(result.fields.body, '<p>Body</p>')
  assert.equal(result.truncated, false)
  assert.equal(typeof result.query.durationMs, 'number')
})

test('fails closed for unavailable pages, missing detail routes, policy entities and identity mismatches', async() => {
  const unavailable = adapter({ pages: [] })
  await assert.rejects(unavailable.read({ entity: 'Content', identifier: '12' }, operations), /not available to the current account/)
  assert.equal(unavailable.requests.length, 0)

  const noDetail = adapter()
  await assert.rejects(noDetail.read({ entity: 'Content', identifier: '12' }, [{ method: 'GET', path: '/api/v1/manage/contents' }]), /documented detail endpoint/)
  assert.equal(noDetail.requests.length, 0)

  const wrongEntity = adapter()
  await assert.rejects(wrongEntity.read({ entity: 'Category', identifier: '12' }, operations), /no approved record read/)
  assert.equal(wrongEntity.requests.length, 0)

  const mismatch = adapter({ response: { id: 13, title: 'Other', body: 'Secret' } })
  await assert.rejects(mismatch.read({ entity: 'Content', identifier: '12' }, operations), /mismatched record response/)
})

test('never expands the fixed policy field set from caller input', async() => {
  const { read, requests } = adapter()
  await assert.rejects(read({ entity: 'Content', identifier: '12', fields: ['body'] }, operations), /unsupported parameters/)
  assert.equal(requests.length, 0)
})
