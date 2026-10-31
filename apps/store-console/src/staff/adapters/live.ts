import { z } from 'zod'
import { ApiClient, ApiError } from './api-client'
import { inDiningCatalog, normalizeDiningMode, type DiningCatalog, type DiningMode } from '../dining-mode'

const uuid = z.string().uuid()
const cents = z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER)
const currency = z.string().regex(/^[A-Z]{3}$/)
const version = z.string().regex(/^[a-f0-9]{64}$/)
export const orderActions = ['accept', 'reject', 'fulfill', 'verify'] as const
export type OrderAction = typeof orderActions[number]
const settingsSchema = z.preprocess((value) => {
  if (typeof value !== 'string' || !value.trim()) return value
  try { return JSON.parse(value) } catch { return {} }
}, z.object({ diningModes: z.unknown().nullable().optional() }).passthrough().nullable().optional())
export const storeSchema = z.object({
  uuid, name: z.string(), code: z.string(), currency, timezone: z.string(),
  settings: settingsSchema,
})
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
  createdAt: z.string(), counter: z.object({ code: z.string(), name: z.string() }).nullable(),
  orderSnapshot: z.unknown().optional(), version, allowedActions: z.array(z.enum(orderActions)), verificationRequired: z.boolean(), rejectionReason: z.string().nullable(),
})
const sessionOrderItemSchema = z.object({ specificationUuid: uuid.nullable(), productName: z.string(), specificationName: z.string(), quantity: z.number().int().positive().max(Number.MAX_SAFE_INTEGER), unitPrice: cents, amount: cents })
export const orderableItemSchema = sessionOrderItemSchema.extend({ specificationUuid: uuid, quantity: z.number().int().min(1).max(99) })
export const sessionOrderSchema = z.object({
  uuid, status: z.string(), operationalStatus: z.string().nullable(), paymentStatus: z.string().nullable(), hasInvoice: z.boolean(),
  currency, totalAmount: cents, notes: z.string().nullable(), createdAt: z.string(), items: z.array(sessionOrderItemSchema),
  canReplace: z.boolean(), canCancel: z.boolean(),
})
const diningModeSnapshotSchema = z.unknown().nullable().optional().superRefine((value, context) => {
  if (value != null && normalizeDiningMode(value) === null) context.addIssue({ code: 'custom', message: 'Invalid dining mode snapshot' })
}).transform((value): DiningMode | null => value == null ? null : normalizeDiningMode(value))
const counterSessionBase = z.object({
  counter: counterSchema.omit({ sort: true }),
  session: z.object({
    uuid, participantCount: z.number().int().min(1).max(100).nullable(), notes: z.string().nullable(), startedAt: z.string(), revision: z.number().int().nonnegative(),
    diningModeUuid: uuid.nullable().optional().transform((value) => value ?? null),
    diningMode: diningModeSnapshotSchema,
    // Some normalized Counter relations expose the database property name.
    diningModeSnapshot: diningModeSnapshotSchema,
  }).passthrough().transform(({ diningMode, diningModeSnapshot, ...session }) => ({
    ...session,
    diningMode: diningMode ?? diningModeSnapshot ?? null,
  })).nullable(),
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
// The live customer-facing counter API predates the scoped counter-operations contract.
// It remains the compatibility read/write path for accounts that already have counter and
// order access but have not yet been assigned the newer counter-session capabilities.
export const legacyCounterOrderSchema = z.object({
  uuid,
  status: z.string(),
  totalAmount: cents,
  currency,
  createdAt: z.string(),
  mergedIntoOrderUuid: uuid.nullable().optional(),
  diningModeUuid: uuid.nullable().optional(),
  diningMode: z.unknown().nullable().optional(),
  items: z.array(z.object({
    productName: z.string().nullable(),
    specificationName: z.string().nullable(),
    quantity: z.number().int().positive(),
    unitPrice: cents,
    price: cents,
  })),
})
const legacyProductSchema = z.object({
  id: z.number().int().positive(),
  uuid,
  name: z.string(),
  status: z.string(),
  metadata: z.unknown().nullable().optional(),
  category: z.preprocess((value) => {
    if (value == null) return null
    const relation = record(value)
    return { ...relation, ...record(relation.__metadata) }
  }, z.object({
    id: z.number().int().positive().nullable().optional(),
    uuid: uuid.optional(),
    __toString: z.string().optional(),
    __metadata: z.unknown().nullable().optional(),
    name: z.string().optional(),
    enabled: z.boolean().optional(),
  }).passthrough().nullable().optional()),
  specifications: z.array(z.preprocess((value) => {
    const relation = record(value)
    // FlatNormalizer exposes expanded entities as { id, uuid, __metadata: {...fields} }.
    return { ...relation, ...record(relation.__metadata) }
  }, z.object({
    id: z.number().int().positive(),
    uuid,
    name: z.string(),
    price: cents,
    status: z.string(),
    sort: z.number().int().optional(),
    metadata: z.unknown().nullable().optional(),
    __metadata: z.unknown().nullable().optional(),
  }).passthrough())),
}).passthrough()
const legacySpecificationSchema = z.object({
  id: z.number().int().positive(),
  uuid,
  name: z.string(),
  price: cents,
  status: z.string(),
  product: z.object({ uuid, __toString: z.string().optional() }).passthrough(),
}).passthrough()
export interface LegacyCatalogItem { uuid: string; specificationId: number; productUuid: string; categoryUuid: string | null; categoryName: string | null; productName: string; name: string; price: number; available: boolean }
export type LegacyCounterOrder = z.infer<typeof legacyCounterOrderSchema>
export const orderQuoteSchema = z.object({ items: z.array(orderableItemSchema).min(1).max(30), totalAmount: cents, currency, quoteToken: version })
export const counterMergeQuoteSchema = z.object({
  sourceOrderUuids: z.array(uuid).min(2), targetUserUuid: uuid,
  items: z.array(orderableItemSchema).min(1).max(30),
  sourceTotalAmount: cents, totalAmount: cents, differenceAmount: z.number().int().min(-Number.MAX_SAFE_INTEGER).max(Number.MAX_SAFE_INTEGER),
  currency, quoteToken: version,
})
const counterCommandResultSchema = z.object({ counterUuid: uuid, sessionUuid: uuid, orderUuid: uuid.optional(), replacedOrderUuid: uuid.optional(), status: z.string().optional() })
export const orderableCatalogSchema = z.object({ uuid, productName: z.string(), name: z.string(), price: cents, available: z.boolean() })
export type CounterSessionView = z.infer<typeof counterSessionSchema>
export type SessionOrder = z.infer<typeof sessionOrderSchema>
export type OrderQuote = z.infer<typeof orderQuoteSchema>
export type CounterMergeQuote = z.infer<typeof counterMergeQuoteSchema>
export interface OrderCart { version: string; items: { specificationUuid: string; quantity: number }[]; notes: string; diningModeUuid?: string; replaceOrderUuid?: string }
export type CounterCommand = 'update' | 'end' | 'create' | 'replace' | 'cancel'
export type LiveStore = z.infer<typeof storeSchema>
export type LiveProduct = z.infer<typeof productSchema>
export type LiveSpecification = z.infer<typeof specificationSchema>
export type LiveCategory = z.infer<typeof categorySchema>
export type LiveCounter = z.infer<typeof counterSchema>
export type LiveOrder = z.infer<typeof orderSchema>
const settledLegacyStatuses = new Set(['paid', 'fulfilled', 'completed', 'cancelled', 'refunded', 'merged'])

/** Adapt the customer counter-order listing to the richer cashier view without inventing money. */
export function legacyCounterSession(counter: LiveCounter, orders: LegacyCounterOrder[], fallbackCurrency: string): CounterSessionView {
  const diningModeUuid = orders.find((order) => order.diningModeUuid)?.diningModeUuid ?? null
  const diningMode = orders.map((order) => normalizeDiningMode(order.diningMode)).find((mode): mode is DiningMode => mode !== null) ?? null
  const rows: SessionOrder[] = orders.map((order) => ({
    uuid: order.uuid,
    status: order.status,
    operationalStatus: null,
    paymentStatus: settledLegacyStatuses.has(order.status) && !['cancelled', 'refunded', 'merged'].includes(order.status) ? order.status : null,
    hasInvoice: false,
    currency: order.currency || fallbackCurrency,
    totalAmount: order.totalAmount,
    notes: null,
    createdAt: order.createdAt,
    items: order.items.map((item) => ({
      specificationUuid: null,
      productName: item.productName ?? '',
      specificationName: item.specificationName ?? '',
      quantity: item.quantity,
      unitPrice: item.unitPrice,
      amount: item.price,
    })),
    canReplace: false,
    canCancel: false,
  }))
  const orderedAmount = orders.reduce((total, order) => total + order.totalAmount, 0)
  const pendingOrders = orders.filter((order) => !settledLegacyStatuses.has(order.status))
  const openAmount = pendingOrders.reduce((total, order) => total + order.totalAmount, 0)
  return {
    counter: { uuid: counter.uuid, code: counter.code, name: counter.name, capacity: counter.capacity, enabled: counter.enabled },
    session: orders.length ? {
      uuid: crypto.randomUUID(),
      participantCount: null,
      notes: null,
      startedAt: orders[0]!.createdAt,
      revision: 0,
      diningModeUuid,
      diningMode,
    } : null,
    currency: orders[0]?.currency || fallbackCurrency,
    canEnd: false,
    version: '0'.repeat(64),
    ordersVisible: true,
    orders: rows,
    openAmount,
    orderedAmount,
    openOrderCount: pendingOrders.length,
    paymentHold: false,
  }
}
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
  private async operationalAll<T>(resource: string, schema: z.ZodType<T>) {
    const items: T[] = []
    let total = 0
    for (let page = 1; ; page += 1) {
      const result = await this.operationalList(resource, schema, page)
      items.push(...result.items)
      total = result.total
      if (!result.hasNext) return { items, page: 1, hasNext: false, total }
      if (!result.items.length || page >= 100000) throw new ApiError('invalid')
    }
  }
  products(page: number, q = '', status = 'all') { return this.operationalList('products', productSchema, page, q, status) }
  allProducts() { return this.operationalAll('products', productSchema) }
  categories(page: number, q = '') { return this.operationalList('product-categories', categorySchema, page, q) }
  allCategories() { return this.operationalAll('product-categories', categorySchema) }
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
  async legacyCounters(): Promise<LiveCounter[]> {
    const counters: LiveCounter[] = []
    for (let page = 1; ; page += 1) {
      const result = await this.client.list(`${this.base}/counters`, counterSchema, page)
      counters.push(...result.items)
      if (!result.hasNext) return counters
      if (!result.items.length) throw new ApiError('invalid')
    }
  }
  legacyCounter(id: string, storeCode: string) {
    return this.client.data(`/api/v1/app/counters/${uuid.parse(id)}`, counterSchema, 'GET', undefined, { 'X-Store-Code': storeCode })
  }
  async legacyCounterOrders(id: string, storeCode: string): Promise<LegacyCounterOrder[]> {
    const orders: LegacyCounterOrder[] = []
    for (let page = 1; ; page += 1) {
      const envelope = await this.client.request(`/api/v1/app/counters/${uuid.parse(id)}/orders?${new URLSearchParams({ page: String(page), limit: '100' })}`, 'GET', undefined, { 'X-Store-Code': storeCode }) as { data?: unknown; paginator?: unknown }
      const rows = z.array(legacyCounterOrderSchema).safeParse(envelope.data)
      const paginator = z.object({ has_next: z.boolean() }).passthrough().safeParse(envelope.paginator)
      if (!rows.success || !paginator.success) throw new ApiError('invalid')
      orders.push(...rows.data)
      if (!paginator.data.has_next) return orders
      if (!rows.data.length) throw new ApiError('invalid')
    }
  }
  private async legacyCatalogRows(storeCode: string, diningCatalog?: DiningCatalog): Promise<LegacyCatalogItem[]> {
    const products: z.infer<typeof legacyProductSchema>[] = []
    for (let sourcePage = 1; ; sourcePage += 1) {
      const query = new URLSearchParams({ page: String(sourcePage), limit: '100', '@expands': 'specifications,category' })
      const envelope = await this.client.request(`/api/v1/app/products?${query}`, 'GET', undefined, { 'X-Store-Code': storeCode }) as { data?: unknown; paginator?: unknown }
      const rows = z.array(legacyProductSchema).safeParse(envelope.data)
      const paginator = z.object({ has_next: z.boolean() }).passthrough().safeParse(envelope.paginator)
      if (!rows.success || !paginator.success) throw new ApiError('invalid')
      products.push(...rows.data)
      if (!paginator.data.has_next) break
      if (!rows.data.length) throw new ApiError('invalid')
    }
    return products.flatMap((product) => {
      const productMetadata = record(product.metadata)
      if (product.status !== 'active' || productMetadata.soldout === true || productMetadata.soldOut === true || product.category?.enabled === false) return []
      if (diningCatalog && !inDiningCatalog({ productUuid: product.uuid, categoryUuid: product.category?.uuid }, diningCatalog)) return []
      const categoryName = (product.category?.name || product.category?.__toString?.replace(/^#\d+\s*/, '') || '').trim() || null
      return product.specifications.flatMap((specification) => {
        const specificationMetadata = record(specification.metadata ?? specification.__metadata)
        if (specification.status !== 'active' || specificationMetadata.isDeleted === true) return []
        return [{ uuid: specification.uuid, specificationId: specification.id, productUuid: product.uuid, categoryUuid: product.category?.uuid ?? null, categoryName, productName: product.name, name: specification.name, price: specification.price, available: true }]
      })
    })
  }
  async legacyCatalog(page: number, q: string, storeCode: string, diningCatalog?: DiningCatalog): Promise<{ items: LegacyCatalogItem[]; page: number; hasNext: boolean; total: number }> {
    const needle = q.trim().toLocaleLowerCase()
    const items = (await this.legacyCatalogRows(storeCode, diningCatalog)).filter((item) => !needle || `${item.productName} ${item.name} ${item.categoryName ?? ''}`.toLocaleLowerCase().includes(needle))
    const start = (page - 1) * 30
    return { items: items.slice(start, start + 30), page, hasNext: start + 30 < items.length, total: items.length }
  }
  async legacyCatalogAll(q: string, storeCode: string, diningCatalog?: DiningCatalog): Promise<{ items: LegacyCatalogItem[]; page: number; hasNext: boolean; total: number }> {
    const needle = q.trim().toLocaleLowerCase()
    const items = (await this.legacyCatalogRows(storeCode, diningCatalog)).filter((item) => !needle || `${item.productName} ${item.name} ${item.categoryName ?? ''}`.toLocaleLowerCase().includes(needle))
    return { items, page: 1, hasNext: false, total: items.length }
  }
  async legacySpecification(id: string, storeCode: string): Promise<LegacyCatalogItem> {
    const specification = await this.client.data(`/api/v1/app/specifications/${uuid.parse(id)}`, legacySpecificationSchema, 'GET', undefined, { 'X-Store-Code': storeCode })
    const productLabel = specification.product.__toString?.replace(/^#\d+\s*/, '').trim()
    return {
      uuid: specification.uuid,
      specificationId: specification.id,
      productUuid: specification.product.uuid,
      categoryUuid: null,
      categoryName: null,
      productName: productLabel || specification.name,
      name: specification.name,
      price: specification.price,
      available: true,
    }
  }
  legacyCreateOrder(storeCode: string, counterUuid: string, currency: string, items: { specificationId: number; quantity: number }[], notes: string, diningMode?: Pick<DiningMode, 'uuid' | 'name'>, participantCount = 0) {
    return this.client.data('/api/v1/app/orders', z.object({ uuid }).passthrough(), 'POST', {
      items,
      currency,
      notes: notes.trim() || null,
      metadata: {
        counterUuid,
        ...(participantCount > 0 ? { guests: participantCount } : {}),
        ...(diningMode ? { diningModeUuid: diningMode.uuid, diningModeName: diningMode.name } : {}),
      },
    }, { 'X-Store-Code': storeCode })
  }
  previewCounterMerge(id: string, version: string) { return this.client.data(`${this.base}/counter-operations/counters/${uuid.parse(id)}/merge/preview`, counterMergeQuoteSchema, 'POST', { version }) }
  confirmCounterMerge(id: string, body: { version: string; commandUuid: string; quoteToken: string }) { return this.client.data(`${this.base}/counter-operations/counters/${uuid.parse(id)}/merge/confirm`, counterCommandResultSchema, 'POST', body) }
  orderQuote(id: string, cart: OrderCart) { return this.client.data(`${this.base}/counter-operations/counters/${uuid.parse(id)}/orders/quote`, orderQuoteSchema, 'POST', cart) }
  counterCommand(id: string, command: CounterCommand, body: Record<string, unknown>) {
    const path = command === 'update' || command === 'end' ? `session/${command}` : `orders/${command}`
    return this.client.data(`${this.base}/counter-operations/counters/${uuid.parse(id)}/${path}`, counterCommandResultSchema, 'POST', body)
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

function record(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}
}
