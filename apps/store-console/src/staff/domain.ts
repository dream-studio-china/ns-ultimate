import type { Label } from '../shared/i18n'

export interface Category { id: string; name: Label }
export interface Variant { id: string; name: Label; price: number }
export interface Product {
  id: string; code: string; name: Label; categoryId: string; variants: Variant[]
  soldOut: boolean; kitchenPrint: boolean; popular?: boolean; schedule?: { start: string; end: string }
  cover?: string
}
export interface OrderLine {
  id: string; productId: string; name: Label; variant: Label; unitPrice: number; quantity: number
}
export interface Table {
  id: string; name: string; capacity: number; guests: number; openedAt: number | null
  lines: OrderLine[]; discount: number; note: string; paymentId: string | null
}
export interface Receipt {
  id: string; tableName: string; lines: OrderLine[]; discount: number; total: number; paidAt: number; method: 'wechat' | 'alipay'
}
export const subtotal = (lines: OrderLine[]) => lines.reduce((sum, line) => sum + line.unitPrice * line.quantity, 0)
export const due = (table: Table) => Math.max(0, subtotal(table.lines) - table.discount)
export const itemCount = (lines: OrderLine[]) => lines.reduce((sum, line) => sum + line.quantity, 0)
export function parseAmount(value: string): number | null {
  if (!/^\d{1,6}(\.\d{0,2})?$/.test(value)) return null
  const [whole, fraction = ''] = value.split('.')
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
}
export function addLine(lines: OrderLine[], product: Product, variantId: string): OrderLine[] {
  const variant = product.variants.find((item) => item.id === variantId)
  if (!variant || product.soldOut) throw new Error('Unavailable product')
  const id = `${product.id}:${variant.id}`
  const current = lines.find((line) => line.id === id)
  if ((current?.quantity ?? 0) >= 99 || (!current && lines.length >= 60)) throw new Error('Order limit reached')
  return current ? lines.map((line) => line.id === id ? { ...line, quantity: line.quantity + 1 } : line)
    : [...lines, { id, productId: product.id, name: product.name, variant: variant.name, unitPrice: variant.price, quantity: 1 }]
}
export function updateQuantity(lines: OrderLine[], id: string, quantity: number): OrderLine[] {
  if (!Number.isInteger(quantity) || quantity < 0 || quantity > 99) throw new Error('Invalid quantity')
  return quantity === 0 ? lines.filter((line) => line.id !== id) : lines.map((line) => line.id === id ? { ...line, quantity } : line)
}
