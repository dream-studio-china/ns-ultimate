import type { MessageKey } from '../lib/i18n'

export type Category = 'coffee' | 'tea' | 'bakery' | 'dessert'
export type Illustration = 'black' | 'latte' | 'flat' | 'foam' | 'oat' | 'matcha' | 'lemon' | 'peach' | 'croissant' | 'bagel' | 'cake' | 'brownie'
export interface Product {
  id: string; code: string; name: MessageKey; description: MessageKey; category: Category
  price: number; illustration: Illustration; popular?: boolean; soldOut?: boolean; customizable: boolean
}
export const demoProducts: Product[] = [
  { id: 'p01', code: '1001', name: 'americano', description: 'americanoDesc', category: 'coffee', price: 1800, illustration: 'black', popular: true, customizable: true },
  { id: 'p02', code: '1002', name: 'latte', description: 'latteDesc', category: 'coffee', price: 2600, illustration: 'latte', popular: true, customizable: true },
  { id: 'p03', code: '1003', name: 'flatWhite', description: 'flatWhiteDesc', category: 'coffee', price: 2800, illustration: 'flat', customizable: true },
  { id: 'p04', code: '1004', name: 'cappuccino', description: 'cappuccinoDesc', category: 'coffee', price: 2600, illustration: 'foam', customizable: true },
  { id: 'p05', code: '1005', name: 'oatLatte', description: 'oatLatteDesc', category: 'coffee', price: 3000, illustration: 'oat', customizable: true },
  { id: 'p06', code: '2001', name: 'matcha', description: 'matchaDesc', category: 'tea', price: 2800, illustration: 'matcha', popular: true, customizable: true },
  { id: 'p07', code: '2002', name: 'lemonTea', description: 'lemonTeaDesc', category: 'tea', price: 2200, illustration: 'lemon', customizable: true },
  { id: 'p08', code: '2003', name: 'peachTea', description: 'peachTeaDesc', category: 'tea', price: 2400, illustration: 'peach', soldOut: true, customizable: true },
  { id: 'p09', code: '3001', name: 'croissant', description: 'croissantDesc', category: 'bakery', price: 1600, illustration: 'croissant', popular: true, customizable: false },
  { id: 'p10', code: '3002', name: 'bagel', description: 'bagelDesc', category: 'bakery', price: 1400, illustration: 'bagel', customizable: false },
  { id: 'p11', code: '4001', name: 'cake', description: 'cakeDesc', category: 'dessert', price: 3200, illustration: 'cake', customizable: false },
  { id: 'p12', code: '4002', name: 'brownie', description: 'brownieDesc', category: 'dessert', price: 2400, illustration: 'brownie', customizable: false },
]
