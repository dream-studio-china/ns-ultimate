/**
 * Real-DOM browser acceptance harness for the admin agent attachment, session
 * and rendering lifecycle.
 *
 * The module has no top-level DOM, storage or global write. Every entry point
 * validates the isolated fixture page before installing isolation, importing
 * the application, mounting the agent or touching fixture state.
 *
 * Run from tests/browser/fixture.html through the Vite dev server. Negative
 * regression checks are exported separately and must also be driven from a
 * fresh isolated fixture context, never from an authenticated admin page.
 */

const FIXTURE_MARKER = 'isolated-fixture'
const PREFS_KEY = 'ns-ultimate-agent.preferences.v1'
const DOCS_PATH = '/__ns_agent_acceptance__/openapi.json'
const PROVIDER_BASE_URL = 'https://agent-acceptance.invalid/v1'
const PROVIDER_ENDPOINT = `${PROVIDER_BASE_URL}/chat/completions`
const MODEL = 'synthetic-acceptance-model'
const CASE_TIMEOUT_MS = 15000
const CASE_SETTLE_TIMEOUT_MS = 8000
const CLEANUP_TIMEOUT_MS = 8000
const WAIT_TIMEOUT_MS = 8000
const MONITOR_LOGIN_TIMEOUT_MS = 5000
const EXPECTED_CASE_COUNT = 21
const SYNTHETIC_ACTOR_A = 'synthetic-acceptance-actor-a'
const SYNTHETIC_ACTOR_B = 'synthetic-acceptance-actor-b'
const NEGATIVE_STORAGE_KEY = 'ns-negative-isolation-sentinel'
const BATCH_PATH = '/api/v1/manage/agent/batch-operations'
const BATCH_OPERATION_ID = 'c'.repeat(64)
const BATCH_STORE_UUID = '550e8400-e29b-41d4-a716-446655440001'
const BATCH_STORE_UUID_INPUT = BATCH_STORE_UUID.toUpperCase()
const BATCH_EXPIRES_OFFSET_MS = 300000

const OPENAPI_DOCUMENT = {
  openapi: '3.0.0',
  info: { title: 'Synthetic browser acceptance API', version: '1.0.0' },
  paths: {
    '/api/v1/manage/synthetic-records': {
      get: {
        operationId: 'listSyntheticRecords',
        summary: 'List synthetic acceptance records',
        tags: ['Synthetic'],
        responses: { 200: { description: 'Synthetic records' } }
      }
    }
  }
}

const state = {
  modules: null,
  storage: null,
  cookieJar: null,
  shadow: null,
  provider: null,
  docsRequests: 0,
  blocked: [],
  restorers: [],
  limitations: [],
  owned: new Set(),
  runToken: 0,
  runAborted: true,
  runController: null,
  isolationRetained: false
}

const ENCODER = new TextEncoder()

function abortError(label = 'operation') {
  const error = new Error(`${label} was aborted.`)
  error.name = 'AbortError'
  error.aborted = true
  return error
}

function timeoutError(label, timeoutMs) {
  const error = new Error(`${label} timed out after ${timeoutMs} ms.`)
  error.name = 'TimeoutError'
  error.timedOut = true
  return error
}

function deferred(label = 'deferred') {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })
  return { label, promise, resolve, reject }
}

function withAbortAndTimeout(promise, signal, timeoutMs, label) {
  return new Promise((resolve, reject) => {
    let done = false
    let timer = null
    const finish = (callback, value) => {
      if (done) return
      done = true
      if (timer !== null) clearTimeout(timer)
      signal?.removeEventListener?.('abort', onAbort)
      callback(value)
    }
    const onAbort = () => finish(reject, abortError(label))
    if (signal?.aborted) {
      onAbort()
      return
    }
    timer = setTimeout(() => finish(reject, timeoutError(label, timeoutMs)), timeoutMs)
    signal?.addEventListener?.('abort', onAbort, { once: true })
    Promise.resolve(promise).then(
      value => finish(resolve, value),
      error => finish(reject, error)
    )
  })
}

function waitFor(predicate, {
  root = document,
  signal = null,
  timeoutMs = WAIT_TIMEOUT_MS,
  label = 'DOM condition',
  onObserver = null
} = {}) {
  return new Promise((resolve, reject) => {
    let done = false
    let observer = null
    let timer = null
    const cleanup = () => {
      if (observer) observer.disconnect()
      observer = null
      if (timer !== null) clearTimeout(timer)
      timer = null
      signal?.removeEventListener?.('abort', onAbort)
    }
    const finish = (callback, value) => {
      if (done) return
      done = true
      cleanup()
      callback(value)
    }
    const check = () => {
      if (done) return
      let matched = false
      try { matched = predicate() } catch (error) {
        finish(reject, error)
        return
      }
      if (matched) finish(resolve)
    }
    const onAbort = () => finish(reject, abortError(label))
    if (signal?.aborted) {
      onAbort()
      return
    }
    observer = new MutationObserver(check)
    observer.observe(root, { subtree: true, childList: true, characterData: true, attributes: true })
    try { onObserver?.(observer) } catch { /* Observation hooks are test-only. */ }
    signal?.addEventListener?.('abort', onAbort, { once: true })
    timer = setTimeout(() => finish(reject, timeoutError(label, timeoutMs)), timeoutMs)
    queueMicrotask(check)
  })
}

async function flushMicrotasks(ctx = null, count = 8) {
  for (let index = 0; index < count; index += 1) {
    await Promise.resolve()
    ctx?.check()
  }
}

function ok(condition, message) {
  if (!condition) throw new Error(message)
}

function equal(actual, expected, message) {
  if (actual !== expected) throw new Error(`${message}: expected ${JSON.stringify(expected)}, received ${JSON.stringify(actual)}`)
}

function deepEqual(actual, expected, message) {
  const actualJson = canonicalJson(actual)
  const expectedJson = canonicalJson(expected)
  if (actualJson !== expectedJson) throw new Error(`${message}: expected ${expectedJson}, received ${actualJson}`)
}

function canonicalJson(value) {
  if (Array.isArray(value)) return `[${value.map(item => canonicalJson(item)).join(',')}]`
  if (value && typeof value === 'object') {
    return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${canonicalJson(value[key])}`).join(',')}}`
  }
  return JSON.stringify(value)
}

function includes(value, needle, message) {
  if (!String(value).includes(needle)) throw new Error(`${message}: expected to find ${JSON.stringify(needle)}`)
}

function excludes(value, needle, message) {
  if (String(value).includes(needle)) throw new Error(`${message}: did not expect ${JSON.stringify(needle)}`)
}

function createMemoryStorage() {
  const values = new Map()
  return {
    get length() { return values.size },
    key(index) { return [...values.keys()][index] ?? null },
    getItem(key) { return values.has(String(key)) ? values.get(String(key)) : null },
    setItem(key, value) { values.set(String(key), String(value)) },
    removeItem(key) { values.delete(String(key)) },
    clear() { values.clear() }
  }
}

function descriptorFor(owner, name) {
  let current = owner
  while (current) {
    const descriptor = Object.getOwnPropertyDescriptor(current, name)
    if (descriptor) return { own: current === owner, descriptor }
    current = Object.getPrototypeOf(current)
  }
  return null
}

function replaceProperty(owner, name, value) {
  const found = descriptorFor(owner, name)
  if (!found || found.descriptor.configurable !== true) return null
  try {
    Object.defineProperty(owner, name, {
      configurable: true,
      enumerable: found.descriptor.enumerable === true,
      writable: true,
      value
    })
  } catch {
    return null
  }
  return () => {
    if (found.own) Object.defineProperty(owner, name, found.descriptor)
    else delete owner[name]
  }
}

function replaceAccessor(owner, name, get, set) {
  const found = descriptorFor(owner, name)
  if (!found || found.descriptor.configurable !== true) return null
  try {
    Object.defineProperty(owner, name, {
      configurable: true,
      enumerable: found.descriptor.enumerable === true,
      get,
      set
    })
  } catch {
    return null
  }
  return () => {
    if (found.own) Object.defineProperty(owner, name, found.descriptor)
    else delete owner[name]
  }
}

function assertFixtureShell() {
  ok(document.body?.getAttribute('data-ns-agent-acceptance') === FIXTURE_MARKER,
    'Refusing to run: the page is not the isolated admin-agent fixture.')
  const pathname = decodeURIComponent(location.pathname)
  ok(/\/apps\/admin-agent\/tests\/browser\/[^/]+\.html?$/u.test(pathname),
    `Refusing to run: unexpected fixture path ${pathname}`)
  ok(location.protocol === 'http:' || location.protocol === 'https:',
    'Refusing to run: the fixture must be served over HTTP by the local Vite server.')
  ok(!location.hash, 'Refusing to run: the fixture URL must not carry a fragment.')
}

function assertFixturePage() {
  assertFixtureShell()
  ok(!document.querySelector('#app') && !document.querySelector('[data-v-app]') && !document.querySelector('ns-admin-agent'),
    'Refusing to run: an application page or mounted agent is already present.')
}

export function assertBrowserAcceptancePage() {
  assertFixturePage()
  return {
    origin: location.origin,
    pathname: decodeURIComponent(location.pathname),
    fixtureMarker: document.body?.getAttribute('data-ns-agent-acceptance') || null
  }
}

function patchabilityRequirements() {
  return [
    { label: 'window.localStorage', owner: window, name: 'localStorage' },
    { label: 'document.cookie', owner: document, name: 'cookie', accessor: true },
    { label: 'window.fetch', owner: window, name: 'fetch' },
    { label: 'window.XMLHttpRequest', owner: window, name: 'XMLHttpRequest' },
    { label: 'window.WebSocket', owner: window, name: 'WebSocket' },
    { label: 'window.EventSource', owner: window, name: 'EventSource' },
    { label: 'navigator.sendBeacon', owner: navigator, name: 'sendBeacon' },
    { label: 'Element.prototype.attachShadow', owner: Element.prototype, name: 'attachShadow' }
  ]
}

function assertPatchability() {
  const failures = []
  for (const requirement of patchabilityRequirements()) {
    const found = descriptorFor(requirement.owner, requirement.name)
    if (!found || found.descriptor.configurable !== true) {
      failures.push(`${requirement.label} is not configurable`)
      continue
    }
    if (requirement.accessor
      && (typeof found.descriptor.get !== 'function' || typeof found.descriptor.set !== 'function')) {
      failures.push(`${requirement.label} is not a replaceable accessor`)
    }
  }
  if (failures.length) {
    throw new Error(`Refusing to run: required browser isolation is unavailable (${failures.join('; ')}).`)
  }
}

function snapshotRequiredDescriptor(requirement) {
  const found = descriptorFor(requirement.owner, requirement.name)
  if (!found) return null
  const descriptor = found.descriptor
  return {
    own: found.own,
    configurable: descriptor.configurable === true,
    enumerable: descriptor.enumerable === true,
    writable: Object.prototype.hasOwnProperty.call(descriptor, 'writable') ? descriptor.writable : null,
    value: Object.prototype.hasOwnProperty.call(descriptor, 'value') ? descriptor.value : null,
    get: typeof descriptor.get === 'function' ? descriptor.get : null,
    set: typeof descriptor.set === 'function' ? descriptor.set : null
  }
}

function captureRequiredDescriptors() {
  return patchabilityRequirements().map(requirement => ({
    label: requirement.label,
    snapshot: snapshotRequiredDescriptor(requirement)
  }))
}

function sameDescriptorSnapshot(first, second) {
  if (!first || !second) return first === second
  return first.own === second.own
    && first.configurable === second.configurable
    && first.enumerable === second.enumerable
    && first.writable === second.writable
    && first.value === second.value
    && first.get === second.get
    && first.set === second.set
}

function restoreExactDescriptor(owner, name, found) {
  if (!found) return false
  if (found.own) Object.defineProperty(owner, name, found.descriptor)
  else delete owner[name]
  return true
}

function requiredDescriptorsRestored(baseline) {
  return baseline.every(entry => {
    const requirement = patchabilityRequirements().find(item => item.label === entry.label)
    const current = requirement ? snapshotRequiredDescriptor(requirement) : null
    return sameDescriptorSnapshot(current, entry.snapshot)
  })
}

function installMemoryCookieJar(restorers) {
  const values = new Map()
  const read = () => [...values].map(([name, value]) => `${name}=${value}`).join('; ')
  const write = raw => {
    const parts = String(raw).split(';')
    const pair = parts.shift() || ''
    const separator = pair.indexOf('=')
    if (separator < 0) return
    const name = pair.slice(0, separator).trim()
    const value = pair.slice(separator + 1).trim()
    let remove = false
    for (const attribute of parts) {
      const [attributeName, attributeValue = ''] = attribute.split('=')
      if (/^\s*max-age\s*$/iu.test(attributeName) && Number(attributeValue) <= 0) remove = true
      if (/^\s*expires\s*$/iu.test(attributeName) && Number.isFinite(Date.parse(attributeValue)) && Date.parse(attributeValue) <= Date.now()) remove = true
    }
    if (remove) values.delete(name)
    else values.set(name, value)
  }
  const restore = replaceAccessor(document, 'cookie', read, write)
  if (!restore) throw new Error('Refusing to run: document.cookie could not be replaced.')
  restorers.push(restore)
  return { read, write, values }
}

function createSseResponse() {
  let controller
  let closed = false
  let abortListener = null
  const stream = new ReadableStream({
    start(value) { controller = value }
  })
  const response = new Response(stream, {
    status: 200,
    headers: { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-store' }
  })
  const entry = {
    response,
    get closed() { return closed },
    detachAbort() {
      if (abortListener) {
        entry.signal?.removeEventListener?.('abort', abortListener)
        abortListener = null
      }
    },
    attachAbort(signal) {
      entry.signal = signal
      if (!signal) return
      if (signal.aborted) {
        entry.error()
        return
      }
      abortListener = () => entry.error()
      signal.addEventListener('abort', abortListener, { once: true })
    },
    sendEvent(event) {
      if (closed) return false
      try {
        controller.enqueue(ENCODER.encode(`data: ${JSON.stringify(event)}\n\n`))
        return true
      } catch {
        closed = true
        entry.detachAbort()
        return false
      }
    },
    text(value) {
      return entry.sendEvent({ choices: [{ delta: { content: value } }] })
    },
    toolCall({ id, name, arguments: args }) {
      return entry.sendEvent({
        choices: [{
          delta: {
            tool_calls: [{
              index: 0,
              id,
              type: 'function',
              function: {
                name,
                arguments: typeof args === 'string' ? args : JSON.stringify(args)
              }
            }]
          }
        }]
      })
    },
    close() {
      if (closed) return
      try {
        controller.enqueue(ENCODER.encode('data: [DONE]\n\n'))
        controller.close()
      } catch { /* The stream may already have been aborted. */ }
      closed = true
      entry.detachAbort()
    },
    error() {
      if (closed) return
      try { controller.error(new DOMException('Synthetic provider stream aborted.', 'AbortError')) } catch { /* Already closed. */ }
      closed = true
      entry.detachAbort()
    }
  }
  return entry
}

class SyntheticProvider {
  constructor(ownerToken) {
    this.ownerToken = ownerToken
    this.active = true
    this.requests = []
    this.queue = []
    this.requestWaiters = []
    this.streams = new Set()
    this.deferredFetches = new Set()
  }

  assertActive() {
    if (!this.active || state.runToken !== this.ownerToken || state.runAborted) {
      throw abortError('synthetic provider')
    }
  }

  enqueueText(text) {
    this.assertActive()
    this.queue.push(({ signal }) => {
      const entry = createSseResponse()
      entry.attachAbort(signal)
      entry.text(text)
      entry.close()
      return entry.response
    })
  }

  enqueueToolCall(name, args, id = `synthetic-call-${this.requests.length + 1}`) {
    this.assertActive()
    this.queue.push(({ signal }) => {
      const entry = createSseResponse()
      entry.attachAbort(signal)
      entry.toolCall({ id, name, arguments: args })
      entry.close()
      return entry.response
    })
  }

  enqueueDeferredStream() {
    this.assertActive()
    const entry = createSseResponse()
    this.streams.add(entry)
    this.queue.push(({ signal }) => {
      entry.attachAbort(signal)
      return entry.response
    })
    return entry
  }

  enqueueDeferredFetch() {
    this.assertActive()
    const request = deferred('provider fetch')
    request.promise.catch(() => {})
    const record = { request, settled: false }
    this.deferredFetches.add(record)
    // Deliberately ignore the AbortSignal: this simulates a provider completion
    // that cannot be retracted after the UI has disposed the old session.
    this.queue.push(() => request.promise)
    return {
      resolve: text => {
        if (record.settled || !this.active) return
        record.settled = true
        const entry = createSseResponse()
        entry.text(text)
        entry.close()
        request.resolve(entry.response)
      },
      reject: error => {
        if (record.settled) return
        record.settled = true
        request.reject(error)
      }
    }
  }

  recordRequest(request) {
    this.assertActive()
    this.requests.push(request)
    const ready = []
    for (const waiter of this.requestWaiters) {
      if (this.requests.length >= waiter.count) ready.push(waiter)
    }
    for (const waiter of ready) {
      this.requestWaiters.splice(this.requestWaiters.indexOf(waiter), 1)
      waiter.resolve(this.requests)
    }
  }

  takeResponse({ signal }) {
    this.assertActive()
    const factory = this.queue.shift()
    if (!factory) throw new Error('Unexpected synthetic provider request: enqueue a response before sending.')
    return factory({ signal })
  }

  waitForRequests(count, ctx) {
    this.assertActive()
    if (this.requests.length >= count) return Promise.resolve(this.requests)
    const inner = new Promise((resolve, reject) => {
      this.requestWaiters.push({ count, resolve, reject })
    })
    return ctx.wait(inner, `synthetic provider request ${count}`)
  }

  dispose(reason = abortError('synthetic provider')) {
    if (!this.active) return
    this.active = false
    for (const stream of this.streams) {
      try { stream.error() } catch { /* Best-effort cleanup. */ }
    }
    this.streams.clear()
    for (const fetch of this.deferredFetches) {
      if (fetch.settled) continue
      fetch.settled = true
      try { fetch.request.reject(reason) } catch { /* Best-effort cleanup. */ }
    }
    this.deferredFetches.clear()
    for (const waiter of this.requestWaiters) {
      try { waiter.reject(reason) } catch { /* Best-effort cleanup. */ }
    }
    this.requestWaiters.length = 0
    this.queue.length = 0
  }
}

function docsResponse() {
  return new Response(JSON.stringify(OPENAPI_DOCUMENT), {
    status: 200,
    headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }
  })
}

