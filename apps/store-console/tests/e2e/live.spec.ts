import { expect, test, type Page, type Route } from '@playwright/test'

const storeId = '00000000-0000-4000-8000-000000000001'
const productId = '00000000-0000-4000-8000-000000000002'
const categoryId = '00000000-0000-4000-8000-000000000003'
const orderId = '00000000-0000-4000-8000-000000000004'
const specId = '00000000-0000-4000-8000-000000000005'
const counterId = '00000000-0000-4000-8000-000000000006'
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

async function mockApi(page: Page, options: { readOnly?: boolean; membership?: string; malformed?: boolean; denyWrite?: boolean; orderOperations?: boolean; auditor?: boolean; counterAccess?: boolean; grants?: string[]; counterOrders?: Record<string, unknown>[]; mergeCounterOrders?: boolean; legacyProducts?: Record<string, unknown>[]; diningModes?: Record<string, unknown> } = {}) {
  const requests: { path: string; method: string; body: Record<string, unknown> | null; storeCode?: string }[] = []
  let products: { uuid: string; name: string; category: { uuid: string } | null; status: string; [key: string]: unknown }[] = [{ uuid: productId, name: 'API product', category: { uuid: categoryId }, status: 'active' }]
  let categories = [{ uuid: categoryId, name: 'API category', slug: 'api-category', sortOrder: 0, enabled: true }]
  let specifications: { uuid: string; name: string; price: number; status: string; sort: number }[] = []
  let counters: { uuid: string; name: string; code: string; capacity: number | null; sort: number; enabled: boolean }[] = [{ uuid: counterId, name: 'Real A01', code: 'A01', capacity: 4, sort: 0, enabled: true }]
  let counterOrders = structuredClone(options.counterOrders ?? [])
  let order = { uuid: orderId, tradeOrderUuid: orderId, operationalStatus: 'pending_validation', totalAmount: 1590, currency: 'CNY', createdAt: '2026-10-30T10:00:00+08:00', orderSnapshot: { items: [{ productName: 'API product', quantity: 1 }] }, allowedActions: options.orderOperations ? ['accept', 'reject'] : [], verificationRequired: true }
  const storePayload = options.diningModes ? { ...store, settings: { diningModes: options.diningModes } } : store
  await page.route('**/api/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const actualPath = url.pathname
    const path = actualPath.replace('/operations/', '/')
    const method = request.method()
    const body = !request.postData() || request.headers()['content-type']?.startsWith('multipart/') ? null : request.postDataJSON() as Record<string, unknown> | null
    requests.push({ path: actualPath, method, body, storeCode: request.headers()['x-store-code'] })
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
    if (path === '/api/v1/app/stores') return respond([storePayload], true)
    if (path === `/api/v1/app/stores/${storeId}`) return respond(storePayload)
    if (path === '/api/v1/app/products') return respond(options.legacyProducts ?? [], true)
    if (path === '/api/v1/app/orders' && method === 'POST') return respond({ uuid: orderId, ...body })
    if (path.startsWith('/api/v1/app/specifications/')) {
      const specificationUuid = path.split('/').at(-1)!
      const product = (options.legacyProducts ?? []).find((entry) => (entry.specifications as { uuid?: string }[] | undefined)?.some((specification) => specification.uuid === specificationUuid))
      const specification = (product?.specifications as Record<string, unknown>[] | undefined)?.find((entry) => entry.uuid === specificationUuid)
      const metadata = specification?.__metadata as Record<string, unknown> | undefined
      return specification && product ? respond({ ...metadata, id: specification.id, uuid: specificationUuid, product: { uuid: product.uuid, __toString: `#1 ${String(product.name)}` } }) : route.fulfill({ status: 404, json: { code: 404 } })
    }
    if (method === 'POST' && path === `/api/v1/store/${storeId}/counter-operations/counters/${counterId}/orders/merge` && options.mergeCounterOrders) {
      expect(body?.version).toBeNull()
      const sources = counterOrders.filter((entry) => entry.status === 'confirmed')
      const target = {
        uuid: '00000000-0000-4000-8000-000000000010', status: 'confirmed', totalAmount: sources.reduce((sum, entry) => sum + Number(entry.totalAmount ?? 0), 0), currency: 'CNY', createdAt: '2026-10-30T11:00:00+08:00', mergedIntoOrderUuid: null, diningModeUuid: null, diningMode: null,
        items: sources.flatMap((entry) => (entry.items as Record<string, unknown>[] | undefined) ?? []),
      }
      counterOrders = [...counterOrders.map((entry) => entry.status === 'confirmed' ? { ...entry, status: 'merged' } : entry), target]
      return respond({ uuid: target.uuid, status: target.status, totalAmount: target.totalAmount, currency: target.currency })
    }
    if (path.startsWith('/api/v1/app/counters/') && path.endsWith('/orders')) return respond(counterOrders, true)
    if (path.startsWith('/api/v1/app/counters/') && !path.endsWith('/orders')) return respond(counters.find((counter) => path.endsWith(counter.uuid)) ?? counters[0])
    if (path.endsWith('/membership')) return respond({ status: options.membership ?? 'active', role: 'manager' })
    if (path === '/api/v1/app/users/me') return respond({ uuid: storeId, username: 'contract-staff', roles: ['ROLE_USER'] })
    if (path === '/api/v1/app/authorization/me') {
      if (options.grants) return respond({ permissions: options.grants, storeScopes: Object.fromEntries(options.grants.map((code) => [code, [storeId]])) })
      const scopes: Record<string, string[]> = {}
      for (const resource of ['product', 'product_category', 'specification', ...(options.counterAccess ? ['counter'] : []), 'order']) {
        for (const action of options.readOnly || resource === 'order' ? ['read'] : ['read', 'create', 'update', 'delete']) scopes[`store:${resource}:${action}`] = [storeId]
      }
      if (!options.readOnly) { scopes['store:product:availability'] = [storeId]; scopes['store:catalog_image:create'] = [storeId] }
      if (options.orderOperations && !options.readOnly) for (const action of ['accept', 'reject', 'fulfill', 'verify']) scopes[`store:order:${action}`] = [storeId]
      if (options.auditor) scopes['store:operation:read'] = [storeId]
      return respond({ permissions: Object.keys(scopes), storeScopes: scopes })
    }
    if (options.denyWrite && method !== 'GET') return route.fulfill({ status: 403, json: { code: 403, message: 'denied' } })
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
    if (path === `/api/v1/store/${storeId}/products/${productId}`) {
      if (method === 'PUT') { products = products.map((item) => ({ ...item, ...body, category: body!.category ? { uuid: String(body!.category) } : null,
        metadata: { subtitle: body!.subtitle ?? null, cover: body!.coverImageUuid ? '/uploads/test-image.png' : null },
        settings: { kitchenPrinterEnable: body!.kitchenPrinterEnable ?? false, startSaleTime: body!.startSaleTime ?? '', endSaleTime: body!.endSaleTime ?? '' },
      })); return respond(products[0]) }
      if (method === 'DELETE') { products = []; return route.fulfill({ status: 204 }) }
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
async function login(page: Page, entry = '/') {
  await page.goto(entry)
  await page.getByLabel('账号', { exact: true }).fill('contract-staff')
  await page.getByLabel('密码', { exact: true }).fill('test-only-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByRole('button', { name: 'Contract store S1', exact: true }).click()
  await page.getByRole('button', { name: '连接门店', exact: true }).click()
}

test('live catalog, separate variants, categories, tables and read-only orders use employee APIs', async ({ page }) => {
  const requests = await mockApi(page, { counterAccess: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
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
  await expect(page.getByLabel('分类标识', { exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'New live category', exact: true })).toBeVisible()
  expect(requests.find((request) => request.method === 'POST' && request.path.endsWith('/product-categories'))?.body?.slug).toMatch(/^new-live-category-[a-f0-9]{32}$/)
  await page.getByRole('navigation').getByRole('button', { name: '收银', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Real A01', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '新增桌台', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '删除', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('桌台名称', { exact: true }).fill('Real A02')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Real A02', exact: true })).toBeVisible()
  expect(requests.find((request) => request.method === 'PUT' && request.path.endsWith(`/counters/${counterId}`))?.body).toMatchObject({ name: 'Real A02', code: 'A01', capacity: 4 })
  expect(requests.filter((request) => /\/counters(?:\/|$)/.test(request.path) && ['POST', 'DELETE'].includes(request.method))).toHaveLength(0)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await page.getByRole('button', { name: '查看', exact: true }).click()
  await expect(page.getByRole('dialog')).toContainText('pending_validation')
  await expect(page.getByRole('button', { name: '确认结账', exact: true })).toHaveCount(0)
  expect(requests.some((request) => request.path.includes('/manage/') || request.path.includes('/payment') || request.path.includes('/app/orders'))).toBe(false)
  const storedSession = await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))
  expect(storedSession).toContain('ns-store-console.auth.tokens')
  expect(storedSession).not.toContain('test-only-password')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
})

test('read-only grants hide mutations, and reload restores staff authentication and the selected store', async ({ page }) => {
  await mockApi(page, { readOnly: true })
  await login(page)
  await expect(page.getByRole('button', { name: '新增商品', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '编辑', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '删除', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '设为售罄', exact: true })).toHaveCount(0)
  await page.reload()
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: '登录', exact: true })).toHaveCount(0)
})

