import { expect, test, type Page, type Route } from '@playwright/test'

const storeId = '00000000-0000-4000-8000-000000000001'
const productId = '00000000-0000-4000-8000-000000000002'
const categoryId = '00000000-0000-4000-8000-000000000003'
const orderId = '00000000-0000-4000-8000-000000000004'
const specId = '00000000-0000-4000-8000-000000000005'
const counterId = '00000000-0000-4000-8000-000000000006'
const tradeOrderId = '00000000-0000-4000-8000-000000000007'
const store = { uuid: storeId, name: 'Contract store', code: 'S1', currency: 'CNY', timezone: 'Asia/Shanghai' }
const version = 'a'.repeat(64)
const operationalProduct = (item: Record<string, unknown>) => ({ version, isSoldout: false, description: null, sortOrder: 0, recommendationLevel: 0, extensionEditable: true, settings: { kitchenPrinterEnable: false, startSaleTime: '', endSaleTime: '' }, ...item })
const operationalOrder = (item: Record<string, unknown>) => ({ version, allowedActions: [], verificationRequired: false, rejectionReason: null, ...item })
function operationalList(route: Route, records: Record<string, unknown>[], hasNext = false) {
  const url = new URL(route.request().url())
  const q = (url.searchParams.get('q') ?? '').toLowerCase()
  const status = url.searchParams.get('status') ?? 'all'
  const items = records.filter((item) => (!q || [item.name, item.uuid, item.slug, item.code, item.tradeOrderUuid].some((value) => String(value ?? '').toLowerCase().includes(q))) &&
    (status === 'all' || (status === 'soldout' ? item.isSoldout : ['enabled', 'disabled'].includes(status) ? item.enabled === (status === 'enabled') : (item.status ?? item.operationalStatus) === status)))
    .map((item) => url.pathname.endsWith('/products') ? operationalProduct(item) : url.pathname.endsWith('/orders') ? operationalOrder(item) : item)
  return route.fulfill({ json: { code: 0, data: { items, total: items.length, page: Number(url.searchParams.get('page') ?? 1), hasNext } } })
}