function requestUrl(input) {
  if (typeof input === 'string') return new URL(input, location.href)
  if (input instanceof URL) return new URL(input.href)
  if (input instanceof Request) return new URL(input.url, location.href)
  return new URL(String(input), location.href)
}

function requestMethod(input, init) {
  return String(init?.method || (input instanceof Request ? input.method : 'GET')).toUpperCase()
}

function requestHeaders(input, init) {
  try {
    if (init?.headers) return new Headers(init.headers)
    if (input instanceof Request) return new Headers(input.headers)
  } catch { /* An empty header list is safe. */ }
  return new Headers()
}

async function requestBodyText(input, init) {
  if (typeof init?.body === 'string') return init.body
  if (init?.body instanceof ArrayBuffer) return new TextDecoder().decode(init.body)
  if (typeof URLSearchParams !== 'undefined' && init?.body instanceof URLSearchParams) return init.body.toString()
  if (input instanceof Request) {
    try { return await input.clone().text() } catch { return '' }
  }
  return ''
}

function blockedRequest(url, reason) {
  state.blocked.push(`${reason}: ${url}`)
  throw new TypeError(`Blocked non-fixture network request (${reason}): ${url}`)
}

async function syntheticFetch(input, init = {}) {
  if (!state.runToken || state.runAborted) throw abortError('network request')
  const url = requestUrl(input)
  const method = requestMethod(input, init)
  if (url.origin === location.origin && url.pathname === DOCS_PATH && method === 'GET') {
    if (!state.runToken || state.runAborted) throw abortError('API document request')
    state.docsRequests += 1
    return docsResponse()
  }
  if (url.href === PROVIDER_ENDPOINT && method === 'POST') {
    const provider = state.provider
    if (!provider) return blockedRequest(url.href, 'provider request without a synthetic provider')
    provider.assertActive()
    const bodyText = await requestBodyText(input, init)
    provider.assertActive()
    let body
    try { body = JSON.parse(bodyText) } catch { return blockedRequest(url.href, 'provider request body was not JSON') }
    const headers = requestHeaders(input, init)
    provider.recordRequest({
      url: url.href,
      method,
      body,
      bodyBytes: bodyText.length,
      credentials: init.credentials || (input instanceof Request ? input.credentials : null),
      authorization: headers.get('authorization') ? 'synthetic-present' : 'absent'
    })
    return provider.takeResponse({ signal: init.signal || (input instanceof Request ? input.signal : undefined) })
  }
  return blockedRequest(url.href, 'no synthetic response')
}

function blockedConstructor(label) {
  const Blocked = function BlockedNetworkChannel() {
    state.blocked.push(`channel: ${label}`)
    throw new DOMException(`${label} is blocked by the isolated acceptance fixture.`, 'NotAllowedError')
  }
  return Blocked
}

function installIsolation() {
  assertPatchability()
  const restorers = []
  try {
    const storage = createMemoryStorage()
    const restoreStorage = replaceAccessor(window, 'localStorage', () => storage, () => {
      throw new TypeError('The isolated acceptance fixture does not permit replacing localStorage.')
    })
    if (!restoreStorage) throw new Error('window.localStorage could not be replaced.')
    restorers.push(restoreStorage)

    const cookieJar = installMemoryCookieJar(restorers)

    const restoreFetch = replaceProperty(window, 'fetch', syntheticFetch)
    if (!restoreFetch) throw new Error('window.fetch could not be replaced.')
    restorers.push(restoreFetch)

    for (const name of ['XMLHttpRequest', 'WebSocket', 'EventSource']) {
      const restore = replaceProperty(window, name, blockedConstructor(name))
      if (!restore) throw new Error(`${name} could not be replaced.`)
      restorers.push(restore)
    }

    const restoreBeacon = replaceProperty(navigator, 'sendBeacon', (...args) => {
      state.blocked.push(`channel: navigator.sendBeacon ${String(args[0] || '')}`)
      return false
    })
    if (!restoreBeacon) throw new Error('navigator.sendBeacon could not be replaced.')
    restorers.push(restoreBeacon)

    const originalAttachShadow = Element.prototype.attachShadow
    if (typeof originalAttachShadow !== 'function') throw new Error('Element.prototype.attachShadow is unavailable.')
    const restoreShadow = replaceProperty(Element.prototype, 'attachShadow', function attachShadowForAcceptance(init) {
      const root = originalAttachShadow.call(this, { ...(init || {}), mode: 'open' })
      state.shadow = root
      return root
    })
    if (!restoreShadow) throw new Error('Element.prototype.attachShadow could not be wrapped.')
    restorers.push(restoreShadow)

    state.storage = storage
    state.cookieJar = cookieJar
    state.restorers = restorers
    state.storage.setItem('app_locale', 'en')
  } catch (error) {
    const rollbackErrors = []
    for (const restore of [...restorers].reverse()) {
      try { restore() } catch (rollbackError) { rollbackErrors.push(rollbackError) }
    }
    state.storage = null
    state.cookieJar = null
    if (rollbackErrors.length) {
      // Keep the owned restorers and ask the caller to retain isolation rather
      // than expose native network/storage channels after an incomplete rollback.
      state.restorers = restorers
      const failure = new Error(`Refusing to run: browser isolation installation failed and rollback could not be completed (${error.message}; ${rollbackErrors[0].message}).`)
      failure.keepIsolation = true
      throw failure
    }
    state.restorers = []
    throw new Error(`Refusing to run: required browser isolation could not be installed (${error.message}).`)
  }
}

function restoreIsolation() {
  const errors = []
  for (const restore of [...state.restorers].reverse()) {
    try { restore() } catch (error) { errors.push(error) }
  }
  state.restorers.length = 0
  state.storage = null
  state.cookieJar = null
  state.modules = null
  state.shadow = null
  state.provider = null
  return errors
}

function writeSyntheticPreferences() {
  if (!state.storage) return
  state.storage.setItem('app_locale', 'en')
  state.storage.setItem(state.modules?.PREFS_KEY || PREFS_KEY, JSON.stringify({
    baseUrl: PROVIDER_BASE_URL,
    model: MODEL,
    docsUrl: DOCS_PATH,
    apiKey: '',
    visionEnabled: false
  }))
}

function readPreferencesString() {
  return state.storage?.getItem(state.modules?.PREFS_KEY || PREFS_KEY) || ''
}

function createSyntheticHost({
  key = 'syntheticRecords',
  name = 'SyntheticRecords',
  knowledgeFactory = () => [],
  query = null,
  writeCapabilities = [],
  prepareChange = null,
  batchHost = null
} = {}) {
  const catalog = {
    key,
    name,
    path: '/api/v1/manage/synthetic-records',
    readable: true,
    fields: [
      { property: 'id', label: 'ID', type: 'integer', required: false },
      { property: 'name', label: 'Name', type: 'text', required: false }
    ],
    filters: [{
      property: 'name',
      label: 'Name',
      type: 'text',
      operator: 'contains',
      operators: ['contains', 'equals'],
      options: [],
      relation: false,
      relationValueKey: null
    }],
    select: ['id', 'name'],
    defaultOrder: null
  }
  let resolveCatalog
  const catalogReady = new Promise(resolve => { resolveCatalog = resolve })
  const host = {
    catalogReady,
    getCatalog() {
      if (resolveCatalog) {
        const resolve = resolveCatalog
        resolveCatalog = null
        resolve(catalog)
      }
      return [catalog]
    },
    getNavigationCatalog() { return [] },
    getKnowledgeArticles() { return knowledgeFactory() },
    getWritePreviewPolicy() { return {} },
    getWriteCapabilities() { return writeCapabilities },
    getInspectionPolicy() { return {} },
    prepareRecordChange(input, signal) {
      if (typeof prepareChange === 'function') return prepareChange(input, signal)
      return Promise.reject(new Error('Synthetic change preparation is unavailable for this host.'))
    },
    queryRecords(input, operations, signal) {
      if (typeof query === 'function') return query(input, operations, signal)
      return Promise.resolve({
        entity: input.entity,
        records: [],
        page: 1,
        count: 0,
        total: 0,
        hasMore: false
      })
    }
  }
  if (batchHost) {
    for (const method of ['getBatchCreateCapabilities', 'prepareRecordBatch', 'restoreRecordBatch', 'commitRecordBatch', 'getRecordBatchStatus', 'cancelRecordBatch']) {
      if (typeof batchHost[method] !== 'function') continue
      host[method] = (...args) => batchHost[method](...args)
    }
  }
  return host
}

function collectControls(shadow) {
  const icons = shadow.querySelectorAll('.header .icon')
  ok(icons.length >= 4, 'The agent header controls were not rendered.')
  return {
    launcher: shadow.querySelector('button.launcher'),
    panel: shadow.querySelector('section.panel'),
    messages: shadow.querySelector('.messages'),
    status: shadow.querySelector('.status'),
    composer: shadow.querySelector('form.composer'),
    input: shadow.querySelector('form.composer textarea'),
    send: shadow.querySelector('form.composer button[type="submit"]'),
    fileInput: shadow.querySelector('form.composer input[type="file"]'),
    settings: shadow.querySelector('form.settings'),
    clearButton: icons[0],
    cancelButton: icons[1],
    settingsButton: icons[2],
    closeButton: icons[3]
  }
}

function createCaseContext({ name, runToken, runSignal }) {
  const controller = new AbortController()
  const providers = new Set()
  const disposes = []
  const restorers = []
  const deferreds = new Set()
  let aborted = false
  let abortReason = null

  const context = {
    name,
    storage: state.storage,
    provider: null,
    get signal() { return controller.signal },
    get aborted() { return aborted || state.runToken !== runToken || state.runAborted },
    check() {
      if (aborted) throw abortReason || abortError(`case ${name}`)
      if (state.runToken !== runToken) throw abortError(`case ${name} ownership changed`)
      if (state.runAborted) throw abortError(`case ${name} run aborted`)
    },
    createProvider() {
      context.check()
      const provider = new SyntheticProvider(runToken)
      providers.add(provider)
      return provider
    },
    deferred(label) {
      context.check()
      const raw = deferred(label)
      raw.promise.catch(() => {})
      const wrapped = {
        label,
        promise: raw.promise,
        resolve(value) {
          deferreds.delete(wrapped)
          raw.resolve(value)
        },
        reject(error) {
          deferreds.delete(wrapped)
          raw.reject(error)
        }
      }
      deferreds.add(wrapped)
      return wrapped
    },
    wait(promise, label, timeoutMs = WAIT_TIMEOUT_MS) {
      context.check()
      return withAbortAndTimeout(promise, controller.signal, timeoutMs, label).then(value => {
        context.check()
        return value
      })
    },
    guard(promise, label) { return context.wait(promise, label) },
    waitFor(predicate, options = {}) {
      context.check()
      return waitFor(predicate, { ...options, signal: controller.signal }).then(() => context.check())
    },
    never(label) {
      context.check()
      return context.wait(new Promise(() => {}), label, 60000)
    },
    addDispose(callback) { disposes.push(callback) },
    addRestore(callback) { restorers.push(callback) },
    abort(reason = abortError(`case ${name}`)) {
      if (aborted) return
      aborted = true
      abortReason = reason
      controller.abort(reason)
      for (const entry of [...deferreds]) {
        try { entry.reject(reason) } catch { /* Best-effort cancellation. */ }
      }
      deferreds.clear()
      for (const provider of [...providers]) {
        try { provider.dispose(reason) } catch { /* Best-effort cancellation. */ }
      }
      for (const dispose of [...disposes].reverse()) {
        try { dispose() } catch { /* Best-effort cancellation. */ }
      }
    },
    async mount({ host = createSyntheticHost(), provider = null, open = true } = {}) {
      context.check()
      ok(!document.querySelector('ns-admin-agent'), 'A previous agent is still mounted at the start of a case.')
      const activeProvider = provider || context.createProvider()
      activeProvider.assertActive()
      state.provider = activeProvider
      state.shadow = null
      context.check()
      const disposeOriginal = state.modules.mountAdminAgent(host)
      ok(typeof disposeOriginal === 'function', 'mountAdminAgent did not return a dispose function.')
      const shadow = state.shadow
      ok(shadow, 'The mounted shadow root was not captured.')
      let disposed = false
      const dispose = () => {
        if (disposed) return
        disposed = true
        state.owned.delete(dispose)
        disposeOriginal()
      }
      disposes.push(dispose)
      state.owned.add(dispose)
      await context.wait(host.catalogReady || Promise.resolve(), 'initial synthetic API catalog load')
      context.check()
      const controls = collectControls(shadow)
      if (open) {
        context.check()
        controls.launcher.click()
      }
      return {
        ctx: context,
        host,
        provider: activeProvider,
        shadow,
        controls,
        dispose,
        assertActive: () => context.check()
      }
    },
    async cleanup() {
      const errors = []
      for (const dispose of [...disposes].reverse()) {
        try { dispose() } catch (error) { errors.push(error) }
      }
      disposes.length = 0
      for (const restore of [...restorers].reverse()) {
        try { restore() } catch (error) { errors.push(error) }
      }
      restorers.length = 0
      for (const provider of [...providers]) {
        try { provider.dispose() } catch (error) { errors.push(error) }
      }
      providers.clear()
      deferreds.clear()
      runSignal?.removeEventListener?.('abort', onRunAbort)
      if (state.provider && providers.size === 0) state.provider = null
      if (errors.length) throw errors[0]
    }
  }

  const onRunAbort = () => context.abort(abortError(`run ${runToken}`))
  if (runSignal?.aborted) onRunAbort()
  else runSignal?.addEventListener?.('abort', onRunAbort, { once: true })

  return context
}

function selectFiles(agent, files) {
  agent.assertActive()
  const transfer = new DataTransfer()
  for (const file of files) transfer.items.add(file)
  agent.controls.fileInput.files = transfer.files
  agent.controls.fileInput.dispatchEvent(new Event('change', { bubbles: true }))
  agent.assertActive()
}

function pasteImages(agent, files) {
  agent.assertActive()
  const transfer = new DataTransfer()
  for (const file of files) transfer.items.add(file)
  const event = new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: transfer })
  agent.controls.input.dispatchEvent(event)
  agent.assertActive()
  return event.defaultPrevented
}

function makeFile(name, content, type = 'text/plain') {
  return new File([content], name, { type, lastModified: 1700000000000 })
}

function findButton(root, matcher) {
  const matches = typeof matcher === 'function'
    ? matcher
    : text => matcher instanceof RegExp ? matcher.test(text) : text === matcher
  return [...root.querySelectorAll('button')].find(button => matches(button.textContent.trim(), button)) || null
}

