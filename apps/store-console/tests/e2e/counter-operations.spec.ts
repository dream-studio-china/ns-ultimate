import { expect, test, type Page } from '@playwright/test'

const storeId = '10000000-0000-4000-8000-000000000001'
const counterId = '10000000-0000-4000-8000-000000000002'
const sessionId = '10000000-0000-4000-8000-000000000003'
const specId = '10000000-0000-4000-8000-000000000004'
const productId = '10000000-0000-4000-8000-000000000005'
const diningModeId = '10000000-0000-4000-8000-000000000010'
const participantRuleId = '10000000-0000-4000-8000-000000000011'
const categoryRuleId = '10000000-0000-4000-8000-000000000012'
const requiredItemRuleId = '10000000-0000-4000-8000-000000000013'
const requiredCheckoutRuleId = '10000000-0000-4000-8000-000000000014'
const store = { uuid: storeId, name: 'Dining contract', code: 'D1', currency: 'USD', timezone: 'UTC' }
const item = { specificationUuid: specId, productName: 'Soup', specificationName: 'Regular', quantity: 1, unitPrice: 1590, amount: 1590 }
const stamp = '2026-10-31T10:00:00+00:00'
const diningMode = {
  uuid: diningModeId,
  name: 'Hotpot',
  enabled: true,
  priority: 10,
  switchButtonLabel: 'Hotpot',
  availability: { allDay: true, daysOfWeek: [1, 2, 3, 4, 5, 6, 7], timeWindows: [] },
  catalog: { categoryUuids: [], productUuids: [] },
  rules: [{ uuid: requiredItemRuleId, name: 'Soup', type: 'fixed_item', specificationUuid: specId, quantity: 1, required: true, allowRemove: false }],
  checkoutRules: [
    { uuid: participantRuleId, name: '人数核对', title: '请确认用餐人数', type: 'confirm_participants', required: true, categoryUuid: '' },
    { uuid: requiredCheckoutRuleId, name: '菜品核对', title: '请确认以下菜品', type: 'confirm_required_items', required: true, categoryUuid: '' },
    { uuid: categoryRuleId, name: '饮料推荐', title: '是否需要饮料', type: 'suggest_category', required: false, categoryUuid: specId },
  ],
}

