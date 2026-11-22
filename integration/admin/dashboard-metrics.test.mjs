import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  buildDashboardSnapshot,
  dayBounds,
  isTestStore,
  isTestStoreOrder,
  toDateInputValue
} from '../../business/admin/dashboard/dashboard-metrics.js'

describe('dashboard metrics', () => {
  const paidInvoice = {
    sourceType: 'trade_order',
    sourceId: 'order-paid',
    status: 'paid',
    amount: 10000,
    refundedAmount: 0,
    paidAt: '2026-11-22T10:15:00+08:00'
  }
  const partialInvoice = {
    sourceType: 'trade_order',
    sourceId: 'order-partial',
    status: 'partial_refunded',
    amount: 4000,
    refundedAmount: 1000,
    paidAt: '2026-11-22T12:30:00+08:00'
  }
  const refundedInvoice = {
    sourceType: 'trade_order',
    sourceId: 'order-refunded',
    status: 'refunded',
    amount: 2500,
    refundedAmount: 2500,
    paidAt: '2026-11-22T14:00:00+08:00'
  }
  const orders = [
    { id: 1, uuid: 'order-paid', status: 'paid', totalAmount: 10000, paidAt: paidInvoice.paidAt, createdAt: '2026-11-22T09:50:00+08:00', metadata: { _store: { code: 'NS002', name: '台城店' }}},
    { id: 2, uuid: 'order-pending', status: 'confirmed', totalAmount: 5000, createdAt: '2026-11-22T13:20:00+08:00', metadata: { _store: { code: 'NS002', name: '台城店' }}},
    { id: 3, uuid: 'order-partial', status: 'completed', totalAmount: 4000, paidAt: partialInvoice.paidAt, createdAt: '2026-11-22T11:40:00+08:00', metadata: { _store: { code: 'NS002', name: '台城店' }}},
    { id: 4, uuid: 'order-refunded', status: 'refunded', totalAmount: 2500, paidAt: refundedInvoice.paidAt, createdAt: '2026-11-22T13:00:00+08:00', metadata: { _store: { code: 'NS002', name: '台城店' }}},
    { id: 5, uuid: 'order-test', status: 'paid', totalAmount: 9900, paidAt: '2026-11-22T09:00:00+08:00', createdAt: '2026-11-22T08:00:00+08:00', metadata: { _store: { code: 'NS001', name: '测试门店' }}},
    { id: 6, uuid: 'order-cancelled', status: 'cancelled', totalAmount: 1200, createdAt: '2026-11-22T08:30:00+08:00', metadata: { _store: { code: 'NS002', name: '台城店' }}}
  ]

  it('formats local dates as date-picker values', () => {
    assert.equal(toDateInputValue(new Date(2026, 10, 2, 8, 30)), '2026-11-02')
  })

  it('creates an exclusive day range', () => {
    const bounds = dayBounds('2026-11-22')
    assert.equal(new Date(bounds.end).getTime() - new Date(bounds.start).getTime(), 24 * 60 * 60 * 1000)
    assert.match(bounds.start, /^2026-11-22T00:00:00[+-]\d{2}:\d{2}$/)
    assert.match(bounds.end, /^2026-11-23T00:00:00[+-]\d{2}:\d{2}$/)
  })

  it('recognises the NS001 test store in orders and store records', () => {
    assert.equal(isTestStoreOrder(orders[4]), true)
    assert.equal(isTestStoreOrder({ storeCodeSnapshot: 'NS001' }), true)
    assert.equal(isTestStore({ code: 'NS001' }), true)
    assert.equal(isTestStoreOrder(orders[0]), false)
  })

  it('calculates daily amounts without NS001 or cancelled orders', () => {
    const snapshot = buildDashboardSnapshot({
      orders,
      invoices: [
        paidInvoice,
        partialInvoice,
        refundedInvoice,
        { ...paidInvoice, sourceId: 'order-test' },
        { ...paidInvoice, sourceId: 'order-paid', status: 'failed', amount: 10000 }
      ],
      storeCount: 2,
      productCount: 12,
      materialCount: 4,
      dayStart: '2026-11-22T00:00:00+08:00',
      dayEnd: '2026-11-23T00:00:00+08:00'
    })

    assert.equal(snapshot.totalAmount, 21500)
    assert.equal(snapshot.settledAmount, 14000)
    assert.equal(snapshot.pendingAmount, 5000)
    assert.equal(snapshot.refundedAmount, 3500)
    assert.equal(snapshot.orderCount, 4)
    assert.equal(snapshot.pendingOrders, 1)
    assert.equal(snapshot.paidOrderCount, 2)
    assert.equal(snapshot.stores, 2)
    assert.equal(snapshot.products, 12)
    assert.equal(snapshot.materials, 4)
    assert.equal(snapshot.paidByHour.reduce((sum, amount) => sum + amount, 0), 14000)
  })

  it('limits recent orders to received and awaiting-payment orders', () => {
    const snapshot = buildDashboardSnapshot({ orders, invoices: [paidInvoice, partialInvoice, refundedInvoice], recentLimit: 2 })
    assert.equal(snapshot.recentOrders.length, 2)
    assert.deepEqual(snapshot.recentOrders.map(order => order.id), [2, 3])
    assert.equal(snapshot.recentOrders.every(order => ['paid', 'pending'].includes(order.paymentState)), true)
    assert.equal(snapshot.recentOrders.every(order => order.storeName === '台城店'), true)
  })
})