async function mockApi(page: Page, options: { partialRefund?: boolean; readOnly?: boolean; membership?: string; malformed?: boolean; denyWrite?: boolean; orderOperations?: boolean; refundAccess?: boolean; cancelAccess?: boolean; confirmedOrder?: boolean; auditor?: boolean; grants?: string[]; kitchenPrint?: boolean; productCount?: number; soldOut?: boolean; productCover?: string; specifications?: { uuid: string; name: string; price: number; status: string; sort: number }[] } = {}) {
  const requests: { path: string; method: string; body: Record<string, unknown> | null; rawBody: string }[] = []
  let products: { uuid: string; name: string; category: { uuid: string } | null; status: string; [key: string]: unknown }[] = Array.from({ length: options.productCount ?? 1 }, (_, index) => ({
    uuid: index === 0 ? productId : `00000000-0000-4000-8000-${String(index + 2).padStart(12, '0')}`,
    name: index === 0 ? 'API product' : `API product ${index + 1}`,
    category: { uuid: categoryId },
    status: 'active',
    isSoldout: Boolean(options.soldOut),
    settings: { kitchenPrinterEnable: Boolean(options.kitchenPrint), startSaleTime: '', endSaleTime: '' },
    metadata: options.productCover ? { subtitle: null, cover: options.productCover } : undefined,
  }))
  let categories = [{ uuid: categoryId, name: 'API category', slug: 'api-category', sortOrder: 0, enabled: true }]
  let specifications: { uuid: string; name: string; price: number; status: string; sort: number }[] = options.specifications?.map((item) => ({ ...item })) ?? []
  let counters: { uuid: string; name: string; code: string; capacity: number | null; sort: number; enabled: boolean }[] = [{ uuid: counterId, name: 'Real A01', code: 'A01', capacity: 4, sort: 0, enabled: true }]
  let order = { uuid: orderId, tradeOrderUuid: orderId, operationalStatus: 'pending_validation', totalAmount: 1590, currency: 'CNY', createdAt: '2026-10-30T10:00:00+08:00', orderSnapshot: { items: [{ productName: 'API product', quantity: 1 }] }, allowedActions: options.orderOperations ? ['accept', 'reject'] : [], verificationRequired: true }
  let reportingOrder = { uuid: orderId, tradeOrderUuid: tradeOrderId, status: options.confirmedOrder ? 'confirmed' : 'paid', paymentStatus: options.confirmedOrder ? null : options.partialRefund ? 'partial_refunded' : 'paid', hasInvoice: !options.confirmedOrder && !!options.partialRefund, totalAmount: 1590, currency: 'CNY', notes: null, createdAt: '2026-11-07T10:00:00+08:00', items: [{ specificationUuid: specId, productName: 'API product', specificationName: 'Regular', quantity: 1, unitPrice: 1590, amount: 1590 }], canReplace: Boolean(options.confirmedOrder), canCancel: Boolean(options.confirmedOrder) }
  await page.route('**/api/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const actualPath = url.pathname
    const path = actualPath.replace('/operations/', '/')
    const method = request.method()
    const body = request.headers()['content-type']?.startsWith('multipart/') ? null : request.postDataJSON() as Record<string, unknown> | null
    requests.push({ path: actualPath, method, body, rawBody: request.postData() ?? '' })
    const respond = (data: unknown, paged = false) => {
      if (actualPath.includes('/operations/')) {
        if (paged) return operationalList(route, data as Record<string, unknown>[])
        if (actualPath.includes('/products/') || actualPath.endsWith('/products')) data = operationalProduct(data as Record<string, unknown>)
        if (actualPath.includes('/orders/')) data = operationalOrder(data as Record<string, unknown>)
      }
      return route.fulfill({ json: { code: 0, data, ...(paged ? { paginator: { page: Number(url.searchParams.get('page') ?? 1), has_next: false } } : {}) } })
    }
    if (path === '/api/auth/login') return route.fulfill({ json: { access_token: 'test-staff-access', refresh_token: 'test-staff-refresh', expires_in: 60 } })
    if (path === '/api/auth/logout') return route.fulfill({ status: 204 })
    expect(request.headers().authorization).toBe('Bearer test-staff-access')
    if (path === '/api/v1/app/stores') return respond([store], true)
    if (path === `/api/v1/app/stores/${storeId}`) return respond(store)
    if (path.endsWith('/membership')) return respond({ status: options.membership ?? 'active', role: 'manager' })
    if (path === '/api/v1/app/users/me') return respond({ uuid: storeId, username: 'contract-staff', roles: ['ROLE_USER'] })
    if (path === '/api/v1/app/authorization/me') {
      if (options.grants) return respond({ permissions: options.grants, storeScopes: Object.fromEntries(options.grants.map((code) => [code, [storeId]])) })
      const scopes: Record<string, string[]> = {}
      for (const resource of ['product', 'product_category', 'specification', 'counter', 'counter_session', 'counter_order', 'order']) {
        const actions = options.readOnly || ['order', 'counter_session', 'counter_order'].includes(resource) ? ['read'] : ['read', 'create', 'update', 'delete']
        for (const action of actions) scopes[`store:${resource}:${action}`] = [storeId]
      }
      if (!options.readOnly) { scopes['store:product:availability'] = [storeId]; scopes['store:catalog_image:create'] = [storeId] }
      if (options.orderOperations && !options.readOnly) for (const action of ['accept', 'reject', 'fulfill', 'verify']) scopes[`store:order:${action}`] = [storeId]
      if (options.refundAccess) scopes['store:order:refund'] = [storeId]
      if (options.cancelAccess) scopes['store:counter_order:cancel'] = [storeId]
      if (options.auditor) scopes['store:operation:read'] = [storeId]
      return respond({ permissions: Object.keys(scopes), storeScopes: scopes })
    }
    if (options.denyWrite && method !== 'GET') return route.fulfill({ status: 403, json: { code: 403, message: 'denied' } })
    if (actualPath.endsWith('/counter-operations/counters')) return respond({ items: [{ counter: { uuid: counterId, name: 'Real A01', code: 'A01', capacity: 4, enabled: true }, session: null, ordersVisible: true, orders: [reportingOrder], openAmount: 0, orderedAmount: 1590, openOrderCount: 0, currency: 'CNY', canEnd: true, version, paymentHold: false }], total: 1, page: 1, hasNext: false })
    if (actualPath === `/api/v1/store/${storeId}/counter-operations/counters/${counterId}/orders/cancel` && method === 'POST') {
      expect(body).toMatchObject({ version, orderUuid: orderId, reason: expect.any(String), commandUuid: expect.any(String) })
      reportingOrder = { ...reportingOrder, status: 'cancelled', canCancel: false, canReplace: false }
      return respond({ counterUuid: counterId, sessionUuid: storeId, orderUuid: orderId, status: 'cancelled' })
    }
    if (actualPath === `/api/v1/public/orders/${tradeOrderId}/payment`) return respond({ orderUuid: tradeOrderId, amount: 1590, currency: 'CNY', invoice: { uuid: specId, status: options.partialRefund ? 'partial_refunded' : 'paid', amount: 1590, refundedAmount: options.partialRefund ? 500 : 0, currency: 'CNY', payment: 'wechat' } })
    if (actualPath === `/api/v1/store/${storeId}/trade-orders/${tradeOrderId}/refunds`) return respond(options.partialRefund ? [{ uuid: counterId, tradeOrderUuid: tradeOrderId, invoiceUuid: specId, amount: 500, reason: 'Customer request', status: 'succeeded', actorUuid: storeId, createdAt: '2026-11-05T10:00:00+08:00', invoiceStatus: 'partial_refunded', refundedAmount: 500 }] : [])
    if (actualPath === `/api/v1/store/${storeId}/trade-orders/${tradeOrderId}/refund` && method === 'POST') return respond({ uuid: counterId, tradeOrderUuid: tradeOrderId, invoiceUuid: specId, amount: 500, reason: '顾客申请部分退款', status: 'processing', actorUuid: storeId, createdAt: '2026-11-05T10:00:00+08:00', invoiceStatus: 'paid', refundedAmount: 0 })
    if (path === `/api/v1/store/${storeId}/products/availability`) {
      const ids = (body!.items as { uuid: string }[]).map((item) => item.uuid)
      products = products.map((item) => ids.includes(item.uuid) ? { ...item, isSoldout: Boolean(body!.isSoldout) } : item)
      return route.fulfill({ json: { code: 0, data: { items: products } } })
    }
    if (path === `/api/v1/store/${storeId}/images`) return route.fulfill({ json: { code: 0, data: { uuid: counterId, url: '/uploads/test-image.png' } } })
    if (path === `/api/v1/store/${storeId}/products`) {
      if (method === 'POST') { products.push({ ...body, uuid: '00000000-0000-4000-8000-000000000007', name: String(body!.name), status: String(body!.status), category: body!.category ? { uuid: String(body!.category) } : null }); return respond(products.at(-1)) }
      return respond(options.malformed ? [{ uuid: 'not-a-uuid' }] : products, true)
    }
    const productRecord = path.match(new RegExp(`^/api/v1/store/${storeId}/products/([0-9a-f-]{36})$`))
    if (productRecord) {
      const index = products.findIndex((item) => item.uuid === productRecord[1])
      if (index < 0) return route.fulfill({ status: 404, json: { code: 404 } })
      if (method === 'PUT') {
        const existing = products[index]!
        const updated = { ...existing, ...body }
        if (Object.hasOwn(body!, 'category')) updated.category = body!.category ? { uuid: String(body!.category) } : null
        if (Object.hasOwn(body!, 'subtitle') || Object.hasOwn(body!, 'coverImageUuid')) {
          updated.metadata = { subtitle: body!.subtitle ?? null, cover: body!.coverImageUuid ? '/uploads/test-image.png' : null }
        }
        if (['kitchenPrinterEnable', 'startSaleTime', 'endSaleTime'].some((key) => Object.hasOwn(body!, key))) {
          updated.settings = { kitchenPrinterEnable: body!.kitchenPrinterEnable ?? false, startSaleTime: body!.startSaleTime ?? '', endSaleTime: body!.endSaleTime ?? '' }
        }
        products[index] = updated
        return respond(updated)
      }
      if (method === 'DELETE') { products.splice(index, 1); return route.fulfill({ status: 204 }) }
    }
    if (path.endsWith('/specifications')) {
      if (method === 'POST') { specifications.push({ uuid: specId, name: String(body!.name), price: Number(body!.price), status: String(body!.status), sort: Number(body!.sort) }); return respond(specifications.at(-1)) }
      return respond(specifications, true)
    }
    if (path.endsWith(`/specifications/${specId}`) && method === 'PUT') { specifications = specifications.map((item) => ({ ...item, ...body })); return respond(specifications[0]) }
    if (path.endsWith('/product-categories')) {
      if (method === 'POST') { categories.push({ uuid: categoryId, name: String(body!.name), slug: String(body!.slug), sortOrder: Number(body!.sortOrder), enabled: Boolean(body!.enabled) }); return respond(categories.at(-1)) }
      return respond(categories, true)
    }
    if (path.endsWith(`/product-categories/${categoryId}`) && method === 'PUT') { categories = categories.map((item) => ({ ...item, ...body })); return respond(categories[0]) }
    if (path.endsWith('/counters')) {
      return respond(counters, true)
    }
    if (path.endsWith(`/counters/${counterId}`) && method === 'PUT') { counters = counters.map((item) => ({ ...item, ...body })); return respond(counters[0]) }
    if (path.endsWith('/orders')) return respond([order], true)
    if (path.endsWith('/history')) return respond([{ uuid: productId, actorUuid: storeId, commandUuid: counterId, action: 'product.update', createdAt: '2026-10-30T10:00:00+08:00' }], true)
    if (path.endsWith(`/orders/${orderId}`)) return respond(order)
    if (path.includes(`/orders/${orderId}/`) && method === 'POST') {
      const action = path.split('/').at(-1)!
      order = { ...order, operationalStatus: ({ accept: 'accepted', reject: 'rejected', fulfill: 'fulfilled', verify: 'verified' } as Record<string, string>)[action]!, allowedActions: action === 'accept' ? ['fulfill'] : action === 'fulfill' ? ['verify'] : [] }
      return respond(order)
    }
    return route.fulfill({ status: 404, json: { code: 404 } })
  })
  return requests
}
async function login(page: Page, entry = '/', workspace: 'kitchen' | 'tables' = 'kitchen') {
  await page.goto(entry)
  await page.getByLabel('账号', { exact: true }).fill('contract-staff')
  await page.getByLabel('密码', { exact: true }).fill('test-only-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByRole('button', { name: 'Contract store S1', exact: true }).click()
  const nav = page.getByRole('navigation')
  await expect(nav.or(page.getByRole('alert')).first()).toBeVisible()
  if (!(await nav.isVisible())) return
  const kitchen = nav.getByRole('button', { name: '厨房', exact: true })
  if (workspace === 'kitchen' && await kitchen.count()) {
    await kitchen.click()
    await expect(kitchen).toHaveAttribute('aria-current', 'page')
  }
}

test('live catalog, separate variants, categories, tables and read-only orders use employee APIs', async ({ page }) => {
  const requests = await mockApi(page)
  await login(page)
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('商品名称', { exact: true }).fill('Renamed live product')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Renamed live product', exact: true })).toBeVisible()
  const update = requests.find((request) => request.method === 'PUT')!
  expect(update.path).toBe(`/api/v1/store/${storeId}/operations/products/${productId}`)
  expect(update.body).toMatchObject({ name: 'Renamed live product', status: 'active', category: categoryId, version, commandUuid: expect.any(String), isSoldout: false })
  await page.getByRole('button', { name: '管理规格' }).click()
  await page.getByRole('button', { name: '增加规格' }).click()
  await page.getByLabel('规格名称', { exact: true }).fill('Regular')
  await page.getByLabel('规格价格 (CNY)', { exact: true }).fill('19.90')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Regular', exact: true })).toBeVisible()
  expect(requests.find((request) => request.path.endsWith('/specifications') && request.method === 'POST')?.body?.price).toBe(1990)
  await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).click()
  await page.getByRole('button', { name: '分类管理', exact: true }).click()
  await page.getByRole('button', { name: '新增分类', exact: true }).click()
  await page.getByLabel('分类名称', { exact: true }).fill('New live category')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'New live category', exact: true })).toBeVisible()
  expect(requests.find((request) => request.method === 'POST' && request.path.endsWith('/product-categories'))?.body?.slug).toMatch(/^new-live-category-[a-f0-9]{32}$/)
  await page.getByRole('navigation').getByRole('button', { name: '收银', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Real A01', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '新增桌台', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '删除', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '桌台配置', exact: true }).click()
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('桌台名称', { exact: true }).fill('Real A02')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Real A02', exact: true })).toBeVisible()
  expect(requests.find((request) => request.method === 'PUT' && request.path.endsWith(`/counters/${counterId}`))?.body).toMatchObject({ name: 'Real A02', code: 'A01', capacity: 4 })
  expect(requests.filter((request) => /\/counters(?:\/|$)/.test(request.path) && ['POST', 'DELETE'].includes(request.method))).toHaveLength(0)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await page.getByRole('button', { name: '查看', exact: true }).click()
  await expect(page.getByRole('dialog')).toContainText('paid')
  await expect(page.getByRole('button', { name: '确认结账', exact: true })).toHaveCount(0)
  expect(requests.some((request) => request.path.includes('/manage/') || request.path.includes('/payment') || request.path.includes('/app/orders'))).toBe(false)
  const storage = await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))
  expect(storage).not.toContain('contract-staff')
  expect(storage).not.toContain('test-only-password')
  expect(storage).toContain('ns-store-console.auth.tokens')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
})

