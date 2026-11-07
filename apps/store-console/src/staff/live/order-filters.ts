export const DEFAULT_TRADE_ORDER_STATUSES = ['paid', 'refunded'] as const

export interface TradeOrderFilterRow {
  order: {
    status: string
    createdAt: string
  }
  counter: {
    code: string
  }
}

export interface TradeOrderFilters {
  statuses: ReadonlySet<string>
  tableCode: string
  date: string
  timeZone: string
}

export function tradeOrderDate(value: string, timeZone: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  try {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(date)
    const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((item) => item.type === type)?.value ?? ''
    const year = part('year')
    const month = part('month')
    const day = part('day')
    return year && month && day ? `${year}-${month}-${day}` : ''
  } catch {
    return ''
  }
}

export function filterTradeOrders<T extends TradeOrderFilterRow>(rows: T[], filters: TradeOrderFilters): T[] {
  return rows.filter(({ order, counter }) => filters.statuses.has(order.status)
    && (!filters.tableCode || counter.code === filters.tableCode)
    && (!filters.date || tradeOrderDate(order.createdAt, filters.timeZone) === filters.date))
}
