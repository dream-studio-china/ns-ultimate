import { z } from 'zod'

const tokensSchema = z.object({ access_token: z.string().min(1), refresh_token: z.string().min(1), expires_in: z.number().positive() })
const storeUuidSchema = z.string().uuid()
const envelopeSchema = z.object({ code: z.number(), data: z.unknown().optional(), paginator: z.unknown().optional() })
const paginatorSchema = z.object({ page: z.number().int().positive(), has_next: z.boolean() })
export const STAFF_TOKEN_STORAGE_KEY = 'ns-store-console.auth.tokens'
export const STAFF_STORE_STORAGE_KEY = 'ns-store-console.auth.store'
export interface PersistedStorage {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

function browserStorage(): PersistedStorage | null {
  try {
    return typeof window === 'undefined' ? null : window.localStorage
  } catch {
    return null
  }
}

export class ApiError extends Error {
  constructor(public readonly kind: 'unauthorized' | 'forbidden' | 'network' | 'invalid' | 'request', public readonly status = 0) {
    super(kind)
  }
}

// Staff tokens persist on this device so a page reload can restore the session.
// The payment entry uses a separate client and never reads this storage.
export class ApiClient {
  private tokens: z.infer<typeof tokensSchema> | null = null
  private refreshTask: Promise<void> | null = null
  private epoch = 0
  constructor(
    private readonly fetcher: typeof fetch = fetch,
    private readonly onExpired: () => void = () => {},
    private readonly storage: PersistedStorage | null = browserStorage()
  ) {
    this.restorePersistedTokens()
  }

  private read(key: string): string | null {
    try {
      return this.storage?.getItem(key) ?? null
    } catch {
      return null
    }
  }
  private write(key: string, value: string): void {
    try { this.storage?.setItem(key, value) } catch { /* Authentication remains available for this page. */ }
  }
  private remove(key: string): void {
    try { this.storage?.removeItem(key) } catch { /* Expiration still clears in-memory credentials. */ }
  }
  private restorePersistedTokens(): void {
    const raw = this.read(STAFF_TOKEN_STORAGE_KEY)
    if (!raw) return
    try {
      const parsed = tokensSchema.safeParse(JSON.parse(raw))
      if (parsed.success) this.tokens = parsed.data
      else this.remove(STAFF_TOKEN_STORAGE_KEY)
    } catch {
      this.remove(STAFF_TOKEN_STORAGE_KEY)
    }
  }
  private persistTokens(): void {
    if (!this.tokens) this.remove(STAFF_TOKEN_STORAGE_KEY)
    else this.write(STAFF_TOKEN_STORAGE_KEY, JSON.stringify(this.tokens))
  }
  hasSession(): boolean { return this.tokens !== null }
  rememberedStoreUuid(): string | null {
    const value = this.read(STAFF_STORE_STORAGE_KEY)
    return value && storeUuidSchema.safeParse(value).success ? value : null
  }
  rememberStore(uuid: string): void {
    if (storeUuidSchema.safeParse(uuid).success) this.write(STAFF_STORE_STORAGE_KEY, uuid)
  }
  forgetRememberedStore(): void { this.remove(STAFF_STORE_STORAGE_KEY) }

