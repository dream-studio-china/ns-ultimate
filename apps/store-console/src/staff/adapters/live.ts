import { z } from 'zod'
import { ApiClient, ApiError } from './api-client'

const uuid = z.string().uuid()
const cents = z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER)
const currency = z.string().regex(/^[A-Z]{3}$/)
const version = z.string().regex(/^[a-f0-9]{64}$/)
export const orderActions = ['accept', 'reject', 'fulfill', 'verify'] as const
export type OrderAction = typeof orderActions[number]
export const storeSchema = z.object({ uuid, name: z.string(), code: z.string(), currency, timezone: z.string() })
export const membershipSchema = z.object({ status: z.string(), role: z.string() })
export const profileSchema = z.object({ uuid, username: z.string().nullable(), roles: z.array(z.string()) })
export const authorizationSchema = z.object({
  permissions: z.array(z.string()),
  // PHP serializes an empty associative array as []; this must not imply any grants.
  storeScopes: z.preprocess((value) => Array.isArray(value) && value.length === 0 ? {} : value, z.record(z.string(), z.array(uuid))),
})
export const productSchema = z.object({
  uuid, name: z.string(), status: z.enum(['active', 'inactive']), category: z.object({ uuid }).nullable(),
  version, isSoldout: z.boolean(), description: z.string().nullable(), sortOrder: z.number().int().nonnegative(), recommendationLevel: z.number().int().nonnegative(), extensionEditable: z.boolean(),
  settings: z.object({ kitchenPrinterEnable: z.boolean(), startSaleTime: z.string(), endSaleTime: z.string() }),
  metadata: z.preprocess((value) => Array.isArray(value) && value.length === 0 ? null : value,
    z.object({ cover: z.string().nullable().optional(), subtitle: z.string().nullable().optional() }).nullable().optional()),
})
export const specificationSchema = z.object({ uuid, name: z.string(), price: cents, status: z.enum(['active', 'inactive']), sort: z.number().int() })
export const categorySchema = z.object({ uuid, name: z.string(), slug: z.string(), sortOrder: z.number().int(), enabled: z.boolean() })
export const counterSchema = z.object({ uuid, code: z.string(), name: z.string(), capacity: z.number().int().nullable(), sort: z.number().int(), enabled: z.boolean() })
export const orderSchema = z.object({
  uuid, tradeOrderUuid: uuid, operationalStatus: z.string(), totalAmount: cents, currency,
  createdAt: z.string(), orderSnapshot: z.unknown().optional(), version, allowedActions: z.array(z.enum(orderActions)), verificationRequired: z.boolean(), rejectionReason: z.string().nullable(),
})
const sessionOrderItemSchema = z.object({ specificationUuid: uuid.nullable(), productName: z.string(), specificationName: z.string(), quantity: z.number().int().positive().max(Number.MAX_SAFE_INTEGER), unitPrice: cents, amount: cents })
export const orderableItemSchema = sessionOrderItemSchema.extend({ specificationUuid: uuid, quantity: z.number().int().min(1).max(99) })
export const sessionOrderSchema = z.object({
  uuid, status: z.string(), operationalStatus: z.string().nullable(), paymentStatus: z.string().nullable(), hasInvoice: z.boolean(),
  currency, totalAmount: cents, notes: z.string().nullable(), createdAt: z.string(), items: z.array(sessionOrderItemSchema),
  canReplace: z.boolean(), canCancel: z.boolean(),
})
const counterSessionBase = z.object({
  counter: counterSchema.omit({ sort: true }),
  session: z.object({ uuid, participantCount: z.number().int().min(1).max(100).nullable(), notes: z.string().nullable(), startedAt: z.string(), revision: z.number().int().nonnegative() }).nullable(),
  currency, canEnd: z.boolean(), version,
})
export const counterSessionSchema = z.discriminatedUnion('ordersVisible', [
  // Order readers receive complete rows and amounts; a missing field is a contract error, not zero data.
  counterSessionBase.extend({
    ordersVisible: z.literal(true),
    orders: z.array(sessionOrderSchema).max(200),
    openAmount: cents, orderedAmount: cents,
    openOrderCount: z.number().int().nonnegative(), paymentHold: z.boolean(),
  }),
  // Session-only readers receive an explicit redaction, never inferred empty orders or amounts.
  counterSessionBase.extend({ ordersVisible: z.literal(false) }),
])
export const orderQuoteSchema = z.object({ items: z.array(orderableItemSchema).min(1).max(30), totalAmount: cents, currency, quoteToken: version })
export const orderableCatalogSchema = z.object({ uuid, productName: z.string(), name: z.string(), price: cents, available: z.boolean() })
export type CounterSessionView = z.infer<typeof counterSessionSchema>
export type SessionOrder = z.infer<typeof sessionOrderSchema>
export type OrderQuote = z.infer<typeof orderQuoteSchema>
export interface OrderCart { version: string; items: { specificationUuid: string; quantity: number }[]; notes: string; replaceOrderUuid?: string }
export type CounterCommand = 'update' | 'end' | 'create' | 'replace' | 'cancel'
export type LiveStore = z.infer<typeof storeSchema>
export type LiveProduct = z.infer<typeof productSchema>
export type LiveSpecification = z.infer<typeof specificationSchema>
export type LiveCategory = z.infer<typeof categorySchema>
export type LiveCounter = z.infer<typeof counterSchema>
export type LiveOrder = z.infer<typeof orderSchema>
export interface StoreAccess {
  store: LiveStore
  membership: z.infer<typeof membershipSchema>
  authorization: z.infer<typeof authorizationSchema>
  profile: z.infer<typeof profileSchema>
}
export type ConnectionStep = 'checkStore' | 'checkMembership' | 'checkPermissions' | 'checkProfile'
export class StoreConnectionError extends ApiError {
  constructor(public readonly step: ConnectionStep, error: unknown) {
    super(error instanceof ApiError ? error.kind : 'invalid', error instanceof ApiError ? error.status : 0)
  }
}
export function can(access: StoreAccess, resource: string, action: string): boolean {
  if (access.membership.status !== 'active') return false
  if (access.profile.roles.includes('ROLE_ADMIN')) return true
  return access.authorization.storeScopes[`store:${resource}:${action}`]?.includes(access.store.uuid) ?? false
}
export class StoreApi {
  readonly base: string
  constructor(readonly client: ApiClient, storeUuid: string) {
    this.base = `/api/v1/store/${uuid.parse(storeUuid)}`
  }
  static async access(client: ApiClient, storeUuid: string): Promise<StoreAccess> {
    uuid.parse(storeUuid)
    const read = async <T>(step: ConnectionStep, path: string, schema: z.ZodType<T>) => {
      try { return await client.data(path, schema) }
      catch (error) { throw new StoreConnectionError(step, error) }
    }
    const [store, membership, authorization, profile] = await Promise.all([
      read('checkStore', `/api/v1/app/stores/${storeUuid}`, storeSchema),
      read('checkMembership', `/api/v1/app/stores/${storeUuid}/membership`, membershipSchema),
      read('checkPermissions', '/api/v1/app/authorization/me', authorizationSchema),
      read('checkProfile', '/api/v1/app/users/me', profileSchema),
    ])
    if (store.uuid !== storeUuid) throw new ApiError('invalid')
    return { store, membership, authorization, profile }
  }
  private async operationalList<T>(resource: string, schema: z.ZodType<T>, page: number, q = '', status = 'all') {
    const result = await this.client.data(`${this.base}/operations/${resource}?${new URLSearchParams({ page: String(page), limit: '30', q: q.trim(), status })}`, z.object({ items: z.array(schema), page: z.number().int().positive(), hasNext: z.boolean(), total: z.number().int().nonnegative() }))
    if (result.page !== page) throw new ApiError('invalid')
    return result
  }
  products(page: number, q = '', status = 'all') { return this.operationalList('products', productSchema, page, q, status) }
  categories(page: number, q = '') { return this.operationalList('product-categories', categorySchema, page, q) }
  counters(page: number, q = '', status = 'all') { return this.operationalList('counters', counterSchema, page, q, status) }
  orders(page: number, q = '', status = 'all') { return this.operationalList('orders', orderSchema, page, q, status) }
  history(page: number) {
    return this.operationalList('history', z.object({ uuid, actorUuid: uuid, commandUuid: uuid, action: z.string(), createdAt: z.string() }), page)
  }
  specifications(productUuid: string, page: number) {
    return this.client.list(`${this.base}/products/${uuid.parse(productUuid)}/specifications`, specificationSchema, page)
  }
  order(orderUuid: string) { return this.client.data(`${this.base}/operations/orders/${uuid.parse(orderUuid)}`, orderSchema) }
  private async sessionList<T>(resource: string, schema: z.ZodType<T>, page: number, q: string) {
    const result = await this.client.data(`${this.base}/counter-operations/${resource}?${new URLSearchParams({ page: String(page), q: q.trim() })}`, z.object({ items: z.array(schema), page: z.number().int().positive(), hasNext: z.boolean(), total: z.number().int().nonnegative() }))
    if (result.page !== page) throw new ApiError('invalid')
    return result
  }
  counterSessions(page: number, q = '') { return this.sessionList('counters', counterSessionSchema, page, q) }
  orderableCatalog(page: number, q = '') { return this.sessionList('catalog', orderableCatalogSchema, page, q) }
  counterSession(id: string) { return this.client.data(`${this.base}/counter-operations/counters/${uuid.parse(id)}`, counterSessionSchema) }
  orderQuote(id: string, cart: OrderCart) { return this.client.data(`${this.base}/counter-operations/counters/${uuid.parse(id)}/orders/quote`, orderQuoteSchema, 'POST', cart) }
  counterCommand(id: string, command: CounterCommand, body: Record<string, unknown>) {
    const path = command === 'update' || command === 'end' ? `session/${command}` : `orders/${command}`
    return this.client.data(`${this.base}/counter-operations/counters/${uuid.parse(id)}/${path}`, z.object({ counterUuid: uuid, sessionUuid: uuid, orderUuid: uuid.optional(), replacedOrderUuid: uuid.optional() }), 'POST', body)
  }
  saveProduct(id: string | null, body: Record<string, unknown>) {
    return this.client.data(`${this.base}/operations/products${id ? `/${uuid.parse(id)}` : ''}`, productSchema, id ? 'PUT' : 'POST', body)
  }
  availability(items: LiveProduct[], isSoldout: boolean, commandUuid: string) {
    return this.client.request(`${this.base}/operations/products/availability`, 'POST', { commandUuid, isSoldout, items: items.map(({ uuid, version }) => ({ uuid, version })) })
  }
  orderCommand(order: LiveOrder, action: OrderAction, reason: string, commandUuid: string) {
    return this.client.data(`${this.base}/operations/orders/${order.uuid}/${action}`, orderSchema, 'POST', { commandUuid, version: order.version, ...(action === 'reject' ? { reason: reason.trim() } : {}) })
  }
  uploadImage(file: File, commandUuid = crypto.randomUUID()) {
    const body = new FormData(); body.append('file', file); body.append('commandUuid', commandUuid)
    return this.client.data(`${this.base}/operations/images`, z.object({ uuid, url: z.string().regex(/^\/(?!\/)/) }), 'POST', body)
  }
  // Existing configuration CRUD stays separate from versioned product/order commands.
  save(resource: 'product-categories' | 'counters', id: string | null, body: Record<string, unknown>) {
    return this.client.request(`${this.base}/${resource}${id ? `/${uuid.parse(id)}` : ''}`, id ? 'PUT' : 'POST', body)
  }
  remove(resource: 'products' | 'product-categories' | 'counters', id: string) {
    return this.client.request(`${this.base}/${resource}/${uuid.parse(id)}`, 'DELETE')
  }
  saveSpecification(productUuid: string, id: string | null, body: Record<string, unknown>) {
    return this.client.request(`${this.base}/products/${uuid.parse(productUuid)}/specifications${id ? `/${uuid.parse(id)}` : ''}`, id ? 'PUT' : 'POST', body)
  }
  removeSpecification(productUuid: string, id: string) {
    return this.client.request(`${this.base}/products/${uuid.parse(productUuid)}/specifications/${uuid.parse(id)}`, 'DELETE')
  }
}