test('kitchen-only staff see availability without catalog management or cashier access', async ({ page }) => {
  const requests = await mockApi(page, { grants: ['store:product:read', 'store:product:availability'] })
  await login(page, '/?view=cashier')
  const nav = page.getByRole('navigation')
  await expect(nav.getByRole('button')).toHaveText(['厨房', '我的'])
  await expect(nav.getByRole('button', { name: '厨房', exact: true })).toHaveAttribute('aria-current', 'page')
  for (const name of ['新增商品', '编辑', '删除', '管理规格', '分类管理', '确认结账']) await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '设为售罄', exact: true }).click()
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('button', { name: '恢复售卖', exact: true })).toBeVisible()
  for (const width of [320, 768, 1280]) {
    await page.setViewportSize({ width, height: 800 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  }
  expect(requests.filter((request) => request.path.startsWith('/api/v1/store/')).every((request) => /\/operations\/products(?:[/?]|$)/.test(request.path))).toBe(true)
})

test('cashier staff read counter orders with service-session permission', async ({ page }) => {
  const requests = await mockApi(page, { grants: ['store:counter_session:read', 'store:counter_order:read', 'store:counter_order:create', 'store:counter_order:replace', 'store:order:read'] })
  const orderUuid = '00000000-0000-4000-8000-000000000008'
  const sessionUuid = '00000000-0000-4000-8000-000000000009'
  const idleCounterId = '00000000-0000-4000-8000-000000000010'
  const tables = [{
    counter: { uuid: counterId, code: 'A01', name: '靠窗桌', capacity: 4, enabled: true },
    session: { uuid: sessionUuid, participantCount: 2, notes: null, startedAt: '2026-10-30T10:00:00+08:00', revision: 1 },
    currency: 'CNY', canEnd: false, version,
    ordersVisible: true, openAmount: 1590, orderedAmount: 1590, openOrderCount: 1, paymentHold: false,
    orders: [{ uuid: orderUuid, status: 'confirmed', operationalStatus: 'accepted', paymentStatus: null, hasInvoice: false, currency: 'CNY', totalAmount: 1590, notes: null, createdAt: '2026-10-30T10:00:00+08:00', items: [{ specificationUuid: null, productName: 'API product', specificationName: 'Regular', quantity: 1, unitPrice: 1590, amount: 1590 }], canReplace: false, canCancel: false }],
  }, {
    counter: { uuid: idleCounterId, code: 'A02', name: '中间桌', capacity: 4, enabled: true }, session: null,
    currency: 'CNY', canEnd: false, version, ordersVisible: true, openAmount: 0, orderedAmount: 0, openOrderCount: 0, paymentHold: false, orders: [],
  }]
  await page.route('**/counter-operations/counters?**', (route) => route.fulfill({ json: { code: 0, data: { items: tables, total: tables.length, page: 1, hasNext: false } } }))
  await page.route('**/counter-operations/counters/*', (route) => {
    const id = new URL(route.request().url()).pathname.split('/').at(-1)
    return route.fulfill({ json: { code: 0, data: tables.find((table) => table.counter.uuid === id) ?? tables[0] } })
  })
  await login(page, '/?view=kitchen')
  const nav = page.getByRole('navigation')
  await expect(nav.getByRole('button')).toHaveText(['收银', '交易订单', '我的'])
  await expect(nav.getByRole('button', { name: '收银', exact: true })).toHaveAttribute('aria-current', 'page')
  await expect(page.locator('.workspace-heading')).toHaveCount(0)
  await expect(page.locator('.page-scope')).toHaveCount(0)
  await expect(page.getByLabel('搜索全店记录', { exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '桌台配置', exact: true })).toHaveCount(0)
  for (const name of ['全部状态', '已启用', '已停用', '当前接入范围', '真实服务']) await expect(page.getByText(name, { exact: true })).toHaveCount(0)
  const summary = page.locator('.dining-summary')
  await expect(summary).toContainText('就餐中')
  await expect(summary).toContainText('空闲')
  await expect(summary).toContainText('总营业额')
  await expect(summary).toContainText('¥15.90')
  await expect(summary).not.toContainText('已收金额')
  await expect(summary).not.toContainText('待收金额')
  const revenue = summary.getByRole('button', { name: '总营业额', exact: true })
  await expect(revenue).toHaveAttribute('aria-expanded', 'false')
  await revenue.click()
  await expect(revenue).toHaveAttribute('aria-expanded', 'true')
  await expect(summary).toContainText('已收金额')
  await expect(summary).toContainText('待收金额')
  await expect(page.locator('.workspace-connection, .workspace-signature')).toHaveCount(0)
  const orderedTable = page.locator('.dining-card').filter({ hasText: '靠窗桌' })
  const idleTable = page.locator('.dining-card').filter({ hasText: '中间桌' })
  await expect(orderedTable).toHaveClass(/has-orders/)
  await expect(orderedTable.getByText('¥15.90', { exact: true })).toBeVisible()
  await expect(orderedTable).not.toContainText('A01')
  await expect(orderedTable.locator('img')).toHaveCount(0)
  await expect(idleTable).not.toHaveClass(/has-orders/)
  await expect(idleTable.getByText('未结订单金额', { exact: true })).toHaveCount(0)
  for (const name of ['点餐', '加单', '结账']) {
    await expect(orderedTable.getByRole('button', { name, exact: true })).toHaveCount(0)
    await expect(idleTable.getByRole('button', { name, exact: true })).toHaveCount(0)
  }
  expect(await orderedTable.evaluate((element) => getComputedStyle(element).backgroundImage)).not.toBe(await idleTable.evaluate((element) => getComputedStyle(element).backgroundImage))
  await orderedTable.locator('.dining-card-total').click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail.getByRole('button', { name: '加单', exact: true })).toBeVisible()
  await expect(detail.getByRole('button', { name: '结账', exact: true })).toBeVisible()
  await detail.getByRole('button', { name: '结账', exact: true }).click()
  const checkout = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(checkout).toContainText('先按本桌点餐方式核对并调整最终菜品')
  await checkout.getByRole('button', { name: '关闭', exact: true }).click()
  await idleTable.getByRole('button', { name: '中间桌', exact: true }).click()
  await expect(detail.getByRole('button', { name: '点餐', exact: true })).toBeVisible()
  await expect(detail.getByRole('button', { name: '结账', exact: true })).toHaveCount(0)
  await detail.getByRole('button', { name: '关闭', exact: true }).click()
  await nav.getByRole('button', { name: '门店订单（只读）', exact: true }).click()
  await page.getByRole('button', { name: '查看', exact: true }).click()
  for (const name of ['接单', '拒单', '完成履约', '核销', '确认结账']) await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0)
  expect(requests.some((request) => /products|specifications|payment|refund/.test(request.path))).toBe(false)
})