  private async send(path: string, method: string, body?: unknown, token?: string, extraHeaders: Record<string, string> = {}): Promise<Response> {
    // Only same-origin, project-owned paths are allowed; no user-controlled API hosts or redirects.
    if (!path.startsWith('/api/') || path.includes('..')) throw new ApiError('invalid')
    try {
      const fetcher = this.fetcher
      return await fetcher(path, {
        method, credentials: 'omit', cache: 'no-store', redirect: 'error', signal: AbortSignal.timeout(20_000),
        headers: { Accept: 'application/json', ...(body === undefined || body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...extraHeaders, ...(token ? { Authorization: `Bearer ${token}` } : {}) },
        ...(body === undefined ? {} : { body: body instanceof FormData ? body : JSON.stringify(body) }),
      })
    } catch { throw new ApiError('network') }
  }
  private check(response: Response) {
    if (!response.ok) throw new ApiError(response.status === 401 ? 'unauthorized' : response.status === 403 ? 'forbidden' : 'request', response.status)
  }
  private async json(response: Response): Promise<unknown> {
    this.check(response)
    try { return await response.json() } catch { throw new ApiError('invalid') }
  }
  private expire() {
    this.epoch++
    this.tokens = null
    this.persistTokens()
    this.onExpired()
  }

  async login(identifier: string, password: string): Promise<void> {
    const epoch = ++this.epoch
    this.tokens = null
    this.persistTokens()
    this.refreshTask = null
    const parsed = tokensSchema.safeParse(await this.json(await this.send('/api/auth/login', 'POST', { identifier, password })))
    if (!parsed.success) throw new ApiError('invalid')
    if (epoch !== this.epoch) throw new ApiError('unauthorized')
    this.tokens = parsed.data
    this.persistTokens()
  }
  private async refresh(): Promise<void> {
    if (this.refreshTask) return this.refreshTask
    const epoch = this.epoch
    const current = this.tokens
    if (!current) throw new ApiError('unauthorized')
    const task = (async () => {
      try {
        const parsed = tokensSchema.safeParse(await this.json(await this.send('/api/auth/token/refresh', 'POST', { refresh_token: current.refresh_token })))
        if (!parsed.success) throw new ApiError('invalid')
        if (epoch !== this.epoch) throw new ApiError('unauthorized')
        this.tokens = parsed.data
        this.persistTokens()
      } catch (error) {
        // Rotating refresh requests are never automatically replayed, including on timeout.
        if (epoch === this.epoch) this.expire()
        throw error
      }
    })()
    this.refreshTask = task
    try { await task } finally { if (this.refreshTask === task) this.refreshTask = null }
  }
  async request(path: string, method = 'GET', body?: unknown, extraHeaders: Record<string, string> = {}): Promise<unknown> {
    const epoch = this.epoch
    const token = this.tokens?.access_token
    if (!token) throw new ApiError('unauthorized')
    let response = await this.send(path, method, body, token, extraHeaders)
    if (epoch !== this.epoch) throw new ApiError('unauthorized')
    if (response.status === 401) {
      // A late 401 from an old token must not rotate the already refreshed token again.
      if (this.tokens?.access_token === token) await this.refresh()
      if (epoch !== this.epoch || !this.tokens) throw new ApiError('unauthorized')
      response = await this.send(path, method, body, this.tokens.access_token, extraHeaders)
      if (epoch !== this.epoch) throw new ApiError('unauthorized')
      if (response.status === 401) this.expire()
    }
    this.check(response)
    if (response.status === 204) return undefined
    const parsed = envelopeSchema.safeParse(await this.json(response))
    if (epoch !== this.epoch) throw new ApiError('unauthorized')
    if (!parsed.success) throw new ApiError('invalid')
    if (parsed.data.code !== 0) throw new ApiError('request', response.status)
    return parsed.data
  }
  async data<T>(path: string, schema: z.ZodType<T>, method = 'GET', body?: unknown, extraHeaders: Record<string, string> = {}): Promise<T> {
    const envelope = await this.request(path, method, body, extraHeaders)
    const parsed = schema.safeParse((envelope as { data: unknown })?.data)
    if (!parsed.success) throw new ApiError('invalid')
    return parsed.data
  }
  async list<T>(path: string, schema: z.ZodType<T>, page: number): Promise<{ items: T[]; hasNext: boolean }> {
    const envelope = await this.request(`${path}?page=${page}&limit=30`) as { data: unknown; paginator?: unknown }
    const items = z.array(schema).safeParse(envelope.data)
    const paginator = paginatorSchema.safeParse(envelope.paginator)
    if (!items.success || !paginator.success || paginator.data.page !== page) throw new ApiError('invalid')
    return { items: items.data, hasNext: paginator.data.has_next }
  }
  async logout(): Promise<void> {
    const tokens = this.tokens
    // Invalidate local work immediately; an in-flight refresh cannot restore this session.
    this.expire()
    this.forgetRememberedStore()
    if (tokens) this.check(await this.send('/api/auth/logout', 'POST', tokens, tokens.access_token))
  }
}
