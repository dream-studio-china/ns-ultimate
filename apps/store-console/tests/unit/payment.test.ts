import { describe, expect, it, vi } from 'vitest'
import { issuePayment, paymentSchema, readPayment, readPayments, resolvePayment, voidPayment, writePayment, type DemoStorage } from '../../src/shared/demo-payment'

function memoryStorage(): DemoStorage {
  const data = new Map<string, string>()
  return { getItem: (key) => data.get(key) ?? null, setItem: (key, value) => { data.set(key, value) } }
}
const input = { storeName: 'Demo Kitchen', tableName: 'A01', lines: [{ name: 'Demo item', variant: 'Regular', quantity: 2, unitPrice: 4800 }], discount: 600, total: 9000 }

describe('restricted demo payment projection', () => {
  it('issues expiring random session identifiers and strips staff data', () => {
    const storage = memoryStorage()
    const issued = issuePayment(input, storage)
    expect(issued.id).toMatch(/^[0-9a-f-]{36}$/)
    expect(issued.expiresAt - issued.createdAt).toBe(15 * 60_000)
    expect(readPayment(issued.id, storage)).toEqual(issued)
    expect(paymentSchema.parse({ ...issued, employee: 'private', token: 'private', note: 'private' })).toEqual(issued)
  })
  it('rejects inconsistent totals, invalid quantities and unrecognized statuses', () => {
    const issued = issuePayment(input, memoryStorage())
    expect(paymentSchema.safeParse({ ...issued, total: 1 }).success).toBe(false)
    expect(paymentSchema.safeParse({ ...issued, status: 'success' }).success).toBe(false)
    expect(paymentSchema.safeParse({ ...issued, lines: [{ ...issued.lines[0], quantity: 100 }] }).success).toBe(false)
  })
  it('only voids links without an unresolved collection', () => {
    const storage = memoryStorage()
    const issued = issuePayment(input, storage)
    expect(voidPayment(issued.id, storage).status).toBe('void')
    expect(() => voidPayment(issued.id, storage)).toThrow()
    const processing = issuePayment(input, storage)
    writePayment({ ...processing, status: 'unknown', method: 'wechat', resolveAt: Date.now() + 1000 }, storage)
    expect(() => voidPayment(processing.id, storage)).toThrow()
  })
  it('resolves the original unknown demo attempt without creating a new payment', () => {
    vi.useFakeTimers()
    try {
      const storage = memoryStorage()
      const issued = issuePayment(input, storage)
      writePayment({ ...issued, status: 'unknown', method: 'wechat', resolveAt: Date.now() + 1000 }, storage)
      expect(resolvePayment(issued.id, storage).status).toBe('unknown')
      vi.advanceTimersByTime(1001)
      expect(resolvePayment(issued.id, storage).status).toBe('paid')
      expect(resolvePayment(issued.id, storage).id).toBe(issued.id)
      expect(readPayments(storage)).toHaveLength(1)
    } finally { vi.useRealTimers() }
  })
  it('bounds demo storage, removes expired history and surfaces blocked or malformed storage', () => {
    const storage = memoryStorage()
    for (let i = 0; i < 24; i++) issuePayment(input, storage)
    expect(readPayments(storage)).toHaveLength(20)
    expect(() => readPayments({ getItem: () => '{invalid', setItem() {} })).toThrow()
    expect(() => issuePayment(input, { getItem: () => null, setItem() { throw new Error('quota') } })).toThrow()
  })
})
