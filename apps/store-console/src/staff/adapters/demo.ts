import type { Category, Product, Table } from '../domain'
import { addLine } from '../domain'
import type { StoreGateway } from './gateway'

export const demoGateway: StoreGateway = {
  mode: 'demo',
  bootstrap() {
    const categories: Category[] = [
      { id: 'fresh', name: 'fresh' }, { id: 'hotpot', name: 'hotpot' },
      { id: 'vegetables', name: 'vegetables' }, { id: 'drinks', name: 'drinks' }, { id: 'snacks', name: 'snacks' },
    ]
    const products: Product[] = [
      { id: 'p1', code: '1001', name: 'brisket', categoryId: 'fresh', variants: [{ id: 'v1', name: 'plate', price: 4800 }, { id: 'v2', name: 'large', price: 6800 }], soldOut: false, kitchenPrint: true, popular: true },
      { id: 'p2', code: '1002', name: 'chuck', categoryId: 'fresh', variants: [{ id: 'v1', name: 'plate', price: 5800 }], soldOut: false, kitchenPrint: true },
      { id: 'p3', code: '1003', name: 'beefBalls', categoryId: 'fresh', variants: [{ id: 'v1', name: 'plate', price: 3800 }], soldOut: false, kitchenPrint: true, popular: true },
      { id: 'p4', code: '2001', name: 'broth', categoryId: 'hotpot', variants: [{ id: 'v1', name: 'pot', price: 2800 }], soldOut: false, kitchenPrint: true },
      { id: 'p5', code: '2002', name: 'spicyBroth', categoryId: 'hotpot', variants: [{ id: 'v1', name: 'pot', price: 3200 }], soldOut: false, kitchenPrint: true },
      { id: 'p6', code: '3001', name: 'lettuce', categoryId: 'vegetables', variants: [{ id: 'v1', name: 'plate', price: 1200 }], soldOut: false, kitchenPrint: true },
      { id: 'p7', code: '3002', name: 'mushrooms', categoryId: 'vegetables', variants: [{ id: 'v1', name: 'plate', price: 1800 }], soldOut: true, kitchenPrint: true },
      { id: 'p8', code: '4001', name: 'tea', categoryId: 'drinks', variants: [{ id: 'v1', name: 'bottle', price: 800 }], soldOut: false, kitchenPrint: false },
      { id: 'p9', code: '4002', name: 'plum', categoryId: 'drinks', variants: [{ id: 'v1', name: 'bottle', price: 1200 }], soldOut: false, kitchenPrint: false },
      { id: 'p10', code: '5001', name: 'riceCake', categoryId: 'snacks', variants: [{ id: 'v1', name: 'plate', price: 1800 }], soldOut: true, kitchenPrint: true },
    ]
    const tables: Table[] = Array.from({ length: 8 }, (_, index) => ({
      id: `t${index + 1}`, name: `A${String(index + 1).padStart(2, '0')}`, capacity: index < 4 ? 4 : 6,
      guests: index < 4 ? index % 3 + 2 : 0, openedAt: index < 4 ? Date.now() - (index + 1) * 9 * 60_000 : null,
      lines: [], discount: 0, note: '', paymentId: null,
    }))
    for (let i = 0; i < 4; i++) {
      tables[i].lines = addLine([], products[i % 2], 'v1')
      tables[i].lines = addLine(tables[i].lines, products[3], 'v1')
      tables[i].lines = addLine(tables[i].lines, products[7], 'v1')
      if (i % 2) tables[i].lines = addLine(tables[i].lines, products[2], 'v1')
    }
    return { categories, products, tables }
  },
}
