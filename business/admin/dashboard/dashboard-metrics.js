export const TEST_STORE_CODE = 'NS001'

const PAID_INVOICE_STATUSES = new Set(['paid', 'partial_refunded', 'refunded'])
const PAID_ORDER_STATUSES = new Set(['paid', 'fulfilled', 'completed', 'refunded'])
const AWAITING_PAYMENT_ORDER_STATUSES = new Set(['draft', 'pending', 'confirmed'])
const CLOSED_ORDER_STATUSES = new Set(['cancelled', 'rejected', 'merged'])

const asCents = (value) => {
  const amount = Number(value)
  return Number.isFinite(amount) ? amount : 0
}

export function toDateInputValue(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export function dayBounds(dateValue) {
  const [year, month, day] = String(dateValue).split('-').map(Number)
  const start = new Date(year, month - 1, day, 0, 0, 0, 0)
  const end = new Date(year, month - 1, day + 1, 0, 0, 0, 0)
  return { start: dateTimeWithOffset(start), end: dateTimeWithOffset(end) }
}

function dateTimeWithOffset(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  const seconds = String(date.getSeconds()).padStart(2, '0')
  const offsetMinutes = -date.getTimezoneOffset()
  const sign = offsetMinutes >= 0 ? '+' : '-'
  const offsetHours = String(Math.floor(Math.abs(offsetMinutes) / 60)).padStart(2, '0')
  const offsetRemainder = String(Math.abs(offsetMinutes) % 60).padStart(2, '0')
  return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}${sign}${offsetHours}:${offsetRemainder}`
}

export function orderStore(order = {}) {
  return order.metadata?._store || order.store || {}
}

export function orderStoreUuid(order = {}) {
  const store = orderStore(order)
  return String(store.uuid || order.storeUuid || order.storeUuidSnapshot || '').trim().toLowerCase()
}

export function filterOrdersByStore(orders = [], storeUuid = '') {
  const targetStoreUuid = String(storeUuid || '').trim().toLowerCase()
  if (!targetStoreUuid) return orders
  return orders.filter(order => orderStoreUuid(order) === targetStoreUuid)
}

export function orderStoreName(order = {}) {
  return orderStore(order).name || order.storeNameSnapshot || order.storeName || '-'
}

export function isTestStoreOrder(order = {}) {
  return orderStore(order).code === TEST_STORE_CODE || order.storeCodeSnapshot === TEST_STORE_CODE
}

export function isTestStore(store = {}) {
  return store.code === TEST_STORE_CODE
}

function successfulInvoicesBySource(invoices) {
  const grouped = new Map()
  invoices.forEach(invoice => {
    if (invoice.sourceType && invoice.sourceType !== 'trade_order') return
    if (!PAID_INVOICE_STATUSES.has(invoice.status)) return
    if (!invoice.sourceId) return
    const current = grouped.get(invoice.sourceId)
    if (!current || paymentTime(invoice) >= paymentTime(current)) grouped.set(invoice.sourceId, invoice)
  })
  return grouped
}

function paymentTime(invoice) {
  const value = invoice.paidAt || invoice.updatedAt || invoice.createdAt
  const timestamp = value ? new Date(value).getTime() : 0
  return Number.isFinite(timestamp) ? timestamp : 0
}

function paymentState(order, invoice) {
  if (order.status === 'refunded' || invoice?.status === 'refunded') return 'refunded'

  const paid = Boolean(invoice) ||
    PAID_ORDER_STATUSES.has(order.status) ||
    PAID_INVOICE_STATUSES.has(order.paymentStatus) ||
    Boolean(order.paidAt)
  if (paid) return 'paid'

  if (CLOSED_ORDER_STATUSES.has(order.status)) return 'closed'
  if (AWAITING_PAYMENT_ORDER_STATUSES.has(order.status)) return 'pending'
  return 'pending'
}

export function buildDashboardSnapshot({
  orders = [],
  invoices = [],
  storeCount = 0,
  productCount = 0,
  materialCount = 0,
  recentLimit = 8,
  dayStart = null,
  dayEnd = null
} = {}) {
  const scopedOrders = orders.filter(order => !isTestStoreOrder(order))
  const validOrders = scopedOrders.filter(order => !CLOSED_ORDER_STATUSES.has(order.status))
  const invoicesBySource = successfulInvoicesBySource(invoices)
  const paidByHour = Array.from({ length: 24 }, () => 0)

  let totalAmount = 0
  let settledAmount = 0
  let pendingAmount = 0
  let refundedAmount = 0
  let pendingOrders = 0
  let paidOrderCount = 0

  validOrders.forEach(order => {
    const amount = asCents(order.totalAmount)
    const invoice = invoicesBySource.get(order.uuid)
    const state = paymentState(order, invoice)

    totalAmount += amount
    if (state === 'paid') {
      settledAmount += invoice ? asCents(invoice.amount) : amount
      paidOrderCount += 1

      const paidAt = invoice?.paidAt || order.paidAt
      const paidTimestamp = paidAt ? new Date(paidAt).getTime() : NaN
      const startTimestamp = dayStart ? new Date(dayStart).getTime() : -Infinity
      const endTimestamp = dayEnd ? new Date(dayEnd).getTime() : Infinity
      if (paidTimestamp >= startTimestamp && paidTimestamp < endTimestamp) {
        const hour = new Date(paidAt).getHours()
        if (hour >= 0 && hour < 24) paidByHour[hour] += invoice ? asCents(invoice.amount) : amount
      }
    }
    if (state === 'pending') pendingAmount += amount

    if (invoice) {
      refundedAmount += asCents(invoice.refundedAmount)
    } else if (order.status === 'refunded') {
      refundedAmount += amount
    }

    if (AWAITING_PAYMENT_ORDER_STATUSES.has(order.status)) pendingOrders += 1
  })

  const recentOrders = validOrders
    .map(order => {
      const invoice = invoicesBySource.get(order.uuid)
      return {
        ...order,
        invoice,
        storeName: orderStoreName(order),
        paymentState: paymentState(order, invoice)
      }
    })
    .filter(order => order.paymentState === 'paid' || order.paymentState === 'pending')
    .sort((left, right) => {
      const rightTime = new Date(right.createdAt || right.paidAt || 0).getTime() || 0
      const leftTime = new Date(left.createdAt || left.paidAt || 0).getTime() || 0
      return rightTime - leftTime
    })
    .slice(0, recentLimit)

  return {
    orderCount: validOrders.length,
    pendingOrders,
    paidOrderCount,
    totalAmount,
    settledAmount,
    pendingAmount,
    refundedAmount,
    paidByHour,
    recentOrders,
    stores: Math.max(0, Number(storeCount) || 0),
    products: Math.max(0, Number(productCount) || 0),
    materials: Math.max(0, Number(materialCount) || 0)
  }
}
