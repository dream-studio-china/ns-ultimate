import { describe, expect, it } from 'vitest'
import { cashMinor, cashMoney } from '../../src/staff/adapters/cash-register'

describe('cash register amount display', () => {
  it('preserves exact two-decimal amounts and rejects invalid input', () => {
    expect(cashMinor('120.00')).toBe('12000')
    expect(cashMinor('0.01')).toBe('1')
    expect(cashMinor('90071992547409.93')).toBe('9007199254740993')
    expect(cashMoney('9007199254740993', 'CNY')).toBe('CNY 90071992547409.93')
    expect(cashMoney('1', 'CNY')).toBe('CNY 0.01')
    for (const value of ['0.00', '-1', '1.001', '1e2', '92233720368547758.08']) expect(cashMinor(value)).toBeNull()
  })
})
