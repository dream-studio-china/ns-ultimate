import { z } from 'zod'

const paymentKey = 'ns-store-console.demo-payments.v1'
const eventName = 'ns-store-console:demo-payment'
export const paymentStatusSchema = z.enum(['pending', 'processing', 'paid', 'failed', 'unknown', 'void'])
export const paymentSchema = z.object({
  id: z.string().uuid(), storeName: z.string().min(1).max(100), tableName: z.string().max(40),
  lines: z.array(z.object({ name: z.string().max(160), variant: z.string().max(100), quantity: z.number().int().min(1).max(99), unitPrice: z.number().int().min(0).max(10_000_000) })).min(1).max(60),
  discount: z.number().int().min(0), total: z.number().int().positive().max(100_000_000),
  createdAt: z.number().int(), expiresAt: z.number().int(), status: paymentStatusSchema,
  method: z.enum(['wechat', 'alipay']).nullable(), paidAt: z.number().int().nullable(), resolveAt: z.number().int().nullable(),
}).refine((value) => value.lines.reduce((sum, line) => sum + line.unitPrice * line.quantity, 0) - value.discount === value.total, { message: 'Invalid bill total' })
export type DemoPayment = z.infer<typeof paymentSchema>
export type DemoOutcome = 'success' | 'failure' | 'timeout'

export interface DemoStorage { getItem(key: string): string | null; setItem(key: string, value: string): void }
export function paymentStorage(): DemoStorage { return window.localStorage }

export function readPayments(storage: DemoStorage = paymentStorage()): DemoPayment[] {
  const raw = storage.getItem(paymentKey)
  if (!raw) return []
  const parsed = z.array(paymentSchema).max(40).safeParse(JSON.parse(raw))
  if (!parsed.success) throw new Error('Invalid demo payment storage')
  return parsed.data
}
export function readPayment(id: string, storage: DemoStorage = paymentStorage()): DemoPayment | null {
  return readPayments(storage).find((payment) => payment.id === id) ?? null
}
export function writePayment(payment: DemoPayment, storage: DemoStorage = paymentStorage()): DemoPayment {
  const safe = paymentSchema.parse(payment)
  const existing = readPayments(storage).filter((item) => item.id !== safe.id && item.expiresAt > Date.now())
  storage.setItem(paymentKey, JSON.stringify([...existing.slice(-19), safe]))
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(eventName))
  return safe
}
export function issuePayment(input: Pick<DemoPayment, 'storeName' | 'tableName' | 'lines' | 'discount' | 'total'>, storage: DemoStorage = paymentStorage()): DemoPayment {
  const now = Date.now()
  return writePayment({ ...input, id: crypto.randomUUID(), createdAt: now, expiresAt: now + 15 * 60_000, status: 'pending', method: null, paidAt: null, resolveAt: null }, storage)
}
export function voidPayment(id: string, storage: DemoStorage = paymentStorage()): DemoPayment {
  const current = readPayment(id, storage)
  if (!current || !['pending', 'failed'].includes(current.status)) throw new Error('Payment cannot be voided')
  return writePayment({ ...current, status: 'void' }, storage)
}
export function resolvePayment(id: string, storage: DemoStorage = paymentStorage()): DemoPayment {
  const current = readPayment(id, storage)
  if (!current) throw new Error('Unknown payment')
  if (['unknown', 'processing'].includes(current.status) && current.resolveAt !== null && Date.now() >= current.resolveAt) {
    return writePayment({ ...current, status: 'paid', paidAt: Date.now() }, storage)
  }
  return current
}
export async function collectPayment(id: string, method: 'wechat' | 'alipay', outcome: DemoOutcome, delay = 1000): Promise<DemoPayment> {
  // Browser locks coordinate the demo tabs only. They are not a backend transaction or authorization boundary.
  if (!navigator.locks) throw new Error('Demo payment requires a secure browser context')
  return navigator.locks.request(`ns-store-console:pay:${id}`, async () => {
    const current = readPayment(id)
    if (!current || current.expiresAt <= Date.now() || current.status === 'void') throw new Error('Expired payment')
    if (!['pending', 'failed'].includes(current.status)) return current
    const started = writePayment({ ...current, method, status: 'processing', resolveAt: Date.now() + delay })
    await new Promise((resolve) => setTimeout(resolve, delay))
    const status = !navigator.onLine || outcome === 'timeout' ? 'unknown' : outcome === 'failure' ? 'failed' : 'paid'
    return writePayment({ ...started, status, paidAt: status === 'paid' ? Date.now() : null })
  })
}
export function subscribePayments(listener: () => void): () => void {
  const onStorage = (event: StorageEvent) => { if (event.key === paymentKey || event.key === null) listener() }
  window.addEventListener('storage', onStorage)
  window.addEventListener(eventName, listener)
  return () => { window.removeEventListener('storage', onStorage); window.removeEventListener(eventName, listener) }
}
export function paymentUrl(id: string): string {
  const url = new URL('payment.html', window.location.href)
  url.searchParams.set('session', id)
  return url.href
}
