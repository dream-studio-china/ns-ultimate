import { describe, expect, it, vi } from 'vitest'
import { ApiClient, ApiError } from '../../src/staff/adapters/api-client'
import { can, counterCommandResultSchema, counterSessionSchema, orderQuoteSchema, sessionOrderSchema, StoreApi, type StoreAccess } from '../../src/staff/adapters/live'
import { loadTradeReportingOrders } from '../../src/staff/reporting'

const id = '00000000-0000-4000-8000-000000000001'
const version = 'a'.repeat(64)
const item = { specificationUuid: id, productName: 'Soup', specificationName: 'Regular', quantity: 1, unitPrice: 1590, amount: 1590 }

describe('live counter session boundary', () => {
  it('normalizes command receipts without requiring optional audit identifiers', () => {
    const orderUuid = '00000000-0000-4000-8000-000000000009'
    const parsed = counterCommandResultSchema.parse({
      result: {
        order: { uuid: orderUuid },
        replacedOrder: { uuid: id },
        status: null,
      },
    })

    expect(parsed).toMatchObject({
      counterUuid: null,
      sessionUuid: null,
      orderUuid,
      replacedOrderUuid: id,
      status: null,
    })
  })

  it('keeps historical non-catalog lines readable without making them valid new cart lines', () => {
    const order = { uuid: id, status: 'pending', operationalStatus: null, paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590, notes: null, createdAt: '2026-10-31T10:00:00Z', canReplace: false, canCancel: false, items: [{ ...item, specificationUuid: null, quantity: 150 }] }
    expect(sessionOrderSchema.safeParse(order).success).toBe(true)
    expect(orderQuoteSchema.safeParse({ items: order.items, totalAmount: 1590, currency: 'USD', quoteToken: version }).success).toBe(false)
    expect(orderQuoteSchema.safeParse({ items: [item], totalAmount: Number.MAX_SAFE_INTEGER + 1, currency: 'USD', quoteToken: version }).success).toBe(false)
    expect(orderQuoteSchema.safeParse({ items: [item], totalAmount: 1590, currency: 'USD', quoteToken: version }).success).toBe(true)
  })

  it('reads the dining mode from Trade Order metadata before rendering an order', () => {
    const metadata = { diningModeUuid: 'dining-mode-hotpot', diningModeName: '火锅', guests: 4 }
    const order = { uuid: id, status: 'pending', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590, notes: null, createdAt: '2026-10-31T10:00:00Z', canReplace: true, canCancel: true, items: [item], metadata }
    const parsed = sessionOrderSchema.parse(order)
    expect(parsed.tradeOrderUuid).toBe(id)
    expect(parsed.metadata).toEqual(metadata)
    expect(parsed.diningModeUuid).toBe(metadata.diningModeUuid)
    expect(parsed.diningModeName).toBe(metadata.diningModeName)
  })

  it('reads a name-only dining mode directly from order metadata', () => {
    const metadata = { diningModeName: '火锅' }
    const order = { uuid: id, status: 'pending', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590, notes: null, createdAt: '2026-10-31T10:00:00Z', canReplace: true, canCancel: true, items: [item], metadata }
    const parsed = sessionOrderSchema.parse(order)
    expect(parsed.metadata).toEqual(metadata)
    expect(parsed.diningModeUuid).toBeNull()
    expect(parsed.diningModeName).toBe(metadata.diningModeName)
  })

  it('normalizes object-valued diningModeUuid returned by the Counter API', () => {
    const order = {
      uuid: id, status: 'pending', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590,
      notes: null, createdAt: '2026-10-31T10:00:00Z', canReplace: true, canCancel: true, items: [item],
      diningModeUuid: { uuid: 'dining-mode-hotpot', name: '火锅' },
    }
    const parsed = sessionOrderSchema.parse(order)
    expect(parsed.diningModeUuid).toBe('dining-mode-hotpot')
    expect(parsed.diningModeName).toBe('火锅')
  })

  it('reads the dining mode fields returned by the store counter API', () => {
    const orderUuid = '00000000-0000-4000-8000-000000000009'
    const table = counterSessionSchema.parse({
      counter: { uuid: id, code: 'A01', name: 'Window', capacity: 4, enabled: true }, session: null, ordersVisible: true, currency: 'USD', canEnd: false, version,
      orders: [{ uuid: orderUuid, status: 'pending', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590, notes: null, createdAt: '2026-10-31T10:00:00Z', canReplace: true, canCancel: true, items: [item], diningModeUuid: 'dining-mode-hotpot', diningModeName: '火锅' }],
      openAmount: 1590, orderedAmount: 1590, openOrderCount: 1, paymentHold: false,
    })
    expect(table.ordersVisible && table.orders[0]).toMatchObject({ diningModeUuid: 'dining-mode-hotpot', diningModeName: '火锅' })
  })

  it('reads the dining mode stored at the Counter session level', () => {
    const parsed = counterSessionSchema.parse({
      counter: { uuid: id, code: 'A01', name: 'Window', capacity: 4, enabled: true },
      session: {
        uuid: '00000000-0000-4000-8000-000000000002', participantCount: 2, notes: null,
        startedAt: '2026-10-31T10:00:00Z', revision: 0,
        diningModeUuid: 'dining-mode-hotpot', diningModeName: '火锅',
      },
      ordersVisible: true, currency: 'USD', canEnd: false, version,
      orders: [], openAmount: 0, orderedAmount: 0, openOrderCount: 0, paymentHold: false,
    })

    expect(parsed.session).toMatchObject({
      diningModeUuid: 'dining-mode-hotpot',
      diningModeName: '火锅',
    })
  })

  it('reads a name-only dining mode from Counter session metadata', () => {
    const parsed = counterSessionSchema.parse({
      counter: { uuid: id, code: 'A01', name: 'Window', capacity: 4, enabled: true },
      session: {
        uuid: '00000000-0000-4000-8000-000000000002', participantCount: 2, notes: null,
        startedAt: '2026-10-31T10:00:00Z', revision: 0,
        metadata: JSON.stringify({ dining_mode_name: '火锅' }),
      },
      ordersVisible: false, currency: 'USD', canEnd: false, version,
    })

    expect(parsed.session).toMatchObject({
      diningModeUuid: null,
      diningModeName: '火锅',
    })
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
    await api.counterCommand(id, 'confirm', { version, orderUuid: id, commandUuid: id })
    expect(data.mock.calls[5]![0]).toBe(`/api/v1/store/${id}/counter-operations/counters/${id}/orders/confirm`)
    expect(() => api.counterCommand('../other', 'create', command)).toThrow()
  })

  it('loads reporting rows from Trade orders instead of Store operational orders', async () => {
    const client = new ApiClient()
    const row = {
      uuid: id, status: 'pending', paymentStatus: 'unpaid', paymentMethod: null, paidAt: null,
      totalAmount: 1590, currency: 'USD', createdAt: '2026-10-31T10:00:00Z',
      orderSnapshot: { items: [{ productName: 'Soup', quantity: 1, unitPrice: 1590, amount: 1590 }] },
    }
    const data = vi.spyOn(client, 'data')
      .mockResolvedValueOnce({ items: [row], page: 1, hasNext: true, total: 2 })
      .mockResolvedValueOnce({ items: [{ ...row, uuid: '00000000-0000-4000-8000-000000000002' }], page: 2, hasNext: false, total: 2 })
    const api = new StoreApi(client, id)

    await expect(api.allReportingOrders()).resolves.toMatchObject({ total: 2 })
    expect(data.mock.calls.map(([path]) => path)).toEqual([
      `/api/v1/store/${id}/operations/reporting-orders?page=1&limit=100`,
      `/api/v1/store/${id}/operations/reporting-orders?page=2&limit=100`,
    ])
  })

  it('builds report rows from Trade Orders returned by the deployed counter query', async () => {
    const order = { uuid: id, status: 'paid', paymentStatus: 'paid', totalAmount: 1590, currency: 'USD', createdAt: '2026-10-31T10:00:00Z', items: [item] }
    const allCounterSessions = vi.fn().mockResolvedValue([
      { ordersVisible: true, orders: [order, order] },
      { ordersVisible: false },
    ])

    await expect(loadTradeReportingOrders({ allCounterSessions })).resolves.toEqual([{
      uuid: id,
      tradeOrderUuid: id,
      status: 'paid',
      paymentStatus: 'paid',
      paymentMethod: null,
      paidAt: null,
      totalAmount: 1590,
      currency: 'USD',
      createdAt: '2026-10-31T10:00:00Z',
      orderSnapshot: { items: [item] },
    }])
    expect(allCounterSessions).toHaveBeenCalledTimes(1)
  })

  it('looks up the Store order projection by the new Trade order UUID', async () => {
    const client = new ApiClient()
    const data = vi.spyOn(client, 'data').mockResolvedValue({
      items: [{ tradeOrderUuid: id }], page: 1, hasNext: false, total: 1,
    })
    const api = new StoreApi(client, id)

    await expect(api.storeOrderExists(id)).resolves.toBe(true)
    expect(data.mock.calls[0]![0]).toBe(`/api/v1/store/${id}/operations/orders?page=1&limit=30&q=${id}&status=all`)

    data.mockRejectedValueOnce(new ApiError('forbidden', 403))
    await expect(api.storeOrderExists(id)).resolves.toBeNull()
  })

  it('reads refund history without a mutation and rejects a mismatched Trade order', async () => {
    const tradeOrderUuid = '00000000-0000-4000-8000-000000000004'
    const refund = {
      uuid: id, tradeOrderUuid, invoiceUuid: id, amount: 500,
      reason: 'Customer request', status: 'succeeded', actorUuid: id, createdAt: '2026-11-05T10:00:00+08:00',
      invoiceStatus: 'partial_refunded', refundedAmount: 500,
    }
    const client = new ApiClient()
    const data = vi.spyOn(client, 'data').mockResolvedValueOnce([refund]).mockResolvedValueOnce([{ ...refund, tradeOrderUuid: id }])
    const api = new StoreApi(client, id)
    await expect(api.refundHistory(tradeOrderUuid)).resolves.toEqual([refund])
    expect(data.mock.calls[0]![0]).toBe(`/api/v1/store/${id}/trade-orders/${tradeOrderUuid}/refunds`)
    expect(data.mock.calls[0]![2]).toBeUndefined()
    await expect(api.refundHistory(tradeOrderUuid)).rejects.toBeInstanceOf(ApiError)
  })

  it('submits a store order refund through the unified Trade order API', async () => {
    const invoiceUuid = '00000000-0000-4000-8000-000000000003'
    const tradeOrderUuid = '00000000-0000-4000-8000-000000000004'
    const payment = {
      orderUuid: tradeOrderUuid,
      amount: 2000,
      currency: 'CNY',
      invoice: { uuid: invoiceUuid, status: 'paid', amount: 2000, refundedAmount: 0, currency: 'CNY', payment: 'wechat' },
    }
    const refund = {
      uuid: '00000000-0000-4000-8000-000000000005', tradeOrderUuid, invoiceUuid, amount: 500,
      reason: 'Customer request', status: 'processing', actorUuid: id, createdAt: '2026-11-05T10:00:00+08:00',
      invoiceStatus: 'paid', refundedAmount: 0,
    }
    const client = new ApiClient()
    const data = vi.spyOn(client, 'data')
      .mockResolvedValueOnce(payment)
      .mockResolvedValueOnce(refund)
    const api = new StoreApi(client, id)

    const preview = await api.refundPreview(tradeOrderUuid)
    await expect(api.refundOrder(tradeOrderUuid, 500, 'Customer request', 'refund-command')).resolves.toEqual(refund)
    expect(data.mock.calls.map(([path]) => path)).toEqual([
      `/api/v1/public/orders/${tradeOrderUuid}/payment`,
      `/api/v1/store/${id}/trade-orders/${tradeOrderUuid}/refund`,
    ])
    expect(preview.payment).toBe('wechat')
    expect(data.mock.calls[1]![3]).toEqual({ amount: 500, reason: 'Customer request', idempotencyKey: 'refund-command' })
  })
})