/**
 * Activate a potentially gated control. A disabled form control cannot be
 * clicked by a user or by HTMLElement.click(), but dispatching a click event
 * still reaches its listeners. This verifies the action-time guard rather than
 * relying on the disabled attribute alone.
 */
function activateGuardedControl(ctx, control) {
  ctx.check()
  const disabled = control.disabled === true
  if (disabled) {
    control.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }))
  } else {
    control.click()
  }
  ctx.check()
  return disabled ? 'dispatchEvent' : 'click'
}

function findSelect(root, label) {
  return [...root.querySelectorAll('select')].find(select => select.getAttribute('aria-label') === label) || null
}

function importWorkbenchNode(shadow) {
  return shadow.querySelector('section.import-workbench')
}

function openImportWorkbench(ctx, agent) {
  ctx.check()
  const button = [...agent.shadow.querySelectorAll('form.composer button')]
    .find(control => control.hasAttribute('aria-expanded')) || null
  ok(button, 'The Import control was not rendered in the composer.')
  ok(!button.disabled, 'The Import control must be enabled for a host with batch capabilities.')
  button.click()
  ctx.check()
  const workbench = importWorkbenchNode(agent.shadow)
  ok(workbench, 'The import workbench section was not rendered.')
  ok(!workbench.classList.contains('hidden'), 'The import workbench did not open.')
  equal(button.getAttribute('aria-expanded'), 'true', 'The Import control should announce the open workbench')
  return { button, workbench }
}

function setImportEntity(ctx, workbench, entity) {
  ctx.check()
  const select = findSelect(workbench, 'Import entity')
  ok(select, 'The import entity selector was not rendered.')
  select.value = entity
  select.dispatchEvent(new Event('change', { bubbles: true }))
  ctx.check()
  const refreshed = findSelect(workbench, 'Import entity')
  equal(refreshed?.value, entity, `The import entity should be ${entity}`)
}

function chooseImportFile(ctx, workbench, file) {
  ctx.check()
  const input = workbench.querySelector('input[type="file"]')
  ok(input, 'The import file input was not rendered.')
  const transfer = new DataTransfer()
  transfer.items.add(file)
  input.files = transfer.files
  input.dispatchEvent(new Event('change', { bubbles: true }))
  ctx.check()
}

function setImportMapping(ctx, workbench, field, columnIndex) {
  ctx.check()
  const select = findSelect(workbench, `Map ${field}`)
  ok(select, `The mapping selector for ${field} was not rendered.`)
  select.value = String(columnIndex)
  select.dispatchEvent(new Event('change', { bubbles: true }))
  ctx.check()
}

function importCellInput(workbench, rowIndex, columnIndex, columnCount) {
  const inputs = [...workbench.querySelectorAll('.import-table input')]
  return inputs[rowIndex * columnCount + columnIndex] || null
}

function editImportCell(ctx, workbench, rowIndex, columnIndex, columnCount, value) {
  ctx.check()
  const input = importCellInput(workbench, rowIndex, columnIndex, columnCount)
  ok(input, `The import cell ${rowIndex + 1}:${columnIndex + 1} was not rendered.`)
  input.value = value
  input.dispatchEvent(new Event('change', { bubbles: true }))
  ctx.check()
}

async function waitForImportTable(agent, rowCount, columnCount) {
  await agent.ctx.waitFor(() => {
    const workbench = importWorkbenchNode(agent.shadow)
    const inputs = workbench ? workbench.querySelectorAll('.import-table input') : []
    return inputs.length === rowCount * columnCount
  }, { root: agent.shadow, label: 'local import table' })
  agent.assertActive()
}

function installFetchCounter(ctx) {
  const current = window.fetch
  let calls = 0
  subscribeCaseProperty(ctx, window, 'fetch', (...args) => {
    calls += 1
    return current(...args)
  })
  return { get calls() { return calls } }
}

