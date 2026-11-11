import { describe, expect, it } from 'vitest'
import { resolveTableDiningModeDisplay, type DiningMode } from '../../src/staff/dining-mode'

function mode(uuid: string, name: string): DiningMode {
  return {
    uuid,
    name,
    enabled: true,
    priority: 0,
    switchButtonLabel: '',
    availability: { allDay: true, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [] },
    catalog: { categoryUuids: [], productUuids: [] },
    rules: [],
    checkoutRules: [],
  }
}

describe('table dining mode display', () => {
  it('uses the first order mode even when later orders contain a different value', () => {
    const hotpot = mode('mode-hotpot', 'Hotpot')
    const breakfast = mode('mode-breakfast', 'Breakfast')
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [
        { uuid: 'order-2', status: 'confirmed', createdAt: '2026-11-02T10:00:00Z', diningModeUuid: breakfast.uuid, diningModeName: breakfast.name },
        { uuid: 'order-1', status: 'confirmed', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: hotpot.uuid, diningModeName: hotpot.name },
      ],
    }, { modes: [hotpot, breakfast] })

    expect(display).toMatchObject({ uuid: hotpot.uuid, name: hotpot.name })
    expect(display?.mode?.uuid).toBe(hotpot.uuid)
  })

  it('matches a persisted mode name when the order has no dining mode UUID', () => {
    const hotpot = mode('mode-hotpot', 'Hotpot')
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [{ uuid: 'order-1', status: 'confirmed', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: null, diningModeName: '  HOTPOT ' }],
    }, { modes: [hotpot] })

    expect(display).toMatchObject({ uuid: hotpot.uuid, name: hotpot.name })
    expect(display?.mode?.uuid).toBe(hotpot.uuid)
  })

  it('ignores session metadata when selecting the table mode', () => {
    const hotpot = mode('mode-hotpot', 'Hotpot')
    const breakfast = mode('mode-breakfast', 'Breakfast')
    const display = resolveTableDiningModeDisplay({
      session: { diningModeUuid: breakfast.uuid, diningModeName: breakfast.name },
      ordersVisible: true,
      orders: [{ uuid: 'order-1', status: 'pending', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: hotpot.uuid, diningModeName: hotpot.name }],
    }, { modes: [hotpot, breakfast] })

    expect(display).toMatchObject({ uuid: hotpot.uuid, name: hotpot.name })
    expect(display?.mode?.uuid).toBe(hotpot.uuid)
  })

  it('ignores terminal orders and uses the first pending or confirmed order', () => {
    const hotpot = mode('mode-hotpot', 'Hotpot')
    const breakfast = mode('mode-breakfast', 'Breakfast')
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [
        { uuid: 'order-old', status: 'cancelled', createdAt: '2026-11-02T08:00:00Z', diningModeUuid: breakfast.uuid, diningModeName: breakfast.name },
        { uuid: 'order-open', status: 'confirmed', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: hotpot.uuid, diningModeName: hotpot.name },
      ],
    }, { modes: [hotpot, breakfast] })

    expect(display).toMatchObject({ uuid: hotpot.uuid, name: hotpot.name })
  })

  it('does not skip a first open order that has no recorded mode', () => {
    const hotpot = mode('mode-hotpot', 'Hotpot')
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [
        { uuid: 'order-1', status: 'confirmed', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: null, diningModeName: null },
        { uuid: 'order-2', status: 'confirmed', createdAt: '2026-11-02T10:00:00Z', diningModeUuid: hotpot.uuid, diningModeName: hotpot.name },
      ],
    }, { modes: [hotpot] })

    expect(display).toBeNull()
  })

  it('does not choose a mode when a name-only record is ambiguous', () => {
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [{ uuid: 'order-1', status: 'confirmed', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: null, diningModeName: 'Lunch' }],
    }, { modes: [mode('mode-1', 'Lunch'), mode('mode-2', ' lunch ')] })

    expect(display).toEqual({ mode: null, uuid: null, name: 'Lunch' })
  })

  it('keeps the recorded name when the mode is no longer in store settings', () => {
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [{ uuid: 'order-1', status: 'confirmed', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: 'mode-removed', diningModeName: 'Old hotpot mode' }],
    }, { modes: [] })

    expect(display).toEqual({ mode: null, uuid: 'mode-removed', name: 'Old hotpot mode' })
  })

  it('does not infer a different configured mode when a recorded UUID is unavailable', () => {
    const currentHotpot = mode('mode-current-hotpot', 'Hotpot')
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [{ uuid: 'order-1', status: 'confirmed', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: 'mode-removed', diningModeName: 'Hotpot' }],
    }, { modes: [currentHotpot] })

    expect(display).toEqual({ mode: null, uuid: 'mode-removed', name: 'Hotpot' })
  })
})
