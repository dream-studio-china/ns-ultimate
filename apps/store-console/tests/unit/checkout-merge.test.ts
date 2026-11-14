import { describe, expect, it } from 'vitest'
import type { CounterSessionView, SessionOrder } from '../../src/staff/adapters/live'
import { checkoutMergeBlockers } from '../../src/staff/checkout-merge'

const id = '00000000-0000-4000-8000-000000000001'
const version = 'a'.repeat(64)

function order(index: number, overrides: Partial<SessionOrder> = {}): SessionOrder {
  const orderUuid = `00000000-0000-4000-8000-${String(index + 10).padStart(12, '0')}`
  return {
    uuid: orderUuid,
    tradeOrderUuid: orderUuid,
    status: 'confirmed',
    paymentStatus: null,
    hasInvoice: false,
    currency: 'CNY',
    totalAmount: 100,
    originalAmount: 100,
    discountAmount: 0,
    discounts: [],
    notes: null,
    createdAt: '2026-11-02T10:00:00Z',
    diningModeUuid: null,
    diningModeName: null,
    items: [{ specificationUuid: id, productName: 'Soup', specificationName: 'Regular', quantity: 1, unitPrice: 100, amount: 100 }],
    canReplace: true,
    canCancel: true,
    changeBlockedReason: null,
    replaceBlockedReason: null,
    ...overrides,
  }
}

function table(orders: SessionOrder[]): CounterSessionView {
  return {
    counter: { uuid: id, code: 'A01', name: 'Window', capacity: 4, enabled: true },
    session: { uuid: id, participantCount: 2, notes: null, startedAt: '2026-11-02T10:00:00Z', revision: 1, diningModeUuid: null, diningModeName: null, diningMode: null },
    currency: 'CNY', canEnd: false, version, ordersVisible: true, openAmount: 200, orderedAmount: 200, openOrderCount: 2, paymentHold: false,
    orders,
  }
}

describe('checkout merge preflight reasons', () => {
  it('returns no blockers for two eligible confirmed orders', () => {
    expect(checkoutMergeBlockers(table([order(1), order(2)]))).toEqual([])
  })

  it('leaves payment, invoice, table and item checks to the backend', () => {
    const confirmed = order(1, {
      paymentStatus: 'pending',
      hasInvoice: true,
      changeBlockedReason: 'inventory_reserved',
      items: [{ specificationUuid: null, productName: 'Legacy', specificationName: 'Item', quantity: 1, unitPrice: 100, amount: 100 }],
    })

    expect(checkoutMergeBlockers(table([confirmed, order(2)]))).toEqual([])
  })

  it('only rejects an unsettled source order that is not confirmed', () => {
    const pending = order(1, { status: 'pending' })
    const blockers = checkoutMergeBlockers(table([pending, order(2)]))

    expect(blockers).toContainEqual({
      code: 'order_status',
      orderUuid: pending.uuid,
      status: 'pending',
    })
  })

  it('ignores terminal orders that are not part of the settlement', () => {
    expect(checkoutMergeBlockers(table([order(1), order(2, { status: 'cancelled' })]))).toEqual([])
  })
})
