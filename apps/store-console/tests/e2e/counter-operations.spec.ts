import { expect, test, type Page } from '@playwright/test'

const storeId = '10000000-0000-4000-8000-000000000001'
const counterId = '10000000-0000-4000-8000-000000000002'
const sessionId = '10000000-0000-4000-8000-000000000003'
const specId = '10000000-0000-4000-8000-000000000004'
const store = { uuid: storeId, name: 'Dining contract', code: 'D1', currency: 'USD', timezone: 'UTC' }
const item = { specificationUuid: specId, productName: 'Soup', specificationName: 'Regular', quantity: 1, unitPrice: 1590, amount: 1590 }
const stamp = '2026-10-31T10:00:00+00:00'

async function setup(page: Page, options: { readOnly?: boolean; sessionOnly?: boolean; conflict?: boolean; uncertain?: boolean; empty?: boolean; customerOrder?: boolean; editOnly?: boolean; noReplace?: boolean } = {}) {
  const requests: { path: string; method: string; body: Record<string, unknown> | null }[] = []
  let revision = 0
  let session: { uuid: string; participantCount: number; notes: string; startedAt: string; revision: number } | null = options.empty ? null : { uuid: sessionId, participantCount: 3, notes: 'Birthday', startedAt: stamp, revision }
  let orders: { uuid: string; status: string; operationalStatus: string; paymentStatus: null; hasInvoice: boolean; currency: string; totalAmount: number; notes: string; createdAt: string; items: typeof item[]; canReplace: boolean }[] = options.empty ? [] : [{ uuid: '10000000-0000-4000-8000-000000000009', status: options.customerOrder ? 'pending' : 'completed', operationalStatus: options.customerOrder ? 'pending_validation' : 'fulfilled', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590, notes: '', createdAt: stamp, items: [item], canReplace: Boolean(options.customerOrder) }]
  const receipts = new Map<string, Record<string, unknown>>()
  const version = () => revision.toString(16).padStart(64, 'a')
  const view = () => {
    const base = { counter: { uuid: counterId, code: 'A01', name: 'Window table', capacity: 4, enabled: true }, session,
      currency: 'USD', canEnd: session !== null && orders.every((order) => ['cancelled', 'completed'].includes(order.status)), version: version() }
    if (options.sessionOnly) return { ...base, ordersVisible: false }
    return { ...base, ordersVisible: true,
      orders, openAmount: orders.filter((order) => order.status === 'pending').reduce((sum, order) => sum + order.totalAmount, 0), orderedAmount: orders.filter((order) => order.status !== 'cancelled').reduce((sum, order) => sum + order.totalAmount, 0), openOrderCount: orders.filter((order) => order.status === 'pending').length,
      paymentHold: false }
  }
  let failed = false
  await page.route('**/api/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname
    const method = request.method()
    const body = request.postDataJSON() as Record<string, unknown> | null
    requests.push({ path, method, body })
    const respond = (data: unknown) => route.fulfill({ json: { code: 0, data } })
    const list = (items: unknown[]) => respond({ items, page: Number(url.searchParams.get('page') ?? 1), total: items.length, hasNext: false })
    if (path === '/api/auth/login') return route.fulfill({ json: { access_token: 'counter-test-only', refresh_token: 'refresh-test-only', expires_in: 600 } })
    expect(request.headers().authorization).toBe('Bearer counter-test-only')
    if (path === '/api/v1/app/stores') return route.fulfill({ json: { code: 0, data: [store], paginator: { page: 1, has_next: false } } })
    if (path === `/api/v1/app/stores/${storeId}`) return respond(store)
    if (path.endsWith('/membership')) return respond({ status: 'active', role: 'manager' })
    if (path.endsWith('/users/me')) return respond({ uuid: storeId, username: 'counter-staff', roles: ['ROLE_USER'] })
    if (path.endsWith('/authorization/me')) {
      const sessionActions = options.sessionOnly || options.readOnly ? ['read'] : ['read', 'update', 'end']
      const orderActions = options.sessionOnly ? [] : options.readOnly ? ['read'] : options.editOnly ? ['read', 'replace'] : options.noReplace ? ['read', 'create'] : ['read', 'create', 'replace']
      const storeScopes = {
        ...Object.fromEntries(sessionActions.map((action) => [`store:counter_session:${action}`, [storeId]])),
        ...Object.fromEntries(orderActions.map((action) => [`store:counter_order:${action}`, [storeId]])),
      }
      return respond({ permissions: Object.keys(storeScopes), storeScopes })
    }
    if (path.endsWith('/counter-operations/counters')) return list([view()])
    if (path.endsWith(`/counter-operations/counters/${counterId}`)) return respond(view())
    if (path.endsWith('/counter-operations/catalog')) return list([{ uuid: specId, productName: 'Soup', name: 'Regular', price: 1590, available: true }])
    if (method === 'POST' && path.includes('/counter-operations/counters/')) {
      if (path.endsWith('/orders/quote')) {
        const quantity = (body!.items as { quantity: number }[])[0]!.quantity
        return respond({ items: [{ ...item, quantity, amount: quantity * 1590 }], totalAmount: quantity * 1590, currency: 'USD', quoteToken: 'f'.repeat(64) })
      }
      const key = String(body!.commandUuid)
      if (receipts.has(key)) return respond(receipts.get(key))
      if (options.conflict && path.endsWith('/orders/create')) return route.fulfill({ status: 409, json: { code: 409, message: 'staff_order_quote_changed' } })
      expect(body!.version).toBe(version())
      revision++
      expect(path.endsWith('/open')).toBe(false)
      if (path.endsWith('/session/update')) session = { ...session!, participantCount: Number(body!.participantCount), notes: String(body!.notes), revision }
      if (path.endsWith('/session/end')) { session = null; orders = [] }
      const result: Record<string, unknown> = { counterUuid: counterId, sessionUuid: sessionId }
      expect(path.endsWith('/cancel')).toBe(false)
      if (path.endsWith('/orders/create') || path.endsWith('/orders/replace')) {
        const uuid = `10000000-0000-4000-8000-${String(revision + 10).padStart(12, '0')}`
        const quantity = (body!.items as { quantity: number }[])[0]!.quantity
        if (path.endsWith('/orders/replace')) {
          expect(body!.reason).toBeTruthy()
          orders = orders.map((order) => order.uuid === body!.replaceOrderUuid ? { ...order, status: 'cancelled', operationalStatus: 'cancelled', canReplace: false } : order)
          result.replacedOrderUuid = body!.replaceOrderUuid
        }
        orders.push({ uuid, status: 'pending', operationalStatus: 'pending_validation', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590 * quantity, notes: String(body!.notes), createdAt: stamp, items: [{ ...item, quantity, amount: 1590 * quantity }], canReplace: true })
        result.orderUuid = uuid
      }
      if (session) session.revision = revision
      receipts.set(key, result)
      if (options.uncertain && !failed && path.endsWith('/orders/create')) { failed = true; return route.abort('failed') }
      return respond(result)
    }
    return route.fulfill({ status: 404, json: { code: 404 } })
  })
  await page.goto('/')
  await page.getByLabel('账号', { exact: true }).fill('counter-staff')
  await page.getByLabel('密码', { exact: true }).fill('test-only-password')
  await page.getByRole('button', { name: '登录', exact: true }).click()
  await page.getByRole('button', { name: 'Dining contract D1', exact: true }).click()
  await page.getByRole('button', { name: '连接门店', exact: true }).click()
  await expect(page.getByRole('heading', { name: '营业桌台', exact: true })).toBeVisible()
  return requests
}