// Legacy cashier rendering is intentionally unavailable without counter_session:read.
test.skip('legacy counter orders show checkout and add without repeating first-round guests or rules', async ({ page }) => {
  const legacyModeId = '00000000-0000-4000-8000-000000000008'
  const checkoutRuleId = '00000000-0000-4000-8000-000000000009'
  const requests = await mockApi(page, {
    counterAccess: true,
    diningModes: {
      version: 1,
      modes: [{
        uuid: legacyModeId, name: '火锅', enabled: true, priority: 10, switchButtonLabel: '火锅',
        availability: { allDay: true, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [] },
        catalog: { categoryUuids: [], productUuids: [] },
        rules: [
          { uuid: 'fixed', name: '必点纸巾', type: 'fixed_item', specificationUuid: specId, quantity: 1, required: true, allowRemove: false },
          { uuid: 'guest', name: '按人数茶位', type: 'per_guest_item', specificationUuid: specId, quantityPerGuest: 1, required: true, allowRemove: false },
          { uuid: 'choice', name: '几选几', type: 'choice_group', targetType: 'specifications', specificationUuids: [specId], categoryUuid: '', minSelections: 1, maxSelections: 1, quantityPerSelection: 1, required: true, allowRemove: false },
        ],
        checkoutRules: [{ uuid: checkoutRuleId, name: '确认用餐人数', type: 'confirm_participants', required: true, categoryUuid: '' }],
      }],
    },
    counterOrders: [{
      uuid: orderId,
      status: 'confirmed',
      totalAmount: 1590,
      currency: 'CNY',
      createdAt: '2026-10-29T18:00:00+08:00',
      mergedIntoOrderUuid: null,
      diningModeUuid: legacyModeId,
      diningMode: {
        uuid: legacyModeId, name: '火锅', enabled: true, priority: 10, switchButtonLabel: '火锅',
        availability: { allDay: true, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [] },
        catalog: { categoryUuids: [], productUuids: [] }, rules: [],
        checkoutRules: [{ uuid: checkoutRuleId, name: '确认用餐人数', type: 'confirm_participants', required: true, categoryUuid: '' }],
      },
      items: [{ productName: 'API product', specificationName: 'Regular', quantity: 1, unitPrice: 1590, price: 1590 }],
    }],
    legacyProducts: [{
      id: 1,
      uuid: productId,
      name: 'API product',
      status: 'active',
      metadata: null,
      category: null,
      specifications: [{ id: 2, uuid: specId, __toString: 'API product - Regular', __metadata: { id: 2, uuid: specId, name: 'Regular', price: 1590, status: 'active', sort: 0, isDeleted: false } }],
    }],
  })
  await login(page, '/?view=kitchen')
  const table = page.locator('.dining-card').filter({ hasText: 'Real A01' })
  await expect(table).toHaveClass(/has-orders/)
  await expect(table.getByText('¥15.90', { exact: true })).toBeVisible()
  for (const name of ['点餐', '加单', '结账']) await expect(table.getByRole('button', { name, exact: true })).toHaveCount(0)
  await table.getByRole('button', { name: 'Real A01', exact: true }).click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail.getByRole('button', { name: '结账', exact: true })).toBeVisible()
  await detail.getByRole('button', { name: '结账', exact: true }).click()
  const checkout = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(checkout.getByText('确认用餐人数', { exact: true })).toBeVisible()
  await checkout.getByRole('button', { name: '返回', exact: true }).click()
  await table.getByRole('button', { name: 'Real A01', exact: true }).click()
  await detail.getByRole('button', { name: '加单', exact: true }).click()
  const editor = page.getByRole('dialog', { name: '加单', exact: true })
  await expect(editor).toBeVisible()
  await expect(editor.locator('.dining-mode-current')).toContainText('火锅')
  await expect(editor.getByLabel('搜索全店记录', { exact: true })).toHaveCount(0)
  await expect(editor.locator('.dining-guest-picker')).toHaveCount(0)
  await expect(editor.locator('.dining-rule-guide')).toHaveCount(0)
  await expect(editor.locator('.dining-choice-rule')).toHaveCount(0)
  await expect(editor.locator('.dining-product-quantity')).toHaveCount(0)
  const confirm = editor.getByRole('button', { name: '确认下单', exact: true })
  await expect(confirm).toBeInViewport()
  await editor.locator('.sheet-scroll').evaluate((element) => element.scrollTo({ top: element.scrollHeight, behavior: 'auto' }))
  await expect(confirm).toBeInViewport()
  await editor.getByRole('button', { name: '加菜 API product Regular', exact: true }).click()
  await expect(editor.locator('.dining-menu li').filter({ hasText: 'API product' }).locator('.dining-product-quantity')).toContainText('×1')
  await confirm.click()
  await expect(page.locator('.resource-editor, .dining-editor')).toHaveCount(0)
  const created = requests.find((request) => request.path === '/api/v1/app/orders' && request.method === 'POST')
  expect(created?.storeCode).toBe('S1')
  expect(created?.body).toMatchObject({ currency: 'CNY', metadata: { counterUuid: counterId, diningModeUuid: legacyModeId }, items: [{ specificationId: 2, quantity: 1 }] })
})

