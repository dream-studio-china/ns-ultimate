import { describe, expect, it } from 'vitest'
import {
  DEFAULT_TRADE_ORDER_STATUSES,
  filterTradeOrders,
  tradeOrderDate,
  type TradeOrderFilterRow,
} from '../../src/staff/live/order-filters'

const row = (status: string, tableCode: string, createdAt: string): TradeOrderFilterRow & { id: string } => ({
  id: `${status}-${tableCode}-${createdAt}`,
  order: { status, createdAt },
  counter: { code: tableCode },
})

describe('trade order filters', () => {
  const rows = [
    row('paid', 'A01', '2026-11-07T15:30:00Z'),
    row('refunded', 'B02', '2026-11-07T16:30:00Z'),
    row('confirmed', 'A01', '2026-11-07T08:00:00Z'),
  ]

  it('defaults to paid and refunded orders', () => {
    const visible = filterTradeOrders(rows, {
      statuses: new Set(DEFAULT_TRADE_ORDER_STATUSES),
      tableCode: '',
      date: '',
      timeZone: 'Asia/Shanghai',
    })

    expect(DEFAULT_TRADE_ORDER_STATUSES).toEqual(['paid', 'refunded'])
    expect(visible.map((item) => item.order.status)).toEqual(['paid', 'refunded'])
  })

  it('filters by table and uses the store date instead of the UTC date', () => {
    expect(tradeOrderDate('2026-11-07T15:30:00Z', 'Asia/Shanghai')).toBe('2026-11-07')
    expect(tradeOrderDate('2026-11-07T16:30:00Z', 'Asia/Shanghai')).toBe('2026-11-08')

    const visible = filterTradeOrders(rows, {
      statuses: new Set(DEFAULT_TRADE_ORDER_STATUSES),
      tableCode: 'B02',
      date: '2026-11-08',
      timeZone: 'Asia/Shanghai',
    })

    expect(visible).toHaveLength(1)
    expect(visible[0]?.order.status).toBe('refunded')
    expect(visible[0]?.counter.code).toBe('B02')
  })

  it('fails closed for invalid dates and time zones', () => {
    expect(tradeOrderDate('not-a-date', 'Asia/Shanghai')).toBe('')
    expect(tradeOrderDate('2026-11-07T10:00:00Z', 'not/a/timezone')).toBe('')
  })
})