async function viewTable(page: Page) {
  await page.getByRole('button', { name: '查看桌次', exact: true }).click()
  await expect(page.getByRole('button', { name: '点单／加菜', exact: true })).toBeVisible()
}
async function quote(page: Page) {
  await page.getByRole('button', { name: '点单／加菜', exact: true }).click()
  await page.getByRole('button', { name: '加菜 Soup Regular', exact: true }).click()
  await page.getByRole('button', { name: '获取服务端报价', exact: true }).click()
  await expect(page.getByRole('heading', { name: '确认服务端报价', exact: true })).toBeVisible()
}

for (const width of [320, 390, 768, 1280]) {
  test(`customer-opened dining allows staff additions and eligible edits at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    const requests = await setup(page)
    const card = page.locator('.dining-card')
    await expect(card).toContainText('Window table')
    await expect(card).toContainText('用餐中')
    await expect(card.getByRole('button', { name: '查看桌次', exact: true })).toBeVisible()
    expect(await card.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
    await viewTable(page)
    await expect(page.locator('.dining-detail-hero')).toContainText('未结订单金额')
    await expect(page.locator('.dining-round')).toHaveCount(1)
    await quote(page)
    await expect(page.getByRole('dialog').last()).toContainText('$15.90')
    await page.getByRole('button', { name: '确认下单', exact: true }).click()
    await expect(page.locator('.dining-round')).toHaveCount(2)
    await expect(page.getByRole('button', { name: '结束桌次', exact: true })).toBeDisabled()
    await quote(page)
    await page.getByRole('button', { name: '确认下单', exact: true }).click()
    await expect(page.locator('.dining-round')).toHaveCount(3)
    await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(2)
    await expect(page.getByRole('button', { name: '取消批次', exact: true })).toHaveCount(0)
    await expect(page.getByRole('button', { name: '结束桌次', exact: true })).toBeDisabled()
    await expect(page.getByRole('button', { name: '开台', exact: true })).toHaveCount(0)
    expect(requests.some((request) => request.path.endsWith('/open'))).toBe(false)
    expect(requests.filter((request) => request.path.endsWith('/orders/create'))).toHaveLength(2)
    expect(requests.some((request) => request.path.endsWith('/orders/replace') || request.path.endsWith('/cancel'))).toBe(false)
    expect(requests.some((request) => request.path.includes('/app/orders') || request.path.includes('/manage/') || request.path.includes('/payment'))).toBe(false)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  })
}

test('a customer-opened table with only terminal orders can end', async ({ page }) => {
  const requests = await setup(page)
  await viewTable(page)
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '结束桌次', exact: true })).toBeEnabled()
  await page.getByRole('button', { name: '结束桌次', exact: true }).click()
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(0)
  expect(requests.filter((request) => request.path.endsWith('/session/end'))).toHaveLength(1)
})

test('an edit-only employee can modify a customer order with a reviewed quote and reason', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true, editOnly: true })
  await page.getByRole('button', { name: '查看桌次', exact: true }).click()
  await expect(page.getByRole('button', { name: '点单／加菜', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '修改批次', exact: true }).click()
  await page.getByLabel('数量 Soup Regular', { exact: true }).fill('2')
  await page.getByLabel('操作原因', { exact: true }).fill('Guest requested two')
  await page.getByRole('button', { name: '获取服务端报价', exact: true }).click()
  await expect(page.getByRole('dialog').last()).toContainText('$31.80')
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(2)
  await expect(page.locator('.dining-round').first()).toContainText('已取消')
  const write = requests.find((request) => request.path.endsWith('/orders/replace'))!
  expect(write.body).toMatchObject({ replaceOrderUuid: '10000000-0000-4000-8000-000000000009', reason: 'Guest requested two', quoteToken: 'f'.repeat(64), commandUuid: expect.any(String) })
  expect(write.body).not.toHaveProperty('user')
  expect(requests.filter((request) => request.path.endsWith('/orders/create'))).toHaveLength(0)
})

test('session readers without counter-order grants see the session but no order rows or amounts', async ({ page }) => {
  const requests = await setup(page, { sessionOnly: true })
  await page.getByRole('button', { name: '查看桌次', exact: true }).click()
  await expect(page.getByRole('dialog').getByText('当前账号可查看场次，但没有关联订单及金额的读取权限。', { exact: true })).toBeVisible()
  await expect(page.locator('.dining-round')).toHaveCount(0)
  await expect(page.locator('.dining-detail-hero')).not.toContainText('未结订单金额')
  for (const name of ['点单／加菜', '修改批次', '结束桌次']) await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0)
  expect(requests.some((request) => request.path.endsWith('/catalog'))).toBe(false)
})

test('ordering permission alone does not enable customer-order modification', async ({ page }) => {
  await setup(page, { customerOrder: true, noReplace: true })
  await viewTable(page)
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
})

test('read-only dining grants never expose commands or load the menu', async ({ page }) => {
  const requests = await setup(page, { readOnly: true })
  await page.getByRole('button', { name: '查看桌次', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(1)
  for (const name of ['开台', '点单／加菜', '修改桌次', '结束桌次', '修改批次', '取消批次']) {
    await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0)
  }
  expect(requests.some((request) => request.path.endsWith('/catalog') || request.method !== 'GET' && request.path.includes('/counter-operations/'))).toBe(false)
})

test('quote conflicts keep the reviewed cart without pretending an order succeeded', async ({ page }) => {
  await setup(page, { conflict: true })
  await viewTable(page); await quote(page)
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  await expect(page.getByRole('dialog').last().getByRole('alert')).toContainText('记录已变化')
  await expect(page.getByRole('button', { name: '返回修改', exact: true })).toBeDisabled()
  await expect(page.locator('.dining-round')).toHaveCount(1)
})

test('an uncertain committed order retries with its original durable command key', async ({ page }) => {
  const requests = await setup(page, { uncertain: true })
  await viewTable(page); await quote(page)
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  await expect(page.getByRole('dialog').last().getByRole('alert')).toContainText('网络异常')
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(2)
  const writes = requests.filter((request) => request.path.endsWith('/orders/create'))
  expect(writes).toHaveLength(2)
  expect(writes[0]!.body).toEqual(writes[1]!.body)
})

test('offline dining submission is paused before sending a write', async ({ page, context }) => {
  const requests = await setup(page)
  await viewTable(page); await quote(page)
  await context.setOffline(true)
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  await expect(page.getByRole('dialog').last().getByRole('alert')).toContainText('网络不可用')
  expect(requests.some((request) => request.path.endsWith('/orders/create'))).toBe(false)
  await context.setOffline(false)
})

test('staff cannot open or order on an empty table', async ({ page }) => {
  const requests = await setup(page, { empty: true })
  await page.getByRole('button', { name: '查看桌次', exact: true }).click()
  await expect(page.getByRole('button', { name: '开台', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '点单／加菜', exact: true })).toHaveCount(0)
  await expect(page.locator('.dining-round')).toHaveCount(0)
  expect(requests.some((request) => request.method !== 'GET' && request.path.includes('/counter-operations/'))).toBe(false)
})