// The same merge behavior is covered under counter-operations with service-session permission.
test.skip('legacy checkout merges confirmed orders when their dining mode is missing', async ({ page }) => {
  const legacyOrders = [
    { uuid: orderId, status: 'confirmed', totalAmount: 1590, currency: 'CNY', createdAt: '2026-10-30T10:00:00+08:00', mergedIntoOrderUuid: null, diningModeUuid: null, diningMode: null, items: [{ productName: 'API product', specificationName: 'Regular', quantity: 1, unitPrice: 1590, price: 1590 }] },
    { uuid: '00000000-0000-4000-8000-000000000011', status: 'confirmed', totalAmount: 1590, currency: 'CNY', createdAt: '2026-10-30T10:10:00+08:00', mergedIntoOrderUuid: null, diningModeUuid: null, diningMode: null, items: [{ productName: 'API product', specificationName: 'Regular', quantity: 1, unitPrice: 1590, price: 1590 }] },
  ]
  const requests = await mockApi(page, { counterAccess: true, counterOrders: legacyOrders, mergeCounterOrders: true })
  await login(page, '/?view=cashier')
  const table = page.locator('.dining-card').filter({ hasText: 'Real A01' })
  await table.getByRole('button', { name: 'Real A01', exact: true }).click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '结账', exact: true }).click()
  const checkout = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(checkout).toContainText('已按合并订单结账')
  await expect(checkout).toContainText('¥31.80')
  const mergeRequest = requests.find((request) => request.method === 'POST' && request.path === `/api/v1/store/${storeId}/counter-operations/counters/${counterId}/orders/merge`)
  expect(mergeRequest?.body).toEqual({ version: null })
  expect(requests.filter((request) => request.path === `/api/v1/store/${storeId}/counter-operations/counters/${counterId}/orders/merge`)).toHaveLength(1)
})

