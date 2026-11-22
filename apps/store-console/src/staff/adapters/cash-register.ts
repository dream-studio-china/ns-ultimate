import { z } from 'zod'
import type { StoreApi } from './live'

const minor = z.string().regex(/^\d+$/)
const categories = z.string().regex(/^[a-z][a-z0-9_]{0,23}$/)
const units = z.enum(['g', 'kg', 'ml', 'l', 'piece'])
const metadata = z.object({ version: z.literal(1), item: z.string().optional(), quantity: z.string().optional(), unit: units.optional(), reference: z.string().optional() })
const monthSchema = z.object({
  month: z.string().regex(/^\d{4}-\d{2}$/), page: z.number().int().positive(), hasMore: z.boolean(),
  totals: z.array(z.object({ currency: z.string(), income: minor, expense: minor, byCategory: z.record(z.string(), minor) })),
  entries: z.array(z.object({
    uuid: z.string().uuid(), date: z.string(), currency: z.string(), direction: z.enum(['income', 'expense']), category: categories,
    amount: minor, note: z.string(), source: z.literal('manual'), metadata,
    purchaseCost: z.object({ baseUnit: units, baseQuantity: z.string(), unitPrice: z.string() }).nullable(),
  })),
})

export type CashMonth = z.infer<typeof monthSchema>
export type CashCategory = z.infer<typeof categories>
export type CashUnit = z.infer<typeof units>
export type CashInput = {
  commandUuid: string; date: string; direction: 'income' | 'expense'; category: CashCategory
  amount: string; note: string; metadata: z.infer<typeof metadata>
}
const categorySchema = z.object({ id: categories, name: z.string(), direction: z.enum(['income', 'expense']) })
export type CashCategoryOption = z.infer<typeof categorySchema>
export function readCashCategories(api: StoreApi) {
  return api.client.data(`${api.base}/cash-register/categories`, z.object({ categories: z.array(categorySchema) }))
}
export function readCashMonth(api: StoreApi, month: string, page: number) {
  const query = new URLSearchParams({ month, page: String(page) })
  return api.client.data(`${api.base}/cash-register?${query}`, monthSchema)
}
export function recordCash(api: StoreApi, entry: CashInput) {
  return api.client.data(`${api.base}/cash-register`, z.object({ uuid: z.string().uuid() }), 'POST', entry)
}

export function cashMinor(value: string): string | null {
  const match = /^(\d{1,19})(?:\.(\d{1,2}))?$/.exec(value.trim())
  if (!match) return null
  const amount = BigInt(`${match[1]}${(match[2] ?? '').padEnd(2, '0')}`)
  return amount > 0n && amount <= 9223372036854775807n ? String(amount) : null
}

export function cashMoney(amount: string, currency: string): string {
  const digits = amount.padStart(3, '0')
  return `${currency} ${digits.slice(0, -2)}.${digits.slice(-2)}`
}