test('table configuration downloads ordering QR codes with table names', async ({ page }) => {
  await mockApi(page)
  await login(page, '/', 'tables')
  await expect(page.getByRole('heading', { name: 'Real A01', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '桌台配置', exact: true }).click()
  await expect(page.locator(`canvas[data-table-qr="${counterId}"]`)).toHaveCount(0)
  await page.getByRole('button', { name: '查看二维码', exact: true }).click()
  await expect(page.locator(`canvas[data-table-qr="${counterId}"]`)).toBeVisible()
  await expect(page.getByText('https://ns-ultimate.wscoffee.com.cn/h5/order/index.html?counterId=00000000-0000-4000-8000-000000000006&storeUuid=00000000-0000-4000-8000-000000000001', { exact: true })).toBeVisible()

  const singleDownload = page.waitForEvent('download')
  await page.getByRole('dialog').getByRole('button', { name: '下载二维码', exact: true }).click()
  expect((await singleDownload).suggestedFilename()).toBe('Real A01.png')
  await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).click()

  const batchDownload = page.waitForEvent('download')
  await page.getByRole('button', { name: '下载全部二维码', exact: true }).click()
  expect((await batchDownload).suggestedFilename()).toMatch(/^桌台点餐二维码-\d{4}-\d{2}-\d{2}\.zip$/)
})

test('read-only grants hide catalog editing while sold-out remains directly controllable', async ({ page }) => {
  await mockApi(page, { readOnly: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await expect(page.getByRole('button', { name: '新增商品', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '编辑', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '删除', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '售罄', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '售罄', exact: true }).click()
  await expect(page.getByRole('button', { name: '恢复', exact: true })).toBeVisible()
  await page.reload()
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
})

test('kitchen defaults to the first category and restores sold-out products without product update permission', async ({ page }) => {
  const requests = await mockApi(page, { readOnly: true, soldOut: true })
  await login(page, '/?view=kitchen')
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'API category', exact: true })).toHaveClass(/active/)
  await expect(page.getByRole('button', { name: '全部', exact: true })).not.toHaveClass(/active/)
  const restoreAll = page.getByRole('button', { name: '全部恢复', exact: true })
  await expect(restoreAll).toBeVisible()
  await expect(restoreAll).toBeEnabled()
  await restoreAll.click()
  await expect(page.getByText('在售', { exact: true })).toHaveClass(/is-positive/)
  expect(requests.filter((request) => request.method === 'PUT' && request.path.endsWith(`/operations/products/${productId}`))).toHaveLength(1)
  expect(requests.some((request) => request.path.endsWith('/operations/products/availability'))).toBe(false)
})

test('reports summarize Trade Orders from the deployed counter query', async ({ page }) => {
  const requests = await mockApi(page, { grants: ['store:counter:read', 'store:counter_session:read', 'store:counter_order:read', 'store:order:read'] })
  await login(page, '/', 'tables')
  const dailySummary = page.locator('.dining-daily-summary')
  await expect(dailySummary).toContainText('今日交易额')
  await expect(dailySummary.getByText('总营业额', { exact: true })).toHaveCount(1)
  await expect(dailySummary.getByText('已收金额', { exact: true })).toHaveCount(1)
  await expect(dailySummary.getByText('待收金额', { exact: true })).toHaveCount(1)
  await expect(dailySummary).toContainText('¥15.90')
  await expect(dailySummary).toContainText('¥0.00')
  await page.getByRole('navigation').getByRole('button', { name: '统计', exact: true }).click()
  await expect(page.locator('.report-hero')).toContainText('¥15.90')
  await expect(page.locator('.sales-ranking')).toContainText('API product')
  expect(requests.some((request) => request.path.endsWith('/counter-operations/counters'))).toBe(true)
  expect(requests.some((request) => request.path.endsWith('/operations/reporting-orders'))).toBe(false)
})

