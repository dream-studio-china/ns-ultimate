export type ReportRange = 'today' | 'week' | 'month'
export type ReportPaymentMethod = 'wechat' | 'alipay' | 'cash' | 'other' | 'unrecorded'

export interface ReportingOrder {
  uuid: string
  tradeOrderUuid?: string
  status: string
  paymentStatus?: string | null
  paymentMethod?: string | null
  paidAt?: string | null
  totalAmount: number
  currency: string
  createdAt: string
  orderSnapshot?: unknown
}

export interface ReportingOrderSource {
  allReportingOrders(): Promise<{ items: ReportingOrder[] }>
}

/** Reporting starts from the Store-scoped Trade Order list, never table/session projections. */
export async function loadTradeReportingOrders(api: ReportingOrderSource): Promise<ReportingOrder[]> {
  return (await api.allReportingOrders()).items
}

export interface ReportingWindow {
  start: string
  end: string
}

export interface ReportProduct {
  name: string
  quantity: number
  amount: number
}

export interface ReportSummary {
  orders: ReportingOrder[]
  settledOrders: ReportingOrder[]
  pendingOrders: ReportingOrder[]
  totalAmount: number
  settledAmount: number
  pendingAmount: number
  averageOrder: number
  products: ReportProduct[]
  payments: { method: ReportPaymentMethod; amount: number }[]
  window: ReportingWindow
}

const excludedStatuses = new Set(['cancelled', 'rejected', 'refunded', 'merged'])
const paidStatuses = new Set(['paid', 'fulfilled', 'completed'])

function record(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}
}

function stringValue(value: unknown): string | null {
  return typeof value === 'string' && value.trim() ? value.trim() : null
}

function paymentSnapshot(order: ReportingOrder) {
  const snapshot = record(order.orderSnapshot)
  const payment = record(snapshot.payment)
  return { snapshot, payment }
}

/** Trade order status and payment fields are authoritative for reporting. */
export function orderPaymentState(order: ReportingOrder): { settled: boolean; method: ReportPaymentMethod } {
  const { snapshot, payment } = paymentSnapshot(order)
  const status = order.status.toLowerCase()
  const paymentStatus = (order.paymentStatus ?? stringValue(snapshot.paymentStatus))?.toLowerCase() ?? null
  const snapshotStatus = stringValue(snapshot.status)?.toLowerCase() ?? null
  const settled = Boolean(order.paidAt ?? snapshot.paidAt)
    || (paymentStatus !== null && ['paid', 'partially_refunded'].includes(paymentStatus))
    || paidStatuses.has(status)
    || (snapshotStatus !== null && paidStatuses.has(snapshotStatus))
  const rawMethod = (order.paymentMethod ?? stringValue(snapshot.paymentMethod) ?? stringValue(payment.method))?.toLowerCase() ?? ''
  const method: ReportPaymentMethod = rawMethod.includes('wechat') || rawMethod.includes('weixin')
    ? 'wechat'
    : rawMethod.includes('alipay')
      ? 'alipay'
      : ['cash', 'cod'].some((value) => rawMethod.includes(value))
        ? 'cash'
        : rawMethod
          ? 'other'
          : 'unrecorded'
  return { settled, method }
}

function storeDateParts(date: Date, timeZone: string): { year: number; month: number; day: number; weekday: string } {
  let formatter: Intl.DateTimeFormat
  try {
    formatter = new Intl.DateTimeFormat('en-US', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short' })
  } catch {
    formatter = new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short' })
  }
  const parts = Object.fromEntries(formatter.formatToParts(date).map((part) => [part.type, part.value]))
  return { year: Number(parts.year), month: Number(parts.month), day: Number(parts.day), weekday: parts.weekday ?? 'Mon' }
}

export function reportDateKey(date: Date, timeZone: string): string {
  const parts = storeDateParts(date, timeZone)
  return calendarKey(parts.year, parts.month, parts.day)
}

function calendarKey(year: number, month: number, day: number): string {
  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function calendarDate(key: string): Date {
  const [year, month, day] = key.split('-').map(Number)
  return new Date(Date.UTC(year!, month! - 1, day!))
}

function addDays(key: string, amount: number): string {
  const date = calendarDate(key)
  date.setUTCDate(date.getUTCDate() + amount)
  return calendarKey(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate())
}

function lastDayOfMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month, 0)).getUTCDate()
}

function reportingCutoff(year: number, month: number, reportingDay: number): string {
  return calendarKey(year, month, Math.min(reportingDay, lastDayOfMonth(year, month)))
}

/** A reporting day of 1 is the natural month; other values form rollover monthly cycles. */
export function reportingCycle(today: string, reportingDay: number): ReportingWindow {
  const [year, month, day] = today.split('-').map(Number)
  const cutoff = Math.min(Math.max(1, Math.trunc(reportingDay)), 31)
  const effectiveCutoff = Math.min(cutoff, lastDayOfMonth(year!, month!))
  const startsThisMonth = day! >= effectiveCutoff
  let startYear = year!
  let startMonth = month!
  if (!startsThisMonth) {
    startMonth -= 1
    if (startMonth === 0) { startMonth = 12; startYear -= 1 }
  }
  const start = reportingCutoff(startYear, startMonth, cutoff)
  let nextYear = startYear
  let nextMonth = startMonth + 1
  if (nextMonth === 13) { nextMonth = 1; nextYear += 1 }
  const nextStart = reportingCutoff(nextYear, nextMonth, cutoff)
  return { start, end: addDays(nextStart, -1) }
}

