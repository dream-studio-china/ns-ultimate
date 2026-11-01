import { describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import { ApiClient, ApiError, STAFF_STORE_STORAGE_KEY, STAFF_TOKEN_STORAGE_KEY, type PersistedStorage } from '../../src/staff/adapters/api-client'
import { authorizationSchema, productSchema, storeSchema, can, StoreApi, type StoreAccess } from '../../src/staff/adapters/live'

const tokens = (suffix = 'one') => ({ access_token: `test-access-${suffix}`, refresh_token: `test-refresh-${suffix}`, expires_in: 60 })
const json = (data: unknown, status = 200) => Response.json(data, { status })
const success = () => json({ code: 0, data: [] })
const store = '00000000-0000-4000-8000-000000000001'
function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => { resolve = done })
  return { promise, resolve }
}
function memoryStorage() {
  const values = new Map<string, string>()
  const storage: PersistedStorage = {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => { values.set(key, value) },
    removeItem: key => { values.delete(key) },
  }
  return { storage, values }
}

describe('staff API transport', () => {
  it('uses same-origin bearer requests without cookies and respects 204 responses', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json(tokens())).mockResolvedValueOnce(new Response(null, { status: 204 }))
    const client = new ApiClient(fetcher)
    await client.login('staff', 'test-only')
    await expect(client.request('/api/v1/store/example/products/example', 'DELETE')).resolves.toBeUndefined()
    expect(fetcher.mock.calls[1][1]).toMatchObject({ method: 'DELETE', credentials: 'omit', redirect: 'error', headers: { Authorization: 'Bearer test-access-one' } })
    await expect(client.request('https://example.test/api/')).rejects.toMatchObject({ kind: 'invalid' })
  })
  it('single-flights rotating refreshes and handles a late 401 from the old access token', async () => {
    const late = deferred<Response>()
    const fetcher = vi.fn<typeof fetch>().mockImplementation(async (path, init) => {
      if (path === '/api/auth/login') return json(tokens())
      if (path === '/api/auth/token/refresh') return json(tokens('two'))
      if ((init?.headers as Record<string, string>).Authorization === 'Bearer test-access-two') return success()
      return path === '/api/late' ? late.promise : new Response(null, { status: 401 })
    })
    const client = new ApiClient(fetcher)
    await client.login('staff', 'test-only')
    const lateRequest = client.request('/api/late')
    await Promise.all([client.request('/api/first'), client.request('/api/second')])
    late.resolve(new Response(null, { status: 401 }))
    await lateRequest
    expect(fetcher.mock.calls.filter(([path]) => path === '/api/auth/token/refresh')).toHaveLength(1)
  })
  it('clears the session after failed refresh and never replays a network-failed write', async () => {
    const expired = vi.fn()
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json(tokens())).mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(new Response(null, { status: 401 })).mockResolvedValueOnce(new Response(null, { status: 401 }))
    const client = new ApiClient(fetcher, expired)
    await client.login('staff', 'test-only')
    await expect(client.request('/api/products', 'POST', { name: 'Item' })).rejects.toMatchObject({ kind: 'network' })
    expect(fetcher).toHaveBeenCalledTimes(2)
    await expect(client.request('/api/products')).rejects.toMatchObject({ kind: 'unauthorized' })
    expect(expired).toHaveBeenCalledOnce()
    await expect(client.request('/api/products')).rejects.toMatchObject({ kind: 'unauthorized' })
    expect(fetcher).toHaveBeenCalledTimes(4)
  })
  it('rejects forbidden, application errors, malformed JSON and invalid pagination without fallback', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json(tokens()))
      .mockResolvedValueOnce(new Response(null, { status: 403 }))
      .mockResolvedValueOnce(json({ code: 123, data: [] }))
      .mockResolvedValueOnce(new Response('<html>error</html>'))
      .mockResolvedValueOnce(json({ code: 0, data: [] }))
    const client = new ApiClient(fetcher)
    await client.login('staff', 'test-only')
    await expect(client.request('/api/items')).rejects.toMatchObject({ kind: 'forbidden' })
    await expect(client.request('/api/items')).rejects.toMatchObject({ kind: 'request' })
    await expect(client.request('/api/items')).rejects.toMatchObject({ kind: 'invalid' })
    await expect(client.list('/api/items', z.string(), 1)).rejects.toMatchObject({ kind: 'invalid' })
  })
  it('does not restore tokens from a refresh that finishes after logout', async () => {
    const refresh = deferred<Response>()
    const fetcher = vi.fn<typeof fetch>().mockImplementation(async (path) => {
      if (path === '/api/auth/login') return json(tokens())
      if (path === '/api/auth/token/refresh') return refresh.promise
      if (path === '/api/auth/logout') return new Response(null, { status: 204 })
      return new Response(null, { status: 401 })
    })
    const client = new ApiClient(fetcher)
    await client.login('staff', 'test-only')
    const pending = client.request('/api/products')
    const rejection = expect(pending).rejects.toMatchObject({ kind: 'unauthorized' })
    await vi.waitFor(() => expect(fetcher.mock.calls.some(([path]) => path === '/api/auth/token/refresh')).toBe(true))
    await client.logout()
    refresh.resolve(json(tokens('two')))
    await rejection
    await expect(client.request('/api/products')).rejects.toMatchObject({ kind: 'unauthorized' })
  })
  it('reads pagination and validates record DTOs', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json(tokens())).mockResolvedValueOnce(json({ code: 0, data: ['value'], paginator: { page: 2, has_next: true } }))
    const client = new ApiClient(fetcher)
    await client.login('staff', 'test-only')
    expect(await client.list('/api/items', z.string(), 2)).toEqual({ items: ['value'], hasNext: true })
    expect(fetcher.mock.calls[1][0]).toBe('/api/items?page=2&limit=30')
  })
  it('restores a saved staff session and store after a page reload', async () => {
    const { storage, values } = memoryStorage()
    const original = new ApiClient(vi.fn<typeof fetch>().mockResolvedValueOnce(json(tokens())), () => {}, storage)
    await original.login('staff', 'test-only-password')
    original.rememberStore(store)

    expect(values.get(STAFF_TOKEN_STORAGE_KEY)).toContain('test-access-one')
    expect(values.get(STAFF_TOKEN_STORAGE_KEY)).not.toContain('test-only-password')
    expect(values.get(STAFF_STORE_STORAGE_KEY)).toBe(store)

    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(success())
    const restored = new ApiClient(fetcher, () => {}, storage)
    expect(restored.hasSession()).toBe(true)
    expect(restored.rememberedStoreUuid()).toBe(store)
    await restored.request('/api/items')
    expect(fetcher.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer test-access-one' } })
  })
  it('persists rotated tokens and clears both saved values on logout', async () => {
    const { storage, values } = memoryStorage()
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(json(tokens()))
      .mockResolvedValueOnce(new Response(null, { status: 401 }))
      .mockResolvedValueOnce(json(tokens('two')))
      .mockResolvedValueOnce(success())
    const client = new ApiClient(fetcher, () => {}, storage)
    await client.login('staff', 'test-only')
    client.rememberStore(store)
    await client.request('/api/items')
    expect(values.get(STAFF_TOKEN_STORAGE_KEY)).toContain('test-access-two')

    const logoutFetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(new Response(null, { status: 204 }))
    const restored = new ApiClient(logoutFetcher, () => {}, storage)
    await restored.logout()
    expect(values.has(STAFF_TOKEN_STORAGE_KEY)).toBe(false)
    expect(values.has(STAFF_STORE_STORAGE_KEY)).toBe(false)
  })
  it('fails closed and removes malformed persisted credentials', () => {
    const { storage, values } = memoryStorage()
    values.set(STAFF_TOKEN_STORAGE_KEY, 'not-json')
    values.set(STAFF_STORE_STORAGE_KEY, 'not-a-store-uuid')
    const client = new ApiClient(vi.fn<typeof fetch>(), () => {}, storage)
    expect(client.hasSession()).toBe(false)
    expect(client.rememberedStoreUuid()).toBeNull()
    expect(values.has(STAFF_TOKEN_STORAGE_KEY)).toBe(false)
  })
})
describe('store-scoped permissions and adapters', () => {
  it('accepts PHP empty product metadata without inventing a cover image', () => {
    const product = productSchema.parse({ uuid: store, name: 'Product', status: 'active', category: null, metadata: [], version: 'a'.repeat(64), isSoldout: false, description: null, sortOrder: 0, recommendationLevel: 0, extensionEditable: true, settings: { kitchenPrinterEnable: false, startSaleTime: '', endSaleTime: '' } })
    expect(product.metadata).toBeNull()
  })
  it('keeps the authenticated store dining-mode settings used by order entry', () => {
    const parsed = storeSchema.parse({
      uuid: store, name: 'Store', code: 'S1', currency: 'CNY', timezone: 'Asia/Shanghai',
      settings: { diningModes: { version: 1, modes: [{ uuid: 'hotpot', name: '火锅' }] } },
    })
    expect(parsed.settings?.diningModes).toMatchObject({ modes: [{ uuid: 'hotpot', name: '火锅' }] })
  })

  it('accepts legacy JSON-string store settings without losing dining modes', () => {
    const parsed = storeSchema.parse({
      uuid: '00000000-0000-4000-8000-000000000001', name: 'Store', code: 'S1', currency: 'CNY', timezone: 'Asia/Shanghai',
      settings: JSON.stringify({ diningModes: { version: 1, modes: [{ uuid: 'hotpot', name: '火锅' }] } }),
    })

    expect(parsed.settings?.diningModes).toMatchObject({ modes: [{ uuid: 'hotpot', name: '火锅' }] })
  })
  it('identifies membership lookup failures without exposing server messages or credentials', async () => {
    const client = new ApiClient()
    vi.spyOn(client, 'data').mockImplementation(async (path) => {
      if (path.endsWith('/membership')) throw new ApiError('request', 404)
      return {}
    })
    await expect(StoreApi.access(client, store)).rejects.toMatchObject({ step: 'checkMembership', kind: 'request', status: 404 })
  })
  it('normalizes PHP empty scope arrays without granting permissions or accepting populated arrays', () => {
    expect(authorizationSchema.parse({ permissions: [], storeScopes: [] })).toEqual({ permissions: [], storeScopes: {} })
    expect(authorizationSchema.parse({ permissions: [], storeScopes: {} })).toEqual({ permissions: [], storeScopes: {} })
    expect(authorizationSchema.safeParse({ permissions: [], storeScopes: [store] }).success).toBe(false)
  })
  const access: StoreAccess = {
    store: { uuid: store, name: 'Store', code: 'S1', currency: 'CNY', timezone: 'UTC' },
    membership: { status: 'active', role: 'manager' },
    profile: { uuid: store, username: 'staff', roles: ['ROLE_USER'] },
    authorization: { permissions: ['store:product:read', 'store:product:update'], storeScopes: { 'store:product:read': [store], 'store:product:update': ['00000000-0000-4000-8000-000000000002'] } },
  }
  it('requires active membership and exact per-action store scopes rather than role labels or global permissions', () => {
    expect(can(access, 'product', 'read')).toBe(true)
    expect(can(access, 'product', 'update')).toBe(false)
    expect(can(access, 'counter', 'create')).toBe(false)
    expect(can({ ...access, membership: { ...access.membership, status: 'suspended' } }, 'product', 'read')).toBe(false)
    const admin = { ...access, profile: { ...access.profile, roles: ['ROLE_ADMIN'] } }
    expect(can(admin, 'product', 'update')).toBe(true)
    expect(can({ ...admin, membership: { ...access.membership, status: 'revoked' } }, 'product', 'update')).toBe(false)
  })
  it('uses only staff endpoints and validates path identifiers', async () => {
    const client = new ApiClient()
    const request = vi.spyOn(client, 'request').mockResolvedValue({ code: 0 })
    const api = new StoreApi(client, store)
    await api.save('product-categories', null, { name: 'Category' })
    expect(request).toHaveBeenCalledWith(`/api/v1/store/${store}/product-categories`, 'POST', { name: 'Category' })
    expect(() => new StoreApi(client, '../manage')).toThrow()
    expect(() => api.remove('products', '../orders')).toThrow()
  })
  it('sends raster uploads as multipart without overriding the browser boundary', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json(tokens())).mockResolvedValueOnce(json({ code: 0, data: { uuid: store, url: '/uploads/test.png' } }))
    const client = new ApiClient(fetcher)
    await client.login('staff', 'test-only')
    await new StoreApi(client, store).uploadImage(new File(['test'], 'test.png', { type: 'image/png' }), store)
    const init = fetcher.mock.calls[1]![1]!
    expect(init.body).toBeInstanceOf(FormData)
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined()
    expect((init.body as FormData).get('commandUuid')).toBe(store)
    expect(init.credentials).toBe('omit')
  })
  it('validates server-wide result pagination and sends bounded search parameters without query expressions', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(json(tokens())).mockResolvedValueOnce(json({ code: 0, data: { items: [], total: 0, page: 2, hasNext: false } })).mockResolvedValueOnce(json({ code: 0, data: { items: [], total: 0, page: 99, hasNext: false } }))
    const client = new ApiClient(fetcher)
    await client.login('staff', 'test-only')
    const api = new StoreApi(client, store)
    expect(await api.products(2, ' special % ', 'soldout')).toEqual({ items: [], total: 0, page: 2, hasNext: false })
    expect(fetcher.mock.calls[1]![0]).toBe(`/api/v1/store/${store}/operations/products?page=2&limit=30&q=special+%25&status=soldout`)
    await expect(api.products(1)).rejects.toMatchObject({ kind: 'invalid' })
  })
})
