import type { CounterSessionView, SessionOrder } from './adapters/live'

const settlementStatuses = new Set(['pending', 'confirmed'])

export type CheckoutMergeBlocker =
  { code: 'order_status'; orderUuid: string; status: string }

function settlementOrders(table: CounterSessionView): SessionOrder[] {
  return table.ordersVisible ? table.orders.filter((order) => settlementStatuses.has(order.status)) : []
}

/**
 * The checkout UI only gates merging on Trade Order status. Payment, invoice,
 * table, session, item and aggregate eligibility remain backend concerns.
 */
export function checkoutMergeBlockers(table: CounterSessionView): CheckoutMergeBlocker[] {
  return settlementOrders(table)
    .filter((order) => order.status !== 'confirmed')
    .map((order) => ({ code: 'order_status', orderUuid: order.uuid, status: order.status }))
}
