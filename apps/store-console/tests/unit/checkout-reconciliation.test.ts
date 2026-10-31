import { describe, expect, it } from 'vitest'
import { reconcileCheckoutQuantities } from '../../src/staff/checkout-reconciliation'

describe('checkout reconciliation', () => {
  it('creates only the positive quantity delta before merging', () => {
    const result = reconcileCheckoutQuantities(
      [{ specificationUuid: 'product-a', quantity: 2 }, { specificationUuid: 'product-b', quantity: 1 }],
      [{ specificationUuid: 'product-a', quantity: 3 }, { specificationUuid: 'product-b', quantity: 2 }],
    )
    expect(result.additions).toEqual([
      { specificationUuid: 'product-a', quantity: 1 },
      { specificationUuid: 'product-b', quantity: 1 },
    ])
    expect(result.reductions).toEqual([])
    expect(result.changed).toBe(true)
  })

  it('keeps reductions separate for replacement after order consolidation', () => {
    const result = reconcileCheckoutQuantities(
      [{ specificationUuid: 'product-a', quantity: 2 }, { specificationUuid: 'product-b', quantity: 2 }],
      [{ specificationUuid: 'product-a', quantity: 3 }, { specificationUuid: 'product-b', quantity: 1 }],
    )
    expect(result.additions).toEqual([{ specificationUuid: 'product-a', quantity: 1 }])
    expect(result.reductions).toEqual([{ specificationUuid: 'product-b', quantity: 1 }])
    expect(result.desired).toEqual([
      { specificationUuid: 'product-a', quantity: 3 },
      { specificationUuid: 'product-b', quantity: 1 },
    ])
  })

  it('merges duplicate lines and reports an unchanged cart', () => {
    const result = reconcileCheckoutQuantities(
      [{ specificationUuid: 'product-a', quantity: 1 }, { specificationUuid: 'product-a', quantity: 2 }],
      [{ specificationUuid: 'product-a', quantity: 3 }],
    )
    expect(result.additions).toEqual([])
    expect(result.reductions).toEqual([])
    expect(result.desired).toEqual([{ specificationUuid: 'product-a', quantity: 3 }])
    expect(result.changed).toBe(false)
  })
})
