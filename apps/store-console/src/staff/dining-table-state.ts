import type { CounterSessionView, SessionOrder } from './adapters/live'

const unsettledStatuses = new Set(['pending', 'confirmed'])

/** Pending and confirmed Trade orders are visible to staff; only confirmed orders can be settled. */
export function unsettledDiningOrders(table: CounterSessionView): SessionOrder[] {
  return table.ordersVisible ? table.orders.filter((order) => unsettledStatuses.has(order.status)) : []
}

export function hasUnsettledDiningOrders(table: CounterSessionView): boolean {
  return unsettledDiningOrders(table).length > 0
}

/** Restricted readers can only infer occupancy from the presence of an active session. */
export function isOccupiedDiningTable(table: CounterSessionView): boolean {
  return table.ordersVisible ? hasUnsettledDiningOrders(table) : table.session !== null
}
