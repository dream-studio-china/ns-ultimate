import { describe, expect, it } from 'vitest'
import { customerSnapshotSchema } from '../../shared/desktop'

const snapshot = { locale: 'en', theme: 'green', storeName: 'Demo store', online: true, lines: [], total: 0, status: 'idle' }
describe('desktop customer projection', () => {
  it('accepts the narrow display contract and strips unrelated identity data', () => {
    expect(customerSnapshotSchema.parse({ ...snapshot, staff: 'private', token: 'private' })).toEqual(snapshot)
  })
  it('rejects malformed statuses, oversized data and invalid amounts', () => {
    expect(customerSnapshotSchema.safeParse({ ...snapshot, status: 'paid' }).success).toBe(false)
    expect(customerSnapshotSchema.safeParse({ ...snapshot, theme: 'invalid' }).success).toBe(false)
    expect(customerSnapshotSchema.safeParse({ ...snapshot, total: -1 }).success).toBe(false)
    expect(customerSnapshotSchema.safeParse({ ...snapshot, storeName: 'a'.repeat(101) }).success).toBe(false)
    expect(customerSnapshotSchema.safeParse({ ...snapshot, lines: Array(101).fill({ id: '1', name: 'x', detail: '', quantity: 1, total: 1 }) }).success).toBe(false)
  })
})
