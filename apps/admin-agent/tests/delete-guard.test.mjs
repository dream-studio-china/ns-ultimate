import assert from 'node:assert/strict'
import test from 'node:test'
import { createDeleteGuard } from '../adapters/ns-ultimate/admin/delete-guard.mjs'

function setup(data = []) {
  const calls = []
  const projection = { entities: ['Category', 'Content', 'Picture', 'Media', 'ProductCategory', 'Product', 'Comment'].map(name => ({ name, route: { name: `${name}List` }, path: `/configured/${name}` })) }
  const guard = createDeleteGuard({ getProjection: () => projection,
    request: { get: async(...args) => { calls.push(args); return { data } } }
  })
  return { guard, calls, projection }
}

test('category deletion checks all legacy reference families through configured read endpoints', async() => {
  const { guard, calls } = setup()
  await guard({ entity: 'Category', record: { id: 7 } })
  assert.deepEqual(calls.map(call => call[0]), ['/configured/Category', '/configured/Content', '/configured/Picture', '/configured/Media'])
  assert.equal(calls[0][1].params['@filter'], 'entity.getParent().getId() == 7')
  assert.equal(calls[1][1].params['@filter'], 'entity.getCategory().getId() == 7')
  assert.equal(calls.every(call => call[1].params.limit === 1), true)
})

test('product-category and comment deletions retain hierarchy/cascade checks; Tag has no content cascade guard', async() => {
  const { guard, calls } = setup()
  await guard({ entity: 'ProductCategory', record: { id: 8 } })
  await guard({ entity: 'Comment', record: { id: 9 } })
  await guard({ entity: 'Tag', record: { id: 10 } })
  assert.deepEqual(calls.map(call => call[0]), ['/configured/ProductCategory', '/configured/Product', '/configured/Comment'])
})

test('references, missing access, malformed list responses and untrusted IDs block deletion', async() => {
  await assert.rejects(setup([{ id: 1 }]).guard({ entity: 'Category', record: { id: 7 } }), /is referenced/)
  await assert.rejects(setup(null).guard({ entity: 'Comment', record: { id: 7 } }), /Cannot verify/)
  const { guard, projection } = setup()
  projection.entities = []
  await assert.rejects(guard({ entity: 'Category', record: { id: 7 } }), /Cannot verify/)
  await assert.rejects(guard({ entity: 'Category', record: { id: '7 || true' } }), /numeric target ID/)
})
