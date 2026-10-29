import { demoProducts } from '../domain/catalog'
import { total } from '../domain/cart'
import type { CashierGateway, DemoOrder, PaymentResult } from './gateway'

// This adapter never sends a network request or interacts with a payment provider.
export function createDemoGateway(delay = 1000): CashierGateway {
  const attempts = new Map<string, Promise<PaymentResult>>()
  const resolved = new Map<string, PaymentResult>()
  return {
    mode: 'demo',
    catalog: async () => demoProducts,
    quote: async (basket) => total(basket.lines),
    collect(input) {
      const previous = attempts.get(input.requestId)
      if (previous) return previous
      const operation = (async (): Promise<PaymentResult> => {
        const amount = total(input.basket.lines)
        if (!input.basket.lines.length || (input.method === 'cash' && input.received < amount)) throw new Error('Invalid payment')
        await new Promise((resolve) => setTimeout(resolve, delay))
        const order: DemoOrder = {
          id: `DEMO-${input.requestId.slice(0, 8).toUpperCase()}`, createdAt: Date.now(),
          basket: structuredClone(input.basket), total: amount, method: input.method,
          received: input.method === 'cash' ? input.received : amount,
          change: input.method === 'cash' ? input.received - amount : 0,
        }
        const final: PaymentResult = input.outcome === 'failed' ? { status: 'failed', order: null } : { status: 'success', order }
        resolved.set(input.requestId, final)
        return input.outcome === 'unknown' ? { status: 'unknown', order: null } : final
      })()
      attempts.set(input.requestId, operation)
      return operation
    },
    async lookup(requestId) {
      await new Promise((resolve) => setTimeout(resolve, delay / 2))
      return resolved.get(requestId) ?? { status: 'unknown', order: null }
    },
  }
}
