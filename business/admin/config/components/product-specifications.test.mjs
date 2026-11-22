import assert from 'node:assert/strict'
import test from 'node:test'
import { formatProductSpecificationPrice, productSpecificationRows } from './product-list-updates.mjs'

test('product specification rows read expanded metadata in display order', () => {
  const rows = productSpecificationRows({
    specifications: [
      { id: 2, __toString: 'Soup - Large', __metadata: { name: 'Large', price: 1800, sort: 2, status: 'active' } },
      { id: 1, __toString: 'Soup - Regular', __metadata: { name: 'Regular', price: 1500, sort: 1, status: 'active' } },
      { id: 3, isDeleted: true, __metadata: { name: 'Deleted', price: 100, sort: 3 } }
    ]
  })

  assert.deepEqual(rows, [
    { id: 1, name: 'Regular', price: 1500, sort: 1, active: true },
    { id: 2, name: 'Large', price: 1800, sort: 2, active: true }
  ])
})

test('product specification rows support plain specifications and inactive entries', () => {
  const rows = productSpecificationRows({
    specifications: [{ id: 4, name: 'Old size', price: 900, sort: 0, status: 'inactive' }]
  })

  assert.deepEqual(rows, [{ id: 4, name: 'Old size', price: 900, sort: 0, active: false }])
})

test('product specification prices are rendered as yuan amounts', () => {
  assert.equal(formatProductSpecificationPrice(500), '¥5.00')
  assert.equal(formatProductSpecificationPrice(2650), '¥26.50')
  assert.equal(formatProductSpecificationPrice(-1), '')
  assert.equal(formatProductSpecificationPrice('invalid'), '')
})
