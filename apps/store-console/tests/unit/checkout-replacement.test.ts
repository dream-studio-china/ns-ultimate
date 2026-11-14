import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../src/staff/adapters/api-client'
import type { CounterSessionView, SessionOrder } from '../../src/staff/adapters/live'
import { submitCheckoutReplacement } from '../../src/staff/checkout-replacement'

const storeUuid = '00000000-0000-4000-8000-000000000001'
const counterUuid = '00000000-0000-4000-8000-000000000002'
const specUuid = '00000000-0000-4000-8000-000000000003'
const mergedOrderUuid = '00000000-0000-4000-8000-000000000004'
const oldVersion = 'a'.repeat(64)
const newVersion = 'b'.repeat(64)
const oldQuoteToken = 'c'.repeat(64)
const newQuoteToken = 'd'.repeat(64)

function mergedOrder(): SessionOrder {
  return {
    uuid: mergedOrderUuid,
    tradeOrderUuid: mergedOrderUuid,
    status: 'confirmed',
    paymentStatus: null,
    hasInvoice: false,
    currency: 'CNY',
    totalAmount: 200,
    originalAmount: 200,
    discountAmount: 0,
    discounts: [],
    notes: null,
    createdAt: '2026-11-02T10:00:00Z',
    metadata: { diningModeUuid: 'mode-hotpot', diningModeName: '火锅' },
    diningModeUuid: 'mode-hotpot',
    diningModeName: '火锅',
    items: [{ specificationUuid: specUuid, productName: 'Soup', specificationName: 'Regular', quantity: 2, unitPrice: 100, amount: 200 }],
    canReplace: true,
    canCancel: true,
    changeBlockedReason: null,
    replaceBlockedReason: null,
  }
}

function table(version: string): CounterSessionView {
  return {
    counter: { uuid: counterUuid, code: 'A01', name: 'Window', capacity: 4, enabled: true },
    session: { uuid: storeUuid, participantCount: 2, notes: null, startedAt: '2026-11-02T10:00:00Z', revision: 1, diningModeUuid: 'mode-hotpot', diningModeName: '火锅', diningMode: null },
    currency: 'CNY',
    canEnd: false,
    version,
    ordersVisible: true,
    orders: [mergedOrder()],
    openAmount: 200,
    orderedAmount: 200,
    openOrderCount: 1,
    paymentHold: false,
  }
}

function pendingProjectionTable(version: string): CounterSessionView {
  const value = table(version)
  if (!value.ordersVisible) throw new Error('Expected visible orders in test fixture')
  return {
    ...value,
    orders: [{
      ...value.orders[0]!,
      canReplace: false,
      replaceBlockedReason: 'projection_pending',
    }],
  }
}

function processedTable(version: string): CounterSessionView {
  const value = table(version)
  if (!value.ordersVisible) throw new Error('Expected visible orders in test fixture')
  return {
    ...value,
    orders: [{
      ...value.orders[0]!,
      canReplace: false,
      replaceBlockedReason: 'order_processed',
    }],
  }
}

