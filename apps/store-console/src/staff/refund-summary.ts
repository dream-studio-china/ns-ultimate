import type { StoreApi } from './adapters/live'
import type { ReportingOrder } from './reporting'

export interface RefundSummary {
  amount: number
  resolved: number
  failed: number
}

/**
 * Reads payment-page refund projections for a bounded set of settled orders.
 * Only completed refund amounts exposed by the payment record are counted;
 * an unresolved or failed read is reported instead of being treated as zero.
 */
export async function loadRefundSummary(api: StoreApi, orders: readonly ReportingOrder[]): Promise<RefundSummary> {
  const unique = [...new Map(orders.map((order) => [order.tradeOrderUuid ?? order.uuid, order])).values()]
  let next = 0
  let amount = 0
  let resolved = 0
  let failed = 0

  async function worker() {
    while (next < unique.length) {
      const order = unique[next++]!
      try {
        const preview = await api.refundPreview(order.tradeOrderUuid ?? order.uuid)
        amount += preview.refundedAmount
        resolved += 1
      } catch {
        failed += 1
      }
    }
  }

  await Promise.all(Array.from({ length: Math.min(4, unique.length) }, () => worker()))
  return { amount, resolved, failed }
}
