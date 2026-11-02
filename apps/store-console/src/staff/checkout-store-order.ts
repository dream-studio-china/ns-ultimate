import { ApiError } from './adapters/api-client'

export interface StoreOrderProjectionPollOptions {
  timeoutMs?: number
  intervalMs?: number
  now?: () => number
  wait?: (milliseconds: number) => Promise<void>
}

const poll = (milliseconds: number) => new Promise<void>((resolve) => setTimeout(resolve, milliseconds))

/**
 * A new Trade Order can appear in the counter session before its Store Order
 * projection exists. Merge commands require that projection, so wait for it
 * instead of retrying a merge that is known to be too early.
 */
export async function waitForStoreOrderProjection(
  exists: () => Promise<boolean>,
  options: StoreOrderProjectionPollOptions = {},
): Promise<void> {
  const timeoutMs = options.timeoutMs ?? 15_000
  const intervalMs = options.intervalMs ?? 300
  const now = options.now ?? Date.now
  const wait = options.wait ?? poll
  const deadline = now() + timeoutMs

  while (true) {
    if (await exists()) return
    if (now() >= deadline) throw new ApiError('request', 409, 'store_order_projection_timeout')
    await wait(intervalMs)
  }
}