async function setup(page: Page, options: { readOnly?: boolean; sessionOnly?: boolean; createWithoutRead?: boolean; conflict?: boolean; uncertain?: boolean; empty?: boolean; emptySession?: boolean; participantMissing?: boolean; customerOrder?: boolean; editOnly?: boolean; cancelOnly?: boolean; noReplace?: boolean; diningMode?: boolean; boundModeSnapshotMissing?: boolean; storeModeOnly?: boolean; stringSettings?: boolean; mergeOnCheckout?: boolean; terminal?: boolean; mixedOrders?: boolean; batchPending?: boolean; checkoutQuantity?: number; delayedCheckoutProjection?: boolean; delayedCheckoutAdditionProjection?: boolean; confirmedCheckoutAdditions?: boolean } = {}) {
  const requests: { path: string; method: string; body: Record<string, unknown> | null }[] = []
  let revision = 0
  const hasBoundDiningMode = options.diningMode || options.boundModeSnapshotMissing
  let session: { uuid: string; participantCount: number | null; notes: string | null; startedAt: string; revision: number; diningModeUuid: string | null; diningMode: typeof diningMode | null } | null = options.empty ? null : {
    uuid: sessionId, participantCount: options.participantMissing ? null : options.emptySession ? 4 : 3, notes: options.emptySession ? 'Guests left' : 'Birthday', startedAt: stamp, revision,
    diningModeUuid: hasBoundDiningMode ? diningModeId : null,
    diningMode: options.diningMode ? diningMode : null,
  }
  const diningModes = { version: 1, modes: [diningMode] }
  const storeView = hasBoundDiningMode || options.storeModeOnly
    ? { ...store, settings: options.stringSettings ? JSON.stringify({ diningModes }) : { diningModes } }
    : store
  type TestOrder = { uuid: string; status: string; operationalStatus: string; paymentStatus: null; hasInvoice: boolean; currency: string; totalAmount: number; notes: string; createdAt: string; items: typeof item[]; canReplace: boolean; canCancel: boolean }
  const testOrder = (uuid: string, status: string, quantity = 1): TestOrder => ({ uuid, status, operationalStatus: status === 'confirmed' ? 'accepted' : status === 'pending' ? 'pending_validation' : 'fulfilled', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590 * quantity, notes: '', createdAt: stamp, items: [{ ...item, quantity, amount: 1590 * quantity }], canReplace: status === 'pending', canCancel: status === 'pending' })
  let orders: TestOrder[] = options.empty || options.emptySession ? [] : options.batchPending
    ? [
        testOrder('10000000-0000-4000-8000-000000000026', 'pending'),
        testOrder('10000000-0000-4000-8000-000000000027', 'pending'),
        testOrder('10000000-0000-4000-8000-000000000028', 'confirmed'),
      ]
    : options.mergeOnCheckout
    ? [testOrder('10000000-0000-4000-8000-000000000008', 'confirmed'), testOrder('10000000-0000-4000-8000-000000000009', 'confirmed')]
    : options.mixedOrders
      ? [
          testOrder('10000000-0000-4000-8000-000000000009', 'pending'),
          testOrder('10000000-0000-4000-8000-000000000020', 'draft'),
          testOrder('10000000-0000-4000-8000-000000000021', 'cancelled'),
          testOrder('10000000-0000-4000-8000-000000000022', 'paid'),
          testOrder('10000000-0000-4000-8000-000000000023', 'completed'),
          testOrder('10000000-0000-4000-8000-000000000024', 'refunded'),
          testOrder('10000000-0000-4000-8000-000000000025', 'merged'),
        ]
      : [testOrder('10000000-0000-4000-8000-000000000009', options.terminal ? 'completed' : options.customerOrder ? 'pending' : 'confirmed', options.checkoutQuantity)]
  const receipts = new Map<string, Record<string, unknown>>()
  let delayedProjectionReads = 0
  let delayedAdditionProjectionReads = 0
  let delayedAddition: TestOrder | null = null
  const version = () => revision.toString(16).padStart(64, 'a')
  const view = () => {
    const base = { counter: { uuid: counterId, code: 'A01', name: 'Window table', capacity: 4, enabled: true }, session,
      currency: 'USD', canEnd: session !== null && orders.every((order) => ['cancelled', 'completed'].includes(order.status)), version: version() }
    if (options.sessionOnly || options.createWithoutRead) return { ...base, ordersVisible: false }
    return { ...base, ordersVisible: true,
      orders, openAmount: orders.filter((order) => ['draft', 'pending', 'confirmed'].includes(order.status)).reduce((sum, order) => sum + order.totalAmount, 0), orderedAmount: orders.filter((order) => order.status !== 'cancelled').reduce((sum, order) => sum + order.totalAmount, 0), openOrderCount: orders.filter((order) => ['draft', 'pending', 'confirmed'].includes(order.status)).length,
      paymentHold: false }
  }
  let failed = false
  await page.route('**/api/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname
    const method = request.method()
    const body = request.postData() ? request.postDataJSON() as Record<string, unknown> : null
    requests.push({ path, method, body })
    const respond = (data: unknown) => route.fulfill({ json: { code: 0, data } })
    const list = (items: unknown[]) => respond({ items, page: Number(url.searchParams.get('page') ?? 1), total: items.length, hasNext: false })
    if (path === '/api/auth/login') return route.fulfill({ json: { access_token: 'counter-test-only', refresh_token: 'refresh-test-only', expires_in: 600 } })
    expect(request.headers().authorization).toBe('Bearer counter-test-only')
    if (path === '/api/v1/app/stores') return route.fulfill({ json: { code: 0, data: [storeView], paginator: { page: 1, has_next: false } } })
    if (path === `/api/v1/app/stores/${storeId}`) return respond(storeView)
    if (path === '/api/v1/app/products') return route.fulfill({ json: { code: 0, data: [{
      id: 1, uuid: productId, name: 'Soup', status: 'active', metadata: null, category: null,
      specifications: [{ id: 1, uuid: specId, name: 'Regular', price: 1590, status: 'active', sort: 0, metadata: null }],
    }], paginator: { page: 1, has_next: false } } })
    if (path === `/api/v1/app/specifications/${specId}`) return respond({
      id: 1, uuid: specId, name: 'Regular', price: 1590, status: 'active', metadata: null,
      product: { uuid: productId, __toString: '#1 Soup' },
    })
    if (method === 'POST' && path.endsWith('/merge/preview')) {
      expect(body!.version).toBe(version())
      const sources = orders.filter((order) => order.status === 'confirmed')
      expect(sources.length).toBeGreaterThanOrEqual(2)
      const quantity = sources.flatMap((order) => order.items).reduce((sum, line) => sum + line.quantity, 0)
      const totalAmount = sources.reduce((sum, order) => sum + order.totalAmount, 0)
      return respond({
        sourceOrderUuids: sources.map((order) => order.uuid), targetUserUuid: storeId,
        items: [{ ...item, quantity, amount: totalAmount }], sourceTotalAmount: totalAmount,
        totalAmount, differenceAmount: 0, currency: 'USD', quoteToken: 'f'.repeat(64),
      })
    }
    if (method === 'POST' && path.endsWith('/merge/confirm')) {
      expect(body!.version).toBe(version())
      expect(body!.quoteToken).toBe('f'.repeat(64))
      const sources = orders.filter((order) => order.status === 'confirmed')
      expect(sources.length).toBeGreaterThanOrEqual(2)
      const quantity = sources.flatMap((order) => order.items).reduce((sum, line) => sum + line.quantity, 0)
      const totalAmount = sources.reduce((sum, order) => sum + order.totalAmount, 0)
      const target: TestOrder = { ...testOrder('10000000-0000-4000-8000-000000000019', 'confirmed', quantity), totalAmount, canReplace: !options.delayedCheckoutProjection }
      orders = [...orders.map((order) => order.status === 'confirmed' ? { ...order, status: 'merged', canReplace: false, canCancel: false } : order), target]
      revision += 1
      if (session) session = { ...session, revision }
      return respond({ counterUuid: counterId, sessionUuid: sessionId, orderUuid: target.uuid })
    }
    if (path.endsWith('/membership')) return respond({ status: 'active', role: 'manager' })
    if (path.endsWith('/users/me')) return respond({ uuid: storeId, username: 'counter-staff', roles: ['ROLE_USER'] })
    if (path.endsWith('/authorization/me')) {
      const sessionActions = options.sessionOnly || options.readOnly ? ['read'] : ['read', 'update', 'end']
      const orderActions = options.sessionOnly ? [] : options.createWithoutRead ? ['create'] : options.readOnly ? ['read'] : options.editOnly ? ['read', 'replace'] : options.cancelOnly ? ['read', 'cancel'] : options.noReplace ? ['read', 'create'] : ['read', 'create', 'replace', 'cancel', 'merge']
      const storeScopes = {
        ...Object.fromEntries(sessionActions.map((action) => [`store:counter_session:${action}`, [storeId]])),
        ...Object.fromEntries(orderActions.map((action) => [`store:counter_order:${action}`, [storeId]])),
      }
      return respond({ permissions: Object.keys(storeScopes), storeScopes })
    }
    if (path.endsWith('/counter-operations/counters')) return list([view()])
    if (path.endsWith(`/counter-operations/counters/${counterId}`)) {
      if (options.delayedCheckoutAdditionProjection && delayedAddition && ++delayedAdditionProjectionReads >= 3) {
        orders.push(delayedAddition)
        delayedAddition = null
        revision += 1
        if (session) session = { ...session, revision }
      }
      if (options.delayedCheckoutProjection && orders.some((order) => order.uuid.endsWith('0019') && !order.canReplace) && ++delayedProjectionReads >= 3) {
        orders = orders.map((order) => order.uuid.endsWith('0019') ? { ...order, canReplace: true } : order)
        revision += 1
        if (session) session = { ...session, revision }
      }
      return respond(view())
    }
    if (path.endsWith('/counter-operations/catalog')) return list([{ uuid: specId, productName: 'Soup', name: 'Regular', price: 1590, available: true }])
    if (method === 'POST' && path === '/api/v1/app/orders') {
      if (options.conflict) return route.fulfill({ status: 409, json: { code: 409, message: 'order_changed' } })
      if (options.uncertain && !failed) { failed = true; return route.abort('failed') }
      revision += 1
      const metadata = (body!.metadata ?? {}) as Record<string, unknown>
      const quantity = (body!.items as { quantity: number }[])[0]!.quantity
      if (!session) session = { uuid: sessionId, participantCount: null, notes: null, startedAt: stamp, revision, diningModeUuid: null, diningMode: null }
      if (metadata.diningModeUuid === diningModeId) session = { ...session, diningModeUuid: diningModeId, diningMode, revision }
      const uuid = `10000000-0000-4000-8000-${String(revision + 10).padStart(12, '0')}`
      orders.push({ uuid, status: 'pending', operationalStatus: 'pending_validation', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590 * quantity, notes: String(body!.notes ?? ''), createdAt: stamp, items: [{ ...item, quantity, amount: 1590 * quantity }], canReplace: true, canCancel: true })
      return respond({ uuid })
    }
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
      if (path.endsWith('/session/update')) session = { ...session!, participantCount: Number(body!.participantCount), notes: String(body!.notes), revision }
      if (path.endsWith('/session/end')) { session = null; orders = [] }
      const result: Record<string, unknown> = { counterUuid: counterId, sessionUuid: sessionId }
      if (path.endsWith('/orders/cancel')) {
        expect(body!.reason).toBeTruthy()
        const target = orders.find((order) => order.uuid === body!.orderUuid)!
        expect(target.canCancel).toBe(true)
        orders = orders.map((order) => order.uuid === body!.orderUuid ? { ...order, status: 'cancelled', operationalStatus: 'cancelled', canReplace: false, canCancel: false } : order)
        result.orderUuid = body!.orderUuid
        result.status = 'cancelled'
      }
      if (path.endsWith('/orders/create') || path.endsWith('/orders/replace')) {
        const uuid = `10000000-0000-4000-8000-${String(revision + 10).padStart(12, '0')}`
        const quantity = (body!.items as { quantity: number }[])[0]!.quantity
        if (path.endsWith('/orders/replace')) {
          expect(body!.reason).toBeTruthy()
          orders = orders.map((order) => order.uuid === body!.replaceOrderUuid ? { ...order, status: 'cancelled', operationalStatus: 'cancelled', canReplace: false, canCancel: false } : order)
          result.replacedOrderUuid = body!.replaceOrderUuid
        }
        const status = options.confirmedCheckoutAdditions ? 'confirmed' : 'pending'
        const createdOrder: TestOrder = { uuid, status, operationalStatus: status === 'confirmed' ? 'accepted' : 'pending_validation', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590 * quantity, notes: String(body!.notes), createdAt: stamp, items: [{ ...item, quantity, amount: 1590 * quantity }], canReplace: true, canCancel: true }
        if (options.delayedCheckoutAdditionProjection && path.endsWith('/orders/create')) delayedAddition = createdOrder
        else orders.push(createdOrder)
        result.orderUuid = uuid
        if (body!.diningModeUuid === diningModeId) session = { ...session!, diningModeUuid: diningModeId, diningMode, revision }
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
  await expect(page.locator('.dining-card')).toBeVisible()
  return requests
}

async function viewTable(page: Page) {
  await page.locator('.dining-card-title').click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail.getByRole('button', { name: '加单', exact: true })).toBeVisible()
}
async function prepareAddRound(page: Page) {
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '加单', exact: true }).click()
  await page.getByRole('button', { name: '加菜 Soup Regular', exact: true }).click()
  await expect(page.getByRole('button', { name: '获取服务端报价', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '确认下单', exact: true })).toBeInViewport()
}

