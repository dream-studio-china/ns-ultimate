import { describe, expect, it } from 'vitest'
import { createDemoGateway } from '../../src/adapters/demo'
import { addProduct, emptyBasket } from '../../src/domain/cart'
import { demoProducts } from '../../src/domain/catalog'

const basket = { ...emptyBasket(), lines: addProduct([], demoProducts[0], { size: 'regular', temperature: 'hot' }, 1) }
describe('explicit demo payment adapter', () => {
  it('quotes integer amounts and calculates change', async () => {
    const gateway = createDemoGateway(0)
    expect(gateway.mode).toBe('demo')
    expect(await gateway.quote(basket)).toBe(1800)
    const result = await gateway.collect({ requestId: 'test-one', basket, method: 'cash', received: 2000, outcome: 'success' })
    expect(result.status).toBe('success')
    expect(result.order?.change).toBe(200)
    expect(result.order?.id).toMatch(/^DEMO-/)
  })
  it('deduplicates repeated requests including concurrent calls', async () => {
    const gateway = createDemoGateway(0)
    const input = { requestId: 'same-key', basket, method: 'cash', received: 2000, outcome: 'success' } as const
    const first = gateway.collect(input)
    const second = gateway.collect(input)
    expect(first).toBe(second)
    expect(await first).toBe(await second)
  })
  it('requires lookup after an unknown outcome', async () => {
    const gateway = createDemoGateway(0)
    const result = await gateway.collect({ requestId: 'unknown-key', basket, method: 'customerScan', received: 1800, outcome: 'unknown' })
    expect(result).toEqual({ status: 'unknown', order: null })
    expect((await gateway.lookup('unknown-key')).status).toBe('success')
    expect((await gateway.lookup('missing-key')).status).toBe('unknown')
  })
  it('failed outcomes create no receipt; invalid payments are rejected', async () => {
    const gateway = createDemoGateway(0)
    expect(await gateway.collect({ requestId: 'failed-key', basket, method: 'cash', received: 2000, outcome: 'failed' })).toEqual({ status: 'failed', order: null })
    await expect(gateway.collect({ requestId: 'invalid', basket, method: 'cash', received: 100, outcome: 'success' })).rejects.toThrow()
  })
})
