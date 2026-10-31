import { describe, expect, it, vi } from 'vitest'
import { ApiClient } from '../../src/staff/adapters/api-client'
import { can, counterMergeQuoteSchema, counterSessionSchema, orderQuoteSchema, sessionOrderSchema, StoreApi, type StoreAccess } from '../../src/staff/adapters/live'

const id = '00000000-0000-4000-8000-000000000001'
const version = 'a'.repeat(64)
const item = { specificationUuid: id, productName: 'Soup', specificationName: 'Regular', quantity: 1, unitPrice: 1590, amount: 1590 }

describe('live counter session boundary', () => {
  it('keeps historical non-catalog lines readable without making them valid new cart lines', () => {
    const order = { uuid: id, status: 'pending', operationalStatus: null, paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590, notes: null, createdAt: '2026-10-31T10:00:00Z', canReplace: false, canCancel: false, items: [{ ...item, specificationUuid: null, quantity: 150 }] }
    expect(sessionOrderSchema.safeParse(order).success).toBe(true)
    expect(orderQuoteSchema.safeParse({ items: order.items, totalAmount: 1590, currency: 'USD', quoteToken: version }).success).toBe(false)
    expect(orderQuoteSchema.safeParse({ items: [item], totalAmount: Number.MAX_SAFE_INTEGER + 1, currency: 'USD', quoteToken: version }).success).toBe(false)
    expect(orderQuoteSchema.safeParse({ items: [item], totalAmount: 1590, currency: 'USD', quoteToken: version }).success).toBe(true)
  })

  it('accepts a redacted session read that omits order rows and amounts', () => {
    const redacted = { counter: { uuid: id, code: 'A01', name: 'Window', capacity: 4, enabled: true }, session: null, ordersVisible: false, currency: 'USD', canEnd: false, version }
    const parsed = counterSessionSchema.parse(redacted)
    expect(parsed.ordersVisible).toBe(false)
    expect('orders' in parsed).toBe(false)
  })

  it('rejects an order-visible session that omits rows or amounts instead of inventing zero data', () => {
    const base = { counter: { uuid: id, code: 'A01', name: 'Window', capacity: 4, enabled: true }, session: null, ordersVisible: true, currency: 'USD', canEnd: false, version }
    expect(counterSessionSchema.safeParse(base).success).toBe(false)
    expect(counterSessionSchema.safeParse({ ...base, orders: [], openAmount: 0, orderedAmount: 0, openOrderCount: 0 }).success).toBe(false)
    expect(counterSessionSchema.safeParse({ ...base, orders: [], openAmount: 0, orderedAmount: 0, openOrderCount: 0, paymentHold: false }).success).toBe(true)
  })

  it('separates session and order grants from catalog, fulfillment and membership labels', () => {
    const access: StoreAccess = { store: { uuid: id, name: 'Store', code: 'S1', currency: 'USD', timezone: 'UTC' }, membership: { status: 'active', role: 'owner' }, profile: { uuid: id, username: 'staff', roles: ['ROLE_USER'] }, authorization: { permissions: ['store:counter_order:create'], storeScopes: { 'store:counter_session:read': [id], 'store:counter_order:read': [id], 'store:order:accept': [id] } } }
    expect(can(access, 'counter_session', 'read')).toBe(true)
    expect(can(access, 'counter_order', 'create')).toBe(false)
    expect(can(access, 'counter_session', 'end')).toBe(false)
    expect(can(access, 'counter_order', 'replace')).toBe(false)
    const editor = { ...access, authorization: { ...access.authorization, storeScopes: { 'store:counter_session:read': [id], 'store:counter_order:read': [id], 'store:counter_order:replace': [id] } } }
    expect(can(editor, 'counter_order', 'replace')).toBe(true)
    expect(can(editor, 'counter_order', 'create')).toBe(false)
    expect(can(access, 'order', 'accept')).toBe(true)
  })

  it('uses explicit counter-session quote and command paths without accepting client prices or actor identities', async () => {
    const client = new ApiClient()
    const data = vi.spyOn(client, 'data').mockResolvedValue({})
    const api = new StoreApi(client, id)
    const cart = { version, items: [{ specificationUuid: id, quantity: 2 }], notes: 'Less salt' }
    await api.orderQuote(id, cart)
    expect(data.mock.calls[0]).toEqual([`/api/v1/store/${id}/counter-operations/counters/${id}/orders/quote`, orderQuoteSchema, 'POST', cart])
    const command = { ...cart, commandUuid: id, quoteToken: version }
    await api.counterCommand(id, 'create', command)
    expect(data.mock.calls[1]![0]).toBe(`/api/v1/store/${id}/counter-operations/counters/${id}/orders/create`)
    expect(data.mock.calls[1]![3]).toEqual(command)
    const replacement = { ...command, replaceOrderUuid: id, reason: 'Correct quantity' }
    await api.counterCommand(id, 'replace', replacement)
    expect(data.mock.calls[2]![0]).toBe(`/api/v1/store/${id}/counter-operations/counters/${id}/orders/replace`)
    expect(data.mock.calls[2]![3]).toEqual(replacement)
    await api.counterCommand(id, 'end', { version, commandUuid: id })
    expect(data.mock.calls[3]![0]).toBe(`/api/v1/store/${id}/counter-operations/counters/${id}/session/end`)
    await api.counterCommand(id, 'cancel', { version, orderUuid: id, reason: 'Guest left', commandUuid: id })
    expect(data.mock.calls[4]![0]).toBe(`/api/v1/store/${id}/counter-operations/counters/${id}/orders/cancel`)
    await api.previewCounterMerge(id, version)
    expect(data.mock.calls[5]).toEqual([`/api/v1/store/${id}/counter-operations/counters/${id}/merge/preview`, counterMergeQuoteSchema, 'POST', { version }])
    const mergeConfirm = { version, commandUuid: id, quoteToken: version }
    await api.confirmCounterMerge(id, mergeConfirm)
    expect(data.mock.calls[6]).toEqual([`/api/v1/store/${id}/counter-operations/counters/${id}/merge/confirm`, expect.anything(), 'POST', mergeConfirm])
    expect(() => api.counterCommand('../other', 'create', command)).toThrow()
  })
})