export function reportWindow(range: ReportRange, reportingDay: number, now: Date, timeZone: string): ReportingWindow {
  const today = reportDateKey(now, timeZone)
  if (range === 'today') return { start: today, end: today }
  if (range === 'month') return reportingCycle(today, reportingDay)
  const weekday = storeDateParts(now, timeZone).weekday
  const weekdayIndex = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(weekday)
  const daysSinceMonday = weekdayIndex <= 0 ? 6 : weekdayIndex - 1
  const start = addDays(today, -daysSinceMonday)
  return { start, end: addDays(start, 6) }
}

export function reportingLoadWindow(reportingDay: number, now: Date, timeZone: string): ReportingWindow {
  const today = reportDateKey(now, timeZone)
  const week = reportWindow('week', reportingDay, now, timeZone)
  const month = reportWindow('month', reportingDay, now, timeZone)
  return { start: [today, week.start, month.start].sort()[0]!, end: [today, week.end, month.end].sort().at(-1)! }
}

export function isOrderInReportingWindow(order: ReportingOrder, window: ReportingWindow, today: string, timeZone: string): boolean {
  const created = new Date(order.createdAt)
  if (Number.isNaN(created.getTime())) return false
  const orderDate = reportDateKey(created, timeZone)
  if (orderDate < window.start || orderDate > window.end) return false
  if (excludedStatuses.has(order.status.toLowerCase()) || order.paymentStatus?.toLowerCase() === 'refunded') return false
  return orderDate === today || orderPaymentState(order).settled
}

function snapshotProducts(order: ReportingOrder): { name: string; quantity: number; amount: number }[] {
  const snapshot = record(order.orderSnapshot)
  const items = Array.isArray(snapshot.items) ? snapshot.items : []
  return items.flatMap((item) => {
    const value = record(item)
    const product = record(value.productSnapshot)
    const name = stringValue(value.productName) ?? stringValue(product.name)
    const quantity = typeof value.quantity === 'number' && Number.isInteger(value.quantity) && value.quantity > 0 ? value.quantity : 0
    const amount = typeof value.amount === 'number' && Number.isFinite(value.amount) ? value.amount
      : typeof value.unitPrice === 'number' && Number.isFinite(value.unitPrice) ? value.unitPrice * quantity : 0
    return name && quantity ? [{ name, quantity, amount }] : []
  })
}

export function summarizeReport(orders: ReportingOrder[], range: ReportRange, reportingDay: number, now: Date, timeZone: string): ReportSummary {
  const today = reportDateKey(now, timeZone)
  const window = reportWindow(range, reportingDay, now, timeZone)
  const included = orders.filter((order) => {
    const created = new Date(order.createdAt)
    return !Number.isNaN(created.getTime()) && isOrderInReportingWindow(order, window, today, timeZone)
  })
  const settled = included.filter((order) => orderPaymentState(order).settled)
  const pending = included.filter((order) => !orderPaymentState(order).settled)
  const products = new Map<string, ReportProduct>()
  for (const order of included) for (const item of snapshotProducts(order)) {
    const previous = products.get(item.name)
    products.set(item.name, { name: item.name, quantity: (previous?.quantity ?? 0) + item.quantity, amount: (previous?.amount ?? 0) + item.amount })
  }
  const paymentTotals = new Map<ReportPaymentMethod, number>()
  for (const order of settled) {
    const method = orderPaymentState(order).method
    paymentTotals.set(method, (paymentTotals.get(method) ?? 0) + order.totalAmount)
  }
  const totalAmount = included.reduce((total, order) => total + order.totalAmount, 0)
  const settledAmount = settled.reduce((total, order) => total + order.totalAmount, 0)
  return {
    orders: included,
    settledOrders: settled,
    pendingOrders: pending,
    totalAmount,
    settledAmount,
    pendingAmount: totalAmount - settledAmount,
    averageOrder: included.length ? Math.round(totalAmount / included.length) : 0,
    products: [...products.values()].sort((left, right) => right.quantity - left.quantity),
    payments: [...paymentTotals.entries()].sort((left, right) => right[1] - left[1]).map(([method, amount]) => ({ method, amount })),
    window,
  }
}

export function reportingDayStorageKey(storeUuid: string): string {
  return `ns-store-console.reporting-day.v1:${storeUuid}`
}

export function readReportingDay(storeUuid: string, storage?: Pick<Storage, 'getItem'>): number {
  try {
    const target = storage ?? window.localStorage
    const value = Number(target.getItem(reportingDayStorageKey(storeUuid)))
    return Number.isInteger(value) && value >= 1 && value <= 31 ? value : 1
  } catch {
    return 1
  }
}

export function writeReportingDay(storeUuid: string, day: number, storage?: Pick<Storage, 'setItem'>): boolean {
  if (!Number.isInteger(day) || day < 1 || day > 31) return false
  try {
    ;(storage ?? window.localStorage).setItem(reportingDayStorageKey(storeUuid), String(day))
    return true
  } catch {
    return false
  }
}