function batchTimestamp(offsetMs = 0) {
  const date = new Date(Date.now() + offsetMs)
  const pad = value => String(value).padStart(2, '0')
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}T${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}+00:00`
}

function buildBatchPreview(input, {
  operationId = BATCH_OPERATION_ID,
  expiresAt = batchTimestamp(BATCH_EXPIRES_OFFSET_MS)
} = {}) {
  const rows = input.rows.map((row, index) => ({
    row: index + 1,
    title: String(row.name ?? `Row ${index + 1}`),
    identifier: null,
    differences: Object.entries(row).map(([property, after]) => ({ property, before: null, after }))
  }))
  return {
    operationId,
    entity: input.entity,
    action: 'create',
    title: `Create ${rows.length} ${input.entity} record${rows.length === 1 ? '' : 's'}`,
    affectedRecords: rows.length,
    expiresAt,
    rows,
    saved: false
  }
}

function buildBatchReceipt(preparedInput, {
  operationId = BATCH_OPERATION_ID,
  status = 'succeeded',
  verified = false,
  expiresAt = null
} = {}) {
  const rows = preparedInput?.rows || []
  const fields = [...new Set(rows.flatMap(row => Object.keys(row)))]
  const results = status === 'succeeded'
    ? rows.map((row, index) => ({ row: index + 1, identifier: String(101 + index) }))
    : []
  return {
    operationId,
    entity: preparedInput?.entity || 'Tag',
    action: 'create',
    status,
    rowCount: rows.length,
    fields,
    results,
    createdAt: batchTimestamp(-1000),
    expiresAt: expiresAt || batchTimestamp(BATCH_EXPIRES_OFFSET_MS),
    completedAt: status === 'pending' ? null : batchTimestamp(0),
    ...(verified ? { verified: true } : {})
  }
}

/**
 * Synthetic batch backend behind the real createBatchMutationHost. It validates
 * and serializes the actual fixed-route transport while returning canonical
 * previews and receipts shaped exactly like the backend contract. No HTTP call
 * leaves this page.
 */
function createSyntheticBatchBackend({
  operationId = BATCH_OPERATION_ID,
  commitOutcome = 'succeeded',
  statusOutcome = 'pending',
  mutatePreview = null,
  mutateCommitReceipt = null,
  commitDeferred = null
} = {}) {
  const calls = []
  let preparedInput = null
  let preview = null
  let pendingCommitReceipt = null
  const operationPath = `${BATCH_PATH}/${operationId}`

  const makeReceipt = (status, verified = false) => buildBatchReceipt(preparedInput, {
    operationId,
    status,
    verified,
    expiresAt: preview?.expiresAt || null
  })

  const request = {
    async post(path, body) {
      calls.push({ method: 'POST', path, body })
      if (path === BATCH_PATH) {
        preparedInput = body
        preview = buildBatchPreview(body, { operationId })
        if (typeof mutatePreview === 'function') preview = mutatePreview(preview, body)
        return { data: preview }
      }
      if (path === `${operationPath}/commit`) {
        const success = makeReceipt('succeeded', true)
        const receipt = commitOutcome === 'uncertain'
          ? makeReceipt('pending')
          : typeof mutateCommitReceipt === 'function' ? mutateCommitReceipt(success) : success
        if (commitDeferred) {
          pendingCommitReceipt = receipt
          return { data: await commitDeferred.promise }
        }
        return { data: receipt }
      }
      if (path === `${operationPath}/cancel`) return { data: makeReceipt('cancelled') }
      throw new Error(`Unexpected synthetic batch POST ${path}`)
    },
    async get(path) {
      calls.push({ method: 'GET', path })
      if (path === `${operationPath}/preview`) return { data: preview }
      if (path === operationPath) return { data: makeReceipt(statusOutcome) }
      if (path === BATCH_PATH) return { data: { items: [], page: 1, hasMore: false } }
      throw new Error(`Unexpected synthetic batch GET ${path}`)
    }
  }

  return {
    request,
    calls,
    get preparedInput() { return preparedInput },
    get preview() { return preview },
    count(method, path) {
      return calls.filter(call => call.method === method && call.path === path).length
    },
    prepareCount() { return this.count('POST', BATCH_PATH) },
    commitCount() { return this.count('POST', `${operationPath}/commit`) },
    cancelCount() { return this.count('POST', `${operationPath}/cancel`) },
    statusCount() { return this.count('GET', operationPath) },
    restoreCount() { return this.count('GET', `${operationPath}/preview`) },
    resolveCommit() {
      ok(commitDeferred && pendingCommitReceipt, 'No context-owned deferred batch commit is pending.')
      const receipt = pendingCommitReceipt
      pendingCommitReceipt = null
      commitDeferred.resolve(receipt)
    }
  }
}

async function loadBatchModules(ctx) {
  const [batchModule, capabilityModule] = await Promise.all([
    ctx.guard(import('../../src/batch-mutation-host.js'), 'real batch transport import'),
    ctx.guard(import('../../adapters/ns-ultimate/admin/mutation-capabilities.mjs'), 'real batch capability import')
  ])
  ctx.check()
  return {
    createBatchMutationHost: batchModule.createBatchMutationHost,
    mutationCapabilities: capabilityModule.default
  }
}

function installConfirm(ctx, accepted = true) {
  let calls = 0
  subscribeCaseProperty(ctx, window, 'confirm', () => {
    calls += 1
    return accepted
  })
  return { get calls() { return calls } }
}

function buildSingleRecordPreview({ name = 'Single interleaving preview value' } = {}) {
  return {
    operationId: 'e'.repeat(64),
    entity: 'SyntheticRecords',
    action: 'update',
    identifier: '1',
    title: 'Synthetic interleaving record',
    affectedRecords: 1,
    expiresAt: batchTimestamp(BATCH_EXPIRES_OFFSET_MS),
    differences: [{ property: 'name', label: 'Name', before: 'old synthetic value', after: name }],
    saved: false
  }
}

function createInterleavedHost(batchHost, preview) {
  const calls = { prepare: 0, list: 0, restore: 0, commit: 0, status: 0, cancel: 0 }
  const host = createSyntheticHost({
    writeCapabilities: [{
      entity: 'SyntheticRecords',
      actions: ['update'],
      actionFields: { update: ['name'] },
      fields: ['name']
    }],
    prepareChange(input) {
      calls.prepare += 1
      return preview
    },
    batchHost
  })
  host.listRecordChanges = async() => {
    calls.list += 1
    return {
      items: [{
        operationId: preview.operationId,
        entity: preview.entity,
        action: preview.action,
        status: 'pending',
        identifier: preview.identifier,
        createdAt: batchTimestamp(-1000),
        expiresAt: preview.expiresAt
      }],
      page: 1,
      hasMore: false
    }
  }
  host.restoreRecordChange = async operationId => {
    calls.restore += 1
    equal(operationId, preview.operationId, 'The restored operation identity should match the single proposal')
    return preview
  }
  host.commitRecordChange = async operationId => {
    calls.commit += 1
    return { operationId, status: 'succeeded' }
  }
  host.getRecordChangeStatus = async operationId => {
    calls.status += 1
    return { operationId, status: 'pending' }
  }
  host.cancelRecordChange = async() => { calls.cancel += 1 }
  return { host, calls }
}

async function prepareSingleRecordPreview(ctx, agent, provider, preview) {
  provider.enqueueToolCall('prepare_record_change', {
    entity: preview.entity,
    action: preview.action,
    identifier: preview.identifier,
    changes: { name: preview.differences[0].after }
  })
  provider.enqueueText('Synthetic single-record preview answer.')
  sendText(agent, 'Prepare a single synthetic record change for interleaving.')
  await provider.waitForRequests(2, ctx)
  await waitForIdle(agent)
  await agent.ctx.waitFor(() => Boolean(findButton(agent.shadow, /^Confirm and save$/)), {
    root: agent.shadow,
    label: 'single prepared change card'
  })
  agent.assertActive()
}

async function preloadOperationHistory(ctx, agent) {
  const button = findButton(agent.shadow, /^Operations$/)
  ok(button && !button.disabled, 'The Operations control was not available for the preloaded history.')
  button.click()
  ctx.check()
  const history = agent.shadow.querySelector('section.operation-history')
  ok(history, 'The operation history section was not rendered.')
  await agent.ctx.waitFor(() => Boolean(findButton(history, /^Restore preview$/)), {
    root: agent.shadow,
    label: 'preloaded pending operation'
  })
  button.click()
  ctx.check()
  ok(history.classList.contains('hidden'), 'The preloaded operation history should close before interleaving.')
  ok(!agent.shadow.querySelector('.messages').classList.contains('hidden'), 'Messages should be visible after closing history.')
}

function openOperationHistory(ctx, agent) {
  const button = findButton(agent.shadow, /^Operations$/)
  ok(button && !button.disabled, 'The Operations control was not available during batch interleaving.')
  button.click()
  ctx.check()
  return agent.shadow.querySelector('section.operation-history')
}

async function prepareBatchFromCsv(ctx, agent, {
  entity = 'Tag',
  fileName,
  content,
  columns,
  mapping,
  rowCount,
  storeUuid = ''
} = {}) {
  const { workbench } = openImportWorkbench(ctx, agent)
  setImportEntity(ctx, workbench, entity)
  if (entity === 'Counter') {
    const storeInput = workbench.querySelector('input[placeholder="Store UUID"]')
    ok(storeInput, 'The explicit Counter Store UUID control was not rendered.')
    storeInput.value = storeUuid
    storeInput.dispatchEvent(new Event('change', { bubbles: true }))
    ctx.check()
  }
  chooseImportFile(ctx, workbench, makeFile(fileName, content, 'text/csv'))
  await waitForImportTable(agent, rowCount, columns.length)
  mapping.forEach((field, index) => setImportMapping(ctx, workbench, field, index))
  const prepare = findButton(workbench, /^Prepare preview$/)
  ok(prepare && !prepare.disabled, 'The interleaving batch preview should be preparable.')
  prepare.click()
  await agent.ctx.waitFor(() => Boolean(importWorkbenchNode(agent.shadow)?.querySelector('.import-preview table')), {
    root: agent.shadow,
    label: 'interleaving batch preview'
  })
  return workbench
}

function confirmAndCommitBatch(ctx, workbench, rowCount) {
  const confirm = workbench.querySelector('.import-preview label.checkbox input[type="checkbox"]')
  ok(confirm, 'The batch review confirmation control was not rendered.')
  confirm.click()
  const commit = findButton(workbench, new RegExp(`^Create ${rowCount} records$`))
  ok(commit && !commit.disabled, 'The batch create control should be enabled after confirmation.')
  commit.click()
  ctx.check()
}

function closeImportWorkbench(ctx, agent) {
  const workbench = importWorkbenchNode(agent.shadow)
  ok(workbench, 'The import workbench was not rendered before Back.')
  const back = findButton(workbench, /^Back$/)
  ok(back, 'The import workbench Back control was not rendered.')
  back.click()
  ctx.check()
  ok(workbench.classList.contains('hidden'), 'The import workbench should be hidden after Back.')
  ok(!agent.shadow.querySelector('.messages').classList.contains('hidden'), 'Back should reveal the existing messages.')
}

async function makePngFile(ctx, name) {
  ctx.check()
  const canvas = document.createElement('canvas')
  canvas.width = 5
  canvas.height = 4
  const context = canvas.getContext('2d')
  ok(context, 'A real 2D canvas context is required for the PNG fixture.')
  context.fillStyle = '#2f6fed'
  context.fillRect(0, 0, canvas.width, canvas.height)
  context.fillStyle = '#f4c542'
  context.fillRect(1, 1, 2, 2)
  const blob = await ctx.wait(new Promise((resolve, reject) => {
    canvas.toBlob(value => value ? resolve(value) : reject(new Error('canvas.toBlob returned no PNG.')), 'image/png')
  }), 'canvas PNG encoding')
  return new File([await ctx.wait(blob.arrayBuffer(), 'canvas PNG bytes')], name, {
    type: 'image/png',
    lastModified: 1700000000000
  })
}

function sendText(agent, text) {
  agent.assertActive()
  agent.controls.input.value = text
  agent.controls.composer.requestSubmit()
  agent.assertActive()
}

function clickControl(ctx, agent, name) {
  ctx.check()
  const control = agent.controls[name]
  ok(control, `Missing agent control ${name}.`)
  control.click()
  ctx.check()
}

function clickWithin(ctx, root, selector) {
  ctx.check()
  const target = root.querySelector(selector)
  ok(target, `Missing element ${selector}.`)
  target.click()
  ctx.check()
}

async function waitForIdle(agent) {
  await waitFor(() => !agent.controls.input.disabled && agent.controls.cancelButton.classList.contains('hidden'), {
    root: agent.shadow,
    signal: agent.ctx.signal,
    label: 'assistant idle'
  })
  agent.assertActive()
}

async function waitForAttachment(agent, name) {
  await waitFor(() => [...agent.shadow.querySelectorAll('.attachment-name')].some(node => node.textContent === name), {
    root: agent.shadow,
    signal: agent.ctx.signal,
    label: `attachment ${name}`
  })
  agent.assertActive()
}

async function enableVision(ctx, agent) {
  ctx.check()
  if (agent.controls.settings.classList.contains('hidden')) {
    ctx.check()
    agent.controls.settingsButton.click()
  }
  const checkbox = agent.controls.settings.querySelector('input[name="visionEnabled"]')
  ok(checkbox, 'The vision preference control was not rendered.')
  ctx.check()
  checkbox.checked = true
  agent.controls.settings.requestSubmit()
  await waitFor(() => agent.controls.settings.classList.contains('hidden'), {
    root: agent.shadow,
    signal: ctx.signal,
    label: 'vision settings save'
  })
  ctx.check()
}

function lastUserMessage(body) {
  const messages = Array.isArray(body?.messages) ? body.messages : []
  for (let index = messages.length - 1; index >= 0; index -= 1) {
    if (messages[index]?.role === 'user') return messages[index]
  }
  return null
}

function subscribeCaseProperty(ctx, owner, name, value) {
  const restore = replaceProperty(owner, name, value)
  if (!restore) throw new Error(`Could not safely replace ${name} for this browser acceptance case.`)
  ctx.addRestore(restore)
}

function installNonAbortingAbortController(ctx) {
  const Original = window.AbortController
  ok(typeof Original === 'function', 'AbortController is required for the deferred FileReader case.')
  class NonAbortingAbortController {
    constructor() {
      this.inner = new Original()
      this.abortCalls = 0
    }
    get signal() { return this.inner.signal }
    abort() {
      // Suppress the app's attachment signal so the deferred read can complete
      // after clear. The application generation guard is the behavior under test.
      this.abortCalls += 1
    }
  }
  subscribeCaseProperty(ctx, window, 'AbortController', NonAbortingAbortController)
}

function installControlledFileReader(ctx) {
  const reads = []
  let firstPending = null
  let firstResolved = false
  const waiters = []
  const rejectWaiters = reason => {
    for (const waiter of [...waiters]) {
      waiters.splice(waiters.indexOf(waiter), 1)
      waiter.reject(reason)
    }
  }
  ctx.addRestore(() => rejectWaiters(abortError('FileReader case cleanup')))
  const notify = () => {
    const ready = waiters.filter(waiter => reads.length >= waiter.count)
    for (const waiter of ready) {
      waiters.splice(waiters.indexOf(waiter), 1)
      waiter.resolve(reads)
    }
  }
  class ControlledFileReader {
    constructor() {
      this.result = null
      this.onload = null
      this.onerror = null
      this.onabort = null
      this.aborted = false
    }
    readAsArrayBuffer(blob) {
      const record = { name: blob?.name || 'blob', blob, reader: this }
      reads.push(record)
      notify()
      if (!firstResolved && !firstPending) {
        firstPending = record
        return
      }
      blob.arrayBuffer().then(buffer => {
        if (this.aborted || ctx.aborted) return
        contextCheckAfterReader(ctx)
        this.result = buffer
        this.onload?.()
      }, error => {
        if (this.aborted || ctx.aborted) return
        this.onerror?.(error)
      })
    }
    abort() {
      // Keep the first deferred read unresolved; the test resolves it explicitly.
      this.aborted = true
    }
  }
  subscribeCaseProperty(ctx, window, 'FileReader', ControlledFileReader)
  return {
    reads,
    waitForReadCount(count) {
      ctx.check()
      if (reads.length >= count) return Promise.resolve(reads)
      const inner = new Promise((resolve, reject) => waiters.push({ count, resolve, reject }))
      return ctx.wait(inner, `FileReader reads ${count}`)
    },
    hasPendingFirst() { return Boolean(firstPending) },
    async resolveFirst() {
      ctx.check()
      const record = firstPending
      ok(record, 'No deferred first FileReader read is pending.')
      firstPending = null
      firstResolved = true
      const buffer = await ctx.wait(record.blob.arrayBuffer(), 'deferred FileReader bytes')
      ctx.check()
      record.reader.result = buffer
      record.reader.onload?.()
    }
  }
}

function contextCheckAfterReader(ctx) {
  try { ctx.check() } catch { /* A stale read must not continue after run ownership changed. */ }
}

const CASES = [
  {
    name: 'chooser selection renders locally without a provider request',
    async run(ctx) {
      const provider = ctx.createProvider()
      const agent = await ctx.mount({ provider })
      const png = await makePngFile(ctx, 'chooser-preview-sentinel.png')
      selectFiles(agent, [png])
      await waitForAttachment(agent, png.name)
      equal(provider.requests.length, 0, 'Choosing a file must not contact the provider')
      equal(state.docsRequests > 0, true, 'The synthetic API catalog should have loaded')
      ctx.check()
      const item = agent.shadow.querySelector('.attachment')
      ok(item, 'The attachment item was not rendered.')
      ok(item.querySelector('img.thumb'), 'The local raster preview thumbnail was not rendered.')
      ok(!agent.shadow.querySelector('.message-image'), 'Selection must not render a sent message image.')
      includes(agent.shadow.textContent, png.name, 'The selected filename should be visible locally')
      return { providerRequests: provider.requests.length, previewThumbnail: true }
    }
  },
  {
    name: 'pasted images attach locally while plain-text paste stays untouched',
    async run(ctx) {
      const provider = ctx.createProvider()
      const agent = await ctx.mount({ provider })
      const png = await makePngFile(ctx, 'pasted-image-sentinel.png')
      const prevented = pasteImages(agent, [png])
      equal(prevented, true, 'A pasted image should be handled by the attachment pipeline')
      await waitForAttachment(agent, png.name)
      equal(provider.requests.length, 0, 'Pasting an image must not contact the provider')
      const item = agent.shadow.querySelector('.attachment')
      ok(item, 'The pasted attachment item was not rendered.')
      ok(item.querySelector('img.thumb'), 'The pasted image should render a local thumbnail.')
      includes(agent.shadow.textContent, png.name, 'The pasted filename should be visible locally')

      const textTransfer = new DataTransfer()
      textTransfer.setData('text/plain', 'plain paste stays in the textarea')
      const textEvent = new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: textTransfer })
      agent.controls.input.dispatchEvent(textEvent)
      equal(textEvent.defaultPrevented, false, 'Plain-text paste must not be intercepted')
      equal(agent.shadow.querySelectorAll('.attachment').length, 1, 'Plain-text paste must not add another attachment')
      return { providerRequests: provider.requests.length, pastedThumbnail: true, textPasteUntouched: true }
    }
  },
  {
    name: 'text attachment send uses current content but later history keeps filenames only',
    async run(ctx) {
      const sentinel = 'ATTACHMENT_TEXT_SENTINEL_5f31c9'
      const filename = 'history-only-sentinel.txt'
      const provider = ctx.createProvider()
      const agent = await ctx.mount({ provider })
      selectFiles(agent, [makeFile(filename, `Synthetic content ${sentinel}`)])
      await waitForAttachment(agent, filename)
      provider.enqueueText('First synthetic answer.')
      sendText(agent, 'Summarize the attached note.')
      await provider.waitForRequests(1, ctx)
      await waitForIdle(agent)
      includes(JSON.stringify(provider.requests[0].body), sentinel, 'The current request must include the selected text content')
      includes(JSON.stringify(provider.requests[0].body), filename, 'The current request must identify the selected filename')
      excludes(readPreferencesString(), sentinel, 'Raw attachment content must not be persisted')
      excludes(readPreferencesString(), filename, 'Attachment filenames must not be persisted')

      provider.enqueueText('Second synthetic answer.')
      sendText(agent, 'Follow up without resending the file.')
      await provider.waitForRequests(2, ctx)
      await waitForIdle(agent)
      const later = JSON.stringify(provider.requests[1].body)
      excludes(later, sentinel, 'Later provider history must not include raw attachment content')
      includes(later, filename, 'Later provider history should retain a bounded filename summary')
      excludes(readPreferencesString(), sentinel, 'Raw attachment content must remain absent from preferences')
      return { currentRequestHadContent: true, laterRequestHadFilenameOnly: true }
    }
  },
  {
    name: 'vision off rejects local image send and explicit enablement sends one image part',
    async run(ctx) {
      const provider = ctx.createProvider()
      const agent = await ctx.mount({ provider })
      const png = await makePngFile(ctx, 'vision-sentinel.png')
      selectFiles(agent, [png])
      await waitForAttachment(agent, png.name)
      sendText(agent, 'Describe the synthetic image.')
      await agent.ctx.waitFor(() => Boolean(agent.shadow.querySelector('.attachment-warning')), {
        root: agent.shadow,
        label: 'vision warning'
      })
      equal(provider.requests.length, 0, 'Vision-off send must be rejected before provider dispatch')
      includes(agent.shadow.querySelector('.status').textContent.toLowerCase(), 'vision', 'The rejection should explain the vision requirement')

      await enableVision(ctx, agent)
      provider.enqueueText('Synthetic vision answer.')
      sendText(agent, 'Describe the synthetic image.')
      await provider.waitForRequests(1, ctx)
      await waitForIdle(agent)
      const user = lastUserMessage(provider.requests[0].body)
      ok(Array.isArray(user?.content), 'The provider user content should be multimodal after vision enablement.')
      const imageParts = user.content.filter(part => part?.type === 'image_url')
      equal(imageParts.length, 1, 'Exactly one image part may be sent for one selected image')
      includes(imageParts[0].image_url.url, 'data:image/png;base64,', 'Only a validated local PNG data URL may be sent')
      excludes(readPreferencesString(), 'data:image', 'Image data must not be stored in preferences')
      return { visionOffRequests: 0, visionOnImageParts: imageParts.length }
    }
  },
  {
    name: 'attachment remove and conversation clear remove rendered previews',
    async run(ctx) {
      const provider = ctx.createProvider()
      const agent = await ctx.mount({ provider })
      const png = await makePngFile(ctx, 'remove-clear-sentinel.png')
      selectFiles(agent, [png])
      await waitForAttachment(agent, png.name)
      clickWithin(ctx, agent.shadow, '.attachment .icon')
      await agent.ctx.waitFor(() => !agent.shadow.querySelector('.attachment'), { root: agent.shadow, label: 'attachment removal' })

      selectFiles(agent, [png])
      await waitForAttachment(agent, png.name)
      await enableVision(ctx, agent)
      provider.enqueueText('Synthetic image answer.')
      sendText(agent, 'Render this local image.')
      await provider.waitForRequests(1, ctx)
      await waitForIdle(agent)
      ok(agent.shadow.querySelector('.messages .message-image'), 'The sent local image should be rendered.')
      clickControl(ctx, agent, 'clearButton')
      await agent.ctx.waitFor(() => !agent.shadow.querySelector('.messages .message-image') && !agent.shadow.querySelector('.attachment'), {
        root: agent.shadow,
        label: 'conversation clear'
      })
      excludes(agent.shadow.textContent, png.name, 'Clearing the conversation must remove the filename')
      equal(agent.shadow.querySelectorAll('.message.user').length, 0, 'Clearing should remove user messages')
      return { removedBeforeSend: true, clearedAfterSend: true }
    }
  },
  {
    name: 'deferred knowledge cancellation keeps the replacement task busy until its own completion',
    async run(ctx) {
      const oldKnowledge = ctx.deferred('old knowledge')
      const knowledgeStarted = ctx.deferred('old knowledge start')
      let knowledgeCalls = 0
      const host = createSyntheticHost({
        knowledgeFactory() {
          knowledgeCalls += 1
          if (knowledgeCalls === 1) {
            knowledgeStarted.resolve()
            return oldKnowledge.promise
          }
          return []
        }
      })
      const provider = ctx.createProvider()
      const agent = await ctx.mount({ host, provider })
      sendText(agent, 'Old knowledge acceptance question.')
      await ctx.wait(knowledgeStarted.promise, 'first knowledge load')
      await agent.ctx.waitFor(() => agent.controls.input.disabled, { root: agent.shadow, label: 'first task busy' })
      equal(provider.requests.length, 0, 'The pre-provider knowledge task must not call the provider')
      clickControl(ctx, agent, 'cancelButton')
      await waitForIdle(agent)
      equal(provider.requests.length, 0, 'The cancelled pre-provider task must not call the provider')

      const replacement = provider.enqueueDeferredStream()
      sendText(agent, 'Fresh replacement task question.')
      await provider.waitForRequests(1, ctx)
      equal(provider.requests.length, 1, 'Only the replacement task should reach the provider')

      oldKnowledge.resolve([{
        id: 'old-knowledge-sentinel',
        title: 'Knowledge acceptance sentinel',
        module: 'Synthetic',
        kind: 'guide',
        locale: 'en',
        aliases: ['knowledge'],
        sourcePath: 'apps/admin-agent/README.md',
        content: 'OLD_KNOWLEDGE_CONTENT_SENTINEL must never unlock the replacement task.'
      }])
      await ctx.wait(oldKnowledge.promise, 'old knowledge resolution')
      await flushMicrotasks(ctx, 12)
      ok(agent.controls.input.disabled, 'Resolving stale knowledge must not unlock the new busy task')
      equal(provider.requests.length, 1, 'Resolving stale knowledge must not create another provider request')
      excludes(JSON.stringify(provider.requests[0].body), 'OLD_KNOWLEDGE_CONTENT_SENTINEL', 'Stale knowledge must not enter the replacement request')

      replacement.text('Replacement synthetic completion.')
      replacement.close()
      await waitForIdle(agent)
      excludes(agent.shadow.textContent, 'OLD_KNOWLEDGE_CONTENT_SENTINEL', 'Stale knowledge must not render')
      includes(agent.shadow.textContent, 'Replacement synthetic completion.', 'The replacement completion should render')
      return { cancelledBeforeProvider: true, busyUntilReplacementCompletion: true }
    }
  },
  {
    name: 'duplicate send creates at most one provider request',
    async run(ctx) {
      const provider = ctx.createProvider()
      const stream = provider.enqueueDeferredStream()
      const agent = await ctx.mount({ provider })
      sendText(agent, 'Duplicate submit sentinel.')
      sendText(agent, 'Duplicate submit sentinel.')
      await provider.waitForRequests(1, ctx)
      await flushMicrotasks(ctx)
      equal(provider.requests.length, 1, 'A second submit while busy must not dispatch another request')
      stream.text('Single synthetic completion.')
      stream.close()
      await waitForIdle(agent)
      equal(provider.requests.length, 1, 'No additional provider request may appear after completion')
      equal(agent.shadow.querySelectorAll('.message.user').length, 1, 'Only one user message should be rendered')
      return { providerRequests: provider.requests.length }
    }
  },
  {
    name: 'deferred first FileReader completion after clear never reads the old second file',
    async run(ctx) {
      const provider = ctx.createProvider()
      const agent = await ctx.mount({ provider })
      installNonAbortingAbortController(ctx)
      const reader = installControlledFileReader(ctx)
      const first = makeFile('deferred-old-first.txt', 'OLD_FIRST_CONTENT_SENTINEL')
      const second = makeFile('deferred-old-second.txt', 'OLD_SECOND_CONTENT_SENTINEL')
      selectFiles(agent, [first, second])
      await reader.waitForReadCount(1)
      ok(reader.hasPendingFirst(), 'The first read should be deferred before clear.')

      clickControl(ctx, agent, 'clearButton')
      await agent.ctx.waitFor(() => !agent.controls.send.disabled, { root: agent.shadow, label: 'attachment clear' })
      const draft = makeFile('deferred-new-draft.txt', 'NEW_DRAFT_CONTENT')
      selectFiles(agent, [draft])
      await waitForAttachment(agent, draft.name)
      equal(reader.reads.length, 2, 'Only the old first file and the new draft should have been read')
      equal(reader.reads.map(record => record.name).join(','), `${first.name},${draft.name}`, 'The old second file must never be read')

      await reader.resolveFirst()
      await flushMicrotasks(ctx, 12)
      equal(reader.reads.length, 2, 'The late old completion must not start the old second read')
      excludes(agent.shadow.textContent, first.name, 'The stale first filename must not render')
      excludes(agent.shadow.textContent, second.name, 'The stale second filename must not render')
      includes(agent.shadow.textContent, draft.name, 'The new draft must remain safe and selected')
      return { readCount: reader.reads.length, newDraftSurvived: true }
    }
  },
  {
    name: 'disposal and remount never expose a late provider completion or old filename',
    async run(ctx) {
      const lateSentinel = 'OLD_LATE_PROVIDER_SENTINEL_91c2'
      const oldFilename = 'old-disposed-attachment.txt'
      const providerA = ctx.createProvider()
      const late = providerA.enqueueDeferredFetch()
      const agentA = await ctx.mount({ provider: providerA })
      selectFiles(agentA, [makeFile(oldFilename, 'OLD_ATTACHMENT_CONTENT_SENTINEL')])
      await waitForAttachment(agentA, oldFilename)
      sendText(agentA, 'Disposal lifetime question.')
      await providerA.waitForRequests(1, ctx)
      const detachedShadow = agentA.shadow
      agentA.dispose()
      equal(document.querySelectorAll('ns-admin-agent').length, 0, 'Disposal should remove the mounted agent')

      const providerB = ctx.createProvider()
      const agentB = await ctx.mount({ provider: providerB })
      late.resolve(`Late synthetic completion ${lateSentinel}`)
      await flushMicrotasks(ctx, 16)
      excludes(detachedShadow.textContent, lateSentinel, 'The disposed agent must not render the late completion')
      excludes(agentB.shadow.textContent, lateSentinel, 'A remounted agent must not render the old completion')
      excludes(agentB.shadow.textContent, oldFilename, 'A remounted agent must not expose the old attachment filename')

      providerB.enqueueText('Remounted synthetic answer.')
      sendText(agentB, 'Remounted session question.')
      await providerB.waitForRequests(1, ctx)
      await waitForIdle(agentB)
      const remountedRequest = JSON.stringify(providerB.requests[0].body)
      excludes(remountedRequest, lateSentinel, 'The remounted provider history must not include the old completion')
      excludes(remountedRequest, oldFilename, 'The remounted provider history must not include the old filename')
      return { disposedAgentConnected: false, remountedRequests: providerB.requests.length }
    }
  },
  {
    name: 'disposal and remount suppress a late prepared-change field value',
    async run(ctx) {
      const fieldSentinel = 'OLD_PREPARED_FIELD_SENTINEL_e37b'
      const prepare = ctx.deferred('old prepared change')
      const prepareStarted = ctx.deferred('old prepared change start')
      const hostA = createSyntheticHost({
        writeCapabilities: [{
          entity: 'SyntheticRecords',
          actions: ['update'],
          actionFields: { update: ['name'] },
          fields: ['name']
        }],
        prepareChange(input) {
          prepareStarted.resolve(input)
          return prepare.promise
        }
      })
      const providerA = ctx.createProvider()
      providerA.enqueueToolCall('prepare_record_change', {
        entity: 'SyntheticRecords',
        action: 'update',
        identifier: '1',
        changes: { name: fieldSentinel }
      })
      const agentA = await ctx.mount({ host: hostA, provider: providerA })
      sendText(agentA, 'Prepare a synthetic change that will outlive this session.')
      await providerA.waitForRequests(1, ctx)
      await ctx.wait(prepareStarted.promise, 'old prepared change start')
      const detachedShadow = agentA.shadow
      agentA.dispose()

      const providerB = ctx.createProvider()
      const agentB = await ctx.mount({ provider: providerB })
      prepare.resolve({
        saved: false,
        entity: 'SyntheticRecords',
        action: 'update',
        identifier: '1',
        title: 'Synthetic acceptance record',
        expiresAt: new Date(Date.now() + 300000).toISOString(),
        differences: [{ property: 'name', label: 'Name', before: 'old synthetic value', after: fieldSentinel }]
      })
      await ctx.wait(prepare.promise, 'old prepared change resolution')
      await flushMicrotasks(ctx, 16)
      excludes(detachedShadow.textContent, fieldSentinel, 'The disposed agent must not render a late prepared field value')
      excludes(agentB.shadow.textContent, fieldSentinel, 'A remounted agent must not render the old prepared field value')
      equal(detachedShadow.querySelectorAll('.messages table').length, 0, 'A late prepared change must not create a confirmation table in the disposed agent')

      providerB.enqueueText('Remounted field-free answer.')
      sendText(agentB, 'Remounted field question.')
      await providerB.waitForRequests(1, ctx)
      await waitForIdle(agentB)
      excludes(JSON.stringify(providerB.requests[0].body), fieldSentinel, 'A late prepared field value must not enter the remounted model history')
      return { latePreparedFieldSuppressed: true, disposedConfirmationControls: 0 }
    }
  },
  {
    name: 'busy composer file drop prevents the browser default without attaching',
    async run(ctx) {
      const provider = ctx.createProvider()
      const stream = provider.enqueueDeferredStream()
      const agent = await ctx.mount({ provider })
      sendText(agent, 'Busy drop sentinel question.')
      await provider.waitForRequests(1, ctx)
      ok(agent.controls.input.disabled, 'The assistant should be busy before the drop.')

      ctx.check()
      const transfer = new DataTransfer()
      transfer.items.add(makeFile('blocked-drop.txt', 'DROP_CONTENT_SENTINEL'))
      const dragover = new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: transfer })
      agent.controls.composer.dispatchEvent(dragover)
      equal(dragover.defaultPrevented, true, 'Busy dragover should prevent the browser default')
      ctx.check()
      const drop = new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer })
      agent.controls.composer.dispatchEvent(drop)
      equal(drop.defaultPrevented, true, 'Busy file drop should prevent navigation or default handling')
      equal(agent.shadow.querySelectorAll('.attachment').length, 0, 'A busy drop must not attach the file')
      await flushMicrotasks(ctx)
      equal(provider.requests.length, 1, 'A busy drop must not dispatch another provider request')
      stream.text('Busy drop synthetic completion.')
      stream.close()
      await waitForIdle(agent)
      return { dragoverPrevented: true, dropPrevented: true }
    }
  },
  {
    name: 'late query_records completion cannot render in a remounted session',
    async run(ctx) {
      const querySentinel = 'OLD_QUERY_ROW_SENTINEL_4a17'
      const query = ctx.deferred('old read query')
      const queryStarted = ctx.deferred('synthetic query start')
      const hostA = createSyntheticHost({
        query() {
          queryStarted.resolve()
          return query.promise
        }
      })
      const providerA = ctx.createProvider()
      providerA.enqueueToolCall('query_records', { entity: 'syntheticRecords' })
      const agentA = await ctx.mount({ host: hostA, provider: providerA })
      sendText(agentA, 'Run the synthetic read query.')
      await ctx.wait(queryStarted.promise, 'synthetic query start')
      agentA.dispose()

      const providerB = ctx.createProvider()
      const agentB = await ctx.mount({ provider: providerB })
      query.resolve({
        entity: 'syntheticRecords',
        records: [{ id: 1, name: querySentinel }],
        page: 1,
        count: 1,
        total: 1,
        hasMore: false
      })
      await ctx.wait(query.promise, 'old read query resolution')
      await flushMicrotasks(ctx, 16)
      excludes(agentB.shadow.textContent, querySentinel, 'A late read result must not render in the remounted session')

      providerB.enqueueText('Remounted read-free answer.')
      sendText(agentB, 'Unrelated remounted question.')
      await providerB.waitForRequests(1, ctx)
      await waitForIdle(agentB)
      excludes(JSON.stringify(providerB.requests[0].body), querySentinel, 'A late read result must not enter the remounted model history')
      return { queryRowsSuppressed: true, remountedRequests: providerB.requests.length }
    }
  },
  {
    name: 'real integration session monitor remounts on synthetic actor token change',
    async run(ctx) {
      const auth = await ctx.guard(
        import('../../../../core/crud-admin/src/utils/auth.js'),
        'real admin token adapter import'
      )
      const { monitorAdminSession } = await ctx.guard(
        import('../../../../integration/admin/agent-session.js'),
        'real session monitor import'
      )
      ctx.check()
      auth.setToken(SYNTHETIC_ACTOR_A)
      equal(auth.getToken(), SYNTHETIC_ACTOR_A, 'The memory cookie jar must hold only the synthetic actor A token')

      const providerA = ctx.createProvider()
      const providerB = ctx.createProvider()
      const actorA = ctx.deferred('actor A login')
      const actorB = ctx.deferred('actor B login')
      let stopped = false
      const stop = monitorAdminSession(async () => {
        ctx.check()
        const token = auth.getToken()
        const isActorA = token === SYNTHETIC_ACTOR_A
        const host = createSyntheticHost({
          key: isActorA ? 'actorARecords' : 'actorBRecords',
          name: isActorA ? 'ActorARecords' : 'ActorBRecords'
        })
        const agent = await ctx.mount({ host, provider: isActorA ? providerA : providerB })
        if (isActorA) actorA.resolve(agent)
        else actorB.resolve(agent)
        return agent.dispose
      }, () => {})
      const stopOnce = () => {
        if (stopped) return
        stopped = true
        stop()
      }
      ctx.addDispose(stopOnce)

      const agentA = await ctx.wait(actorA.promise, 'real monitor actor A login', MONITOR_LOGIN_TIMEOUT_MS)
      auth.setToken(SYNTHETIC_ACTOR_B)
      ctx.check()
      const agentB = await ctx.wait(actorB.promise, 'real monitor actor B login after token change', MONITOR_LOGIN_TIMEOUT_MS)
      equal(auth.getToken(), SYNTHETIC_ACTOR_B, 'The memory cookie jar must hold only the synthetic actor B token')
      equal(agentA.shadow.host.isConnected, false, 'The real monitor should dispose actor A on the token change')
      equal(document.querySelectorAll('ns-admin-agent').length, 1, 'Exactly one agent should remain after the monitored session change')

      providerB.enqueueText('Monitored actor B synthetic answer.')
      sendText(agentB, 'Actor B session monitor question.')
      await providerB.waitForRequests(1, ctx)
      await waitForIdle(agentB)
      const requestBody = JSON.stringify(providerB.requests[0].body)
      includes(requestBody, 'actorBRecords', 'The remounted monitor session should use actor B host capabilities')
      excludes(requestBody, 'actorARecords', 'The remounted monitor session must not use actor A host capabilities')
      stopOnce()
      return { syntheticMemoryCookies: true, remountedOnTokenChange: true }
    }
  },
  {
    name: 'real local CSV import prepares edited canonical rows and commits once from an explicit click',
    async run(ctx) {
      const provider = ctx.createProvider()
      const { createBatchMutationHost, mutationCapabilities } = await loadBatchModules(ctx)
      const backend = createSyntheticBatchBackend()
      const batchHost = createBatchMutationHost(backend.request, BATCH_PATH, mutationCapabilities)
      const agent = await ctx.mount({ host: createSyntheticHost({ batchHost }), provider })
      const fetchCounter = installFetchCounter(ctx)
      const { workbench } = openImportWorkbench(ctx, agent)
      setImportEntity(ctx, workbench, 'Tag')
      chooseImportFile(ctx, workbench, makeFile(
        'acceptance-tags.csv',
        'Name,Slug,Color\nNorth,north,#112233\nSouth,south,\n',
        'text/csv'
      ))
      await waitForImportTable(agent, 2, 3)
      equal(fetchCounter.calls, 0, 'Selecting and parsing a local CSV must not use fetch')
      equal(provider.requests.length, 0, 'Selecting and parsing a local CSV must not contact the provider')
      equal(state.docsRequests > 0, true, 'The synthetic API catalog should already be loaded')
      const docsAtSelection = state.docsRequests

      setImportMapping(ctx, workbench, 'Name', 0)
      setImportMapping(ctx, workbench, 'Slug', 1)
      setImportMapping(ctx, workbench, 'Color', 2)
      editImportCell(ctx, workbench, 0, 0, 3, 'North edited')
      editImportCell(ctx, workbench, 1, 2, 3, '#445566')
      equal(fetchCounter.calls, 0, 'Mapping and editing must stay local')
      equal(state.docsRequests, docsAtSelection, 'Local import work must not reload API documentation')
      equal(provider.requests.length, 0, 'Mapping and editing must not contact the provider')

      const prepareButton = findButton(workbench, /^Prepare preview$/)
      ok(prepareButton, 'The Prepare preview control was not rendered.')
      ok(!prepareButton.disabled, 'The Prepare preview control should be enabled for a complete mapping.')
      prepareButton.click()
      await agent.ctx.waitFor(() => Boolean(importWorkbenchNode(agent.shadow)?.querySelector('.import-preview table')), {
        root: agent.shadow,
        label: 'canonical batch preview'
      })
      agent.assertActive()

      equal(backend.prepareCount(), 1, 'Exactly one batch prepare request should be sent')
      const preparedBody = backend.calls.find(call => call.method === 'POST' && call.path === BATCH_PATH)?.body
      deepEqual(preparedBody, {
        entity: 'Tag',
        action: 'create',
        rows: [
          { name: 'North edited', slug: 'north', color: '#112233' },
          { name: 'South', slug: 'south', color: '#445566' }
        ]
      }, 'The prepared input must preserve the mapped and edited canonical rows')
      equal(preparedBody.rows.length, 2, 'The prepared batch must contain exactly two rows')
      equal(backend.preview?.affectedRecords, 2, 'The canonical preview must report the same row count')
      includes(workbench.textContent, 'Batch preview — not saved', 'The complete preview should be shown before any save')
      equal(workbench.querySelectorAll('.import-preview table tr').length, 3, 'The preview table should render a header and two canonical rows')
      includes(workbench.querySelector('.import-preview table').textContent, 'North edited', 'The preview should show the edited canonical value')
      equal(backend.commitCount(), 0, 'Preparing must not commit the batch')
      equal(backend.statusCount(), 0, 'Preparing must not query a commit outcome')

      const confirm = workbench.querySelector('.import-preview label.checkbox input[type="checkbox"]')
      ok(confirm, 'The explicit review confirmation control was not rendered.')
      equal(confirm.checked, false, 'Confirmation must start unchecked')
      confirm.click()
      const commitButton = findButton(workbench, /^Create 2 records$/)
      ok(commitButton, 'The explicit create control was not rendered after confirmation.')
      ok(!commitButton.disabled, 'The create control should be enabled only after explicit confirmation')
      commitButton.click()
      await agent.ctx.waitFor(() => importWorkbenchNode(agent.shadow)?.textContent.includes('Created 2 records.'), {
        root: agent.shadow,
        label: 'batch success receipt'
      })
      agent.assertActive()

      equal(backend.commitCount(), 1, 'One human click must produce exactly one commit request')
      equal(backend.prepareCount(), 1, 'Committing must not prepare another batch')
      equal(backend.statusCount(), 0, 'A successful commit must not need an outcome check')
      equal(provider.requests.length, 0, 'Bulk import must never call the model provider')
      equal(fetchCounter.calls, 0, 'Bulk import must not use browser fetch with the synthetic transport')
      await flushMicrotasks(ctx, 12)
      equal(backend.commitCount(), 1, 'No automatic retry may follow a successful commit')
      excludes(readPreferencesString(), 'North edited', 'Imported row values must not be persisted in preferences')
      return {
        importedRows: preparedBody.rows.length,
        previewRows: backend.preview.affectedRecords,
        prepareCalls: backend.prepareCount(),
        commitCalls: backend.commitCount(),
        providerRequests: provider.requests.length,
        fetchCalls: fetchCounter.calls
      }
    }
  },
  {
    name: 'real local Counter CSV keeps one explicit canonical Store UUID on every prepared row',
    async run(ctx) {
      const provider = ctx.createProvider()
      const { createBatchMutationHost, mutationCapabilities } = await loadBatchModules(ctx)
      const backend = createSyntheticBatchBackend()
      const batchHost = createBatchMutationHost(backend.request, BATCH_PATH, mutationCapabilities)
      const agent = await ctx.mount({ host: createSyntheticHost({ batchHost }), provider })
      const fetchCounter = installFetchCounter(ctx)
      const { workbench } = openImportWorkbench(ctx, agent)
      setImportEntity(ctx, workbench, 'Counter')

      const storeInput = workbench.querySelector('input[placeholder="Store UUID"]')
      ok(storeInput, 'The explicit Counter Store UUID control was not rendered.')
      storeInput.value = BATCH_STORE_UUID_INPUT
      storeInput.dispatchEvent(new Event('change', { bubbles: true }))
      ctx.check()

      chooseImportFile(ctx, workbench, makeFile(
        'acceptance-counters.csv',
        'Code,Name\n A01 , Window \n A02 , Door \n',
        'text/csv'
      ))
      await waitForImportTable(agent, 2, 2)
      equal(fetchCounter.calls, 0, 'Parsing a local Counter CSV must not use fetch')
      equal(provider.requests.length, 0, 'Parsing a local Counter CSV must not contact the provider')

      setImportMapping(ctx, workbench, 'Code', 0)
      setImportMapping(ctx, workbench, 'Name', 1)
      const prepareButton = findButton(workbench, /^Prepare preview$/)
      ok(prepareButton && !prepareButton.disabled, 'A complete Counter mapping should allow preview preparation.')
      prepareButton.click()
      await agent.ctx.waitFor(() => Boolean(importWorkbenchNode(agent.shadow)?.querySelector('.import-preview table')), {
        root: agent.shadow,
        label: 'Counter batch preview'
      })
      agent.assertActive()

      equal(backend.prepareCount(), 1, 'Exactly one Counter prepare request should be sent')
      const preparedBody = backend.calls.find(call => call.method === 'POST' && call.path === BATCH_PATH)?.body
      deepEqual(preparedBody, {
        entity: 'Counter',
        action: 'create',
        rows: [
          { storeUuid: BATCH_STORE_UUID, code: 'A01', name: 'Window' },
          { storeUuid: BATCH_STORE_UUID, code: 'A02', name: 'Door' }
        ]
      }, 'Every Counter row must carry the same explicit canonical Store UUID')
      const scopes = new Set(preparedBody.rows.map(row => row.storeUuid))
      equal(scopes.size, 1, 'A Counter batch must use exactly one Store scope')
      equal([...scopes][0], BATCH_STORE_UUID, 'The explicit Store UUID must be canonicalized, never inferred')
      equal(backend.preview?.affectedRecords, 2, 'The Counter preview count must match the submitted rows')
      equal(backend.commitCount(), 0, 'Preparing a Counter batch must not commit it')
      equal(provider.requests.length, 0, 'Counter import must never call the model provider')
      equal(fetchCounter.calls, 0, 'Counter import must not use browser fetch with the synthetic transport')
      return {
        storeScopes: scopes.size,
        rows: preparedBody.rows.length,
        prepareCalls: backend.prepareCount(),
        providerRequests: provider.requests.length
      }
    }
  },
  {
    name: 'editing an imported row after preview invalidates and cancels the prepared proposal',
    async run(ctx) {
      const provider = ctx.createProvider()
      const { createBatchMutationHost, mutationCapabilities } = await loadBatchModules(ctx)
      const backend = createSyntheticBatchBackend()
      const batchHost = createBatchMutationHost(backend.request, BATCH_PATH, mutationCapabilities)
      const agent = await ctx.mount({ host: createSyntheticHost({ batchHost }), provider })
      const fetchCounter = installFetchCounter(ctx)
      const { workbench } = openImportWorkbench(ctx, agent)
      setImportEntity(ctx, workbench, 'Tag')
      chooseImportFile(ctx, workbench, makeFile(
        'acceptance-invalidation.csv',
        'Name,Slug\nAlpha,alpha\n',
        'text/csv'
      ))
      await waitForImportTable(agent, 1, 2)
      setImportMapping(ctx, workbench, 'Name', 0)
      setImportMapping(ctx, workbench, 'Slug', 1)
      const prepareButton = findButton(workbench, /^Prepare preview$/)
      ok(prepareButton && !prepareButton.disabled, 'The initial proposal should be preparable.')
      prepareButton.click()
      await agent.ctx.waitFor(() => Boolean(importWorkbenchNode(agent.shadow)?.querySelector('.import-preview')), {
        root: agent.shadow,
        label: 'initial prepared proposal'
      })
      equal(backend.prepareCount(), 1, 'The initial proposal should use one prepare request')
      equal(backend.cancelCount(), 0, 'No proposal should be cancelled before the edit')

      editImportCell(ctx, workbench, 0, 0, 2, 'Alpha changed')
      await agent.ctx.waitFor(() => !importWorkbenchNode(agent.shadow)?.querySelector('.import-preview'), {
        root: agent.shadow,
        label: 'invalidated proposal removal'
      })
      agent.assertActive()
      equal(backend.cancelCount(), 1, 'Editing must explicitly cancel the prepared proposal')
      equal(backend.commitCount(), 0, 'Invalidating a proposal must never commit it')
      equal(provider.requests.length, 0, 'Editing must not contact the provider')

      const refreshedPrepare = findButton(workbench, /^Prepare preview$/)
      ok(refreshedPrepare && !refreshedPrepare.disabled, 'A fresh proposal should be preparable after the edit.')
      refreshedPrepare.click()
      await agent.ctx.waitFor(() => Boolean(importWorkbenchNode(agent.shadow)?.querySelector('.import-preview table')), {
        root: agent.shadow,
        label: 'refreshed prepared proposal'
      })
      equal(backend.prepareCount(), 2, 'The refreshed proposal should use a new prepare request')
      const refreshedBody = backend.calls.filter(call => call.method === 'POST' && call.path === BATCH_PATH).at(-1)?.body
      equal(refreshedBody.rows[0].name, 'Alpha changed', 'The refreshed input must include the edited value')
      includes(importWorkbenchNode(agent.shadow).querySelector('.import-preview table').textContent, 'Alpha changed', 'The refreshed preview must show the edited value')
      equal(fetchCounter.calls, 0, 'Invalidation and refresh must stay local to the synthetic transport')
      return {
        cancelledPreparedProposal: backend.cancelCount() === 1,
        prepareCalls: backend.prepareCount(),
        commitCalls: backend.commitCount(),
        providerRequests: provider.requests.length
      }
    }
  },
  {
    name: 'an uncertain batch commit blocks retry until an explicit outcome check restores the proposal',
    async run(ctx) {
      const provider = ctx.createProvider()
      const { createBatchMutationHost, mutationCapabilities } = await loadBatchModules(ctx)
      const backend = createSyntheticBatchBackend({ commitOutcome: 'uncertain', statusOutcome: 'pending' })
      const batchHost = createBatchMutationHost(backend.request, BATCH_PATH, mutationCapabilities)
      const agent = await ctx.mount({ host: createSyntheticHost({ batchHost }), provider })
      const fetchCounter = installFetchCounter(ctx)
      const { workbench } = openImportWorkbench(ctx, agent)
      setImportEntity(ctx, workbench, 'Tag')
      chooseImportFile(ctx, workbench, makeFile(
        'acceptance-uncertain.csv',
        'Name,Slug\nGamma,gamma\n',
        'text/csv'
      ))
      await waitForImportTable(agent, 1, 2)
      setImportMapping(ctx, workbench, 'Name', 0)
      setImportMapping(ctx, workbench, 'Slug', 1)
      const prepare = findButton(workbench, /^Prepare preview$/)
      ok(prepare && !prepare.disabled, 'The uncertain-commit proposal should be preparable.')
      prepare.click()
      await agent.ctx.waitFor(() => Boolean(importWorkbenchNode(agent.shadow)?.querySelector('.import-preview')), {
        root: agent.shadow,
        label: 'uncertain commit proposal'
      })

      const confirm = workbench.querySelector('.import-preview label.checkbox input[type="checkbox"]')
      ok(confirm, 'The explicit confirmation control was not rendered.')
      confirm.click()
      const commit = findButton(workbench, /^Create 1 records$/)
      ok(commit && !commit.disabled, 'The explicit commit control should be enabled after confirmation.')
      commit.click()
      await agent.ctx.waitFor(() => Boolean(findButton(importWorkbenchNode(agent.shadow), /^Check outcome$/)), {
        root: agent.shadow,
        label: 'unknown outcome controls'
      })
      await flushMicrotasks(ctx, 12)
      agent.assertActive()
      equal(backend.commitCount(), 1, 'An uncertain commit must have been attempted exactly once')
      equal(backend.statusCount(), 0, 'An uncertain commit must not trigger an automatic status request')
      equal(backend.restoreCount(), 0, 'An uncertain commit must not automatically restore or retry')
      ok(!findButton(workbench, /^Create 1 records$/), 'No retry control may remain after an unknown outcome')
      includes(workbench.textContent, 'Outcome unknown.', 'The workbench must explain the unresolved outcome')

      const check = findButton(workbench, /^Check outcome$/)
      ok(check && !check.disabled, 'The explicit outcome check should be available.')
      check.click()
      await agent.ctx.waitFor(() => importWorkbenchNode(agent.shadow)?.textContent.includes('The server reports this batch as pending.'), {
        root: agent.shadow,
        label: 'restored pending proposal'
      })
      agent.assertActive()
      equal(backend.statusCount(), 1, 'One explicit click must perform exactly one status request')
      equal(backend.restoreCount(), 1, 'One explicit status check may restore the same proposal once')
      equal(backend.commitCount(), 1, 'Checking the outcome must never retry the commit')
      const restoredConfirm = workbench.querySelector('.import-preview label.checkbox input[type="checkbox"]')
      ok(restoredConfirm, 'The restored proposal must require confirmation again.')
      equal(restoredConfirm.checked, false, 'A restored proposal must not be pre-confirmed')
      const restoredCommit = findButton(workbench, /^Create 1 records$/)
      ok(restoredCommit, 'The restored proposal should expose an explicit create control.')
      ok(restoredCommit.disabled, 'The restored create control must stay disabled until confirmation')
      await flushMicrotasks(ctx, 12)
      equal(backend.commitCount(), 1, 'No automatic retry may follow the explicit outcome check')
      equal(provider.requests.length, 0, 'Batch recovery must never call the model provider')
      equal(fetchCounter.calls, 0, 'Batch recovery must not use browser fetch with the synthetic transport')
      return {
        commitCalls: backend.commitCount(),
        statusCalls: backend.statusCount(),
        restoreCalls: backend.restoreCount(),
        restoredAwaitingConfirmation: restoredConfirm.checked === false
      }
    }
  },
  {
    name: 'a malformed canonical batch preview renders no confirmation or commit control',
    async run(ctx) {
      const provider = ctx.createProvider()
      const { createBatchMutationHost, mutationCapabilities } = await loadBatchModules(ctx)
      const backend = createSyntheticBatchBackend({
        mutatePreview: preview => ({ ...preview, affectedRecords: preview.affectedRecords + 1 })
      })
      const batchHost = createBatchMutationHost(backend.request, BATCH_PATH, mutationCapabilities)
      const agent = await ctx.mount({ host: createSyntheticHost({ batchHost }), provider })
      const fetchCounter = installFetchCounter(ctx)
      const { workbench } = openImportWorkbench(ctx, agent)
      setImportEntity(ctx, workbench, 'Tag')
      chooseImportFile(ctx, workbench, makeFile(
        'acceptance-malformed.csv',
        'Name,Slug\nDelta,delta\n',
        'text/csv'
      ))
      await waitForImportTable(agent, 1, 2)
      setImportMapping(ctx, workbench, 'Name', 0)
      setImportMapping(ctx, workbench, 'Slug', 1)
      const prepare = findButton(workbench, /^Prepare preview$/)
      ok(prepare && !prepare.disabled, 'The malformed-preview attempt should be preparable.')
      prepare.click()
      await agent.ctx.waitFor(() => importWorkbenchNode(agent.shadow)?.textContent.includes('The batch preview could not be prepared. Nothing was confirmed.'), {
        root: agent.shadow,
        label: 'malformed preview rejection'
      })
      agent.assertActive()
      equal(importWorkbenchNode(agent.shadow).querySelectorAll('.import-preview').length, 0, 'A malformed preview must not render a preview panel')
      equal(importWorkbenchNode(agent.shadow).querySelectorAll('.import-preview input[type="checkbox"]').length, 0, 'A malformed preview must not render confirmation')
      ok(!findButton(importWorkbenchNode(agent.shadow), /^Create 1 records$/), 'A malformed preview must not render a create control')
      equal(backend.prepareCount(), 1, 'The malformed preview should be rejected after one prepare request')
      equal(backend.commitCount(), 0, 'A malformed preview must never reach commit')
      equal(provider.requests.length, 0, 'A malformed preview must not contact the provider')
      equal(fetchCounter.calls, 0, 'A malformed preview must not trigger browser fetch')
      return {
        previewPanels: 0,
        confirmationControls: 0,
        prepareCalls: backend.prepareCount(),
        commitCalls: backend.commitCount()
      }
    }
  },
  {
    name: 'disposal and remount suppress a delayed prepared batch preview',
    async run(ctx) {
      const lateSentinel = 'LATE_BATCH_PREVIEW_SENTINEL_7d31'
      const { createBatchMutationHost, mutationCapabilities } = await loadBatchModules(ctx)
      const capabilitySource = createBatchMutationHost({
        async post() { throw new Error('The capability source must not transport.') },
        async get() { throw new Error('The capability source must not transport.') }
      }, BATCH_PATH, mutationCapabilities)
      const started = ctx.deferred('deferred batch prepare start')
      const late = ctx.deferred('late batch preview')
      let submitted = null
      let commitCalls = 0
      const deferredBatchHost = {
        getBatchCreateCapabilities: () => capabilitySource.getBatchCreateCapabilities(),
        prepareRecordBatch(input) {
          submitted = input
          started.resolve(input)
          // Deliberately ignore AbortSignal so the test observes whether the UI
          // generation guard, not transport cancellation, suppresses the preview.
          return late.promise
        },
        async restoreRecordBatch() { throw new Error('Unexpected restore in the delayed batch case.') },
        async commitRecordBatch() {
          commitCalls += 1
          return buildBatchReceipt(submitted, { verified: true })
        },
        async getRecordBatchStatus() { throw new Error('Unexpected status in the delayed batch case.') },
        async cancelRecordBatch() {}
      }

      const providerA = ctx.createProvider()
      const agentA = await ctx.mount({ host: createSyntheticHost({ batchHost: deferredBatchHost }), provider: providerA })
      const fetchCounter = installFetchCounter(ctx)
      const { workbench } = openImportWorkbench(ctx, agentA)
      setImportEntity(ctx, workbench, 'Tag')
      chooseImportFile(ctx, workbench, makeFile(
        'acceptance-late-batch.csv',
        `Name,Slug\n${lateSentinel},late-batch\n`,
        'text/csv'
      ))
      await waitForImportTable(agentA, 1, 2)
      setImportMapping(ctx, workbench, 'Name', 0)
      setImportMapping(ctx, workbench, 'Slug', 1)
      findButton(workbench, /^Prepare preview$/).click()
      await ctx.wait(started.promise, 'deferred batch prepare start')

      const detachedShadow = agentA.shadow
      agentA.dispose()
      const providerB = ctx.createProvider()
      const agentB = await ctx.mount({ provider: providerB })
      const fetchCallsAfterRemount = fetchCounter.calls
      late.resolve(buildBatchPreview(submitted))
      await ctx.wait(late.promise, 'late batch preview resolution')
      await flushMicrotasks(ctx, 16)
      ctx.check()

      excludes(detachedShadow.textContent, lateSentinel, 'The disposed agent must not render the late batch preview')
      equal(detachedShadow.querySelectorAll('.import-preview').length, 0, 'The disposed agent must not keep a late preview panel')
      excludes(agentB.shadow.textContent, lateSentinel, 'A remounted agent must not render the old batch preview')
      equal(agentB.shadow.querySelectorAll('.import-preview').length, 0, 'A remounted agent must not contain the old preview panel')
      equal(commitCalls, 0, 'A late preview must never commit')
      equal(providerA.requests.length, 0, 'Preparing a local batch must not contact the provider')
      equal(providerB.requests.length, 0, 'A remounted session must not contact the provider because of a late batch preview')
      equal(fetchCounter.calls, fetchCallsAfterRemount, 'A late batch preview must not trigger browser fetch')
      return {
        latePreviewSuppressed: true,
        disposedPreviewPanels: 0,
        remountedPreviewPanels: 0,
        commitCalls
      }
    }
  },
  {
    name: 'batch submitting then Back blocks single confirm and history restore until the batch resolves',
    async run(ctx) {
      const provider = ctx.createProvider()
      const { createBatchMutationHost, mutationCapabilities } = await loadBatchModules(ctx)
      const singlePreview = buildSingleRecordPreview({ name: 'Single preview survives an in-flight batch' })
      const commitDeferred = ctx.deferred('deferred batch commit')
      const backend = createSyntheticBatchBackend({ commitDeferred })
      const batchHost = createBatchMutationHost(backend.request, BATCH_PATH, mutationCapabilities)
      const { host, calls } = createInterleavedHost(batchHost, singlePreview)
      const agent = await ctx.mount({ host, provider })
      installConfirm(ctx, true)
      await prepareSingleRecordPreview(ctx, agent, provider, singlePreview)
      const providerRequestsBeforeBatch = provider.requests.length
      await preloadOperationHistory(ctx, agent)
      equal(calls.list, 1, 'The pending single operation should be listed once before interleaving')

      const workbench = await prepareBatchFromCsv(ctx, agent, {
        fileName: 'acceptance-interleave-submitting.csv',
        content: 'Name,Slug\nInterleave submitting,interleave-submitting\n',
        columns: ['Name', 'Slug'],
        mapping: ['Name', 'Slug'],
        rowCount: 1
      })
      confirmAndCommitBatch(ctx, workbench, 1)
      await agent.ctx.waitFor(() => importWorkbenchNode(agent.shadow)?.textContent.includes('Creating records…'), {
        root: agent.shadow,
        label: 'batch submitting state'
      })
      equal(backend.commitCount(), 1, 'The batch commit should be in flight before Back')
      equal(calls.commit, 0, 'No single record commit should exist before interleaving')

      closeImportWorkbench(ctx, agent)
      const singleConfirm = findButton(agent.shadow, /^Confirm and save$/)
      ok(singleConfirm, 'The prior single confirmation should be visible after Back.')
      const singleActivation = activateGuardedControl(ctx, singleConfirm)
      await flushMicrotasks(ctx, 12)
      equal(calls.commit, 0, 'A single record confirmation must not call the host while a batch is submitting')

      const history = openOperationHistory(ctx, agent)
      await flushMicrotasks(ctx, 12)
      await agent.ctx.waitFor(() => Boolean(findButton(history, /^Restore preview$/)), {
        root: agent.shadow,
        label: 'pending operation restore control during batch'
      })
      const restore = findButton(history, /^Restore preview$/)
      ok(restore, 'The prior history restore control should be visible during batch submitting.')
      const restoreActivation = activateGuardedControl(ctx, restore)
      await flushMicrotasks(ctx, 12)
      equal(calls.restore, 0, 'Operation history restore must not call the host while a batch is submitting')
      const historyButton = findButton(agent.shadow, /^Operations$/)
      ok(historyButton, 'The Operations control should remain available to close history.')
      historyButton.click()
      ctx.check()

      backend.resolveCommit()
      await agent.ctx.waitFor(() => importWorkbenchNode(agent.shadow)?.textContent.includes('Created 1 records.'), {
        root: agent.shadow,
        label: 'resolved batch success receipt'
      })
      const normalConfirm = findButton(agent.shadow, /^Confirm and save$/)
      ok(normalConfirm && !normalConfirm.disabled, 'The single confirmation should be usable after the batch resolves.')
      normalConfirm.click()
      await agent.ctx.waitFor(() => calls.commit === 1, {
        root: agent.shadow,
        label: 'single commit after batch resolution'
      })
      await flushMicrotasks(ctx, 12)
      equal(calls.commit, 1, 'Exactly one single record commit may run after the batch resolves')
      equal(backend.commitCount(), 1, 'Resolving the batch must not retry its commit')
      equal(backend.statusCount(), 0, 'A successful batch commit should not need a status request')
      equal(provider.requests.length, providerRequestsBeforeBatch, 'Batch interleaving must not dispatch another provider request')
      return {
        blockedSingleCommitCalls: 0,
        blockedRestoreCalls: 0,
        singleConfirmActivation: singleActivation,
        restoreActivation,
        commitCalls: backend.commitCount(),
        singleCommitCalls: calls.commit,
        providerRequests: provider.requests.length
      }
    }
  },
  {
    name: 'a same-identity mismatched batch receipt stays unknown and blocks single and history actions',
    async run(ctx) {
      const provider = ctx.createProvider()
      const { createBatchMutationHost, mutationCapabilities } = await loadBatchModules(ctx)
      const singlePreview = buildSingleRecordPreview({ name: 'Single preview survives an unknown batch' })
      const backend = createSyntheticBatchBackend({
        statusOutcome: 'succeeded',
        mutateCommitReceipt: receipt => ({
          ...receipt,
          entity: 'Counter',
          rowCount: 1,
          fields: ['storeUuid'],
          results: [{ row: 1, identifier: '9001' }],
          expiresAt: batchTimestamp(BATCH_EXPIRES_OFFSET_MS + 60000)
        })
      })
      const batchHost = createBatchMutationHost(backend.request, BATCH_PATH, mutationCapabilities)
      const { host, calls } = createInterleavedHost(batchHost, singlePreview)
      const agent = await ctx.mount({ host, provider })
      installConfirm(ctx, true)
      await prepareSingleRecordPreview(ctx, agent, provider, singlePreview)
      const providerRequestsBeforeBatch = provider.requests.length
      await preloadOperationHistory(ctx, agent)

      const workbench = await prepareBatchFromCsv(ctx, agent, {
        fileName: 'acceptance-interleave-mismatch.csv',
        content: 'Name,Slug\nInterleave mismatch,interleave-mismatch\nSecond mismatch,second-mismatch\n',
        columns: ['Name', 'Slug'],
        mapping: ['Name', 'Slug'],
        rowCount: 2
      })
      confirmAndCommitBatch(ctx, workbench, 2)
      await agent.ctx.waitFor(() => {
        const node = importWorkbenchNode(agent.shadow)
        return Boolean(findButton(node, /^Check outcome$/))
          || node.textContent.includes('Created 2 records.')
          || node.textContent.includes('Outcome unknown.')
      }, {
        root: agent.shadow,
        label: 'mismatched batch receipt resolution'
      })
      agent.assertActive()
      const outcomeControl = findButton(workbench, /^Check outcome$/)
      if (!outcomeControl) {
        if (workbench.textContent.includes('Created 2 records.')) {
          throw new Error('A same-ID receipt with different entity, count, fields and expiry was presented as success instead of unknown')
        }
        throw new Error('A mismatched batch receipt did not expose an explicit recovery control after remaining unknown')
      }
      equal(backend.commitCount(), 1, 'The mismatched receipt should follow exactly one commit request')
      equal(backend.statusCount(), 0, 'A mismatched receipt must not trigger an automatic status request')
      equal(backend.restoreCount(), 0, 'A mismatched receipt must not automatically restore or retry')
      includes(workbench.textContent, 'Outcome unknown.', 'A same-ID receipt with different metadata must remain unknown')
      excludes(workbench.textContent, 'Created 2 records.', 'A mismatched receipt must not be presented as success')

      closeImportWorkbench(ctx, agent)
      const singleConfirm = findButton(agent.shadow, /^Confirm and save$/)
      ok(singleConfirm, 'The prior single confirmation should be visible after Back.')
      const singleActivation = activateGuardedControl(ctx, singleConfirm)
      await flushMicrotasks(ctx, 12)
      equal(calls.commit, 0, 'A single record confirmation must not call the host while the batch outcome is unknown')

      const history = openOperationHistory(ctx, agent)
      await flushMicrotasks(ctx, 12)
      await agent.ctx.waitFor(() => Boolean(findButton(history, /^Restore preview$/)), {
        root: agent.shadow,
        label: 'pending operation restore control during unknown batch'
      })
      const restore = findButton(history, /^Restore preview$/)
      ok(restore, 'The prior history restore control should be visible during an unknown batch outcome.')
      const restoreActivation = activateGuardedControl(ctx, restore)
      await flushMicrotasks(ctx, 12)
      equal(calls.restore, 0, 'Operation history restore must not call the host while the batch outcome is unknown')
      const historyButton = findButton(agent.shadow, /^Operations$/)
      ok(historyButton, 'The Operations control should remain available to close history.')
      historyButton.click()
      ctx.check()

      const importButton = [...agent.shadow.querySelectorAll('form.composer button')]
        .find(control => control.hasAttribute('aria-expanded'))
      ok(importButton && !importButton.disabled, 'The Import control should remain available after Back.')
      importButton.click()
      ctx.check()
      const reopened = importWorkbenchNode(agent.shadow)
      ok(reopened && !reopened.classList.contains('hidden'), 'The import workbench should reopen for an explicit outcome check.')
      const check = findButton(reopened, /^Check outcome$/)
      ok(check && !check.disabled, 'The explicit outcome check should be available.')
      check.click()
      await agent.ctx.waitFor(() => importWorkbenchNode(agent.shadow)?.textContent.includes('Created 2 records.'), {
        root: agent.shadow,
        label: 'explicit batch outcome resolution'
      })
      equal(backend.statusCount(), 1, 'One explicit click must perform exactly one status request')
      equal(backend.restoreCount(), 0, 'A successful status result should not restore the preview')
      equal(backend.commitCount(), 1, 'Checking the mismatched outcome must never retry the commit')

      closeImportWorkbench(ctx, agent)
      const normalConfirm = findButton(agent.shadow, /^Confirm and save$/)
      ok(normalConfirm && !normalConfirm.disabled, 'The single confirmation should be usable after the batch resolves.')
      normalConfirm.click()
      await agent.ctx.waitFor(() => calls.commit === 1, {
        root: agent.shadow,
        label: 'single commit after unknown batch resolution'
      })
      await flushMicrotasks(ctx, 12)
      equal(calls.commit, 1, 'Exactly one single record commit may run after the batch resolves')
      equal(backend.commitCount(), 1, 'The resolved batch must not retry its commit')
      equal(provider.requests.length, providerRequestsBeforeBatch, 'Batch outcome recovery must not dispatch another provider request')
      return {
        mismatchedReceiptUnknown: true,
        blockedSingleCommitCalls: 0,
        blockedRestoreCalls: 0,
        singleConfirmActivation: singleActivation,
        restoreActivation,
        statusCalls: backend.statusCount(),
        commitCalls: backend.commitCount(),
        singleCommitCalls: calls.commit
      }
    }
  }
]

function writeStandardLimitations(results) {
  const standard = [
    'Runs in a real browser DOM but uses only synthetic host, provider, API-document and file data; no backend, model provider, printer, file-storage or database is contacted.',
    'Required isolation is preflighted before any mutation. localStorage, document.cookie, fetch, XMLHttpRequest, WebSocket, EventSource, navigator.sendBeacon and Element.prototype.attachShadow must all be replaceable or the run fails before import/mount.',
    'FileReader and AbortController are replaced only for the deferred-read case. Browser descriptors are restored exactly in finally unless an unsettled timeout forces fail-closed isolation retention.',
    'The closed shadow root is forced open only for inspection during the run; production source and its shadow mode are unchanged.',
    'Session switching uses the real integration monitor with synthetic in-memory cookies. It is not real authentication, a real account switch, or evidence about backend authorization.',
    'Provider and read-query results are synthetic. This does not establish live-provider compatibility, backend read authorization, delayed authenticated-read behavior against a real backend, or database concurrency.',
    'The deferred FileReader case suppresses AbortController.signal delivery so a late read can complete after clear; the application generation guard, not signal delivery, is the behavior under test.',
    'Bulk import acceptance parses real local CSV bytes in the browser and drives the mounted workbench through the real createBatchMutationHost and shared mutation capability policy with a synthetic request object. It does not establish real backend persistence, server authorization, database concurrency, or file upload behavior.',
    'The delayed batch-preview case uses a synthetic equivalent host whose capability descriptor comes from the real batch transport. It establishes UI disposal suppression, not backend cancellation or storage semantics.',
    'Batch/single interleaving cases use synthetic single-record and batch hosts to verify action-time UI gating. They do not establish backend authorization, real record persistence, or that an independently submitted single-record write would succeed.'
  ]
  for (const text of [...standard, ...state.limitations]) {
    if (!results.limitations.includes(text)) results.limitations.push(text)
  }
}

async function loadApplicationModules() {
  const [entryModule, preferenceModule] = await Promise.all([
    import('../../src/entry.js'),
    import('../../src/preferences.js')
  ])
  return { ...entryModule, PREFS_KEY: preferenceModule.PREFS_KEY }
}

async function executeAcceptance({
  cases = CASES,
  expectedCount = EXPECTED_CASE_COUNT,
  caseTimeoutMs = CASE_TIMEOUT_MS,
  selfTest = false
} = {}) {
  // The guard is deliberately the first side-effecting-preparation step. No
  // DOM, storage, global or application mutation occurs before it accepts.
  const results = {
    ok: false,
    selfTest,
    startedAt: new Date().toISOString(),
    durationMs: 0,
    environment: {
      origin: location.origin,
      pathname: decodeURIComponent(location.pathname),
      fixtureMarker: document.body?.getAttribute('data-ns-agent-acceptance') || null
    },
    expectedCases: expectedCount,
    counts: { passed: 0, failed: 0, skipped: 0, total: 0 },
    cases: [],
    limitations: [],
    blockedRequests: [],
    setupError: null,
    cleanupErrors: [],
    stoppedByTimeout: false,
    stopReason: null,
    isolationRetained: false,
    descriptorsRestored: null
  }
  let setupError = null
  let stopRun = false
  let keepIsolation = false
  let runController = null
  let runToken = 0
  let descriptorBaseline = null

  const startedAt = performance.now()
  try {
    try {
      assertFixturePage()
      ok(!state.isolationRetained && !state.storage && state.restorers.length === 0,
        'Refusing to run: a previous acceptance run retained browser isolation.')
      ok(cases.length === expectedCount, `Expected ${expectedCount} browser acceptance cases, received ${cases.length}.`)
      descriptorBaseline = captureRequiredDescriptors()
      state.blocked.length = 0
      state.limitations.length = 0
      state.docsRequests = 0
      runToken = state.runToken + 1
      state.runToken = runToken
      state.runAborted = false
      runController = new AbortController()
      state.runController = runController
      installIsolation()
      state.modules = await loadApplicationModules()
      writeSyntheticPreferences()
      results.counts.total = cases.length
    } catch (error) {
      setupError = error
      results.setupError = String(error?.stack || error)
      if (error?.keepIsolation === true) keepIsolation = true
    }

    if (!setupError) {
      for (const definition of cases) {
        const caseStarted = performance.now()
        const context = createCaseContext({ name: definition.name, runToken, runSignal: runController.signal })
        let failure = null
        let evidence = null
        let timedOut = false
        let caseSettled = true
        let cleanupSettled = true
        const blockedBefore = state.blocked.length
        const casePromise = Promise.resolve().then(() => definition.run(context))
        const settlement = casePromise.then(() => true, () => true)

        try {
          evidence = await withAbortAndTimeout(casePromise, context.signal, caseTimeoutMs, `Case "${definition.name}"`)
          context.check()
          const blockedDuringCase = state.blocked.slice(blockedBefore)
          if (blockedDuringCase.length) throw new Error(`Blocked unexpected network request(s): ${blockedDuringCase.join(', ')}`)
        } catch (error) {
          failure = error
          timedOut = error?.timedOut === true
          if (timedOut || error?.aborted === true) {
            context.abort(error)
            stopRun = true
            results.stoppedByTimeout = results.stoppedByTimeout || timedOut
            results.stopReason = error?.message || String(error)
            try {
              await withAbortAndTimeout(settlement, null, CASE_SETTLE_TIMEOUT_MS, `Case "${definition.name}" settlement`)
            } catch {
              caseSettled = false
            }
          }
        } finally {
          // Even a normally completed or assertion-failed case must relinquish
          // every owned callback before the next case or global restoration.
          if (!context.aborted) context.abort(failure || abortError(`case ${definition.name} completed`))
          try {
            await withAbortAndTimeout(context.cleanup(), null, CLEANUP_TIMEOUT_MS, 'case cleanup')
          } catch {
            cleanupSettled = false
            results.cleanupErrors.push(`${definition.name}: cleanup did not settle`)
          }
        }

        const durationMs = Math.round(performance.now() - caseStarted)
        if (failure) {
          results.counts.failed += 1
          results.cases.push({
            name: definition.name,
            status: 'failed',
            durationMs,
            timedOut,
            error: String(failure?.stack || failure?.message || failure)
          })
        } else if (evidence?.skip) {
          results.counts.skipped += 1
          results.cases.push({
            name: definition.name,
            status: 'skipped',
            durationMs,
            reason: evidence.skip,
            evidence
          })
        } else {
          results.counts.passed += 1
          results.cases.push({
            name: definition.name,
            status: 'passed',
            durationMs,
            evidence
          })
        }

        if (!caseSettled || !cleanupSettled) {
          keepIsolation = true
          stopRun = true
        }
        if (stopRun) break
      }
    }
  } finally {
    if (runController) runController.abort(abortError('run completion'))
    state.runAborted = true
    for (const dispose of [...state.owned]) {
      try { dispose() } catch { /* Owned cleanup only. */ }
    }
    state.owned.clear()
    state.provider = null
    if (!keepIsolation) {
      try { state.storage?.clear() } catch (error) {
        results.cleanupErrors.push(`memory storage cleanup: ${error.message}`)
      }
      const restoreErrors = restoreIsolation()
      for (const error of restoreErrors) results.cleanupErrors.push(`descriptor restore: ${error.message}`)
      state.runToken = 0
      state.isolationRetained = false
      if (descriptorBaseline) {
        results.descriptorsRestored = requiredDescriptorsRestored(descriptorBaseline)
        if (!results.descriptorsRestored) {
          results.cleanupErrors.push('Required browser descriptors were not restored exactly.')
        }
      }
    } else {
      state.isolationRetained = true
      results.isolationRetained = true
    }
    state.shadow = null
  }

  results.blockedRequests = [...state.blocked]
  results.cleanupErrors = [...results.cleanupErrors]
  if (results.cleanupErrors.length || results.blockedRequests.length || results.isolationRetained) results.ok = false
  else {
    results.ok = !setupError
      && !results.stoppedByTimeout
      && results.counts.total === expectedCount
      && results.counts.failed === 0
      && results.counts.skipped === 0
      && results.counts.passed === expectedCount
      && results.cases.length === expectedCount
  }
  results.durationMs = Math.round(performance.now() - startedAt)
  writeStandardLimitations(results)
  return results
}

let mainExecution = null

/**
 * Run the main 21-case browser acceptance suite once per fixture page.
 */
export function runBrowserAcceptance(options = {}) {
  let execution
  if (options.fresh === true) {
    execution = executeAcceptance({
      cases: options.cases || CASES,
      expectedCount: options.expectedCount === undefined ? EXPECTED_CASE_COUNT : options.expectedCount,
      caseTimeoutMs: options.caseTimeoutMs || CASE_TIMEOUT_MS,
      selfTest: options.selfTest === true
    })
  } else {
    if (!mainExecution) mainExecution = executeAcceptance()
    execution = mainExecution
  }
  const throwOnFailure = options.throwOnFailure !== false
  return execution.then(results => {
    if (!results.ok && throwOnFailure) {
      const error = new Error(`Browser acceptance failed: ${results.counts.failed} failed, ${results.counts.skipped} skipped, ${results.counts.total}/${results.expectedCases} ran.`)
      error.results = results
      throw error
    }
    return results
  })
}

/**
 * Fixture-owned boot. The guard runs before the fixture global or status text is
 * created. Guard rejection leaves the page and globals untouched.
 */
export async function bootBrowserAcceptanceFixture() {
  assertFixturePage()
  const statusNode = document.querySelector('[data-role="fixture-status"]')
  const fixtureState = {
    status: 'running',
    results: null,
    error: null,
    promise: null
  }
  window.__NS_ADMIN_AGENT_ACCEPTANCE__ = fixtureState
  if (statusNode) statusNode.textContent = 'running'
  try {
    fixtureState.promise = runBrowserAcceptance({ throwOnFailure: false })
    const results = await fixtureState.promise
    fixtureState.results = results
    fixtureState.status = results.ok ? 'passed' : 'failed'
    if (statusNode) {
      statusNode.textContent = results.ok
        ? `PASS ${results.counts.passed}/${results.expectedCases} browser acceptance cases`
        : `FAIL ${results.counts.failed} failed, ${results.counts.skipped} skipped, ${results.counts.passed}/${results.expectedCases} passed`
    }
    return results
  } catch (error) {
    fixtureState.status = 'failed'
    fixtureState.error = String(error?.stack || error)
    if (statusNode) statusNode.textContent = `FAIL ${error?.message || error}`
    throw error
  }
}

/**
 * Negative regression: a production-looking page with an existing agent must be
 * rejected without removing the agent, changing storage or writing globals.
 */
export async function runBrowserAcceptanceProductionGuardSelfCheck() {
  assertFixturePage()

  // Capture the descriptor only. Never invoke the native getter/setter and
  // never copy a native storage value into this self-check.
  const original = descriptorFor(window, 'localStorage')
  if (!original || original.descriptor.configurable !== true) {
    throw new Error('Production guard self-check cannot safely replace the localStorage property descriptor.')
  }
  const originalSnapshot = snapshotRequiredDescriptor({ owner: window, name: 'localStorage' })
  const sentinel = 'production-agent-sentinel-6d20'
  const memory = createMemoryStorage()
  let canaryReads = 0
  let canaryWrites = 0
  let memoryReads = 0
  const memoryGetter = () => {
    memoryReads += 1
    return memory
  }
  const memorySetter = () => {
    throw new TypeError('The production guard self-check memory storage is read-only.')
  }

  let existingAgent = null
  let app = null
  try {
    // A throwing canary replaces the native descriptor without reading it. The
    // memory descriptor then replaces the canary. If any path tries to restore
    // or read the underlying storage, it either misses our memory descriptor or
    // increments the canary counters instead of reaching native storage.
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      enumerable: original.descriptor.enumerable === true,
      get() {
        canaryReads += 1
        throw new Error('Native localStorage getter was invoked.')
      },
      set() {
        canaryWrites += 1
        throw new Error('Native localStorage setter was invoked.')
      }
    })
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      enumerable: original.descriptor.enumerable === true,
      get: memoryGetter,
      set: memorySetter
    })
    memory.setItem(NEGATIVE_STORAGE_KEY, 'keep')
  } catch (error) {
    try { restoreExactDescriptor(window, 'localStorage', original) } catch { /* Owned rollback. */ }
    throw new Error(`Production guard self-check could not install in-memory storage before sentinel access: ${error.message}`)
  }

  const memoryDescriptor = Object.getOwnPropertyDescriptor(window, 'localStorage')
  const evidence = {
    ok: false,
    setupRejected: false,
    setupError: null,
    runError: null,
    counts: { passed: 0, failed: 0, skipped: 0, total: 0 },
    expectedCases: EXPECTED_CASE_COUNT,
    sentinelStillConnected: false,
    appStillPresent: false,
    memorySentinelUnchanged: false,
    memoryDescriptorUnchanged: false,
    memoryReads: 0,
    canaryReads: 0,
    canaryWrites: 0,
    fetchUnchanged: false,
    attachShadowUnchanged: false,
    fixtureGlobalAbsent: false,
    statusUnchanged: false,
    agentCount: 0,
    originalDescriptorRestored: false
  }

  const statusBefore = document.querySelector('[data-role="fixture-status"]')?.textContent ?? null
  const fetchBefore = window.fetch
  const shadowBefore = Element.prototype.attachShadow
  try {
    existingAgent = document.createElement('ns-admin-agent')
    existingAgent.setAttribute('data-production-sentinel', sentinel)
    document.body.append(existingAgent)
    app = document.createElement('div')
    app.id = 'app'
    document.body.append(app)

    const results = await runBrowserAcceptance({
      fresh: true,
      throwOnFailure: false,
      caseTimeoutMs: 2000,
      selfTest: true
    })
    const afterMemory = Object.getOwnPropertyDescriptor(window, 'localStorage')
    evidence.setupRejected = !results.ok && Boolean(results.setupError)
    evidence.setupError = results.setupError
    evidence.counts = results.counts
    evidence.expectedCases = results.expectedCases
    evidence.sentinelStillConnected = existingAgent.isConnected
      && existingAgent.getAttribute('data-production-sentinel') === sentinel
    evidence.appStillPresent = Boolean(document.querySelector('#app'))
    evidence.memorySentinelUnchanged = memory.getItem(NEGATIVE_STORAGE_KEY) === 'keep'
    evidence.memoryDescriptorUnchanged = afterMemory !== null
      && afterMemory.configurable === memoryDescriptor.configurable
      && afterMemory.enumerable === memoryDescriptor.enumerable
      && afterMemory.get === memoryDescriptor.get
      && afterMemory.set === memoryDescriptor.set
    evidence.memoryReads = memoryReads
    evidence.canaryReads = canaryReads
    evidence.canaryWrites = canaryWrites
    evidence.fetchUnchanged = window.fetch === fetchBefore
    evidence.attachShadowUnchanged = Element.prototype.attachShadow === shadowBefore
    evidence.fixtureGlobalAbsent = !Object.prototype.hasOwnProperty.call(window, '__NS_ADMIN_AGENT_ACCEPTANCE__')
    evidence.statusUnchanged = (document.querySelector('[data-role="fixture-status"]')?.textContent ?? null) === statusBefore
    evidence.agentCount = document.querySelectorAll('ns-admin-agent').length
  } catch (error) {
    evidence.runError = String(error?.stack || error)
  } finally {
    let restored = false
    try { restored = restoreExactDescriptor(window, 'localStorage', original) } catch { /* Evidence below. */ }
    evidence.originalDescriptorRestored = restored
      && sameDescriptorSnapshot(snapshotRequiredDescriptor({ owner: window, name: 'localStorage' }), originalSnapshot)
    try { existingAgent?.remove() } catch { /* Owned fixture cleanup. */ }
    try { app?.remove() } catch { /* Owned fixture cleanup. */ }
  }

  evidence.ok = evidence.setupRejected
    && evidence.runError === null
    && evidence.counts.total === 0
    && evidence.counts.passed === 0
    && evidence.counts.failed === 0
    && evidence.sentinelStillConnected
    && evidence.appStillPresent
    && evidence.memorySentinelUnchanged
    && evidence.memoryDescriptorUnchanged
    && evidence.memoryReads === 0
    && evidence.canaryReads === 0
    && evidence.canaryWrites === 0
    && evidence.fetchUnchanged
    && evidence.attachShadowUnchanged
    && evidence.fixtureGlobalAbsent
    && evidence.statusUnchanged
    && evidence.agentCount === 1
    && evidence.originalDescriptorRestored
  return evidence
}

/**
 * Negative regression: an unpatchable required cookie accessor must fail before
 * any installation or application mount, leaving native channels untouched.
 */
export async function runBrowserAcceptancePatchabilitySelfCheck() {
  assertFixturePage()
  const before = {
    fetch: window.fetch,
    xmlHttpRequest: window.XMLHttpRequest,
    webSocket: window.WebSocket,
    eventSource: window.EventSource,
    sendBeacon: navigator.sendBeacon,
    attachShadow: Element.prototype.attachShadow,
    localStorage: Object.getOwnPropertyDescriptor(window, 'localStorage')
  }
  Object.defineProperty(document, 'cookie', {
    configurable: false,
    get() { return '' },
    set() { /* Intentionally immutable for this negative check. */ }
  })
  const results = await runBrowserAcceptance({
    fresh: true,
    throwOnFailure: false,
    cases: [],
    expectedCount: 0,
    caseTimeoutMs: 1000,
    selfTest: true
  })
  const localStorageAfter = Object.getOwnPropertyDescriptor(window, 'localStorage')
  const evidence = {
    ok: false,
    setupRejected: !results.ok && Boolean(results.setupError),
    setupError: results.setupError,
    counts: results.counts,
    expectedCases: results.expectedCases,
    agentCount: document.querySelectorAll('ns-admin-agent').length,
    fixtureGlobalAbsent: !Object.prototype.hasOwnProperty.call(window, '__NS_ADMIN_AGENT_ACCEPTANCE__'),
    statusUnchanged: document.querySelector('[data-role="fixture-status"]')?.textContent === 'pending',
    fetchUnchanged: window.fetch === before.fetch,
    xmlHttpRequestUnchanged: window.XMLHttpRequest === before.xmlHttpRequest,
    webSocketUnchanged: window.WebSocket === before.webSocket,
    eventSourceUnchanged: window.EventSource === before.eventSource,
    sendBeaconUnchanged: navigator.sendBeacon === before.sendBeacon,
    attachShadowUnchanged: Element.prototype.attachShadow === before.attachShadow,
    localStorageUnchanged: localStorageAfter?.configurable === before.localStorage?.configurable
      && localStorageAfter?.get === before.localStorage?.get
      && localStorageAfter?.set === before.localStorage?.set,
    isolationRetained: results.isolationRetained
  }
  evidence.ok = evidence.setupRejected
    && /cookie/iu.test(evidence.setupError || '')
    && evidence.counts.total === 0
    && evidence.counts.passed === 0
    && evidence.counts.failed === 0
    && evidence.agentCount === 0
    && evidence.fixtureGlobalAbsent
    && evidence.statusUnchanged
    && evidence.fetchUnchanged
    && evidence.xmlHttpRequestUnchanged
    && evidence.webSocketUnchanged
    && evidence.eventSourceUnchanged
    && evidence.sendBeaconUnchanged
    && evidence.attachShadowUnchanged
    && evidence.localStorageUnchanged
    && evidence.isolationRetained === false
  return evidence
}

/**
 * Negative regression: a stalled case is aborted at the bounded timeout, all
 * controlled deferrals are rejected, the case settles, and no late mount,
 * provider send or observer callback can occur after isolation is restored.
 */
export async function runBrowserAcceptanceTimeoutSelfCheck(options = {}) {
  assertFixturePage()
  const timeoutMs = Math.min(2000, Math.max(100, Number(options.caseTimeoutMs) || 300))
  const originalFetch = window.fetch
  let leakedContext = null
  let leakedProvider = null
  let lateRead = null
  let observerRoot = null
  let observerChecks = 0
  let observerDisconnected = false
  let capturedSyntheticFetch = null

  const injected = [{
    name: 'injected stalled browser acceptance case',
    async run(ctx) {
      leakedContext = ctx
      lateRead = ctx.deferred('injected late read')
      const queryStarted = ctx.deferred('injected query start')
      const host = createSyntheticHost({
        query() {
          queryStarted.resolve()
          return lateRead.promise
        }
      })
      const provider = ctx.createProvider()
      leakedProvider = provider
      provider.enqueueToolCall('query_records', { entity: 'syntheticRecords' })
      capturedSyntheticFetch = window.fetch
      const agent = await ctx.mount({ host, provider })
      observerRoot = agent.shadow.querySelector('.messages')
      waitFor(() => {
        observerChecks += 1
        return false
      }, {
        root: observerRoot,
        signal: ctx.signal,
        timeoutMs: 60000,
        label: 'injected observer',
        onObserver(observer) {
          const disconnect = observer.disconnect.bind(observer)
          observer.disconnect = () => {
            observerDisconnected = true
            disconnect()
          }
        }
      }).catch(() => {})
      sendText(agent, 'Injected timeout question.')
      await ctx.wait(queryStarted.promise, 'injected query start')
      await ctx.never('injected stalled case')
    }
  }]

  const results = await runBrowserAcceptance({
    fresh: true,
    throwOnFailure: false,
    cases: injected,
    expectedCount: 1,
    caseTimeoutMs: timeoutMs,
    selfTest: true
  })

  let lateMountRejected = false
  const agentsBeforeMount = document.querySelectorAll('ns-admin-agent').length
  if (leakedContext) {
    try {
      await leakedContext.mount({ host: createSyntheticHost() })
    } catch {
      lateMountRejected = true
    }
  }
  const agentsAfterMount = document.querySelectorAll('ns-admin-agent').length

  let lateReadRejected = false
  if (lateRead) {
    try {
      lateReadRejected = await Promise.race([
        lateRead.promise.then(() => false, () => true),
        new Promise(resolve => setTimeout(() => resolve(false), 250))
      ])
    } catch {
      lateReadRejected = false
    }
  }

  const requestsBeforeLateFetch = leakedProvider ? leakedProvider.requests.length : 0
  let lateFetchRejected = false
  if (capturedSyntheticFetch) {
    try {
      await capturedSyntheticFetch(PROVIDER_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ model: MODEL, messages: [], stream: true })
      })
    } catch {
      lateFetchRejected = true
    }
  }
  const lateFetchRecorded = leakedProvider
    ? leakedProvider.requests.length !== requestsBeforeLateFetch
    : false

  for (let index = 0; index < 8; index += 1) await Promise.resolve()
  const checksBeforeMutation = observerChecks
  if (observerRoot) observerRoot.textContent = 'late observer mutation sentinel'
  for (let index = 0; index < 8; index += 1) await Promise.resolve()
  const observerCallbacksAfterMutation = observerChecks - checksBeforeMutation

  const evidence = {
    ok: false,
    timedOut: results.stoppedByTimeout === true,
    counts: results.counts,
    expectedCases: results.expectedCases,
    caseTimedOut: results.cases[0]?.timedOut === true,
    isolationRetained: results.isolationRetained,
    nativeFetchRestored: window.fetch === originalFetch,
    lateMountRejected,
    agentsBeforeMount,
    agentsAfterMount,
    lateReadRejected,
    lateFetchRejected,
    lateFetchRecorded,
    observerDisconnected,
    observerCallbacksAfterMutation
  }
  evidence.ok = evidence.timedOut
    && evidence.counts.total === 1
    && evidence.counts.failed === 1
    && evidence.counts.passed === 0
    && evidence.caseTimedOut
    && evidence.isolationRetained === false
    && evidence.nativeFetchRestored
    && evidence.lateMountRejected
    && evidence.agentsBeforeMount === 0
    && evidence.agentsAfterMount === 0
    && evidence.lateReadRejected
    && evidence.lateFetchRejected
    && evidence.lateFetchRecorded === false
    && evidence.observerDisconnected
    && evidence.observerCallbacksAfterMutation === 0
  return evidence
}
