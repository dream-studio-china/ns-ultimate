import type { Product } from './catalog'

export interface Selection { size: 'regular' | 'large'; temperature: 'hot' | 'iced' }
export interface CartLine { id: string; product: Product; selection: Selection; quantity: number; unitPrice: number }
export interface Basket { lines: CartLine[]; note: string; service: 'dineIn' | 'takeaway' }
export interface HeldBasket { id: string; createdAt: number; basket: Basket }
export const emptyBasket = (): Basket => ({ lines: [], note: '', service: 'dineIn' })
export const total = (lines: CartLine[]) => lines.reduce((sum, line) => sum + line.unitPrice * line.quantity, 0)
export const count = (lines: CartLine[]) => lines.reduce((sum, line) => sum + line.quantity, 0)
export function addProduct(lines: CartLine[], product: Product, selection: Selection, quantity: number): CartLine[] {
  if (product.soldOut || !Number.isInteger(quantity) || quantity < 1 || quantity > 99) throw new Error('Invalid product or quantity')
  const normalized: Selection = product.customizable ? selection : { size: 'regular', temperature: 'hot' }
  const id = `${product.id}:${normalized.size}:${normalized.temperature}`
  const existing = lines.find((line) => line.id === id)
  if ((existing?.quantity ?? 0) + quantity > 99 || (!existing && lines.length >= 100)) throw new Error('Basket limit reached')
  const unitPrice = product.price + (product.customizable && normalized.size === 'large' ? 400 : 0)
  return existing ? lines.map((line) => line.id === id ? { ...line, quantity: line.quantity + quantity } : line)
    : [...lines, { id, product, selection: normalized, quantity, unitPrice }]
}
export function setQuantity(lines: CartLine[], id: string, quantity: number): CartLine[] {
  if (!Number.isInteger(quantity) || quantity < 0 || quantity > 99) throw new Error('Invalid quantity')
  return quantity === 0 ? lines.filter((line) => line.id !== id) : lines.map((line) => line.id === id ? { ...line, quantity } : line)
}
export function parseCash(value: string): number | null {
  if (!/^\d{1,7}(\.\d{0,2})?$/.test(value)) return null
  const [whole, fraction = ''] = value.split('.')
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
}
