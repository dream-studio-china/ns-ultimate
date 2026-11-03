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
        { uuid: 'order-2', createdAt: '2026-11-02T10:00:00Z', diningModeUuid: breakfast.uuid, diningModeName: breakfast.name },
        { uuid: 'order-1', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: hotpot.uuid, diningModeName: hotpot.name },
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
      orders: [{ uuid: 'order-1', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: null, diningModeName: '  HOTPOT ' }],
    }, { modes: [hotpot] })

    expect(display).toMatchObject({ uuid: hotpot.uuid, name: hotpot.name })
    expect(display?.mode?.uuid).toBe(hotpot.uuid)
  })

  it('matches a session mode name when the session has no dining mode UUID', () => {
    const hotpot = mode('mode-hotpot', 'Hotpot')
    const display = resolveTableDiningModeDisplay({
      session: { diningModeUuid: null, diningModeName: 'Hotpot' },
      ordersVisible: false,
    }, { modes: [hotpot] })

    expect(display).toMatchObject({ uuid: hotpot.uuid, name: hotpot.name })
    expect(display?.mode?.uuid).toBe(hotpot.uuid)
  })

  it('does not choose a mode when a name-only record is ambiguous', () => {
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [{ uuid: 'order-1', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: null, diningModeName: 'Lunch' }],
    }, { modes: [mode('mode-1', 'Lunch'), mode('mode-2', ' lunch ')] })

    expect(display).toEqual({ mode: null, uuid: null, name: 'Lunch' })
  })

  it('keeps the recorded name when the mode is no longer in store settings', () => {
    const display = resolveTableDiningModeDisplay({
      session: null,
      ordersVisible: true,
      orders: [{ uuid: 'order-1', createdAt: '2026-11-02T09:00:00Z', diningModeUuid: 'mode-removed', diningModeName: 'Old hotpot mode' }],
    }, { modes: [] })

    expect(display).toEqual({ mode: null, uuid: 'mode-removed', name: 'Old hotpot mode' })
  })

  it('uses the session-level name when the configured mode was removed', () => {
    const display = resolveTableDiningModeDisplay({
      session: { diningModeUuid: 'mode-removed', diningModeName: 'Old session mode' },
      ordersVisible: false,
    }, { modes: [] })

    expect(display).toEqual({ mode: null, uuid: 'mode-removed', name: 'Old session mode' })
  })
})
