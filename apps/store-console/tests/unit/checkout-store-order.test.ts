import { describe, expect, it, vi } from 'vitest'
import { waitForStoreOrderProjection } from '../../src/staff/checkout-store-order'

describe('Store order projection polling', () => {
  it('polls until the Store order exists', async () => {
    const exists = vi.fn<() => Promise<boolean>>()
      .mockResolvedValueOnce(false)
      .mockResolvedValueOnce(false)
      .mockResolvedValueOnce(true)
    let clock = 0

    await expect(waitForStoreOrderProjection(exists, {
      timeoutMs: 1_000,
      intervalMs: 100,
      now: () => clock,
      wait: async (milliseconds) => { clock += milliseconds },
    })).resolves.toBeUndefined()

    expect(exists).toHaveBeenCalledTimes(3)
    expect(clock).toBe(200)
  })

  it('stops with a specific error when projection never appears', async () => {
    const exists = vi.fn<() => Promise<boolean>>().mockResolvedValue(false)
    let clock = 0

    await expect(waitForStoreOrderProjection(exists, {
      timeoutMs: 200,
      intervalMs: 100,
      now: () => clock,
      wait: async (milliseconds) => { clock += milliseconds },
    })).rejects.toMatchObject({ status: 409, serverMessage: 'store_order_projection_timeout' })

    expect(exists).toHaveBeenCalledTimes(3)
  })
})
