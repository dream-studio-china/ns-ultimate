import type { Category, Product, Table } from '../domain'

// UI bootstrap seam only. Live store authentication and transaction contracts are not implemented.
export interface StoreGateway {
  readonly mode: 'demo'
  bootstrap(): { categories: Category[]; products: Product[]; tables: Table[] }
}