test('kitchen-only staff toggle sold-out products with product edit permission', async ({ page }) => {
  const requests = await mockApi(page, { grants: ['store:product:read', 'store:product:update'] })
  await login(page, '/?view=cashier')
  const nav = page.getByRole('navigation')
  await expect(nav.getByRole('button')).toHaveText(['厨房', '我的'])
  await expect(nav.getByRole('button', { name: '厨房', exact: true })).toHaveAttribute('aria-current', 'page')
  for (const name of ['新增商品', '删除', '管理规格', '分类管理', '确认结账']) await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '编辑', exact: true })).toBeVisible()
  await expect(page.locator('.catalog-print-flag')).toHaveText('不打印小票')
  await page.getByRole('button', { name: '售罄', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await expect(page.getByRole('button', { name: '恢复', exact: true })).toBeVisible()
  for (const width of [320, 768, 1280]) {
    await page.setViewportSize({ width, height: 800 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  }
  expect(requests.some((request) => request.path.endsWith('/operations/products/availability'))).toBe(false)
  expect(requests.filter((request) => request.path.startsWith('/api/v1/store/')).every((request) => /\/operations\/products(?:[/?]|$)/.test(request.path))).toBe(true)
})

test('catalog operators always see restore all beside add product', async ({ page }) => {
  await mockApi(page, { grants: ['store:product:read', 'store:product:create'] })
  await login(page, '/?view=kitchen')
  const restoreAll = page.getByRole('button', { name: /全部恢复/ })
  const addProduct = page.getByRole('button', { name: '新增商品', exact: true })
  await expect(restoreAll).toBeVisible()
  await expect(restoreAll).toBeDisabled()
  await expect(addProduct).toBeVisible()
  expect((await restoreAll.boundingBox())!.x).toBeLessThan((await addProduct.boundingBox())!.x)
})

test('kitchen product card toggles availability directly and shows every specification price', async ({ page }) => {
  const requests = await mockApi(page, { kitchenPrint: true, specifications: [
    { uuid: '00000000-0000-4000-8000-000000000008', name: '小份', price: 1290, status: 'active', sort: 1 },
    { uuid: '00000000-0000-4000-8000-000000000009', name: '大份', price: 1690, status: 'active', sort: 0 },
  ] })
  await login(page, '/?view=kitchen')
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  const card = page.locator('.catalog-product-card')
  const addProduct = page.getByRole('button', { name: '新增商品', exact: true })
  const restoreAll = page.getByRole('button', { name: '全部恢复', exact: true })
  await expect(addProduct).toBeVisible()
  await expect(restoreAll).toBeVisible()
  expect((await restoreAll.boundingBox())!.x).toBeLessThan((await addProduct.boundingBox())!.x)
  await expect(restoreAll).toBeDisabled()
  await expect(card.getByRole('button', { name: '编辑', exact: true })).toBeVisible()
  await expect(card.getByRole('button', { name: '删除', exact: true })).toHaveCount(0)
  await expect(card.locator('.catalog-product-actions > button')).toHaveText(['编辑', '管理规格', '售罄'])
  await expect(card.locator('.catalog-print-flag')).toHaveText('打印小票')
  await expect(card.locator('.catalog-product-price')).toHaveText([/大份\s*¥16\.90/, /小份\s*¥12\.90/])
  await expect(card.getByText('在售', { exact: true })).toHaveClass(/is-positive/)
  await card.getByRole('button', { name: '售罄', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await expect(card.getByText('已售罄', { exact: true })).toHaveClass(/is-negative/)
  await expect(card.getByRole('button', { name: '恢复', exact: true })).toBeVisible()
  await expect(restoreAll).toBeEnabled()
  await restoreAll.click()
  await expect(card.getByText('在售', { exact: true })).toHaveClass(/is-positive/)
  expect(requests.filter((request) => request.method === 'PUT' && request.path.endsWith(`/operations/products/${productId}`)).map((request) => request.body)).toEqual([
    { commandUuid: expect.any(String), version, isSoldout: true },
    { commandUuid: expect.any(String), version, isSoldout: false },
  ])
  for (const width of [320, 390, 768, 1280]) {
    await page.setViewportSize({ width, height: 800 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  }
})

test('cashier staff land on dining without product configuration, fulfillment or payment', async ({ page }) => {
  const requests = await mockApi(page, { grants: ['store:counter_session:read', 'store:counter_session:update', 'store:counter_session:end', 'store:counter_order:read', 'store:counter_order:create', 'store:counter_order:replace', 'store:order:read'] })
  await page.route('**/counter-operations/counters?**', (route) => route.fulfill({ json: { code: 0, data: { items: [], total: 0, page: 1, hasNext: false } } }))
  await login(page, '/?view=kitchen', 'tables')
  const nav = page.getByRole('navigation')
  await expect(nav.getByRole('button')).toHaveText(['收银', '交易订单', '统计', '我的'])
  await expect(nav.getByRole('button', { name: '收银', exact: true })).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('heading', { name: '安排好每一张桌台', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '桌台配置', exact: true })).toHaveCount(0)
  await nav.getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await expect(page.getByRole('heading', { name: '静候下一笔好生意', exact: true })).toBeVisible()
  for (const name of ['接单', '拒单', '完成履约', '核销', '确认结账']) await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0)
  expect(requests.some((request) => /products|specifications|payment|refund/.test(request.path))).toBe(false)
})

test('dual workspace grants land on dining and allow switching to the kitchen', async ({ page }) => {
  await mockApi(page, { grants: ['store:product:read', 'store:counter_session:read'] })
  await page.route('**/counter-operations/counters?**', (route) => route.fulfill({ json: { code: 0, data: { items: [], total: 0, page: 1, hasNext: false } } }))
  await login(page, '/?view=kitchen', 'tables')
  const nav = page.getByRole('navigation')
  await expect(nav.getByRole('button', { name: '收银', exact: true })).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('heading', { name: '安排好每一张桌台', exact: true })).toBeVisible()
  await nav.getByRole('button', { name: '厨房', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
})

test('reconnecting a store discards the selected workspace and uses fresh grants', async ({ page }) => {
  const grants = ['store:product:read', 'store:counter:read']
  const requests = await mockApi(page, { grants })
  await login(page, '/?view=kitchen')
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await page.getByRole('button', { name: '切换门店', exact: true }).click()
  grants.splice(0, grants.length, 'store:order:read')
  const before = requests.length
  await page.getByRole('button', { name: 'Contract store S1', exact: true }).click()
  await expect(page.getByRole('navigation')).toBeVisible()
  await expect(page.getByRole('navigation').getByRole('button')).toHaveText(['统计', '我的'])
  await expect(page.locator('.report-hero')).toBeVisible()
  expect(requests.slice(before).some((request) => /products/.test(request.path))).toBe(false)
})

test('another store scopes and job labels cannot unlock a workspace through its URL', async ({ page }) => {
  const requests = await mockApi(page)
  await page.route('**/api/v1/app/users/me', (route) => route.fulfill({ json: { code: 0, data: { uuid: storeId, username: 'contract-staff', roles: ['ROLE_USER', 'store_counter_session_operator', 'store_counter_order_editor'] } } }))
  await page.route('**/api/v1/app/authorization/me', (route) => route.fulfill({ json: { code: 0, data: { permissions: ['store:product:read', 'store:counter_session:read'], storeScopes: { 'store:product:read': [productId], 'store:counter_session:read': [productId] } } } }))
  await login(page, '/?view=cashier')
  await expect(page.getByRole('navigation').getByRole('button')).toHaveText(['我的'])
  await expect(page.getByText('当前门店未授权厨房、桌台或订单功能', { exact: false })).toBeVisible()
  expect(requests.some((request) => request.path.startsWith('/api/v1/store/'))).toBe(false)
})

test('the customer payment entry ignores staff view preferences and never calls staff APIs', async ({ page }) => {
  const requests = await mockApi(page)
  await page.goto('/payment.html?view=cashier')
  await expect(page.getByRole('navigation')).toHaveCount(0)
  await expect(page.getByLabel('账号', { exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '确认付款', exact: true })).toHaveCount(0)
  expect(requests).toEqual([])
})

test('catalog operating fields and raster image uploads save through one versioned staff command', async ({ page }) => {
  const requests = await mockApi(page)
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('商品副标题', { exact: true }).fill('Lunch special')
  await page.getByLabel('商品描述', { exact: true }).fill('Freshly prepared')
  await page.locator('.resource-editor input[type=file]').setInputFiles({ name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', 'base64') })
  await page.getByRole('switch', { name: '后厨打印标记', exact: true }).click()
  await page.locator('.editor-advanced summary').click()
  await page.getByLabel('排序值', { exact: true }).fill('5')
  await page.getByLabel('推荐等级', { exact: true }).fill('2')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  const save = requests.find((request) => request.method === 'PUT')!
  expect(save.body).toMatchObject({ subtitle: 'Lunch special', description: 'Freshly prepared', sortOrder: 5, recommendationLevel: 2, kitchenPrinterEnable: true, startSaleTime: '', endSaleTime: '', coverImageUuid: counterId, version, commandUuid: expect.any(String) })
  const imageUpload = requests.find((request) => request.path.endsWith('/operations/images'))
  expect(imageUpload).toBeDefined()
  expect(imageUpload?.rawBody).toContain('name="storage"\r\n\r\nqiniu')
  await expect(page.locator('.catalog-product-info')).toContainText('Lunch special')
})

test('product editor previews the existing cover image', async ({ page }) => {
  await mockApi(page, { productCover: '/uploads/test-image.png' })
  await page.route('**/uploads/**', (route) => route.fulfill({
    status: 200,
    contentType: 'image/png',
    body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', 'base64'),
  }))
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  const preview = page.locator('.product-image-preview img')
  await expect(preview).toBeVisible()
  await expect(preview).toHaveAttribute('src', '/uploads/test-image.png')
})

test('restore all updates every sold-out product through the product edit endpoint', async ({ page }) => {
  const requests = await mockApi(page, { grants: ['store:product:read', 'store:product:update'], productCount: 31, soldOut: true })
  await login(page, '/?view=kitchen')
  await expect(page.locator('.catalog-product-card')).toHaveCount(31)
  const restoreAll = page.getByRole('button', { name: '全部恢复', exact: true })
  await expect(restoreAll).toBeEnabled()
  await restoreAll.click()
  await expect(page.getByText('已售罄', { exact: true })).toHaveCount(0)
  await expect(restoreAll).toBeDisabled()
  const updates = requests.filter((request) => request.method === 'PUT' && request.path.includes('/operations/products/')).map((request) => request.body!)
  expect(updates).toHaveLength(31)
  expect(updates.every((body) => body.isSoldout === false && body.version === version && typeof body.commandUuid === 'string')).toBe(true)
  expect(requests.some((request) => request.path.endsWith('/operations/products/availability'))).toBe(false)
})

test('availability conflicts remain visible without hiding the product form', async ({ page }) => {
  await mockApi(page)
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await page.route('**/operations/products/*', (route) => route.fulfill({ status: 409, json: { code: 409, message: 'stale_record' } }))
  await page.getByRole('button', { name: '售罄', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('记录已变化')
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('商品名称', { exact: true }).fill('Conflict input')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('记录已变化')
  await expect(page.getByLabel('商品名称', { exact: true })).toHaveValue('Conflict input')
})

test('order details expose operational state without fulfillment or payment commands', async ({ page }) => {
  const requests = await mockApi(page, { orderOperations: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await page.getByRole('button', { name: '查看', exact: true }).click()
  await expect(page.getByRole('dialog')).toContainText('API product')
  await expect(page.getByRole('dialog')).toContainText('paid')
  for (const name of ['接单', '拒单', '完成履约', '核销', '确认结账']) await expect(page.getByRole('dialog').getByRole('button', { name, exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '确认结账', exact: true })).toHaveCount(0)
  expect(requests.some((request) => /payment|refund|manage/.test(request.path))).toBe(false)
})

test('trade orders default to paid and refunded and filter by table and date', async ({ page }) => {
  await mockApi(page, { orderOperations: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()

  const filters = page.locator('.trade-order-filters')
  const paid = filters.getByRole('button', { name: '已付款', exact: true })
  const refunded = filters.getByRole('button', { name: '已退款', exact: true })
  const confirmed = filters.getByRole('button', { name: '已确认', exact: true })
  await expect(paid).toHaveAttribute('aria-pressed', 'true')
  await expect(refunded).toHaveAttribute('aria-pressed', 'true')
  await expect(confirmed).toHaveAttribute('aria-pressed', 'false')
  await expect(page.locator('.workspace-order-list article')).toHaveCount(1)

  const table = filters.getByLabel('台号', { exact: true })
  await expect(table.getByRole('option', { name: 'A01', exact: true })).toHaveCount(1)
  await table.selectOption('A01')
  await expect(page.locator('.workspace-order-list article')).toHaveCount(1)

  const date = filters.getByLabel('交易日期', { exact: true })
  await date.fill('2026-11-08')
  await expect(page.getByRole('heading', { name: '没有匹配的记录', exact: true })).toBeVisible()
  await expect(page.locator('.workspace-order-list article')).toHaveCount(0)
  await date.fill('2026-11-07')
  await expect(page.locator('.workspace-order-list article')).toHaveCount(1)

  await paid.click()
  await expect(paid).toHaveAttribute('aria-pressed', 'false')
  await expect(page.locator('.workspace-order-list article')).toHaveCount(0)
  await filters.getByRole('button', { name: '重置筛选', exact: true }).click()
  await expect(paid).toHaveAttribute('aria-pressed', 'true')
  await expect(refunded).toHaveAttribute('aria-pressed', 'true')
  await expect(table).toHaveValue('')
  await expect(date).toHaveValue('')
  await expect(page.locator('.workspace-order-list article')).toHaveCount(1)
})

test('confirmed orders can be cancelled from the trade order list', async ({ page }) => {
  const requests = await mockApi(page, { confirmedOrder: true, cancelAccess: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await page.locator('.trade-order-filters').getByRole('button', { name: '已确认', exact: true }).click()

  const card = page.locator('.workspace-order-list article')
  await expect(card).toContainText('已确认')
  await card.getByRole('button', { name: '取消订单', exact: true }).click()

  const dialog = page.getByRole('dialog', { name: '确认取消这笔订单？' })
  await expect(dialog.getByLabel('取消原因')).toHaveValue('收银端取消已确认订单')
  await dialog.getByRole('button', { name: '确认取消', exact: true }).click()

  await expect.poll(() => requests.filter((request) => request.path.endsWith('/orders/cancel')).length).toBe(1)
  expect(requests.find((request) => request.path.endsWith('/orders/cancel'))?.body).toEqual({
    version,
    orderUuid: orderId,
    reason: '收银端取消已确认订单',
    commandUuid: expect.any(String),
  })
  await expect(card).toHaveCount(0)
})

test('trade order cancellation is disabled without the cancel grant', async ({ page }) => {
  await mockApi(page, { confirmedOrder: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await page.locator('.trade-order-filters').getByRole('button', { name: '已确认', exact: true }).click()

  const card = page.locator('.workspace-order-list article')
  await expect(card).toContainText('已确认')
  const cancel = card.getByRole('button', { name: '当前账号缺少取消订单权限，请联系管理员开通。', exact: true })
  await expect(cancel).toBeVisible()
  await expect(cancel).toBeDisabled()
  await card.getByRole('button', { name: '查看', exact: true }).click()
  await expect(page.getByRole('dialog', { name: '订单明细', exact: true }).getByRole('button', { name: '当前账号缺少取消订单权限，请联系管理员开通。', exact: true })).toBeDisabled()
})

test('partial refund visibility shows confirmed amounts and read-only history on the paid order', async ({ page }) => {
  const requests = await mockApi(page, { refundAccess: true, partialRefund: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await expect(page.locator('.workspace-order-list article')).toContainText('部分退款')
  await page.getByRole('button', { name: '查看', exact: true }).click()
  const details = page.locator('.order-refund-visibility')
  await expect(details).toContainText('部分退款')
  await expect(details).toContainText('¥15.90')
  await expect(details).toContainText('¥5.00')
  await expect(details).toContainText('¥10.90')
  await expect(details).toContainText('Customer request')
  await expect(details).toContainText('退款完成')
  await details.getByRole('button', { name: '刷新退款详情', exact: true }).click()
  await expect(details).toContainText('Customer request')
  expect(requests.filter((request) => request.path.endsWith('/refunds')).length).toBeGreaterThanOrEqual(2)
  expect(requests.some((request) => request.method === 'POST' && request.path.endsWith('/refund'))).toBe(false)
})

test('paid orders support a partial store refund with an entered amount', async ({ page }) => {
  const requests = await mockApi(page, { refundAccess: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await page.locator('.workspace-order-list article').getByRole('button', { name: '退款', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toContainText('可退金额')
  await expect(dialog).toContainText('¥15.90')
  await dialog.getByPlaceholder('请输入退款金额').fill('5.00')
  await dialog.getByPlaceholder('请输入退款原因').fill('顾客申请部分退款')
  const submit = dialog.getByRole('button', { name: '确认退款', exact: true })
  await submit.click()
  await expect(dialog).toContainText('确认退款 ¥5.00？')
  await dialog.getByRole('button', { name: '确认', exact: true }).click()
  await expect(dialog).toContainText('退款申请已提交')
  await dialog.getByRole('button', { name: '确认', exact: true }).click()
  expect(requests.filter((request) => request.path === `/api/v1/public/orders/${tradeOrderId}/payment`).length).toBeGreaterThanOrEqual(1)
  expect(requests.find((request) => request.path === `/api/v1/store/${storeId}/trade-orders/${tradeOrderId}/refund` && request.method === 'POST')?.body).toEqual({
    amount: 500,
    reason: '顾客申请部分退款',
    idempotencyKey: expect.any(String),
  })
})

test('auditors can inspect successful store operations without receiving financial actions', async ({ page }) => {
  await mockApi(page, { readOnly: true, auditor: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '我的', exact: true }).click()
  await page.getByRole('button', { name: '操作记录', exact: true }).click()
  await expect(page.getByRole('dialog')).toContainText('product.update')
  await expect(page.getByRole('dialog')).toContainText(storeId)
  await expect(page.getByRole('dialog')).toContainText('不是所有历史 CRUD 或财务流水')
})

test('an empty product response never substitutes demo products', async ({ page }) => {
  await mockApi(page)
  let productRequests = 0
  await page.route('**/api/v1/store/**/products?**', (route) => {
    productRequests++
    return operationalList(route, [])
  })
  await login(page)
  await expect(page.getByRole('heading', { name: '从第一件商品开始', exact: true })).toBeVisible()
  await expect(page.getByText('鲜切吊龙', { exact: true })).toHaveCount(0)
  expect(productRequests).toBeGreaterThan(0)
})

test('inactive membership cannot enter a store and never enrolls automatically', async ({ page }) => {
  const requests = await mockApi(page, { membership: 'suspended' })
  await login(page)
  await expect(page.getByRole('alert')).toContainText('需要有效门店成员身份')
  expect(requests.some((request) => request.method === 'POST' && request.path.endsWith('/membership'))).toBe(false)
  expect(requests.some((request) => request.path.startsWith('/api/v1/store/'))).toBe(false)
})

test('missing store membership reports its specific connection step and HTTP status', async ({ page }) => {
  const requests = await mockApi(page)
  await page.route('**/api/v1/app/stores/*/membership', (route) => route.fulfill({ status: 404, json: { code: 404, message: 'Membership not found.' } }))
  await login(page)
  await expect(page.getByRole('alert')).toContainText('需要有效门店成员身份')
  await expect(page.getByRole('alert')).toContainText('门店成员检查 · HTTP 404')
  await expect(page.getByRole('navigation')).toHaveCount(0)
  expect(requests.some((request) => request.method === 'POST' && request.path.endsWith('/membership'))).toBe(false)
})

test('empty PHP authorization scopes connect safely without granting employee permissions', async ({ page }) => {
  const requests = await mockApi(page)
  await page.route('**/api/v1/app/authorization/me', (route) => route.fulfill({ json: { code: 0, data: { permissions: [], storeScopes: [] } } }))
  await login(page)
  await expect(page.getByRole('navigation')).toBeVisible()
  await expect(page.getByRole('navigation').getByRole('button')).toHaveCount(1)
  await expect(page.getByText('当前门店未授权厨房、桌台或订单功能', { exact: false })).toBeVisible()
  expect(requests.some((request) => request.path.startsWith('/api/v1/store/'))).toBe(false)
})

test('server write denial keeps the form and does not display optimistic success', async ({ page }) => {
  await mockApi(page, { denyWrite: true })
  await login(page)
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('商品名称', { exact: true }).fill('Denied update')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('没有此项门店权限')
  await expect(page.getByLabel('商品名称', { exact: true })).toHaveValue('Denied update')
  await expect(page.getByRole('button', { name: '保存', exact: true })).toBeEnabled()
  await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
})

test('all seven themes change rendered surfaces, controls and portal editors and survive a reload', async ({ page }) => {
  await mockApi(page)
  await login(page)
  const samples: string[][] = []
  for (const [theme, label] of [['red', '红'], ['orange', '橙'], ['yellow', '黄'], ['green', '绿'], ['blue', '蓝'], ['indigo', '靛'], ['purple', '紫']]) {
    await page.getByRole('navigation').getByRole('button', { name: '我的', exact: true }).click()
    const option = page.getByRole('group', { name: '配色方案' }).getByRole('button', { name: label, exact: true })
    await option.click()
    await expect(option).toHaveAttribute('aria-pressed', 'true')
    await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
    const soft = await page.locator('.account-store-card').evaluate((element) => getComputedStyle(element).backgroundColor)
    await expect(page.locator('.workspace-nav button[aria-current] > span')).toHaveCSS('background-color', soft)
    const colors = await page.evaluate(() => ['.account-store-card', '.profile-monogram', '.live-app', '.workspace-logo', '.workspace-nav button[aria-current] > span'].map((selector) => getComputedStyle(document.querySelector(selector)!).backgroundColor))
    if (theme === 'orange' || theme === 'blue') await page.screenshot({ path: `test-results/theme-${theme}-account.png` })
    await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
    await page.getByRole('button', { name: '编辑', exact: true }).click()
    await expect(page.getByRole('dialog')).toBeVisible()
    colors.push(...await page.evaluate(() => ['.resource-editor', '.editor-preview', '.editor-toggle button', '.editor-footer .adm-button-primary', '.resource-editor input'].map((selector) => getComputedStyle(document.querySelector(selector)!).backgroundColor)))
    if (theme === 'orange' || theme === 'blue') await page.screenshot({ path: `test-results/theme-${theme}-editor.png` })
    samples.push(colors)
    await page.getByRole('button', { name: '取消', exact: true }).click()
  }
  // Each surface must actually change for every theme; checking --brand alone missed the regression.
  for (let index = 0; index < samples[0]!.length; index++) expect(new Set(samples.map((colors) => colors[index])).size, `Surface ${index}: ${JSON.stringify(samples.map((colors) => colors[index]))}`).toBe(7)
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'purple')
  await expect(page.getByRole('navigation')).toBeVisible()
  await page.getByRole('navigation').getByRole('button', { name: '我的', exact: true }).click()
  await expect(page.getByRole('button', { name: '紫', exact: true })).toHaveAttribute('aria-pressed', 'true')
})

test('shared editor validates collapsed advanced fields and traps and restores keyboard focus', async ({ page }) => {
  const requests = await mockApi(page)
  await login(page)
  const edit = page.getByRole('button', { name: '编辑', exact: true })
  await edit.click()
  const dialog = page.getByRole('dialog')
  const save = dialog.getByRole('button', { name: '保存', exact: true })
  const close = dialog.getByRole('button', { name: '关闭', exact: true })
  await save.focus()
  await page.keyboard.press('Tab')
  await expect(close).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(save).toBeFocused()
  const advanced = dialog.locator('.editor-advanced summary')
  await advanced.click()
  await page.getByLabel('所属分类 UUID', { exact: true }).fill('invalid-uuid')
  await advanced.click()
  await save.click()
  await expect(dialog.locator('.editor-advanced')).toHaveAttribute('open', '')
  await expect(page.getByLabel('所属分类 UUID', { exact: true })).toBeFocused()
  expect(requests.filter((request) => request.method === 'PUT')).toHaveLength(0)
  await page.getByLabel('所属分类 UUID', { exact: true }).fill(categoryId)
  await advanced.click()
  await dialog.getByRole('switch', { name: '后厨打印标记', exact: true }).focus()
  await page.keyboard.press('Tab')
  await expect(advanced).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(edit).toBeFocused()
})

test('pending saves lock fields, cancellation and repeated submission without discarding failed input', async ({ page }) => {
  await mockApi(page)
  let release!: () => void
  const gate = new Promise<void>((resolve) => { release = resolve })
  let writes = 0
  await page.route(`**/api/v1/store/${storeId}/operations/products/${productId}`, async (route) => {
    writes++
    await gate
    await route.fulfill({ status: 403, json: { code: 403 } })
  })
  await login(page)
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('商品名称', { exact: true }).fill('Keep my changes')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByLabel('商品名称', { exact: true })).toBeDisabled()
  await expect(page.getByRole('button', { name: '取消', exact: true })).toBeDisabled()
  await expect(page.getByRole('button', { name: '关闭', exact: true })).toHaveCount(0)
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.locator('.resource-editor form').evaluate((form: HTMLFormElement) => { form.requestSubmit(); form.requestSubmit() })
  release()
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('没有此项门店权限')
  expect(writes).toBe(1)
  await expect(page.getByLabel('商品名称', { exact: true })).toHaveValue('Keep my changes')
  await expect(page.getByRole('button', { name: '取消', exact: true })).toBeEnabled()
  await page.getByRole('button', { name: '取消', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
})

test('category and specification edits preserve payloads and table seating controls support blank and bounded values', async ({ page }) => {
  const requests = await mockApi(page)
  await login(page)
  await page.getByRole('button', { name: '分类管理', exact: true }).click()
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('分类名称', { exact: true }).fill('Updated category')
  await page.getByRole('switch', { name: '启用', exact: true }).click()
  await page.locator('.editor-advanced summary').click()
  await page.getByLabel('排序值', { exact: true }).fill('7')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  expect(requests.find((request) => request.method === 'PUT')?.body).toEqual({ name: 'Updated category', slug: 'api-category', sortOrder: 7, enabled: false })
  await page.locator('.workspace-tabs').getByRole('button', { name: '商品', exact: true }).click()
  await page.getByRole('button', { name: '管理规格', exact: true }).click()
  await page.getByRole('button', { name: '增加规格', exact: true }).click()
  await page.getByLabel('规格名称', { exact: true }).fill('Regular')
  await page.getByLabel('规格价格 (CNY)', { exact: true }).fill('9.90')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('规格价格 (CNY)', { exact: true }).fill('12.30')
  await page.getByRole('switch', { name: '上下架状态', exact: true }).click()
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(1)
  expect(requests.find((request) => request.method === 'PUT' && request.path.endsWith(`/specifications/${specId}`))?.body).toEqual({ name: 'Regular', price: 1230, status: 'inactive', sort: 0 })
  await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).click()
  await page.getByRole('navigation').getByRole('button', { name: '收银', exact: true }).click()
  await page.getByRole('button', { name: '桌台配置', exact: true }).click()
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('桌台名称', { exact: true }).fill('Blank capacity')
  await page.getByLabel('桌台编号', { exact: true }).fill('B01')
  await page.getByLabel('座位数', { exact: true }).fill('')
  await expect(page.getByRole('button', { name: '减少座位', exact: true })).toBeDisabled()
  await page.getByRole('button', { name: '增加座位', exact: true }).click()
  await expect(page.getByLabel('座位数', { exact: true })).toHaveValue('1')
  await page.getByLabel('座位数', { exact: true }).fill('100')
  await expect(page.getByRole('button', { name: '增加座位', exact: true })).toBeDisabled()
  await page.getByLabel('座位数', { exact: true }).fill('')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  expect(requests.find((request) => request.method === 'PUT' && request.path.endsWith(`/counters/${counterId}`))?.body).toEqual({ name: 'Blank capacity', code: 'B01', capacity: null, sort: 0, enabled: true })
  expect(requests.filter((request) => /\/counters(?:\/|$)/.test(request.path) && ['POST', 'DELETE'].includes(request.method))).toHaveLength(0)
})

test('every resource editor keeps fixed actions reachable on small phones and centered on desktop', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await mockApi(page)
  await login(page)
  for (const width of [320, 390, 768, 1280]) {
    await page.setViewportSize({ width, height: 740 })
    const inspect = async (resource: string) => {
      const sheet = page.locator('.resource-editor')
      const save = sheet.getByRole('button', { name: '保存', exact: true })
      await expect(save).toBeInViewport()
      const bounds = await sheet.boundingBox()
      expect(bounds!.x).toBeGreaterThanOrEqual(0)
      expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width)
      if (width >= 700) expect(Math.abs(bounds!.y + bounds!.height / 2 - 370)).toBeLessThan(2)
      await page.screenshot({ path: `test-results/editor-${resource}-${width}.png` })
      await sheet.locator('.sheet-scroll').evaluate((element) => { element.scrollTop = element.scrollHeight })
      await expect(save).toBeInViewport()
      expect(await sheet.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
      await sheet.getByRole('button', { name: '取消', exact: true }).click()
    }
    await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
    await page.getByRole('button', { name: '新增商品', exact: true }).click()
    await inspect('product')
    await page.getByRole('button', { name: '管理规格', exact: true }).click()
    await page.getByRole('button', { name: '增加规格', exact: true }).click()
    await inspect('specification')
    await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).click()
    await page.getByRole('button', { name: '分类管理', exact: true }).click()
    await page.getByRole('button', { name: '新增分类', exact: true }).click()
    await inspect('category')
    await page.getByRole('navigation').getByRole('button', { name: '收银', exact: true }).click()
    await page.getByRole('button', { name: '桌台配置', exact: true }).click()
    await page.getByRole('button', { name: '编辑', exact: true }).click()
    await inspect('table')
  }
})

test('malformed records show a contract error instead of demo products', async ({ page }) => {
  await mockApi(page, { malformed: true })
  await login(page)
  await expect(page.getByRole('alert')).toContainText('后端响应不符合预期')
  await expect(page.getByText('鲜切吊龙', { exact: true })).toHaveCount(0)
})

test('expired refresh returns to login and removes loaded store data', async ({ page }) => {
  await mockApi(page)
  await login(page)
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
  await page.route('**/api/v1/store/**/products?**', (route) => route.fulfill({ status: 401, json: { code: 401 } }))
  await page.route('**/api/auth/token/refresh', (route) => route.fulfill({ status: 401, json: { code: 401 } }))
  await page.getByRole('button', { name: '刷新', exact: true }).click()
  await expect(page.getByRole('heading', { name: '登录', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toHaveCount(0)
})

test('demo entry stays independent and never sends staff API requests', async ({ page }) => {
  const requests = await mockApi(page)
  await page.goto('/?mode=demo')
  await expect(page.getByRole('heading', { name: '把每一份用心，做好。' })).toBeVisible()
  expect(requests).toEqual([])
})

test('live lists load every backend page and clear data on store switching and logout', async ({ page }) => {
  const requests = await mockApi(page)
  const productPages: number[] = []
  await page.route('**/api/v1/store/**/products?**', (route) => {
    const pageNumber = Number(new URL(route.request().url()).searchParams.get('page'))
    productPages.push(pageNumber)
    return operationalList(route, [{ uuid: productId, name: `Live page ${pageNumber}`, category: null, status: 'active' }], pageNumber === 1)
  })
  await login(page)
  await expect(page.getByRole('heading', { name: 'Live page 1', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Live page 2', exact: true })).toBeVisible()
  expect([...new Set(productPages)]).toEqual([1, 2])
  await page.getByRole('button', { name: '切换门店', exact: true }).click()
  await expect(page.getByRole('heading', { name: '选择门店', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Live page 1', exact: true })).toHaveCount(0)
  await expect(page.getByRole('heading', { name: 'Live page 2', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '退出登录', exact: true }).click()
  await expect(page.getByRole('heading', { name: '登录', exact: true })).toBeVisible()
  await expect.poll(() => requests.filter((request) => request.path === '/api/auth/logout').length).toBe(1)
})

test('late responses from a discarded store view cannot repopulate it', async ({ page }) => {
  await mockApi(page)
  await login(page)
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
  let release!: () => void
  const gate = new Promise<void>((resolve) => { release = resolve })
  const requested = page.waitForRequest('**/api/v1/store/**/products?**')
  await page.route('**/api/v1/store/**/products?**', async (route) => {
    await gate
    await operationalList(route, [{ uuid: productId, name: 'Late old record', category: null, status: 'active' }])
  })
  await page.getByRole('button', { name: '刷新', exact: true }).click()
  await requested
  await page.getByRole('button', { name: '切换门店', exact: true }).click()
  const returned = page.waitForResponse('**/api/v1/store/**/products?**')
  release()
  await returned
  await expect(page.getByRole('heading', { name: '选择门店', exact: true })).toBeVisible()
  await expect(page.getByText('Late old record', { exact: true })).toHaveCount(0)
})

test('redesigned workspace remains usable across phone, tablet and desktop layouts', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await mockApi(page)
  await page.route('**/api/v1/store/**/products?**', (route) => operationalList(route, [
    { uuid: productId, name: 'Fresh-cut beef', category: null, status: 'active' },
    { uuid: '00000000-0000-4000-8000-000000000012', name: 'Garden vegetables', category: null, status: 'active' },
    { uuid: '00000000-0000-4000-8000-000000000013', name: 'House broth', category: null, status: 'inactive' },
  ]))
  await page.route(`**/api/v1/store/${storeId}/operations/counters?**`, (route) => operationalList(route, Array.from({ length: 4 }, (_, index) => ({ uuid: `00000000-0000-4000-8000-00000000002${index}`, name: `Table A0${index + 1}`, code: `LONG-TABLE-CONFIGURATION-CODE-${index + 1}`, capacity: 4, sort: index, enabled: index < 3 }))))
  await page.goto('/')
  await expect(page.getByRole('heading', { name: '登录', exact: true })).toBeVisible()
  await page.screenshot({ path: 'test-results/ui-login.png', fullPage: true })
  await page.getByLabel('账号', { exact: true }).fill('contract-staff')
  await page.getByLabel('密码', { exact: true }).fill('test-only-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  const choice = page.getByRole('button', { name: 'Contract store S1', exact: true })
  await expect(choice).toBeVisible()
  await page.screenshot({ path: 'test-results/ui-store-selection.png', fullPage: true })
  await choice.click()
  const nav = page.getByRole('navigation')
  await expect(nav).toBeVisible()
  await nav.getByRole('button', { name: '厨房', exact: true }).click()
  await expect(page.locator('.catalog-product-card')).toHaveCount(3)
  await expect.poll(() => page.evaluate(() => scrollY)).toBe(0)
  await expect(page.locator('.record-identity dd').first()).not.toBeVisible()
  await expect(page.getByRole('heading', { name: 'House broth', exact: true })).toBeVisible()
  await page.screenshot({ path: 'test-results/ui-catalog.png', fullPage: true })
  await page.getByRole('button', { name: '编辑', exact: true }).first().click()
  await expect(page.getByRole('dialog').getByRole('combobox', { name: '所属分类', exact: true })).toHaveValue('')
  await page.screenshot({ path: 'test-results/ui-product-form.png', fullPage: true })
  await expect(page.getByRole('dialog')).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog')).toHaveCount(0)
  for (const width of [320, 390, 768, 1280]) {
    await page.setViewportSize({ width, height: 900 })
    for (const name of ['厨房', '收银', '门店订单（只读）', '统计', '我的']) {
      await page.getByRole('navigation').getByRole('button', { name, exact: true }).click()
      await expect(page.locator('.workspace-skeleton')).toHaveCount(0)
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
      const bounds = await page.getByRole('navigation').getByRole('button', { name, exact: true }).boundingBox()
      expect(bounds!.height).toBeGreaterThanOrEqual(44)
      if (width === 390) await page.screenshot({ path: `test-results/ui-${name === '收银' ? 'tables' : name === '我的' ? 'account' : name === '厨房' ? 'products' : 'orders'}.png`, fullPage: true })
    }
  }
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await expect(page.locator('.catalog-product-card')).toHaveCount(3)
  await page.screenshot({ path: 'test-results/ui-desktop.png', fullPage: true })
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await page.getByRole('button', { name: '查看', exact: true }).click()
  await expect(page.getByRole('dialog')).toContainText('API product')
  await expect(page.getByRole('dialog').locator('pre')).not.toBeVisible()
  expect(errors).toEqual([])
})
