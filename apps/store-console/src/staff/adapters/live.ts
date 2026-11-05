import { z } from 'zod'
import { ApiClient, ApiError } from './api-client'
import { inDiningCatalog, normalizeDiningMode, type DiningCatalog, type DiningMode } from '../dining-mode'
import { invoiceReferenceUuid, parseStaffPaymentResult, type StaffPaymentResult } from '../order-payment'
import { parseRefundPreview, type RefundPreview } from '../order-refund'

const uuid = z.string().uuid()
const diningModeReferenceId = z.string().trim().min(1).max(120)
const cents = z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER)
const currency = z.string().regex(/^[A-Z]{3}$/)
const version = z.string().regex(/^[a-f0-9]{64}$/)
const record = (value: unknown): Record<string, unknown> => value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}
const objectRecord = (value: unknown): Record<string, unknown> | null => {
  if (typeof value === 'string' && value.trim()) {
    try { return record(JSON.parse(value)) }
    catch { return null }
  }
  const parsed = record(value)
  return Object.keys(parsed).length ? parsed : null
}
const nonEmptyText = (value: unknown): string | null => typeof value === 'string' && value.trim() ? value.trim() : null
const referenceText = (value: unknown, fields: string[], depth = 0, allowDirectString = true): string | null => {
  const direct = allowDirectString ? nonEmptyText(value) : null
  if (direct) return direct
  if (depth >= 3) return null
  const source = objectRecord(value)
  if (!source) return null
  const expanded = { ...source, ...objectRecord(source.__metadata), ...objectRecord(source.metadata) }
  for (const field of fields) {
    const found = referenceText(expanded[field], fields, depth + 1)
    if (found) return found
  }
  return null
}
const diningModeUuidText = (value: unknown) => referenceText(value, ['uuid', 'diningModeUuid', 'dining_mode_uuid'])
const diningModeNameText = (value: unknown, allowDirectString = true) => referenceText(value, ['name', 'diningModeName', 'dining_mode_name', 'title'], 0, allowDirectString)
const diningModeMetadata = (value: unknown): Record<string, unknown> => {
  const row = record(value)
  const rowMetadata = objectRecord(row.metadata) ?? objectRecord(row.__metadata)
  const nestedMetadata = objectRecord(rowMetadata?.metadata)
  const rowMeta = objectRecord(row.meta)
  const relationCandidate = objectRecord(rowMetadata?.diningMode) ?? objectRecord(nestedMetadata?.diningMode) ?? objectRecord(row.diningMode) ?? objectRecord(row.diningModeUuid)
  const relation = relationCandidate ? { ...relationCandidate, ...objectRecord(relationCandidate.__metadata) } : null
  const uuidSource = row.diningModeUuid ?? rowMetadata?.diningModeUuid ?? rowMetadata?.dining_mode_uuid ?? nestedMetadata?.diningModeUuid ?? nestedMetadata?.dining_mode_uuid ?? rowMeta?.diningModeUuid ?? rowMeta?.dining_mode_uuid
  const nameSource = row.diningModeName ?? rowMetadata?.diningModeName ?? rowMetadata?.dining_mode_name ?? nestedMetadata?.diningModeName ?? nestedMetadata?.dining_mode_name ?? rowMeta?.diningModeName ?? rowMeta?.dining_mode_name
  return {
    ...row,
    ...rowMetadata,
    ...nestedMetadata,
    ...rowMeta,
    ...relation,
    diningModeUuid: diningModeUuidText(uuidSource) ?? diningModeUuidText(relation) ?? null,
    diningModeName: diningModeNameText(uuidSource, false) ?? diningModeNameText(nameSource) ?? diningModeNameText(relation, false) ?? null,
  }
}
const withDiningModeFields = (row: Record<string, unknown>): Record<string, unknown> => {
  const metadata = diningModeMetadata(row)
  return {
    ...row,
    // A mode is recorded metadata, not a backend binding. Both identifiers are optional;
    // older orders may retain only the display name when a UUID was never persisted.
    diningModeUuid: diningModeUuidText(row.diningModeUuid) ?? diningModeUuidText(metadata.diningModeUuid) ?? null,
    diningModeName: diningModeNameText(row.diningModeUuid, false) ?? diningModeNameText(row.diningModeName) ?? diningModeNameText(metadata.diningModeName) ?? null,
  }
}
const withDiningModeFieldsOrNull = (value: unknown): unknown => value == null ? value : withDiningModeFields(record(value))
const withDiningModeMetadata = (value: unknown): Record<string, unknown> => {
  const row = record(value)
  return {
    ...withDiningModeFields(row),
    tradeOrderUuid: row.tradeOrderUuid ?? row.uuid,
  }
}
const settingsSchema = z.preprocess((value) => {
  if (typeof value !== 'string' || !value.trim()) return value
  try { return JSON.parse(value) } catch { return {} }
}, z.object({ diningModes: z.unknown().nullable().optional() }).passthrough().nullable().optional())
export const storeSchema = z.object({
  uuid, name: z.string(), code: z.string(), currency, timezone: z.string(),
  settings: settingsSchema,
  paymentSetting: z.unknown().nullable().optional(),
}).passthrough()
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
  uuid, tradeOrderUuid: uuid, status: z.string(), totalAmount: cents, currency,
  createdAt: z.string(), counter: z.object({ code: z.string(), name: z.string() }).nullable(),
  orderSnapshot: z.unknown().optional(), version, allowedActions: z.array(z.string()), cancellationReason: z.string().nullable(),
  paymentStatus: z.string().nullable().optional(),
  paymentMethod: z.string().nullable().optional(), paidAt: z.string().nullable().optional(),
})
const storeOrderProjectionSchema = z.object({ tradeOrderUuid: uuid }).passthrough()
export const orderRefundResultSchema = z.object({
  uuid,
  tradeOrderUuid: uuid,
  invoiceUuid: uuid,
  amount: cents,
  reason: z.string().min(1).max(1000),
  status: z.enum(['processing', 'unknown', 'succeeded', 'failed']),
  actorUuid: uuid,
  createdAt: z.string(),
  invoiceStatus: z.string().nullable(),
  refundedAmount: cents.nullable(),
}).passthrough()
export type OrderRefundResult = z.infer<typeof orderRefundResultSchema>
const reportingOrderItemSchema = z.object({ productName: z.string(), quantity: z.number().int().positive(), unitPrice: cents, amount: cents })
export const reportingOrderSchema = z.object({
  uuid, status: z.string(), paymentStatus: z.string().nullable().optional(),
  paymentMethod: z.string().nullable().optional(), paidAt: z.string().nullable().optional(),
  totalAmount: cents, currency, createdAt: z.string(),
  orderSnapshot: z.object({ items: z.array(reportingOrderItemSchema) }).passthrough(),
})
export type ReportingOrderRow = z.infer<typeof reportingOrderSchema>
const sessionOrderItemSchema = z.object({ specificationUuid: uuid.nullable(), productName: z.string(), specificationName: z.string(), quantity: z.number().int().positive().max(Number.MAX_SAFE_INTEGER), unitPrice: cents, amount: cents })
export const orderableItemSchema = sessionOrderItemSchema.extend({ specificationUuid: uuid, quantity: z.number().int().min(1).max(99) })
const orderMetadataSchema = z.preprocess((value) => objectRecord(value), z.record(z.string(), z.unknown()).nullable().optional())
const optionalDiningModeUuidSchema = z.unknown().nullable().optional().transform((value) => diningModeUuidText(value))
const optionalDiningModeNameSchema = z.unknown().nullable().optional().transform((value) => diningModeNameText(value))
const optionalInvoiceUuidSchema = z.unknown().transform((value) => invoiceReferenceUuid(value)).optional()
export const sessionOrderSchema = z.preprocess(withDiningModeMetadata, z.object({
  uuid, tradeOrderUuid: uuid, status: z.string(), paymentStatus: z.string().nullable(), hasInvoice: z.boolean(),
  currency, totalAmount: cents, notes: z.string().nullable(), createdAt: z.string(), items: z.array(sessionOrderItemSchema),
  metadata: orderMetadataSchema,
  // Newer Counter responses may expose the invoice UUID after recording the hasInvoice flag.
  // Keep both representations compatible while payment remains a Store-scoped operation.
  invoiceUuid: optionalInvoiceUuidSchema,
  invoiceId: z.unknown().nullable().optional(),
  invoice: z.unknown().nullable().optional(),
  diningModeUuid: optionalDiningModeUuidSchema,
  diningModeName: optionalDiningModeNameSchema,
  canReplace: z.boolean(), canCancel: z.boolean(),
  changeBlockedReason: z.string().max(80).nullable().optional().transform((value) => value ?? null),
  replaceBlockedReason: z.string().max(80).nullable().optional().transform((value) => value ?? null),
}))
const diningModeSnapshotSchema = z.unknown().nullable().optional().transform((value): DiningMode | null => value == null ? null : normalizeDiningMode(value))
const counterSessionBase = z.object({
  counter: counterSchema.omit({ sort: true }),
  session: z.preprocess(withDiningModeFieldsOrNull, z.object({
    uuid, participantCount: z.number().int().min(1).max(100).nullable(), notes: z.string().nullable(), startedAt: z.string(), revision: z.number().int().nonnegative(),
    diningModeUuid: optionalDiningModeUuidSchema,
    diningModeName: optionalDiningModeNameSchema,
    diningMode: diningModeSnapshotSchema,
    // Some normalized Counter relations expose the database property name.
    diningModeSnapshot: diningModeSnapshotSchema,
  }).passthrough().transform(({ diningMode, diningModeSnapshot, ...session }) => ({
    ...session,
    diningMode: diningMode ?? diningModeSnapshot ?? null,
  })).nullable()),
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
export const legacyCounterOrderSchema = z.preprocess(withDiningModeMetadata, z.object({
  uuid,
  status: z.string(),
  totalAmount: cents,
  currency,
  createdAt: z.string(),
  mergedIntoOrderUuid: uuid.nullable().optional(),
  diningModeUuid: diningModeReferenceId.nullable().optional(),
  diningModeName: z.string().max(120).nullable().optional(),
  diningMode: z.unknown().nullable().optional(),
  items: z.array(z.object({
    productName: z.string().nullable(),
    specificationName: z.string().nullable(),
    quantity: z.number().int().positive(),
    unitPrice: cents,
    price: cents,
  })),
}))
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
const commandResultId = z.preprocess(
  (value) => referenceText(value, ['uuid', 'orderUuid', 'replacedOrderUuid', 'counterUuid', 'sessionUuid']),
  uuid.nullable(),
)
// Command success is defined by the HTTP status and envelope code. Older or
// replayed receipts can omit ancillary identifiers or expose the result under a
// nested `result`; only the UUID fields that are present must be valid.
export const counterCommandResultSchema = z.preprocess((value) => {
  const root = record(value)
  const result = objectRecord(root.result) ?? root
  return {
    ...result,
    counterUuid: result.counterUuid ?? objectRecord(root.counter)?.uuid ?? null,
    sessionUuid: result.sessionUuid ?? objectRecord(root.session)?.uuid ?? null,
    orderUuid: result.orderUuid ?? result.uuid ?? objectRecord(result.order)?.uuid ?? null,
    replacedOrderUuid: result.replacedOrderUuid ?? objectRecord(result.replacedOrder)?.uuid ?? null,
  }
}, z.object({
  counterUuid: commandResultId,
  sessionUuid: commandResultId,
  orderUuid: commandResultId,
  replacedOrderUuid: commandResultId,
  status: z.unknown().optional(),
}).passthrough())
export const orderableCatalogSchema = z.object({ uuid, productName: z.string(), name: z.string(), price: cents, available: z.boolean() })
export type CounterSessionView = z.infer<typeof counterSessionSchema>
export type SessionOrder = z.infer<typeof sessionOrderSchema>
export type OrderQuote = z.infer<typeof orderQuoteSchema>
export type CounterMergeQuote = z.infer<typeof counterMergeQuoteSchema>
export interface OrderCart { version: string; items: { specificationUuid: string; quantity: number }[]; notes: string; metadata?: Record<string, unknown>; replaceOrderUuid?: string }
export type CounterCommand = 'update' | 'end' | 'create' | 'replace' | 'cancel' | 'confirm'
export type LiveStore = z.infer<typeof storeSchema>
export type LiveProduct = z.infer<typeof productSchema>
export type LiveSpecification = z.infer<typeof specificationSchema>
export type LiveCategory = z.infer<typeof categorySchema>
export type LiveCounter = z.infer<typeof counterSchema>
export type LiveOrder = z.infer<typeof orderSchema>
const settledLegacyStatuses = new Set(['paid', 'fulfilled', 'completed', 'cancelled', 'refunded', 'merged'])

/** Adapt the customer counter-order listing to the richer cashier view without inventing money. */
export function legacyCounterSession(counter: LiveCounter, orders: LegacyCounterOrder[], fallbackCurrency: string): CounterSessionView {
  const confirmedOrders = orders.filter((order) => order.status === 'confirmed')
  const diningModeOrder = confirmedOrders.find((order) => order.diningModeUuid || order.diningModeName)
  const diningModeUuid = diningModeOrder?.diningModeUuid ?? null
  const diningMode = confirmedOrders.map((order) => normalizeDiningMode(order.diningMode)).find((mode): mode is DiningMode => mode !== null) ?? null
  const rows: SessionOrder[] = confirmedOrders.map((order) => ({
    uuid: order.uuid,
    tradeOrderUuid: order.uuid,
    status: order.status,
    paymentStatus: settledLegacyStatuses.has(order.status) && !['cancelled', 'refunded', 'merged'].includes(order.status) ? order.status : null,
    hasInvoice: false,
    currency: order.currency || fallbackCurrency,
    totalAmount: order.totalAmount,
    notes: null,
    createdAt: order.createdAt,
    diningModeUuid: order.diningModeUuid ?? null,
    diningModeName: order.diningModeName ?? normalizeDiningMode(order.diningMode)?.name ?? null,
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
    changeBlockedReason: null,
    replaceBlockedReason: null,
  }))
  const orderedAmount = orders.reduce((total, order) => total + order.totalAmount, 0)
  const openAmount = confirmedOrders.reduce((total, order) => total + order.totalAmount, 0)
  return {
    counter: { uuid: counter.uuid, code: counter.code, name: counter.name, capacity: counter.capacity, enabled: counter.enabled },
    session: confirmedOrders.length ? {
      uuid: crypto.randomUUID(),
      participantCount: null,
      notes: null,
      startedAt: confirmedOrders[0]!.createdAt,
      revision: 0,
      diningModeUuid,
      diningModeName: diningModeOrder?.diningModeName ?? diningMode?.name ?? null,
      diningMode,
    } : null,
    currency: confirmedOrders[0]?.currency || fallbackCurrency,
    canEnd: false,
    version: '0'.repeat(64),
    ordersVisible: true,
    orders: rows,
    openAmount,
    orderedAmount,
    openOrderCount: confirmedOrders.length,
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
  allCounters() { return this.operationalAll('counters', counterSchema) }
  orders(page: number, q = '', status = 'all') { return this.operationalList('orders', orderSchema, page, q, status) }
  allOrders() { return this.operationalAll('orders', orderSchema) }
  async storeOrderExists(tradeOrderUuid: string): Promise<boolean | null> {
    const id = uuid.parse(tradeOrderUuid)
    try {
      // Readiness only depends on the projection key. A narrow schema avoids
      // coupling checkout readiness to unrelated operational DTO fields.
      const result = await this.operationalList('orders', storeOrderProjectionSchema, 1, id)
      return result.items.some((order) => order.tradeOrderUuid === id)
    } catch (error) {
      // Counter-only staff can still use the session projection as a readiness signal.
      if (error instanceof ApiError && error.status === 403) return null
      throw error
    }
  }
  async allReportingOrders(): Promise<{ items: ReportingOrderRow[]; total: number }> {
    const items: ReportingOrderRow[] = []
    let total = 0
    for (let page = 1; ; page += 1) {
      const result = await this.client.data(
        `${this.base}/operations/reporting-orders?${new URLSearchParams({ page: String(page), limit: '100' })}`,
        z.object({ items: z.array(reportingOrderSchema), page: z.number().int().positive(), hasNext: z.boolean(), total: z.number().int().nonnegative() }),
      )
      if (result.page !== page) throw new ApiError('invalid')
      items.push(...result.items)
      total = result.total
      if (!result.hasNext) return { items, total }
      if (!result.items.length || page >= 100000) throw new ApiError('invalid')
    }
  }
  history(page: number) {
    return this.operationalList('history', z.object({ uuid, actorUuid: uuid, commandUuid: uuid, action: z.string(), createdAt: z.string() }), page)
  }
  specifications(productUuid: string, page: number) {
    return this.client.list(`${this.base}/products/${uuid.parse(productUuid)}/specifications`, specificationSchema, page)
  }
  allSpecifications(productUuid: string) {
    return this.client.all(`${this.base}/products/${uuid.parse(productUuid)}/specifications`, specificationSchema)
  }
  private async sessionList<T>(resource: string, schema: z.ZodType<T>, page: number, q: string) {
    const result = await this.client.data(`${this.base}/counter-operations/${resource}?${new URLSearchParams({ page: String(page), q: q.trim() })}`, z.object({ items: z.array(schema), page: z.number().int().positive(), hasNext: z.boolean(), total: z.number().int().nonnegative() }))
    if (result.page !== page) throw new ApiError('invalid')
    return result
  }
  counterSessions(page: number, q = '') { return this.sessionList('counters', counterSessionSchema, page, q) }
  async allCounterSessions(): Promise<CounterSessionView[]> {
    const sessions: CounterSessionView[] = []
    for (let page = 1; ; page += 1) {
      const result = await this.sessionList('counters', counterSessionSchema, page, '')
      sessions.push(...result.items)
      if (!result.hasNext) return sessions
      if (!result.items.length) throw new ApiError('invalid')
    }
  }
  orderableCatalog(page: number, q = '') { return this.sessionList('catalog', orderableCatalogSchema, page, q) }
  async counterSession(id: string) {
    return this.client.data(`${this.base}/counter-operations/counters/${uuid.parse(id)}`, counterSessionSchema)
  }
  async orderInvoiceReference(tradeOrderUuid: string): Promise<string | null> {
    const data = await this.client.data(`/api/v1/app/orders/${uuid.parse(tradeOrderUuid)}`, z.unknown())
    return invoiceReferenceUuid(data)
  }
  async startHuifuPayment(invoiceUuid: string, options: { channel?: string; tradeType?: string } = {}): Promise<StaffPaymentResult> {
    const result = await this.client.data(`${this.base}/huifu-payments`, z.unknown(), 'POST', {
      invoiceUuid: uuid.parse(invoiceUuid),
      channel: options.channel ?? 'native',
      tradeType: options.tradeType ?? 'T_NATIVE',
    })
    return parseStaffPaymentResult(result)
  }
  async refundPreview(orderUuid: string): Promise<RefundPreview> {
    const id = uuid.parse(orderUuid)
    const data = await this.client.data(`/api/v1/public/orders/${id}/payment`, z.unknown())
    const preview = parseRefundPreview(data, id)
    if (!preview) throw new ApiError('invalid')
    return preview
  }
  async refundOrder(tradeOrderUuid: string, amount: number, reason: string, idempotencyKey: string): Promise<OrderRefundResult> {
    const id = uuid.parse(tradeOrderUuid)
    const trimmedReason = reason.trim()
    if (!Number.isSafeInteger(amount) || amount <= 0
      || !trimmedReason || new TextEncoder().encode(trimmedReason).length > 1000
      || !idempotencyKey.trim() || idempotencyKey.length > 200) throw new ApiError('invalid')
    const result = await this.client.data(`${this.base}/trade-orders/${id}/refund`, orderRefundResultSchema, 'POST', {
      amount,
      reason: trimmedReason,
      idempotencyKey,
    })
    if (result.tradeOrderUuid !== id || result.amount !== amount) throw new ApiError('invalid')
    return result
  }
  async refundHistory(tradeOrderUuid: string): Promise<OrderRefundResult[]> {
    const id = uuid.parse(tradeOrderUuid)
    const rows = await this.client.data(`${this.base}/trade-orders/${id}/refunds`, z.array(orderRefundResultSchema))
    if (rows.some((row) => row.tradeOrderUuid !== id)) throw new ApiError('invalid')
    return rows
  }
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
  async legacyCreateOrder(storeCode: string, counterUuid: string, currency: string, items: { specificationId: number; quantity: number }[], notes: string, diningMode?: Pick<DiningMode, 'uuid' | 'name'>, participantCount = 0) {
    const headers = { 'X-Store-Code': storeCode }
    const created = await this.client.data('/api/v1/app/orders', z.object({ uuid }).passthrough(), 'POST', {
      items,
      currency,
      notes: notes.trim() || null,
      metadata: {
        counterUuid,
        ...(participantCount > 0 ? { guests: participantCount } : {}),
        ...(diningMode ? { diningModeUuid: diningMode.uuid, diningModeName: diningMode.name } : {}),
      },
    }, headers)
    // Creation submits a Trade order to pending. Employee-created rounds must be
    // confirmed before payment, merge and checkout can operate on them.
    return this.client.data(`/api/v1/app/orders/${uuid.parse(created.uuid)}/confirm`, z.object({ uuid, status: z.string() }).passthrough(), 'POST', {}, headers)
  }
  async legacyCreateOrderFromCart(storeCode: string, counterUuid: string, currency: string, items: { specificationUuid: string; quantity: number }[], notes: string, diningMode?: Pick<DiningMode, 'uuid' | 'name'>, participantCount = 0) {
    const catalogItems = await Promise.all(items.map(async ({ specificationUuid, quantity }) => ({
      specificationId: (await this.legacySpecification(specificationUuid, storeCode)).specificationId,
      quantity,
    })))
    return this.legacyCreateOrder(storeCode, counterUuid, currency, catalogItems, notes, diningMode, participantCount)
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
  uploadImage(file: File, commandUuid = crypto.randomUUID(), storage: 'qiniu' = 'qiniu') {
    const body = new FormData()
    body.append('file', file)
    body.append('commandUuid', commandUuid)
    body.append('storage', storage)
    return this.client.data(`${this.base}/operations/images`, z.object({ uuid, url: z.string().max(2048).refine((value) => (/^https:\/\//.test(value) || /^\/(?!\/)/.test(value)) && !value.includes('\\')) }), 'POST', body)
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