async function addRound(page: Page) {
  await prepareAddRound(page)
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  const review = page.getByRole('dialog', { name: '订单确认' })
  await expect(review).toContainText('Soup')
  await review.getByRole('button', { name: '创建订单', exact: true }).click()
}

test('pulling down refreshes the current cashier data', async ({ page }) => {
  const requests = await setup(page)
  const listPath = `/api/v1/store/${storeId}/counter-operations/counters`
  const before = requests.filter((request) => request.method === 'GET' && request.path === listPath).length
  await page.evaluate(() => window.scrollTo(0, 0))
  const client = await page.context().newCDPSession(page)
  await client.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ id: 1, x: 195, y: 180 }] })
  for (const y of [250, 350, 500]) {
    await client.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ id: 1, x: 195, y }] })
    await page.waitForTimeout(150)
  }
  await page.waitForTimeout(500)
  await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] })

  await expect.poll(() => requests.filter((request) => request.method === 'GET' && request.path === listPath).length).toBeGreaterThan(before)
  await expect(page.getByText('刷新完成', { exact: true })).toBeVisible()
  await expect(page.locator('.dining-card')).toContainText('Window table')
})

for (const width of [320, 390, 768, 1280]) {
  test(`customer-opened dining allows staff additions and eligible edits at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    const requests = await setup(page)
    const card = page.locator('.dining-card')
    await expect(card).toContainText('Window table')
    await expect(card).toContainText('用餐中')
    await expect(card.locator('.dining-card-title')).toHaveText('Window table')
    expect(await card.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
    await viewTable(page)
    await expect(page.locator('.dining-detail-hero')).toContainText('未结订单金额')
    await expect(page.locator('.dining-round')).toHaveCount(1)
    await addRound(page)
    await expect(page.locator('.dining-round')).toHaveCount(2)
    await expect(page.getByRole('button', { name: '结束桌次', exact: true })).toBeDisabled()
    await addRound(page)
    await expect(page.locator('.dining-round')).toHaveCount(3)
    await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(2)
    await expect(page.getByRole('button', { name: '取消批次', exact: true })).toHaveCount(0)
    await expect(page.getByRole('button', { name: '结束桌次', exact: true })).toBeDisabled()
    await expect(page.getByRole('button', { name: '开台', exact: true })).toHaveCount(0)
    expect(requests.some((request) => request.path.endsWith('/open'))).toBe(false)
    const additions = requests.filter((request) => request.method === 'POST' && request.path === '/api/v1/app/orders')
    expect(additions).toHaveLength(2)
    expect(additions.every((request) => (request.body?.metadata as Record<string, unknown> | undefined)?.counterUuid === counterId)).toBe(true)
    expect(requests.some((request) => request.path.endsWith('/orders/quote') || request.path.endsWith('/orders/create') || request.path.endsWith('/orders/replace') || request.path.endsWith('/cancel'))).toBe(false)
    expect(requests.some((request) => request.path.includes('/manage/') || request.path.includes('/payment'))).toBe(false)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  })
}

test('a customer-opened table with only terminal orders can end', async ({ page }) => {
  const requests = await setup(page, { terminal: true })
  await page.locator('.dining-card-title').click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail.getByRole('button', { name: '点餐', exact: true })).toBeVisible()
  await expect(page.locator('.dining-round')).toHaveCount(0)
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '结束桌次', exact: true })).toBeEnabled()
  await page.getByRole('button', { name: '结束桌次', exact: true }).click()
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(0)
  expect(requests.filter((request) => request.path.endsWith('/session/end'))).toHaveLength(1)
})

test('staff can clear an opened table before it has any orders', async ({ page }) => {
  const requests = await setup(page, { emptySession: true })
  await page.locator('.dining-card-title').click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail.locator('.dining-detail-hero')).toContainText('4 位')
  await expect(detail.getByRole('button', { name: '结束桌次', exact: true })).toHaveCount(0)
  await detail.getByRole('button', { name: '清空点餐', exact: true }).click()

  const clear = page.getByRole('dialog', { name: '清空点餐', exact: true })
  await expect(clear).toContainText('本次开台尚未产生订单')
  await clear.getByRole('button', { name: '确认清空', exact: true }).click()
  await expect(clear).toHaveCount(0)
  await expect(detail.getByRole('button', { name: '点餐', exact: true })).toBeVisible()
  expect(requests.filter((request) => request.path.endsWith('/session/end'))).toHaveLength(1)
  await expect(page.locator('.dining-card')).toContainText('空闲')
})

test('an edit-only employee can modify a customer order with a reviewed quote and reason', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true, editOnly: true })
  await page.locator('.dining-card-title').click()
  await expect(page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '加单', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '修改批次', exact: true }).click()
  const soup = page.locator('.dining-menu li').filter({ hasText: 'Soup' })
  await expect(soup.locator('.dining-product-quantity')).toContainText('×1')
  await soup.getByRole('button', { name: '增加数量 Soup Regular', exact: true }).click()
  await expect(soup.locator('.dining-product-quantity')).toContainText('×2')
  await page.getByLabel('操作原因', { exact: true }).fill('Guest requested two')
  await page.getByRole('button', { name: '获取服务端报价', exact: true }).click()
  await expect(page.getByRole('dialog').last()).toContainText('$31.80')
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(1)
  await expect(page.locator('.dining-round').first()).toContainText('待处理')
  await expect(page.locator('.dining-round').first()).not.toContainText('已取消')
  const write = requests.find((request) => request.path.endsWith('/orders/replace'))!
  expect(write.body).toMatchObject({ replaceOrderUuid: '10000000-0000-4000-8000-000000000009', reason: 'Guest requested two', quoteToken: 'f'.repeat(64), commandUuid: expect.any(String) })
  expect(write.body).not.toHaveProperty('user')
  expect(requests.filter((request) => request.path.endsWith('/orders/create'))).toHaveLength(0)
})

test('session readers without counter-order grants see the session but no order rows or amounts', async ({ page }) => {
  const requests = await setup(page, { sessionOnly: true })
  await page.locator('.dining-card-title').click()
  await expect(page.getByRole('dialog').getByText('当前账号可查看场次，但没有关联订单及金额的读取权限。', { exact: true })).toBeVisible()
  await expect(page.locator('.dining-round')).toHaveCount(0)
  await expect(page.locator('.dining-detail-hero')).not.toContainText('未结订单金额')
  for (const name of ['加单', '修改批次', '结束桌次']) await expect(page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name, exact: true })).toHaveCount(0)
  expect(requests.some((request) => request.path.endsWith('/catalog'))).toBe(false)
})

test('ordering permission alone does not enable customer-order modification', async ({ page }) => {
  await setup(page, { customerOrder: true, noReplace: true })
  await viewTable(page)
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
})

test('an eligible order can be cancelled only with a reason and is removed from the open orders', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('button', { name: '取消订单', exact: true }).click()
  await page.getByRole('button', { name: '保存', exact: true }).click()
  expect(requests.filter((request) => request.path.endsWith('/orders/cancel'))).toHaveLength(0)
  await page.getByLabel('取消原因', { exact: true }).fill('Guest left')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(0)
  const write = requests.find((request) => request.path.endsWith('/orders/cancel'))!
  expect(write.body).toMatchObject({ orderUuid: '10000000-0000-4000-8000-000000000009', reason: 'Guest left', commandUuid: expect.any(String), version: expect.any(String) })
})

test('staff can cancel every pending order for one table after confirmation', async ({ page }) => {
  const requests = await setup(page, { batchPending: true })
  await page.locator('.dining-card-title').click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail.locator('.dining-round')).toHaveCount(3)
  const cancelRequests = () => requests.filter((request) => request.path.endsWith('/orders/cancel'))

  await detail.getByRole('button', { name: '取消待处理订单', exact: true }).click()
  expect(cancelRequests()).toHaveLength(0)
  const confirmation = page.getByRole('dialog', { name: '取消本桌待处理订单？' })
  await expect(confirmation).toBeVisible()
  await expect(page.getByText('将取消当前桌台 2 笔待处理订单', { exact: false })).toBeVisible()
  await confirmation.getByRole('button', { name: '确认取消', exact: true }).click()

  await expect.poll(() => cancelRequests().length).toBe(2)
  await expect(confirmation).toHaveCount(0)
  await expect(detail.locator('.dining-round')).toHaveCount(1)
  await expect(detail.locator('.dining-round')).toContainText('已确认')
  await expect(detail.getByRole('button', { name: '取消待处理订单', exact: true })).toHaveCount(0)

  const writes = cancelRequests()
  expect(writes.map((request) => request.body?.orderUuid)).toEqual([
    '10000000-0000-4000-8000-000000000026',
    '10000000-0000-4000-8000-000000000027',
  ])
  expect(new Set(writes.map((request) => request.body?.version)).size).toBe(2)
  expect(writes.every((request) => request.body?.reason === '收银端批量取消本桌待处理订单')).toBe(true)
})

test('table details show only effective unsettled orders', async ({ page }) => {
  await setup(page, { mixedOrders: true })
  await page.locator('.dining-card-title').click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail.locator('.dining-round')).toHaveCount(1)
  await expect(detail.locator('.dining-round')).toContainText('待处理')
  await expect(detail.locator('.dining-round')).not.toContainText('已取消')
  await expect(detail.locator('.dining-round')).not.toContainText('已付款')
  await expect(detail.locator('.dining-round')).not.toContainText('已完成')
  await expect(detail.locator('.dining-round')).not.toContainText('已退款')
  await expect(detail.locator('.dining-round')).not.toContainText('已并单')
  await expect(detail.locator('.dining-section-heading span')).toHaveText('1')
})

test('cancellation and replacement use independent grants', async ({ page }) => {
  await setup(page, { customerOrder: true, editOnly: true })
  await page.locator('.dining-card-title').click()
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '取消订单', exact: true })).toHaveCount(0)
})

test('cancel-only staff can cancel without ordering or replacement access', async ({ page }) => {
  await setup(page, { customerOrder: true, cancelOnly: true })
  await page.locator('.dining-card-title').click()
  await expect(page.getByRole('button', { name: '取消订单', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
  await expect(page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '加单', exact: true })).toHaveCount(0)
})

test('read-only dining grants never expose commands or load the menu', async ({ page }) => {
  const requests = await setup(page, { readOnly: true })
  await page.locator('.dining-card-title').click()
  await expect(page.locator('.dining-round')).toHaveCount(1)
  for (const name of ['开台', '加单', '修改桌次', '结束桌次', '修改批次', '取消订单', '取消批次']) {
    await expect(page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name, exact: true })).toHaveCount(0)
  }
  expect(requests.some((request) => request.path.endsWith('/catalog') || request.method !== 'GET' && request.path.includes('/counter-operations/'))).toBe(false)
})

test('add-round errors keep the cart without pretending an order succeeded', async ({ page }) => {
  await setup(page, { conflict: true })
  await viewTable(page); await prepareAddRound(page)
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  const review = page.getByRole('dialog', { name: '订单确认' })
  await expect(review).toContainText('Soup')
  await review.getByRole('button', { name: '创建订单', exact: true }).click()
  await expect(review.getByRole('alert')).toContainText('记录已变化')
  await expect(review).toContainText('Soup')
  await review.getByRole('button', { name: '返回修改', exact: true }).click()
  await expect(page.locator('.dining-product-quantity')).toContainText('×1')
})

test('an uncertain add-round requires refresh instead of automatic duplicate submission', async ({ page }) => {
  const requests = await setup(page, { uncertain: true })
  await viewTable(page); await prepareAddRound(page)
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  const review = page.getByRole('dialog', { name: '订单确认' })
  await review.getByRole('button', { name: '创建订单', exact: true }).click()
  await expect(review.getByRole('alert')).toContainText('网络异常')
  await expect(review).toContainText('Soup')
  expect(requests.filter((request) => request.method === 'POST' && request.path === '/api/v1/app/orders')).toHaveLength(1)
})

test('offline dining submission is paused before sending a write', async ({ page, context }) => {
  const requests = await setup(page)
  await viewTable(page); await prepareAddRound(page)
  await context.setOffline(true)
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  const review = page.getByRole('dialog', { name: '订单确认' })
  await review.getByRole('button', { name: '创建订单', exact: true }).click()
  await expect(review.getByRole('alert')).toContainText('网络不可用')
  expect(requests.some((request) => request.path === '/api/v1/app/orders')).toBe(false)
  await context.setOffline(false)
})

test('the first order creates the table session without opening it separately', async ({ page }) => {
  const requests = await setup(page, { empty: true })
  await page.locator('.dining-card-title').click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail.getByRole('button', { name: '点餐', exact: true })).toBeVisible()
  await detail.getByRole('button', { name: '点餐', exact: true }).click()
  await expect(page.getByRole('dialog').last()).toContainText('由订单自动建立桌次')
  expect(requests.filter((request) => request.path.endsWith('/session/open'))).toHaveLength(0)
  await page.getByRole('button', { name: '加菜 Soup Regular', exact: true }).click()
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  const review = page.getByRole('dialog', { name: '订单确认' })
  await expect(review).toContainText('Soup')
  expect(requests.filter((request) => request.path === '/api/v1/app/orders')).toHaveLength(0)
  await review.getByRole('button', { name: '创建订单', exact: true }).click()
  await expect(detail).toBeVisible()
  await expect(detail).toContainText('待处理')
  await expect(page.locator('.dining-card')).toHaveClass(/has-orders/)
  await expect(detail.getByRole('button', { name: '加单', exact: true })).toBeVisible()
  expect(requests.filter((request) => request.path === '/api/v1/app/orders')).toHaveLength(1)
})

test('order submission stores the selected dining mode and checkout uses its saved rules', async ({ page }) => {
  const requests = await setup(page, { empty: true, diningMode: true, stringSettings: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '点餐', exact: true }).click()
  const editor = page.getByRole('dialog', { name: '点餐' })
  await expect(editor.locator('.dining-mode-current')).toContainText('Hotpot')
  await expect(editor.locator('.dining-product-quantity')).toContainText('×1')
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  const review = page.getByRole('dialog', { name: '订单确认' })
  await expect(review).toContainText('Hotpot')
  await expect(review).toContainText('Soup')
  await expect(review).toContainText('Regular')
  expect(requests.some((request) => request.path === '/api/v1/app/orders')).toBe(false)
  await review.getByRole('button', { name: '创建订单', exact: true }).click()
  const detail = page.getByRole('dialog', { name: '查看桌次' })
  await expect(detail).toContainText('待处理')
  await detail.getByRole('button', { name: '结账', exact: true }).click()
  const checkoutReview = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(checkoutReview).toContainText('请确认用餐人数')
  await expect(checkoutReview).toContainText('请确认以下菜品')
  await expect(checkoutReview).toContainText('是否需要饮料')
  await expect(checkoutReview).toContainText('本次结账订单')
  await checkoutReview.getByRole('button', { name: '按规则核对并调整订单', exact: true }).click()
  const editorCheckout = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(editorCheckout).toContainText('先按本桌点餐方式核对并调整最终菜品')
  await expect(editorCheckout).toContainText('请确认用餐人数')
  await expect(editorCheckout.locator('.dining-product-quantity')).toContainText('×1')
  await editorCheckout.getByRole('button', { name: '核对结账菜品', exact: true }).click()
  await editorCheckout.getByRole('button', { name: '确认菜品并整理订单', exact: true }).click()
  const checkout = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(checkout).toContainText('按本桌点餐模式核对')
  await expect(checkout).toContainText('请确认用餐人数')
  await expect(checkout).toContainText('请确认以下菜品')
  await expect(checkout).toContainText('Soup · Regular')
  await expect(checkout).toContainText('是否需要饮料')
  const write = requests.find((request) => request.path === '/api/v1/app/orders')!
  expect(write.body).toMatchObject({ metadata: { counterUuid: counterId, diningModeUuid: diningModeId } })
})

test('checkout participant rule can save a missing guest count before order review', async ({ page }) => {
  const requests = await setup(page, { diningMode: true, participantMissing: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '结账', exact: true }).click()
  const review = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(review).toContainText('本桌尚未记录用餐人数')
  await review.getByRole('button', { name: '按规则核对并调整订单', exact: true }).click()
  const editor = page.getByRole('dialog', { name: '结账', exact: true })
  const guests = editor.locator('.dining-guest-picker')
  await expect(guests).toBeVisible()
  await guests.getByRole('button', { name: '2', exact: true }).click()
  await expect(editor).toContainText('当前记录用餐人数为 2 位')
  await editor.getByRole('button', { name: '核对结账菜品', exact: true }).click()
  await editor.getByRole('button', { name: '确认菜品并整理订单', exact: true }).click()
  await expect(page.locator('.dining-settlement')).toContainText('当前记录用餐人数为 2 位')
  const update = requests.find((request) => request.path.endsWith('/session/update'))
  expect(update?.body).toMatchObject({ participantCount: 2, notes: 'Birthday' })
})

test('staff can clear an unordered cart from the ordering sheet', async ({ page }) => {
  const requests = await setup(page, { empty: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '点餐', exact: true }).click()
  await page.getByRole('button', { name: '加菜 Soup Regular', exact: true }).click()
  await expect(page.locator('.dining-product-quantity')).toContainText('×1')
  await page.getByRole('button', { name: '清空本次点餐', exact: true }).click()
  await expect(page.locator('.dining-product-quantity')).toHaveCount(0)
  expect(requests.filter((request) => request.path.endsWith('/session/open'))).toHaveLength(0)
  expect(requests.some((request) => request.path === '/api/v1/app/orders')).toBe(false)
})

test('checkout review merges confirmed orders when the session has no dining mode', async ({ page }) => {
  const requests = await setup(page, { mergeOnCheckout: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '结账', exact: true }).click()
  const review = page.getByRole('dialog', { name: '结账', exact: true })
  await review.getByRole('button', { name: '按规则核对并调整订单', exact: true }).click()
  const editor = page.getByRole('dialog', { name: '结账', exact: true })
  await editor.getByRole('button', { name: '核对结账菜品', exact: true }).click()
  await editor.getByRole('button', { name: '确认菜品并整理订单', exact: true }).click()
  const checkout = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(checkout).toContainText('#10000000')
  await expect(checkout).toContainText('$31.80')
  expect(requests.filter((request) => request.method === 'POST' && request.path.endsWith('/merge/preview'))).toHaveLength(1)
  expect(requests.filter((request) => request.method === 'POST' && request.path.endsWith('/merge/confirm'))).toHaveLength(1)
})

test('checkout without a saved mode ignores current store modes and merges directly', async ({ page }) => {
  const requests = await setup(page, { mergeOnCheckout: true, storeModeOnly: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '结账', exact: true }).click()
  const review = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(review).toContainText('本桌订单没有保存点餐模式')
  await expect(review).not.toContainText('请确认用餐人数')
  await review.getByRole('button', { name: '按规则核对并调整订单', exact: true }).click()
  const editor = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(editor.locator('.dining-mode-current')).toHaveCount(0)
  await expect(editor.locator('.dining-checkout-rules')).toHaveCount(0)
  await editor.getByRole('button', { name: '核对结账菜品', exact: true }).click()
  await editor.getByRole('button', { name: '确认菜品并整理订单', exact: true }).click()
  await expect(page.locator('.dining-settlement')).toContainText('本桌订单没有保存点餐模式')
  expect(requests.filter((request) => request.path.endsWith('/merge/preview'))).toHaveLength(1)
  expect(requests.filter((request) => request.path.endsWith('/merge/confirm'))).toHaveLength(1)
  expect(requests.some((request) => request.path.endsWith('/orders/create') || request.path.endsWith('/orders/replace'))).toBe(false)
})

test('checkout resolves the bound mode when the session response omits its snapshot', async ({ page }) => {
  await setup(page, { boundModeSnapshotMissing: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '结账', exact: true }).click()
  const review = page.getByRole('dialog', { name: '结账', exact: true })

  await expect(review).not.toContainText('本桌订单没有保存点餐模式')
  await expect(review).toContainText('请确认用餐人数')
  await expect(review).toContainText('本次结账订单')
})

test('checkout additions create a separate order and then merge it with the table orders', async ({ page }) => {
  const requests = await setup(page, { confirmedCheckoutAdditions: true, delayedCheckoutAdditionProjection: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '结账', exact: true }).click()
  const review = page.getByRole('dialog', { name: '结账', exact: true })
  await review.getByRole('button', { name: '按规则核对并调整订单', exact: true }).click()
  const editor = page.getByRole('dialog', { name: '结账', exact: true })
  await editor.getByRole('button', { name: '增加数量 Soup Regular', exact: true }).click()
  await expect(editor.locator('.dining-product-quantity')).toContainText('×2')
  await expect(editor.locator('.dining-checkout-plan')).toContainText('需加单')
  await editor.getByRole('button', { name: '核对结账菜品', exact: true }).click()
  await editor.getByRole('button', { name: '确认菜品并整理订单', exact: true }).click()

  const checkout = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(checkout).toContainText('#10000000')
  await expect(checkout).toContainText('$31.80')
  const create = requests.find((request) => request.path.endsWith('/orders/create'))!
  expect(create.body).toMatchObject({ items: [{ specificationUuid: specId, quantity: 1 }] })
  expect(requests.filter((request) => request.path.endsWith('/merge/preview'))).toHaveLength(1)
  expect(requests.filter((request) => request.path.endsWith('/merge/confirm'))).toHaveLength(1)
  expect(requests.some((request) => request.path.endsWith('/orders/replace'))).toBe(false)
})

test('checkout reductions wait for projection, merge, and then replace the consolidated order', async ({ page }) => {
  const requests = await setup(page, { mergeOnCheckout: true, delayedCheckoutProjection: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '结账', exact: true }).click()
  const review = page.getByRole('dialog', { name: '结账', exact: true })
  await review.getByRole('button', { name: '按规则核对并调整订单', exact: true }).click()
  const editor = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(editor.locator('.dining-product-quantity')).toContainText('×2')
  await editor.getByRole('button', { name: '减少数量 Soup Regular', exact: true }).click()
  await expect(editor.locator('.dining-checkout-plan')).toContainText('需扣减')
  await editor.getByRole('button', { name: '核对结账菜品', exact: true }).click()
  await editor.getByRole('button', { name: '确认菜品并整理订单', exact: true }).click()

  const checkout = page.getByRole('dialog', { name: '结账', exact: true })
  await expect(checkout).toContainText('#10000000')
  await expect(checkout).toContainText('$15.90')
  const writes = requests.filter((request) => request.method === 'POST').map((request) => request.path)
  expect(writes.indexOf(`/api/v1/store/${storeId}/counter-operations/counters/${counterId}/merge/confirm`)).toBeLessThan(writes.indexOf(`/api/v1/store/${storeId}/counter-operations/counters/${counterId}/orders/replace`))
  const replace = requests.find((request) => request.path.endsWith('/orders/replace'))!
  expect(replace.body).toMatchObject({ replaceOrderUuid: '10000000-0000-4000-8000-000000000019', items: [{ specificationUuid: specId, quantity: 1 }] })
  expect(requests.some((request) => request.path.endsWith('/orders/create'))).toBe(false)
})

test('an empty table still offers ordering when order rows are redacted', async ({ page }) => {
  const requests = await setup(page, { empty: true, createWithoutRead: true })
  await page.locator('.dining-card-title').click()
  await page.getByRole('dialog', { name: '查看桌次' }).getByRole('button', { name: '点餐', exact: true }).click()
  await expect(page.getByRole('dialog')).toContainText('由订单自动建立桌次')
  expect(requests.filter((request) => request.path.endsWith('/session/open'))).toHaveLength(0)
  expect(requests.some((request) => request.path.endsWith('/orders/create'))).toBe(false)
})
