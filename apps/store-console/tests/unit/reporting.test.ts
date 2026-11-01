import { describe, expect, it } from 'vitest'
import {
  orderPaymentState,
  readReportingDay,
  reportDateKey,
  reportingCycle,
  reportingDayStorageKey,
  summarizeReport,
  writeReportingDay,
  type ReportingOrder,
} from '../../src/staff/reporting'

const timeZone = 'Asia/Shanghai'
const now = new Date('2026-11-01T04:00:00Z')

function order(overrides: Partial<ReportingOrder> & Pick<ReportingOrder, 'uuid' | 'createdAt' | 'totalAmount'>): ReportingOrder {
  return {
    status: 'confirmed',
    currency: 'CNY',
    orderSnapshot: { items: [{ productName: 'Beef', quantity: 1, amount: overrides.totalAmount }] },
    ...overrides,
  }
}

describe('reporting cycle', () => {
  it('treats day one as the natural month and day ten as a rollover month', () => {
    expect(reportingCycle('2026-11-01', 1)).toEqual({ start: '2026-11-01', end: '2026-11-30' })
    expect(reportingCycle('2026-11-01', 10)).toEqual({ start: '2026-10-10', end: '2026-11-09' })
    expect(reportingCycle('2026-11-10', 10)).toEqual({ start: '2026-11-10', end: '2026-12-09' })
  })

  it('uses the last day of shorter months and crosses a year boundary', () => {
    expect(reportingCycle('2026-02-01', 31)).toEqual({ start: '2026-01-31', end: '2026-02-27' })
    expect(reportingCycle('2026-02-28', 31)).toEqual({ start: '2026-02-28', end: '2026-03-30' })
    expect(reportingCycle('2026-12-31', 10)).toEqual({ start: '2026-12-10', end: '2027-01-09' })
  })

  it('uses the store timezone for calendar-day comparisons', () => {
    expect(reportDateKey(new Date('2026-10-31T17:30:00Z'), timeZone)).toBe('2026-11-01')
  })
})

describe('reporting order selection', () => {
  it('includes today unsettled orders and settled historical orders only', () => {
    const rows = [
      order({ uuid: 'today-pending', createdAt: '2026-11-01T01:00:00Z', totalAmount: 1200 }),
      order({ uuid: 'yesterday-pending', createdAt: '2026-10-31T01:00:00Z', totalAmount: 800 }),
      order({ uuid: 'yesterday-paid', createdAt: '2026-10-31T02:00:00Z', totalAmount: 2000, status: 'paid', paymentMethod: 'wechat' }),
    ]
    const summary = summarizeReport(rows, 'today', 1, now, timeZone)
    expect(summary.orders.map((row) => row.uuid)).toEqual(['today-pending'])
    const month = summarizeReport(rows, 'month', 10, now, timeZone)
    expect(month.orders.map((row) => row.uuid)).toEqual(['today-pending', 'yesterday-paid'])
    expect(month).toMatchObject({ totalAmount: 3200, settledAmount: 2000, pendingAmount: 1200, averageOrder: 1600 })
    expect(month.payments).toEqual([{ method: 'wechat', amount: 2000 }])
  })

  it('excludes cancelled, rejected, refunded and merged records', () => {
    const rows = [
      order({ uuid: 'cancelled', createdAt: '2026-11-01T01:00:00Z', totalAmount: 100, status: 'cancelled' }),
      order({ uuid: 'refunded', createdAt: '2026-11-01T02:00:00Z', totalAmount: 200, status: 'refunded' }),
      order({ uuid: 'merged', createdAt: '2026-11-01T03:00:00Z', totalAmount: 300, status: 'merged' }),
      order({ uuid: 'valid', createdAt: '2026-11-01T04:00:00Z', totalAmount: 400 }),
    ]
    expect(summarizeReport(rows, 'today', 1, now, timeZone).orders.map((row) => row.uuid)).toEqual(['valid'])
  })

  it('recognizes payment fields and labels missing methods without inventing one', () => {
    expect(orderPaymentState(order({ uuid: 'paid', createdAt: now.toISOString(), totalAmount: 100, paymentStatus: 'paid' }))).toEqual({ settled: true, method: 'unrecorded' })
    expect(orderPaymentState(order({ uuid: 'alipay', createdAt: now.toISOString(), totalAmount: 100, paidAt: now.toISOString(), paymentMethod: 'ALIPAY' }))).toEqual({ settled: true, method: 'alipay' })
  })
})

describe('reporting day storage', () => {
  it('stores a valid day per store and falls back to one', () => {
    const values = new Map<string, string>()
    const storage = { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => { values.set(key, value) } }
    expect(readReportingDay('store-a', storage)).toBe(1)
    expect(writeReportingDay('store-a', 10, storage)).toBe(true)
    expect(values.get(reportingDayStorageKey('store-a'))).toBe('10')
    expect(readReportingDay('store-a', storage)).toBe(10)
    expect(readReportingDay('store-b', storage)).toBe(1)
    expect(writeReportingDay('store-a', 32, storage)).toBe(false)
  })
})