// Modern counter-operations coverage owns dining-mode selection when session permission is present.
test.skip('store dining modes select by priority, filter the menu, apply rules and allow configured switching', async ({ page }) => {
  const fixedProductId = '00000000-0000-4000-8000-000000000011'
  const fixedSpecId = '00000000-0000-4000-8000-000000000012'
  const drinkProductId = '00000000-0000-4000-8000-000000000013'
  const drinkSpecId = '00000000-0000-4000-8000-000000000014'
  const drinkCategoryId = '00000000-0000-4000-8000-000000000015'
  const teaProductId = '00000000-0000-4000-8000-000000000017'
  const teaSpecId = '00000000-0000-4000-8000-000000000018'
  const meatCategoryId = '00000000-0000-4000-8000-000000000019'
  const meatProductId = '00000000-0000-4000-8000-000000000020'
  const meatSpecId = '00000000-0000-4000-8000-000000000021'
  const clearSoupProductId = '00000000-0000-4000-8000-000000000023'
  const clearSoupSpecId = '00000000-0000-4000-8000-000000000024'
  const requests = await mockApi(page, {
    counterAccess: true,
    diningModes: {
      version: 1,
      modes: [{
        uuid: 'breakfast', name: '早餐', enabled: true, priority: 5, switchButtonLabel: '不吃火锅',
        availability: { allDay: true, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [] },
        catalog: { categoryUuids: [drinkCategoryId], productUuids: [] }, rules: [],
      }, {
        uuid: 'hotpot', name: '火锅', enabled: true, priority: 10, switchButtonLabel: '吃火锅',
        availability: { allDay: true, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [] },
        catalog: { categoryUuids: [categoryId, meatCategoryId], productUuids: [] },
        rules: [
          { uuid: 'tissue-rule', name: '每台纸巾', type: 'fixed_item', specificationUuid: fixedSpecId, quantity: 1, required: true, allowRemove: false },
          { uuid: 'tea-rule', name: '按人数茶位', type: 'per_guest_item', specificationUuid: teaSpecId, quantityPerGuest: 1, required: true, allowRemove: false },
          { uuid: 'soup-rule', name: '选择汤底', type: 'choice_group', targetType: 'category', categoryUuid: categoryId, specificationUuids: [], minSelections: 1, maxSelections: 1, quantityPerSelection: 1, required: true, allowRemove: false },
        ],
      }],
    },
    legacyProducts: [{
      id: 1, uuid: productId, name: 'API product', status: 'active', metadata: null,
      category: { uuid: categoryId, name: '火锅' }, specifications: [{ id: 2, uuid: specId, __metadata: { name: 'Regular', price: 1590, status: 'active', sort: 0 } }],
    }, {
      id: 2, uuid: fixedProductId, name: '纸巾', status: 'active', metadata: null,
      category: { uuid: '00000000-0000-4000-8000-000000000016', name: '用品' }, specifications: [{ id: 3, uuid: fixedSpecId, __metadata: { name: '纸巾', price: 100, status: 'active', sort: 0 } }],
    }, {
      id: 3, uuid: drinkProductId, name: '早餐饮品', status: 'active', metadata: null,
      category: { uuid: drinkCategoryId, name: '饮品' }, specifications: [{ id: 4, uuid: drinkSpecId, __metadata: { name: '杯装', price: 800, status: 'active', sort: 0 } }],
    }, {
      id: 4, uuid: teaProductId, name: '茶位', status: 'active', metadata: null,
      category: { uuid: '00000000-0000-4000-8000-000000000022', name: '用品' }, specifications: [{ id: 5, uuid: teaSpecId, __metadata: { name: '每位', price: 100, status: 'active', sort: 0 } }],
    }, {
      id: 5, uuid: meatProductId, name: '鲜切牛肉', status: 'active', metadata: null,
      category: { uuid: meatCategoryId, name: '荤菜' }, specifications: [{ id: 6, uuid: meatSpecId, __metadata: { name: '标准份', price: 2800, status: 'active', sort: 0 } }],
    }, {
      id: 6, uuid: clearSoupProductId, name: '清汤锅底', status: 'active', metadata: null,
      category: { uuid: categoryId, name: '火锅' }, specifications: [{ id: 7, uuid: clearSoupSpecId, __metadata: { name: '小锅', price: 1800, status: 'active', sort: 0 } }],
    }],
  })
  await page.setViewportSize({ width: 320, height: 900 })
  await login(page, '/?view=kitchen')
  const table = page.locator('.dining-card').filter({ hasText: 'Real A01' })
  for (const name of ['点餐', '加单', '结账']) await expect(table.getByRole('button', { name, exact: true })).toHaveCount(0)
  await table.getByRole('button', { name: 'Real A01', exact: true }).click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '点餐', exact: true }).click()
  const editor = page.getByRole('dialog', { name: '点餐', exact: true })
  await expect(editor.locator('.dining-mode-current')).toContainText('火锅')
  await expect(editor.getByLabel('搜索全店记录', { exact: true })).toHaveCount(0)
  await expect(editor.getByRole('button', { name: '吃火锅', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await expect(editor.getByRole('button', { name: '不吃火锅', exact: true })).toBeVisible()
  await expect(editor.locator('.dining-menu')).toContainText('API product')
  await expect(editor.locator('.dining-menu')).not.toContainText('早餐饮品')
  await expect(editor.locator('.dining-guest-quick')).toBeVisible()
  await editor.locator('.dining-guest-quick').getByRole('button', { name: '2', exact: true }).click()
  await expect(editor.getByLabel('用餐人数', { exact: true })).toHaveValue('2')
  await expect(editor.getByRole('button', { name: '全部分类', exact: true })).toBeVisible()
  const categories = editor.locator('.dining-category-filter')
  const editorScroll = editor.locator('.sheet-scroll')
  const categoryTop = (await categories.boundingBox())!.y
  expect(await editorScroll.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true)
  await editorScroll.evaluate((element) => element.scrollTo({ top: Math.min(520, element.scrollHeight - element.clientHeight), behavior: 'auto' }))
  await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))))
  await expect(categories).toBeVisible()
  expect(Math.abs((await categories.boundingBox())!.y - categoryTop)).toBeLessThan(2)
  await editor.getByRole('button', { name: '荤菜', exact: true }).click()
  await expect(editor.locator('.dining-menu')).toContainText('鲜切牛肉')
  await expect(editor.locator('.dining-menu')).not.toContainText('API product')
  await editor.getByRole('button', { name: '加菜 鲜切牛肉 标准份', exact: true }).click()
  const beefRow = editor.locator('.dining-menu li').filter({ hasText: '鲜切牛肉' })
  await expect(beefRow.locator('.dining-product-quantity')).toContainText('×1')
  await beefRow.getByRole('button', { name: '减少数量 鲜切牛肉 标准份', exact: true }).click()
  await expect(beefRow.getByRole('button', { name: '加菜 鲜切牛肉 标准份', exact: true })).toBeVisible()
  await editor.getByRole('button', { name: '全部分类', exact: true }).click()
  await expect(editor.locator('.dining-choice-rule')).toContainText('选择汤底')
  expect(await editor.locator('.dining-choice-scroll').evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true)
  const confirm = editor.getByRole('button', { name: '确认下单', exact: true })
  await expect(confirm).toBeDisabled()
  await expect(confirm).toBeInViewport()
  await expect(editor.locator('.dining-rule-guide')).toContainText('0/1')

  await editor.getByRole('button', { name: '不吃火锅', exact: true }).click()
  await expect(editor.locator('.dining-mode-current')).toContainText('早餐')
  await expect(editor.locator('.dining-menu')).toContainText('早餐饮品')
  await expect(editor.locator('.dining-menu')).not.toContainText('API product')
  await expect(editor.locator('.dining-product-quantity')).toHaveCount(0)

  await editor.getByRole('button', { name: '吃火锅', exact: true }).click()
  await editor.getByRole('button', { name: '选择商品 API product Regular', exact: true }).click()
  await expect(editor.locator('.dining-menu li').filter({ hasText: 'API product' }).locator('.dining-product-quantity')).toContainText('×1')
  await expect(editor.locator('.dining-rule-guide')).toContainText('1/1')
  await expect(confirm).toBeEnabled()
  await expect(confirm).toBeInViewport()
  await confirm.click()
  await expect(editor).toHaveCount(0)
  const created = requests.find((request) => request.path === '/api/v1/app/orders' && request.method === 'POST')
  expect(created?.body).toMatchObject({ metadata: { counterUuid: counterId, diningModeUuid: 'hotpot' }, items: expect.arrayContaining([{ specificationId: 3, quantity: 1 }, { specificationId: 5, quantity: 2 }, { specificationId: 2, quantity: 1 }]) })
})