describe('checkout replacement after merge', () => {
  it('quotes and replaces even when the read response says the order is locked', async () => {
    const loadTable = vi.fn().mockResolvedValue(pendingProjectionTable(oldVersion))
    const quote = vi.fn().mockResolvedValue({ items: [], totalAmount: 100, currency: 'CNY', quoteToken: newQuoteToken })
    const replace = vi.fn().mockResolvedValue({ orderUuid: '00000000-0000-4000-8000-000000000005' })
    let clock = 0

    const result = await submitCheckoutReplacement({
      loadTable,
      quote,
      replace,
      targetOrderUuid: mergedOrderUuid,
      items: [{ specificationUuid: specUuid, quantity: 1 }],
      notes: '',
      diningMode: null,
      now: () => clock,
      wait: async (milliseconds) => { clock += milliseconds },
    })

    expect(result.table.version).toBe(oldVersion)
    expect(loadTable).toHaveBeenCalledTimes(1)
    expect(quote).toHaveBeenCalledTimes(1)
    expect(quote.mock.calls[0]![0]).toMatchObject({ version: oldVersion, replaceOrderUuid: mergedOrderUuid })
    expect(replace).toHaveBeenCalledTimes(1)
    expect(replace.mock.calls[0]![0]).toMatchObject({ version: oldVersion, replaceOrderUuid: mergedOrderUuid, quoteToken: newQuoteToken })
  })

  it('surfaces the backend replacement rejection without a frontend eligibility check', async () => {
    const loadTable = vi.fn().mockResolvedValue(processedTable(newVersion))
    const quote = vi.fn().mockResolvedValue({ items: [], totalAmount: 100, currency: 'CNY', quoteToken: newQuoteToken })
    const replace = vi.fn().mockRejectedValue(new ApiError('request', 409, 'staff_order_locked'))

    await expect(submitCheckoutReplacement({
      loadTable,
      quote,
      replace,
      targetOrderUuid: mergedOrderUuid,
      items: [{ specificationUuid: specUuid, quantity: 1 }],
      notes: '',
      diningMode: null,
      now: () => 0,
    })).rejects.toMatchObject({ serverMessage: 'staff_order_locked' })
    expect(loadTable).toHaveBeenCalledTimes(1)
    expect(quote).toHaveBeenCalledTimes(1)
    expect(replace).toHaveBeenCalledTimes(1)
  })

  it('re-reads the merged Counter and replaces again after a stale-record conflict', async () => {
    const loadTable = vi.fn()
      .mockResolvedValueOnce(table(oldVersion))
      .mockResolvedValueOnce(table(newVersion))
    const quote = vi.fn()
      .mockResolvedValueOnce({ items: [], totalAmount: 100, currency: 'CNY', quoteToken: oldQuoteToken })
      .mockResolvedValueOnce({ items: [], totalAmount: 100, currency: 'CNY', quoteToken: newQuoteToken })
    const replace = vi.fn()
      .mockRejectedValueOnce(new ApiError('request', 409, 'stale_record'))
      .mockResolvedValueOnce({ orderUuid: '00000000-0000-4000-8000-000000000005' })
    const commandUuids = ['00000000-0000-4000-8000-000000000006', '00000000-0000-4000-8000-000000000007']

    const result = await submitCheckoutReplacement({
      loadTable,
      quote,
      replace,
      targetOrderUuid: mergedOrderUuid,
      items: [{ specificationUuid: specUuid, quantity: 1 }],
      notes: 'Less soup',
      diningMode: { uuid: 'mode-hotpot', name: '火锅' },
      now: () => 0,
      wait: async () => undefined,
      commandUuid: () => commandUuids.shift()!,
    })

    expect(result.table.version).toBe(newVersion)
    expect(loadTable).toHaveBeenCalledTimes(2)
    expect(quote).toHaveBeenCalledTimes(2)
    expect(replace.mock.calls[0]![0]).toMatchObject({ version: oldVersion, replaceOrderUuid: mergedOrderUuid, quoteToken: oldQuoteToken })
    expect(replace.mock.calls[1]![0]).toMatchObject({
      version: newVersion,
      replaceOrderUuid: mergedOrderUuid,
      quoteToken: newQuoteToken,
      commandUuid: '00000000-0000-4000-8000-000000000007',
      metadata: { diningModeUuid: 'mode-hotpot', diningModeName: '火锅' },
    })
    expect(replace.mock.calls[1]![0]).not.toHaveProperty('diningModeUuid')
    expect(replace.mock.calls[0]![0]!.commandUuid).not.toBe(replace.mock.calls[1]![0]!.commandUuid)
  })

  it('does not retry permanent replacement failures', async () => {
    const loadTable = vi.fn().mockResolvedValue(table(newVersion))
    const quote = vi.fn().mockResolvedValue({ items: [], totalAmount: 100, currency: 'CNY', quoteToken: newQuoteToken })
    const replace = vi.fn().mockRejectedValue(new ApiError('request', 409, 'staff_order_locked'))

    await expect(submitCheckoutReplacement({
      loadTable,
      quote,
      replace,
      targetOrderUuid: mergedOrderUuid,
      items: [{ specificationUuid: specUuid, quantity: 1 }],
      notes: '',
      diningMode: null,
      now: () => 0,
    })).rejects.toMatchObject({ serverMessage: 'staff_order_locked' })

    expect(loadTable).toHaveBeenCalledTimes(1)
    expect(quote).toHaveBeenCalledTimes(1)
    expect(replace).toHaveBeenCalledTimes(1)
  })

  it('waits for the merged Store order projection before every replacement attempt', async () => {
    const events: string[] = []
    const loadTable = vi.fn().mockResolvedValue(table(newVersion))
    const quote = vi.fn().mockResolvedValue({ items: [], totalAmount: 100, currency: 'CNY', quoteToken: newQuoteToken })
    const replace = vi.fn().mockResolvedValue({ orderUuid: '00000000-0000-4000-8000-000000000005' })
    const ensureTargetReady = vi.fn(async (targetOrderUuid: string) => {
      events.push(`ready:${targetOrderUuid}`)
    })

    await submitCheckoutReplacement({
      loadTable: vi.fn(async () => {
        events.push('load')
        return loadTable()
      }),
      quote: vi.fn(async (cart) => {
        events.push('quote')
        return quote(cart)
      }),
      replace: vi.fn(async (body) => {
        events.push('replace')
        return replace(body)
      }),
      ensureTargetReady,
      targetOrderUuid: mergedOrderUuid,
      items: [{ specificationUuid: specUuid, quantity: 1 }],
      notes: '',
      diningMode: null,
      now: () => 0,
    })

    expect(events).toEqual([`ready:${mergedOrderUuid}`, 'load', 'quote', 'replace'])
  })
})
