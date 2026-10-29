import { z } from 'zod'

const tokensSchema = z.object({ access_token: z.string().min(1), refresh_token: z.string().min(1), expires_in: z.number().positive() })
const envelopeSchema = z.object({ code: z.number(), data: z.unknown().optional(), paginator: z.unknown().optional() })
const paginatorSchema = z.object({ page: z.number().int().positive(), has_next: z.boolean() })
export class ApiError extends Error {
  constructor(public readonly kind: 'unauthorized' | 'forbidden' | 'network' | 'invalid' | 'request', public readonly status = 0) {
    super(kind)
  }
}

// Credentials are private to this staff instance, never persisted or shared with the payment entry.
export class ApiClient {
  private tokens: z.infer<typeof tokensSchema> | null = null
  private refreshTask: Promise<void> | null = null
  private epoch = 0
  constructor(private readonly fetcher: typeof fetch = fetch, private readonly onExpired: () => void = () => {}) {}

  private async send(path: string, method: string, body?: unknown, token?: string): Promise<Response> {
    // Only same-origin, project-owned paths are allowed; no user-controlled API hosts or redirects.
    if (!path.startsWith('/api/') || path.includes('..')) throw new ApiError('invalid')
    try {
      const fetcher = this.fetcher
      return await fetcher(path, {
        method, credentials: 'omit', redirect: 'error', signal: AbortSignal.timeout(20_000),
        headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), ...(token ? { Authorization: `Bearer ${token}` } : {}) },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
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
  private expire() { this.epoch++; this.tokens = null; this.onExpired() }

  async login(identifier: string, password: string): Promise<void> {
    const epoch = ++this.epoch
    this.tokens = null
    this.refreshTask = null
    const parsed = tokensSchema.safeParse(await this.json(await this.send('/api/auth/login', 'POST', { identifier, password })))
    if (!parsed.success) throw new ApiError('invalid')
    if (epoch !== this.epoch) throw new ApiError('unauthorized')
    this.tokens = parsed.data
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
      } catch (error) {
        // Rotating refresh requests are never automatically replayed, including on timeout.
        if (epoch === this.epoch) this.expire()
        throw error
      }
    })()
    this.refreshTask = task
    try { await task } finally { if (this.refreshTask === task) this.refreshTask = null }
  }
  async request(path: string, method = 'GET', body?: unknown): Promise<unknown> {
    const epoch = this.epoch
    const token = this.tokens?.access_token
    if (!token) throw new ApiError('unauthorized')
    let response = await this.send(path, method, body, token)
    if (epoch !== this.epoch) throw new ApiError('unauthorized')
    if (response.status === 401) {
      // A late 401 from an old token must not rotate the already refreshed token again.
      if (this.tokens?.access_token === token) await this.refresh()
      if (epoch !== this.epoch || !this.tokens) throw new ApiError('unauthorized')
      response = await this.send(path, method, body, this.tokens.access_token)
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
  async data<T>(path: string, schema: z.ZodType<T>, method = 'GET', body?: unknown): Promise<T> {
    const envelope = await this.request(path, method, body)
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
    if (tokens) this.check(await this.send('/api/auth/logout', 'POST', tokens, tokens.access_token))
  }
}
