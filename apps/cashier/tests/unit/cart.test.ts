import { describe, expect, it } from 'vitest'
import { addProduct, count, emptyBasket, parseCash, setQuantity, total } from '../../src/domain/cart'
import { demoProducts } from '../../src/domain/catalog'

const regular = { size: 'regular', temperature: 'hot' } as const
describe('basket', () => {
  it('merges identical selections and separates variants', () => {
    const initial = addProduct([], demoProducts[0], regular, 1)
    const merged = addProduct(initial, demoProducts[0], regular, 2)
    const variants = addProduct(merged, demoProducts[0], { size: 'large', temperature: 'iced' }, 1)
    expect(variants).toHaveLength(2)
    expect(variants[0].quantity).toBe(3)
    expect(variants[1].unitPrice).toBe(2200)
    expect(total(variants)).toBe(7600)
    expect(count(variants)).toBe(4)
    expect(initial[0].quantity).toBe(1)
  })
  it('normalizes non-customizable products', () => {
    const lines = addProduct([], demoProducts[8], { size: 'large', temperature: 'iced' }, 1)
    expect(lines[0].unitPrice).toBe(1600)
    expect(lines[0].selection).toEqual(regular)
  })
  it('removes zero quantity and rejects invalid quantities', () => {
    const lines = addProduct([], demoProducts[0], regular, 1)
    expect(setQuantity(lines, lines[0].id, 0)).toEqual([])
    for (const quantity of [-1, 0.5, 100, NaN]) expect(() => setQuantity(lines, lines[0].id, quantity)).toThrow()
    expect(() => addProduct([], demoProducts[0], regular, 0)).toThrow()
    expect(() => addProduct(lines, demoProducts[0], regular, 99)).toThrow()
    expect(() => addProduct([], demoProducts[7], regular, 1)).toThrow()
  })
  it('creates independent empty baskets', () => {
    const a = emptyBasket(); const b = emptyBasket()
    expect(a).not.toBe(b)
    expect(a.lines).not.toBe(b.lines)
  })
})
describe('cash input in integer minor units', () => {
  it.each([['0', 0], ['18', 1800], ['18.', 1800], ['18.1', 1810], ['0.29', 29], ['100.01', 10001]])('parses %s', (input, expected) => expect(parseCash(input)).toBe(expected))
  it.each(['', '.', '-1', '1.234', 'Infinity', '1e2', ' 1', '10000000'])('rejects %s', (input) => expect(parseCash(input)).toBeNull())
})
