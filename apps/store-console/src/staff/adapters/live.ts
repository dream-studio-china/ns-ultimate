import { z } from 'zod'
import { ApiClient, ApiError } from './api-client'

const uuid = z.string().uuid()
const cents = z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER)
const currency = z.string().regex(/^[A-Z]{3}$/)
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
  isSoldout: z.boolean().optional(),
  metadata: z.preprocess((value) => Array.isArray(value) && value.length === 0 ? null : value,
    z.object({ cover: z.string().nullable().optional(), subtitle: z.string().nullable().optional() }).nullable().optional()),
})
export const specificationSchema = z.object({ uuid, name: z.string(), price: cents, status: z.enum(['active', 'inactive']), sort: z.number().int() })
export const categorySchema = z.object({ uuid, name: z.string(), slug: z.string(), sortOrder: z.number().int(), enabled: z.boolean() })
export const counterSchema = z.object({ uuid, code: z.string(), name: z.string(), capacity: z.number().int().nullable(), sort: z.number().int(), enabled: z.boolean() })
export const orderSchema = z.object({
  uuid, tradeOrderUuid: uuid, operationalStatus: z.string(), totalAmount: cents, currency,
  createdAt: z.string(), orderSnapshot: z.unknown().optional(),
})
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
  products(page: number) { return this.client.list(`${this.base}/products`, productSchema, page) }
  categories(page: number) { return this.client.list(`${this.base}/product-categories`, categorySchema, page) }
  counters(page: number) { return this.client.list(`${this.base}/counters`, counterSchema, page) }
  orders(page: number) { return this.client.list(`${this.base}/orders`, orderSchema, page) }
  specifications(productUuid: string, page: number) {
    return this.client.list(`${this.base}/products/${uuid.parse(productUuid)}/specifications`, specificationSchema, page)
  }
  order(orderUuid: string) { return this.client.data(`${this.base}/orders/${uuid.parse(orderUuid)}`, orderSchema) }
  // Callers supply explicit per-resource payloads. Extension-only fields and transactions are not exposed here.
  save(resource: 'products' | 'product-categories' | 'counters', id: string | null, body: Record<string, unknown>) {
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
