import { ApiError } from './adapters/api-client'
import type { CounterSessionView, OrderCart, OrderQuote } from './adapters/live'
import { unsettledDiningOrders } from './dining-table-state'
import type { DiningMode } from './dining-mode'

const replacementConflictMessages = new Set(['stale_record', 'counter_session_changed', 'staff_order_quote_changed'])

function logCheckoutReplacement(label: string, data: unknown) {
  if (!import.meta.env.DEV || typeof window === 'undefined') return
  console.log(`[store-console][checkout-replace] ${label} ${JSON.stringify(data, null, 2)}`)
}

export function isRetryableCheckoutReplacementConflict(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409 && replacementConflictMessages.has(error.serverMessage ?? '')
}

export interface CheckoutReplacementDependencies {
  loadTable(): Promise<CounterSessionView>
  quote(cart: OrderCart): Promise<OrderQuote>
  replace(body: Record<string, unknown>): Promise<{ orderUuid?: string | null }>
  ensureTargetReady?(targetOrderUuid: string): Promise<void>
  targetOrderUuid: string
  items: OrderCart['items']
  notes: string
  diningMode: Pick<DiningMode, 'uuid' | 'name'> | null
  timeoutMs?: number
  intervalMs?: number
  now?: () => number
  wait?: (milliseconds: number) => Promise<unknown>
  commandUuid?: () => string
}

/** Re-read and re-quote on every attempt because the Store order projection can
 * continue changing the Counter version after a merged order first appears.
 * Order eligibility is intentionally left to the quote/replace endpoints. */
export async function submitCheckoutReplacement({
  loadTable,
  quote,
  replace,
  ensureTargetReady,
  targetOrderUuid,
  items,
  notes,
  diningMode,
  timeoutMs = 10_000,
  intervalMs = 250,
  now = Date.now,
  wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
  commandUuid = () => crypto.randomUUID(),
}: CheckoutReplacementDependencies) {
  const deadline = now() + timeoutMs
  let attempt = 0
  while (true) {
    attempt += 1
    // A newly merged Trade Order can appear in the Counter session before its
    // Store Order projection exists. Replace requires that projection just like
    // merge does, so re-check readiness before every quote/replace attempt.
    await ensureTargetReady?.(targetOrderUuid)
    const table = await loadTable()
    const target = unsettledDiningOrders(table).find((order) => order.uuid === targetOrderUuid)
    logCheckoutReplacement(`attempt ${attempt} · latest Counter`, {
      version: table.version,
      targetOrderUuid,
      targetOrder: target ?? null,
      openOrders: unsettledDiningOrders(table).map((order) => ({
        uuid: order.uuid,
        status: order.status,
        canReplace: order.canReplace,
        changeBlockedReason: order.changeBlockedReason,
        replaceBlockedReason: order.replaceBlockedReason,
      })),
    })
    const cart: OrderCart = {
      version: table.version,
      items,
      notes,
      ...(diningMode ? { metadata: { diningModeUuid: diningMode.uuid, diningModeName: diningMode.name } } : {}),
      replaceOrderUuid: targetOrderUuid,
    }
    try {
      logCheckoutReplacement(`attempt ${attempt} · quote request`, cart)
      const orderQuote = await quote(cart)
      logCheckoutReplacement(`attempt ${attempt} · quote response`, orderQuote)
      const replacementBody = {
        ...cart,
        quoteToken: orderQuote.quoteToken,
        commandUuid: commandUuid(),
        reason: 'Adjust items during checkout',
      }
      logCheckoutReplacement(`attempt ${attempt} · replace request`, replacementBody)
      const result = await replace(replacementBody)
      logCheckoutReplacement(`attempt ${attempt} · replace response`, result)
      return { table, result }
    } catch (error) {
      logCheckoutReplacement(`attempt ${attempt} · quote/replace error`, {
        error,
        kind: error instanceof ApiError ? error.kind : null,
        status: error instanceof ApiError ? error.status : null,
        serverMessage: error instanceof ApiError ? error.serverMessage : null,
        retryable: isRetryableCheckoutReplacementConflict(error),
      })
      if (!isRetryableCheckoutReplacementConflict(error) || now() >= deadline) throw error
    }
    await wait(intervalMs)
  }
}
