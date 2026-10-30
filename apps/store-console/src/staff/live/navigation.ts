import { can, type StoreAccess } from '../adapters/live'

export type WorkspacePage = 'products' | 'tables' | 'orders' | 'mine'

export function workspacePages(access: StoreAccess): WorkspacePage[] {
  const pages: WorkspacePage[] = []
  if (can(access, 'product', 'read') || can(access, 'product_category', 'read')) pages.push('products')
  if (can(access, 'counter_session', 'read') || can(access, 'counter', 'read')) pages.push('tables')
  if (can(access, 'order', 'read')) pages.push('orders')
  return [...pages, 'mine']
}

// An entry preference never grants access, and is re-evaluated on every store connection.
export function defaultWorkspacePage(access: StoreAccess, view: string | null): WorkspacePage {
  const pages = workspacePages(access)
  const preferred = view === 'kitchen' ? 'products' : view === 'cashier' ? 'tables' : null
  if (preferred && pages.includes(preferred)) return preferred
  if (can(access, 'counter_session', 'read')) return 'tables'
  return pages[0]!
}