test('dual workspace grants default to cashier even with a kitchen entry preference', async ({ page }) => {
  await mockApi(page, { grants: ['store:product:read', 'store:counter_session:read'] })
  await page.route('**/counter-operations/counters?**', (route) => route.fulfill({ json: { code: 0, data: { items: [], total: 0, page: 1, hasNext: false } } }))
  await login(page, '/?view=kitchen')
  const nav = page.getByRole('navigation')
  await expect(nav.getByRole('button', { name: '收银', exact: true })).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('heading', { name: '安排好每一张桌台', exact: true })).toBeVisible()
  await nav.getByRole('button', { name: '厨房', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
})

test('cashier reports the missing service-session permission for legacy counter-only access', async ({ page }) => {
  await mockApi(page, { counterAccess: true })
  await login(page, '/?view=kitchen')
  const cashier = page.getByRole('navigation').getByRole('button', { name: '收银', exact: true })
  await expect(cashier).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('heading', { name: 'Real A01', exact: true })).toBeVisible()
  await expect(page.getByText('当前账号缺少服务场次读取权限', { exact: false })).toBeVisible()
  await expect(page.locator('.dining-card')).toHaveCount(0)
  await expect(page.getByText('真实数据', { exact: true })).toHaveCount(0)
  await page.reload()
  await expect(cashier).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('heading', { name: 'Real A01', exact: true })).toBeVisible()
  await expect(page.getByText('当前账号缺少服务场次读取权限', { exact: false })).toBeVisible()
  await expect(page.locator('.dining-card')).toHaveCount(0)
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
  await page.getByRole('button', { name: '连接门店', exact: true }).click()
  await expect(page.getByRole('navigation').getByRole('button')).toHaveText(['交易订单', '我的'])
  await expect(page.getByRole('heading', { name: '门店订单（只读）', exact: true })).toBeVisible()
  expect(requests.slice(before).some((request) => /products|counters/.test(request.path))).toBe(false)
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
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('商品副标题', { exact: true }).fill('Lunch special')
  await page.getByLabel('商品描述', { exact: true }).fill('Freshly prepared')
  await page.locator('.resource-editor input[type=file]').setInputFiles({ name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', 'base64') })
  await page.getByRole('switch', { name: '后厨打印标记', exact: true }).click()
  await page.getByLabel('开始售卖时间', { exact: true }).fill('22:00')
  await page.getByLabel('结束售卖时间', { exact: true }).fill('02:00')
  await page.locator('.editor-advanced summary').click()
  await page.getByLabel('排序值', { exact: true }).fill('5')
  await page.getByLabel('推荐等级', { exact: true }).fill('2')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  const save = requests.find((request) => request.method === 'PUT')!
  expect(save.body).toMatchObject({ subtitle: 'Lunch special', description: 'Freshly prepared', sortOrder: 5, recommendationLevel: 2, kitchenPrinterEnable: true, startSaleTime: '22:00', endSaleTime: '02:00', coverImageUuid: counterId, version, commandUuid: expect.any(String) })
  expect(requests.filter((request) => request.path.endsWith('/operations/images'))).toHaveLength(1)
  await expect(page.locator('.catalog-product-info')).toContainText('Lunch special')
})

test('single and explicit-page batch availability use selected record versions and preserve errors', async ({ page }) => {
  const requests = await mockApi(page)
  await login(page)
  await page.getByRole('button', { name: '设为售罄', exact: true }).click()
  await expect(page.getByRole('dialog')).toContainText('API product')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await expect(page.getByRole('button', { name: '恢复售卖', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '恢复本页售罄商品', exact: true }).click()
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  expect(requests.filter((request) => request.path.endsWith('/availability')).map((request) => request.body)).toEqual([
    { commandUuid: expect.any(String), isSoldout: true, items: [{ uuid: productId, version }] },
    { commandUuid: expect.any(String), isSoldout: false, items: [{ uuid: productId, version }] },
  ])
  await page.route('**/operations/products/*', (route) => route.fulfill({ status: 409, json: { code: 409, message: 'stale_record' } }))
  await page.getByRole('button', { name: '编辑', exact: true }).click()
  await page.getByLabel('商品名称', { exact: true }).fill('Conflict input')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('记录已变化')
  await expect(page.getByLabel('商品名称', { exact: true })).toHaveValue('Conflict input')
})

test('order operators can accept, fulfill and verify but cannot treat operational state as payment', async ({ page }) => {
  const requests = await mockApi(page, { orderOperations: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单', exact: true }).click()
  await page.getByRole('button', { name: '查看', exact: true }).click()
  for (const [label, action] of [['接单', 'accept'], ['完成履约', 'fulfill'], ['核销', 'verify']]) {
    await page.getByRole('dialog').getByRole('button', { name: label, exact: true }).click()
    await expect(page.locator('.resource-editor')).toContainText('不代表付款、退款或结账')
    await page.getByRole('button', { name: '保存', exact: true }).click()
    await expect(page.locator('.resource-editor')).toHaveCount(0)
    expect(requests.find((request) => request.path.endsWith(`/${action}`))?.body).toMatchObject({ commandUuid: expect.any(String), version })
  }
  await expect(page.getByRole('dialog')).toContainText('已核销')
  await expect(page.getByRole('button', { name: '确认结账', exact: true })).toHaveCount(0)
  expect(requests.some((request) => /payment|refund|manage/.test(request.path))).toBe(false)
})

test('rejection requires a reason and removes actions after reaching a terminal state', async ({ page }) => {
  const requests = await mockApi(page, { orderOperations: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '门店订单', exact: true }).click()
  await page.getByRole('button', { name: '查看', exact: true }).click()
  await page.getByRole('button', { name: '拒单', exact: true }).click()
  await page.getByRole('button', { name: '保存', exact: true }).click()
  expect(requests.filter((request) => request.path.endsWith('/reject'))).toHaveLength(0)
  await page.getByLabel('拒单原因', { exact: true }).fill('Kitchen closed')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('.resource-editor')).toHaveCount(0)
  expect(requests.find((request) => request.path.endsWith('/reject'))?.body).toMatchObject({ reason: 'Kitchen closed', commandUuid: expect.any(String), version })
  await expect(page.getByRole('dialog').getByRole('button', { name: '接单', exact: true })).toHaveCount(0)
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

test('a server search miss never pretends that the store has no products', async ({ page }) => {
  const requests = await mockApi(page)
  await login(page)
  await page.getByLabel('搜索全店记录', { exact: true }).fill('No matching item')
  await expect(page.getByRole('heading', { name: '没有匹配的记录', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: '从第一件商品开始', exact: true })).toHaveCount(0)
  expect(requests.filter((request) => request.path.endsWith('/operations/products')).length).toBeGreaterThan(0)
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

test('all seven themes change rendered surfaces, controls and portal editors and survive a new login', async ({ page }) => {
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
  await expect(page.getByRole('heading', { name: 'API product', exact: true })).toBeVisible()
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
  const requests = await mockApi(page, { counterAccess: true })
  await login(page)
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
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
  const tableCard = page.locator('.workspace-table-card-compact').filter({ hasText: 'Real A01' })
  const tableEdit = tableCard.getByRole('button', { name: '编辑', exact: true })
  await expect(tableEdit).toHaveCSS('min-height', '28px')
  expect((await tableEdit.boundingBox())!.y).toBeLessThan((await tableCard.locator('.table-config-seats').boundingBox())!.y)
  await tableEdit.click()
  await expect(page.getByRole('dialog', { name: '查看桌次' })).toHaveCount(0)
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
  await mockApi(page, { counterAccess: true })
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

test('live lists request the next backend page and clear data on store switching and logout', async ({ page }) => {
  const requests = await mockApi(page)
  await page.route('**/api/v1/store/**/products?**', (route) => {
    const pageNumber = Number(new URL(route.request().url()).searchParams.get('page'))
    return operationalList(route, [{ uuid: productId, name: `Live page ${pageNumber}`, category: null, status: 'active' }], pageNumber === 1)
  })
  await login(page)
  await expect(page.getByRole('heading', { name: 'Live page 1', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '下一页', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Live page 2', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Live page 1', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '切换门店', exact: true }).click()
  await expect(page.getByRole('heading', { name: '选择门店', exact: true })).toBeVisible()
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
  await mockApi(page, { counterAccess: true })
  await page.route('**/api/v1/store/**/products?**', (route) => operationalList(route, [
    { uuid: productId, name: 'Fresh-cut beef', category: null, status: 'active' },
    { uuid: '00000000-0000-4000-8000-000000000012', name: 'Garden vegetables', category: null, status: 'active' },
    { uuid: '00000000-0000-4000-8000-000000000013', name: 'House broth', category: null, status: 'inactive' },
  ]))
  await page.route('**/api/v1/store/**/counters?**', (route) => operationalList(route, Array.from({ length: 4 }, (_, index) => ({ uuid: `00000000-0000-4000-8000-00000000002${index}`, name: `Table A0${index + 1}`, code: `LONG-TABLE-CONFIGURATION-CODE-${index + 1}`, capacity: 4, sort: index, enabled: index < 3 }))))
  await page.goto('/')
  await expect(page.getByRole('heading', { name: '登录', exact: true })).toBeVisible()
  await page.screenshot({ path: 'test-results/ui-login.png', fullPage: true })
  await page.getByLabel('账号', { exact: true }).fill('contract-staff')
  await page.getByLabel('密码', { exact: true }).fill('test-only-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  const choice = page.getByRole('button', { name: 'Contract store S1', exact: true })
  await choice.click()
  await expect(choice).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByLabel('门店 UUID', { exact: true })).toHaveValue(storeId)
  await expect(page.getByLabel('门店 UUID', { exact: true })).not.toBeVisible()
  await page.screenshot({ path: 'test-results/ui-store-selection.png', fullPage: true })
  await page.getByRole('button', { name: '连接门店', exact: true }).click()
  await page.getByRole('navigation').getByRole('button', { name: '厨房', exact: true }).click()
  await expect(page.locator('.catalog-product-card')).toHaveCount(3)
  await expect.poll(() => page.evaluate(() => scrollY)).toBe(0)
  await expect(page.locator('.record-identity dd').first()).not.toBeVisible()
  await page.screenshot({ path: 'test-results/ui-catalog.png', fullPage: true })
  await page.getByLabel('搜索全店记录', { exact: true }).fill('Garden')
  await expect(page.locator('.catalog-product-card')).toHaveCount(1)
  await page.getByLabel('搜索全店记录', { exact: true }).fill('')
  await page.getByRole('button', { name: '已下架', exact: true }).click()
  await expect(page.locator('.catalog-product-card')).toHaveCount(1)
  await expect(page.getByRole('heading', { name: 'House broth', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '全部状态', exact: true }).click()
  await page.getByRole('button', { name: '编辑', exact: true }).first().click()
  await expect(page.getByRole('dialog').getByRole('combobox', { name: '所属分类', exact: true })).toHaveValue('')
  await page.screenshot({ path: 'test-results/ui-product-form.png', fullPage: true })
  await expect(page.getByRole('dialog')).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog')).toHaveCount(0)
  for (const width of [320, 390, 768, 1280]) {
    await page.setViewportSize({ width, height: 900 })
    for (const name of ['厨房', '收银', '门店订单（只读）', '我的']) {
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
