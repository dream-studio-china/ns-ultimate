import { describe, expect, it } from 'vitest'
import { counterSessionSchema, type CounterSessionView, type SessionOrder } from '../../src/staff/adapters/live'
import { checkoutMergeBlockers } from '../../src/staff/checkout-merge'

const tableUuid = '00000000-0000-4000-8000-000000000002'
const itemUuid = '00000000-0000-4000-8000-000000000101'

function order(uuid: string, overrides: Partial<SessionOrder> = {}): SessionOrder {
  return {
    uuid,
    status: 'pending',
    paymentStatus: null,
    hasInvoice: false,
    currency: 'CNY',
    totalAmount: 1000,
    notes: null,
    createdAt: '2026-11-01T10:00:00Z',
    items: [{ specificationUuid: itemUuid, productName: 'Beef', specificationName: 'Regular', quantity: 1, unitPrice: 1000, amount: 1000 }],
    diningModeUuid: null,
    diningModeName: null,
    canReplace: true,
    canCancel: true,
    changeBlockedReason: null,
    replaceBlockedReason: null,
    ...overrides,
  }
}

function table(orders: SessionOrder[], options: { enabled?: boolean; paymentHold?: boolean } = {}): CounterSessionView {
  const openAmount = orders.filter((entry) => ['pending', 'confirmed'].includes(entry.status)).reduce((total, entry) => total + entry.totalAmount, 0)
  return counterSessionSchema.parse({
    counter: { uuid: tableUuid, code: 'A01', name: 'Window', capacity: 4, enabled: options.enabled ?? true },
    session: { uuid: tableUuid, participantCount: 2, notes: null, startedAt: '2026-11-01T10:00:00Z', revision: 1, diningModeUuid: null, diningMode: null },
    ordersVisible: true,
    orders,
    openAmount,
    orderedAmount: openAmount,
    openOrderCount: orders.filter((entry) => !['completed', 'cancelled', 'refunded', 'merged'].includes(entry.status)).length,
    paymentHold: options.paymentHold ?? false,
    currency: 'CNY',
    canEnd: false,
    version: 'a'.repeat(64),
  })
}

describe('checkout merge preflight', () => {
  it('accepts two eligible pending orders even when they were created on different days', () => {
    const tableState = table([
      order('00000000-0000-4000-8000-000000000201', { createdAt: '2026-10-31T23:50:00+08:00' }),
      order('00000000-0000-4000-8000-000000000202', { createdAt: '2026-11-01T00:10:00+08:00' }),
    ])

    expect(checkoutMergeBlockers(tableState)).toEqual([])
  })

  it('does not confuse the stricter replacement flag with merge eligibility', () => {
    const tableState = table([
      order('00000000-0000-4000-8000-000000000201', { canReplace: false, replaceBlockedReason: 'unsupported_order_items' }),
      order('00000000-0000-4000-8000-000000000202'),
    ])

    expect(checkoutMergeBlockers(tableState)).toEqual([])
  })

  it('reports payment records and linked invoices on the affected orders', () => {
    const first = '00000000-0000-4000-8000-000000000201'
    const second = '00000000-0000-4000-8000-000000000202'
    const blockers = checkoutMergeBlockers(table([
      order(first, { paymentStatus: 'paid' }),
      order(second, { hasInvoice: true }),
    ]))

    expect(blockers).toEqual(expect.arrayContaining([
      { code: 'payment_status', orderUuid: first, paymentStatus: 'paid' },
      { code: 'invoice', orderUuid: second },
    ]))
    expect(blockers).toContainEqual({ code: 'too_few_orders', count: 0 })
  })

  it('reports the backend-provided Trade lifecycle or counter binding reason', () => {
    const uuid = '00000000-0000-4000-8000-000000000201'
    const blockers = checkoutMergeBlockers(table([
      order(uuid, { canReplace: false, changeBlockedReason: 'order_ineligible' }),
      order('00000000-0000-4000-8000-000000000202'),
    ]))

    expect(blockers).toContainEqual({ code: 'order_ineligible', orderUuid: uuid, reason: 'order_ineligible' })
    expect(blockers).toContainEqual({ code: 'too_few_orders', count: 1 })
  })

  it('rejects legacy item lines without a valid specification', () => {
    const uuid = '00000000-0000-4000-8000-000000000201'
    const blockers = checkoutMergeBlockers(table([
      order(uuid, { items: [{ specificationUuid: null, productName: 'Legacy dish', specificationName: '', quantity: 1, unitPrice: 1000, amount: 1000 }] }),
      order('00000000-0000-4000-8000-000000000202'),
    ]))

    expect(blockers).toContainEqual({ code: 'invalid_item', orderUuid: uuid, productName: 'Legacy dish', specificationName: '' })
    expect(blockers).toContainEqual({ code: 'too_few_orders', count: 1 })
  })

  it('aggregates quantities across orders before applying the merge limit', () => {
    const blockers = checkoutMergeBlockers(table([
      order('00000000-0000-4000-8000-000000000201', { items: [{ specificationUuid: itemUuid, productName: 'Beef', specificationName: 'Regular', quantity: 60, unitPrice: 1000, amount: 60000 }] }),
      order('00000000-0000-4000-8000-000000000202', { items: [{ specificationUuid: itemUuid, productName: 'Beef', specificationName: 'Regular', quantity: 40, unitPrice: 1000, amount: 40000 }] }),
    ]))

    expect(blockers).toContainEqual({ code: 'quantity_limit', productName: 'Beef', specificationName: 'Regular', quantity: 100 })
  })

  it('reports an over-limit merged catalog after combining distinct products', () => {
    const specification = (index: number) => `00000000-0000-4000-8000-${String(index).padStart(12, '0')}`
    const line = (index: number) => ({ specificationUuid: specification(index), productName: `Product ${index}`, specificationName: 'Regular', quantity: 1, unitPrice: 1000, amount: 1000 })
    const blockers = checkoutMergeBlockers(table([
      order('00000000-0000-4000-8000-000000000201', { items: Array.from({ length: 16 }, (_, index) => line(index + 1)) }),
      order('00000000-0000-4000-8000-000000000202', { items: Array.from({ length: 15 }, (_, index) => line(index + 17)) }),
    ]))

    expect(blockers).toContainEqual({ code: 'product_limit', count: 31 })
  })

  it('reports disabled tables and payment holds before attempting the merge request', () => {
    const blockers = checkoutMergeBlockers(table([order('00000000-0000-4000-8000-000000000201'), order('00000000-0000-4000-8000-000000000202')], { enabled: false, paymentHold: true }))

    expect(blockers).toEqual(expect.arrayContaining([{ code: 'table_disabled' }, { code: 'payment_hold' }]))
  })
})
