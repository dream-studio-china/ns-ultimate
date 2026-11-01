import type { CounterSessionView, SessionOrder } from './adapters/live'

const closedStatuses = new Set(['completed', 'cancelled', 'refunded', 'merged'])
const mergeableStatuses = new Set(['pending', 'confirmed'])
const maximumOrderCount = 200
const maximumProductCount = 30
const maximumQuantity = 99

export type CheckoutMergeBlocker =
  | { code: 'table_disabled' }
  | { code: 'session_missing' }
  | { code: 'payment_hold' }
  | { code: 'order_limit' }
  | { code: 'too_few_orders'; count: number }
  | { code: 'no_items' }
  | { code: 'order_status'; orderUuid: string; status: string }
  | { code: 'payment_status'; orderUuid: string; paymentStatus: string }
  | { code: 'invoice'; orderUuid: string }
  | { code: 'order_ineligible'; orderUuid: string; reason: string | null }
  | { code: 'invalid_item'; orderUuid: string; productName: string; specificationName: string }
  | { code: 'product_limit'; count: number }
  | { code: 'quantity_limit'; productName: string; specificationName: string; quantity: number }

function activeOrders(table: CounterSessionView): SessionOrder[] {
  return table.ordersVisible ? table.orders.filter((order) => !closedStatuses.has(order.status)) : []
}

/**
 * Checks every source order before checkout creates another round or calls the merge API.
 * The backend remains authoritative; this prevents avoidable writes and exposes the same
 * eligibility fields already returned by the counter-session read.
 */
export function checkoutMergeBlockers(table: CounterSessionView, includeAggregate = true): CheckoutMergeBlocker[] {
  const blockers: CheckoutMergeBlocker[] = []
  if (!table.counter.enabled) blockers.push({ code: 'table_disabled' })
  if (table.session === null) blockers.push({ code: 'session_missing' })
  if (!table.ordersVisible) return blockers
  if (table.paymentHold) blockers.push({ code: 'payment_hold' })
  if (table.orders.length >= maximumOrderCount) blockers.push({ code: 'order_limit' })

  const orders = activeOrders(table)
  const quantities = new Map<string, { productName: string; specificationName: string; quantity: number }>()
  let eligibleOrderCount = 0
  for (const order of orders) {
    let orderBlocker = false
    if (!mergeableStatuses.has(order.status)) {
      blockers.push({ code: 'order_status', orderUuid: order.uuid, status: order.status })
      orderBlocker = true
    }
    if (order.paymentStatus !== null) {
      blockers.push({ code: 'payment_status', orderUuid: order.uuid, paymentStatus: order.paymentStatus })
      orderBlocker = true
    }
    if (order.hasInvoice) {
      blockers.push({ code: 'invoice', orderUuid: order.uuid })
      orderBlocker = true
    }
    // changeBlockedReason mirrors the Trade lifecycle and Counter binding checks used by
    // merge. canReplace is stricter because it also guards item-level replacement input.
    if (order.changeBlockedReason !== null) {
      blockers.push({ code: 'order_ineligible', orderUuid: order.uuid, reason: order.changeBlockedReason })
      orderBlocker = true
    }
    for (const item of order.items) {
      if (item.specificationUuid === null || !Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > maximumQuantity) {
        blockers.push({
          code: 'invalid_item',
          orderUuid: order.uuid,
          productName: item.productName,
          specificationName: item.specificationName,
        })
        orderBlocker = true
      }
    }
    if (orderBlocker) continue
    for (const item of order.items) {
      // The first pass excludes the whole order when any line is invalid, so
      // aggregate limits never include products from an order that cannot merge.
      const specificationUuid = item.specificationUuid!
      const current = quantities.get(specificationUuid)
      quantities.set(specificationUuid, {
        productName: item.productName,
        specificationName: item.specificationName,
        quantity: (current?.quantity ?? 0) + item.quantity,
      })
    }
    eligibleOrderCount += 1
  }

  if (eligibleOrderCount < 2) blockers.push({ code: 'too_few_orders', count: eligibleOrderCount })
  if (eligibleOrderCount >= 2 && quantities.size === 0) blockers.push({ code: 'no_items' })
  if (includeAggregate) {
    if (quantities.size > maximumProductCount) blockers.push({ code: 'product_limit', count: quantities.size })
    for (const item of quantities.values()) {
      if (item.quantity > maximumQuantity) blockers.push({ code: 'quantity_limit', ...item })
    }
  }
  return blockers
}
