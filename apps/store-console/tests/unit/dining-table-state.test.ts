import { describe, expect, it } from 'vitest'
import type { CounterSessionView } from '../../src/staff/adapters/live'
import { hasUnsettledDiningOrders, isOccupiedDiningTable, unsettledDiningOrders } from '../../src/staff/dining-table-state'

const id = '00000000-0000-4000-8000-000000000001'
const version = 'a'.repeat(64)

function table(statuses: string[]): CounterSessionView {
  return {
    counter: { uuid: id, code: 'A01', name: 'Window', capacity: 4, enabled: true },
    session: { uuid: id, participantCount: 2, notes: null, startedAt: '2026-11-02T10:00:00Z', revision: 1, diningModeUuid: 'mode-hotpot', diningModeName: 'Hotpot', diningMode: null },
    currency: 'CNY', canEnd: true, version, ordersVisible: true, openAmount: 0, orderedAmount: 0, openOrderCount: 0, paymentHold: false,
    orders: statuses.map((status, index) => ({
      uuid: `00000000-0000-4000-8000-${String(index + 10).padStart(12, '0')}`,
      tradeOrderUuid: `00000000-0000-4000-8000-${String(index + 10).padStart(12, '0')}`,
      status, paymentStatus: null, hasInvoice: false, currency: 'CNY', totalAmount: 100, notes: null,
      createdAt: '2026-11-02T10:00:00Z', items: [], diningModeUuid: 'mode-hotpot', diningModeName: 'Hotpot', canReplace: false, canCancel: false,
      changeBlockedReason: null, replaceBlockedReason: null,
    })),
  }
}

describe('dining table occupancy', () => {
  it('treats a session with only cancelled orders as idle', () => {
    const value = table(['cancelled', 'refunded', 'merged'])
    expect(unsettledDiningOrders(value)).toEqual([])
    expect(hasUnsettledDiningOrders(value)).toBe(false)
    expect(isOccupiedDiningTable(value)).toBe(false)
  })

  it('keeps a table occupied while pending or confirmed orders still require processing', () => {
    const value = table(['cancelled', 'pending', 'confirmed', 'completed'])
    expect(unsettledDiningOrders(value).map((order) => order.status)).toEqual(['pending', 'confirmed'])
    expect(isOccupiedDiningTable(value)).toBe(true)
  })

  it('does not treat a draft order as visible dining activity', () => {
    const value = table(['draft'])
    expect(unsettledDiningOrders(value)).toEqual([])
    expect(isOccupiedDiningTable(value)).toBe(false)
  })
})
