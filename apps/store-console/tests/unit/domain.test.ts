import { describe, expect, it } from 'vitest'
import { addLine, due, itemCount, parseAmount, subtotal, updateQuantity } from '../../src/staff/domain'
import { demoGateway } from '../../src/staff/adapters/demo'

describe('UI order arithmetic', () => {
  it('keeps integer cents and preserves separate variants', () => {
    const { products } = demoGateway.bootstrap()
    const lines = addLine(addLine([], products[0], 'v1'), products[0], 'v1')
    const variants = addLine(lines, products[0], 'v2')
    expect(lines).toHaveLength(1)
    expect(lines[0].quantity).toBe(2)
    expect(variants).toHaveLength(2)
    expect(subtotal(variants)).toBe(16400)
    expect(itemCount(variants)).toBe(3)
    expect(products[0].variants[0].price).toBe(4800)
  })
  it('rejects unavailable selections and out-of-range quantities', () => {
    const { products } = demoGateway.bootstrap()
    expect(() => addLine([], products[6], 'v1')).toThrow()
    expect(() => addLine([], products[0], 'missing')).toThrow()
    const lines = addLine([], products[0], 'v1')
    expect(updateQuantity(lines, lines[0].id, 0)).toEqual([])
    for (const value of [-1, 100, 1.5, NaN]) expect(() => updateQuantity(lines, lines[0].id, value)).toThrow()
    const full = updateQuantity(lines, lines[0].id, 99)
    expect(() => addLine(full, products[0], 'v1')).toThrow()
  })
  it('creates independent demo fixtures and applies discounts', () => {
    const first = demoGateway.bootstrap()
    const second = demoGateway.bootstrap()
    first.products[0].soldOut = true
    expect(second.products[0].soldOut).toBe(false)
    expect(due({ ...second.tables[0], discount: 1000 })).toBe(7400)
  })
  it.each([['0', 0], ['12', 1200], ['12.3', 1230], ['0.29', 29], ['12.', 1200]])('parses %s into minor units', (input, result) => expect(parseAmount(input)).toBe(result))
  it.each(['', '1.234', '-1', '1e3', ' 1', '1000000'])('rejects %s', (input) => expect(parseAmount(input)).toBeNull())
})
