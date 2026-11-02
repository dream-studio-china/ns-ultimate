import { describe, expect, it } from 'vitest'
import type { StoreAccess } from '../../src/staff/adapters/live'
import { defaultWorkspacePage, workspacePages } from '../../src/staff/live/navigation'

const store = '00000000-0000-4000-8000-000000000001'
function access(permissions: string[]): StoreAccess {
  return {
    store: { uuid: store, name: 'Store', code: 'S1', currency: 'CNY', timezone: 'UTC' },
    membership: { status: 'active', role: 'manager' },
    profile: { uuid: store, username: 'staff', roles: ['ROLE_USER'] },
    authorization: { permissions, storeScopes: Object.fromEntries(permissions.map((code) => [code, [store]])) },
  }
}

describe('permission-based workspace navigation', () => {
  it('shows availability-only and counter-session-only modules without a payment entry', () => {
    const availability = access(['store:product:read', 'store:product:availability'])
    const counter = access(['store:counter_session:read', 'store:order:read'])
    expect(workspacePages(availability)).toEqual(['products', 'mine'])
    expect(defaultWorkspacePage(availability, 'cashier')).toBe('products')
    expect(workspacePages(counter)).toEqual(['tables', 'reports', 'mine'])
    expect(defaultWorkspacePage(counter, 'kitchen')).toBe('tables')
  })

  it('uses counter sessions by default even when an old kitchen entry preference is present', () => {
    const both = access(['store:product:read', 'store:counter_session:read', 'store:counter_order:read'])
    expect(workspacePages(both)).toEqual(['products', 'tables', 'orders', 'reports', 'mine'])
    expect(defaultWorkspacePage(both, null)).toBe('tables')
    expect(defaultWorkspacePage(both, 'kitchen')).toBe('tables')
    expect(defaultWorkspacePage(both, 'cashier')).toBe('tables')
    expect(defaultWorkspacePage(both, 'payment')).toBe('tables')
  })

  it('uses cashier for table configuration access even without session read permission', () => {
    const both = access(['store:product:read', 'store:counter:read'])
    expect(workspacePages(both)).toEqual(['products', 'tables', 'mine'])
    expect(defaultWorkspacePage(both, null)).toBe('tables')
    expect(defaultWorkspacePage(both, 'kitchen')).toBe('tables')
  })

  it('uses Trade orders for the order-only cashier view without requiring legacy Store order grants', () => {
    const cashier = access(['store:counter_order:read', 'store:counter_order:create'])
    expect(workspacePages(cashier)).toEqual(['orders', 'mine'])
    expect(defaultWorkspacePage(cashier, null)).toBe('orders')
    expect(defaultWorkspacePage(cashier, 'kitchen')).toBe('orders')
  })

  it('retains category, counter-configuration and legacy report-only users', () => {
    for (const [code, pages, page] of [
      ['product_category', ['products', 'mine'], 'products'],
      ['counter', ['tables', 'mine'], 'tables'],
      ['order', ['reports', 'mine'], 'reports'],
    ] as const) {
      const employee = access([`store:${code}:read`])
      expect(workspacePages(employee)).toEqual(pages)
      expect(defaultWorkspacePage(employee, null)).toBe(page)
    }
  })

  it('never infers grants from write permissions, labels, global lists or another store', () => {
    const employee = access(['store:product:availability', 'store:counter_order:create', 'store:counter_order:replace'])
    employee.profile.roles.push('store_counter_session_operator', 'store_counter_order_editor')
    employee.authorization.permissions.push('store:counter_session:read')
    employee.authorization.storeScopes['store:counter_order:read'] = ['00000000-0000-4000-8000-000000000002']
    expect(workspacePages(employee)).toEqual(['mine'])
    expect(defaultWorkspacePage(employee, 'cashier')).toBe('mine')
    expect(defaultWorkspacePage(employee, 'kitchen')).toBe('mine')
  })

  it('recomputes the destination when store or membership changes', () => {
    const employee = access(['store:product:read', 'store:counter_session:read'])
    employee.store.uuid = '00000000-0000-4000-8000-000000000002'
    expect(defaultWorkspacePage(employee, 'kitchen')).toBe('mine')
    employee.store.uuid = store
    employee.membership.status = 'suspended'
    expect(workspacePages(employee)).toEqual(['mine'])
  })
})
