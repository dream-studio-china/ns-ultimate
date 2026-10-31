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
    expect(workspacePages(counter)).toEqual(['tables', 'orders', 'mine'])
    expect(defaultWorkspacePage(counter, 'kitchen')).toBe('tables')
  })

  it('uses counter sessions by default even when an old kitchen entry preference is present', () => {
    const both = access(['store:product:read', 'store:counter_session:read', 'store:order:read'])
    expect(workspacePages(both)).toEqual(['products', 'tables', 'orders', 'mine'])
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

  it('does not expose cashier operations from counter-order grants alone', () => {
    const cashier = access(['store:counter_order:read', 'store:counter_order:create'])
    expect(workspacePages(cashier)).toEqual(['mine'])
    expect(defaultWorkspacePage(cashier, null)).toBe('mine')
    expect(defaultWorkspacePage(cashier, 'kitchen')).toBe('mine')
  })

  it('retains category-only, counter-configuration-only and order-only users', () => {
    for (const [code, page] of [['product_category', 'products'], ['counter', 'tables'], ['order', 'orders']]) {
      const employee = access([`store:${code}:read`])
      expect(workspacePages(employee)).toEqual([page, 'mine'])
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
