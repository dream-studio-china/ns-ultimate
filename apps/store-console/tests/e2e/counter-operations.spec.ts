import { expect, test, type Page } from '@playwright/test'

const storeId = '10000000-0000-4000-8000-000000000001'
const counterId = '10000000-0000-4000-8000-000000000002'
const sessionId = '10000000-0000-4000-8000-000000000003'
const specId = '10000000-0000-4000-8000-000000000004'
const productId = '10000000-0000-4000-8000-000000000005'
const categoryId = '10000000-0000-4000-8000-000000000006'
const invoiceId = '10000000-0000-4000-8000-000000000007'
const orderId = '10000000-0000-4000-8000-000000000009'
const store = { uuid: storeId, name: 'Dining contract', code: 'D1', currency: 'USD', timezone: 'UTC' }
const item = { specificationUuid: specId, productName: 'Soup', specificationName: 'Regular', quantity: 1, unitPrice: 1590, amount: 1590 }
const stamp = '2026-10-31T10:00:00+00:00'

async function setup(page: Page, options: { readOnly?: boolean; sessionOnly?: boolean; conflict?: boolean; uncertain?: boolean; empty?: boolean; customerOrder?: boolean; pendingOrder?: boolean; payingOrder?: boolean; failedInvoice?: boolean; paymentClose?: 'success' | 'already-paid' | 'network'; paymentCloseAccess?: boolean; editOnly?: boolean; cancelOnly?: boolean; noReplace?: boolean } = {}) {
  const requests: { path: string; method: string; body: Record<string, unknown> | null }[] = []
  let revision = 0
  let session: { uuid: string; participantCount: number; notes: string; startedAt: string; revision: number } | null = options.empty ? null : { uuid: sessionId, participantCount: 3, notes: 'Birthday', startedAt: stamp, revision }
  const initialStatus = options.pendingOrder ? 'pending' : options.customerOrder ? 'confirmed' : 'completed'
  let orders: { uuid: string; status: string; operationalStatus: string; paymentStatus: string | null; hasInvoice: boolean; currency: string; totalAmount: number; notes: string; createdAt: string; items: typeof item[]; diningModeUuid: string; diningModeName: string; canReplace: boolean; canCancel: boolean }[] = options.empty ? [] : [{ uuid: orderId, status: initialStatus, operationalStatus: initialStatus === 'completed' ? 'fulfilled' : 'pending_validation', paymentStatus: options.payingOrder ? 'paying' : options.failedInvoice ? 'failed' : null, hasInvoice: Boolean(options.failedInvoice), currency: 'USD', totalAmount: 1590, notes: '', createdAt: stamp, items: [item], diningModeUuid: 'dining-mode-hotpot', diningModeName: '火锅', canReplace: initialStatus === 'confirmed', canCancel: initialStatus === 'pending' || initialStatus === 'confirmed' }]
  const receipts = new Map<string, Record<string, unknown>>()
  const version = () => revision.toString(16).padStart(64, 'a')
  const newOrderUuid = () => `10000000-0000-4000-8000-${String(revision + 10).padStart(12, '0')}`
  const view = () => {
    const base = { counter: { uuid: counterId, code: 'A01', name: 'Window table', capacity: 4, enabled: true }, session,
      currency: 'USD', canEnd: session !== null && orders.every((order) => ['cancelled', 'completed'].includes(order.status)), version: version() }
    if (options.sessionOnly) return { ...base, ordersVisible: false }
    return { ...base, ordersVisible: true,
      orders, openAmount: orders.filter((order) => order.status === 'pending' || order.status === 'confirmed').reduce((sum, order) => sum + order.totalAmount, 0), orderedAmount: orders.filter((order) => order.status !== 'cancelled').reduce((sum, order) => sum + order.totalAmount, 0), openOrderCount: orders.filter((order) => order.status === 'pending' || order.status === 'confirmed').length,
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
      const orderActions = options.sessionOnly ? [] : options.readOnly ? ['read'] : options.editOnly ? ['read', 'replace'] : options.cancelOnly ? ['read', 'cancel'] : options.noReplace ? ['read', 'create'] : ['read', 'create', 'replace', 'cancel']
      const storeScopes = {
        ...Object.fromEntries(sessionActions.map((action) => [`store:counter_session:${action}`, [storeId]])),
        ...Object.fromEntries(orderActions.map((action) => [`store:counter_order:${action}`, [storeId]])),
        ...(options.failedInvoice && options.paymentCloseAccess !== false ? { 'store:order:payment_close': [storeId] } : {}),
      }
      return respond({ permissions: Object.keys(storeScopes), storeScopes })
    }
    if (path.endsWith('/counter-operations/counters')) return list([view()])
    if (path.endsWith(`/counter-operations/counters/${counterId}`)) return respond(view())
    if (path.endsWith('/counter-operations/catalog')) return list([{ uuid: specId, productName: 'Soup', name: 'Regular', price: 1590, available: true }])
    if (path === '/api/v1/app/products') return route.fulfill({ json: { code: 0, data: [{ id: 1, uuid: productId, name: 'Soup', status: 'active', metadata: null, category: { id: 1, uuid: categoryId, name: 'Menu', enabled: true }, specifications: [{ id: 1, uuid: specId, name: 'Regular', price: 1590, status: 'active', sort: 0 }] }], paginator: { page: 1, has_next: false } } })
    if (method === 'GET' && path === `/api/v1/app/orders/${orderId}`) return respond({ uuid: orderId, invoiceUuid: invoiceId, paymentStatus: 'failed' })
    if (path === '/api/v1/app/orders' && method === 'POST') {
      if (options.conflict) return route.fulfill({ status: 409, json: { code: 409, message: 'order_changed' } })
      if (options.uncertain && !failed) { failed = true; return route.abort('failed') }
      revision++
      const createdUuid = newOrderUuid()
      const quantities = new Map((body!.items as { specificationUuid: string; quantity: number }[]).map((entry) => [entry.specificationUuid, entry.quantity]))
      orders.push({ uuid: createdUuid, status: 'pending', operationalStatus: 'pending_validation', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590 * (quantities.get(specId) ?? 1), notes: String(body!.notes ?? ''), createdAt: stamp, items: [{ ...item, quantity: quantities.get(specId) ?? 1, amount: 1590 * (quantities.get(specId) ?? 1) }], diningModeUuid: 'dining-mode-hotpot', diningModeName: '火锅', canReplace: true, canCancel: true })
      return respond({ uuid: createdUuid })
    }
    const appOrderConfirm = path.match(new RegExp('^/api/v1/app/orders/([0-9a-f-]{36})/confirm$'))
    if (appOrderConfirm && method === 'POST') {
      const orderUuid = appOrderConfirm[1]!
      orders = orders.map((order) => order.uuid === orderUuid ? { ...order, status: 'confirmed' } : order)
      return respond({ uuid: orderUuid, status: 'confirmed' })
    }
    const paymentClose = path.match(new RegExp(`^/api/v1/store/${storeId}/trade-orders/([0-9a-f-]{36})/payment/close$`))
    if (paymentClose && method === 'POST') {
      const tradeOrderUuid = paymentClose[1]!
      expect(body).toMatchObject({ invoiceUuid: invoiceId, commandUuid: expect.any(String) })
      expect(body!.reason).toBe('Payment failed; release invoice before retry or order changes.')
      if (options.paymentClose === 'network') return route.abort('failed')
      if (options.paymentClose === 'already-paid') {
        orders = orders.map((order) => order.uuid === tradeOrderUuid ? { ...order, paymentStatus: 'paid' } : order)
        return route.fulfill({ status: 409, json: { code: 409, message: 'payment_already_paid', data: { status: 'paid', tradeOrderUuid, invoiceUuid: invoiceId, released: false } } })
      }
      orders = orders.map((order) => order.uuid === tradeOrderUuid ? { ...order, paymentStatus: null, hasInvoice: false } : order)
      return respond({ status: 'closed', tradeOrderUuid, invoiceUuid: invoiceId, invoiceStatus: 'cancelled', released: true })
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
      expect(path.endsWith('/open')).toBe(false)
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
      if (path.endsWith('/orders/confirm')) {
        const target = orders.find((order) => order.uuid === body!.orderUuid)!
        expect(target.status).toBe('pending')
        orders = orders.map((order) => order.uuid === body!.orderUuid ? { ...order, status: 'confirmed', canReplace: true } : order)
        result.orderUuid = body!.orderUuid
        result.status = 'confirmed'
      }
      if (path.endsWith('/orders/create') || path.endsWith('/orders/replace')) {
        const uuid = newOrderUuid()
        const quantity = (body!.items as { quantity: number }[])[0]!.quantity
        if (path.endsWith('/orders/replace')) {
          expect(body!.reason).toBeTruthy()
          orders = orders.map((order) => order.uuid === body!.replaceOrderUuid ? { ...order, status: 'cancelled', operationalStatus: 'cancelled', canReplace: false, canCancel: false } : order)
          result.replacedOrderUuid = body!.replaceOrderUuid
        }
        orders.push({ uuid, status: 'confirmed', operationalStatus: 'pending_validation', paymentStatus: null, hasInvoice: false, currency: 'USD', totalAmount: 1590 * quantity, notes: String(body!.notes), createdAt: stamp, items: [{ ...item, quantity, amount: 1590 * quantity }], diningModeUuid: 'dining-mode-hotpot', diningModeName: '火锅', canReplace: true, canCancel: true })
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
  await expect(page.locator('.dining-card')).toBeVisible()
  return requests
}

async function viewTable(page: Page) {
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByRole('button', { name: '加单', exact: true })).toBeVisible()
}
async function quote(page: Page) {
  await page.getByRole('button', { name: '加单', exact: true }).click()
  await page.getByRole('button', { name: '加菜 Soup Regular', exact: true }).click()
  await page.getByRole('button', { name: '确认下单', exact: true }).click()
  await expect(page.getByRole('heading', { name: '订单确认', exact: true })).toBeVisible()
}

for (const width of [320, 390, 768, 1280]) {
  test(`customer-opened dining allows staff additions and eligible edits at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    const requests = await setup(page, { customerOrder: true })
    const card = page.locator('.dining-card')
    await expect(card).toContainText('Window table')
    await expect(card).toContainText('用餐中')
    await expect(card).toContainText('点餐方式')
    await expect(card).toContainText('火锅')
    await expect(card.getByRole('button', { name: 'Window table', exact: true })).toBeVisible()
    expect(await card.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
    await viewTable(page)
    await expect(page.locator('.dining-detail-hero')).toContainText('点餐方式')
    await expect(page.locator('.dining-detail-hero')).toContainText('火锅')
    await expect(page.locator('.dining-detail-hero')).toContainText('未结订单金额')
    await expect(page.locator('.dining-round')).toHaveCount(1)
    await expect(page.getByRole('button', { name: '人数与备注', exact: true })).toHaveCount(0)
    await expect(page.getByRole('button', { name: '结束桌次', exact: true })).toHaveCount(0)
    await quote(page)
    await expect(page.getByRole('dialog').last()).toContainText('$15.90')
    await page.getByRole('button', { name: '创建订单', exact: true }).click()
    await expect(page.locator('.dining-round')).toHaveCount(2)
    await quote(page)
    await page.getByRole('button', { name: '创建订单', exact: true }).click()
    await expect(page.locator('.dining-round')).toHaveCount(3)
    await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
    await expect(page.getByRole('button', { name: '取消批次', exact: true })).toHaveCount(0)
    await expect(page.getByRole('button', { name: '开台', exact: true })).toHaveCount(0)
    expect(requests.some((request) => request.path.endsWith('/open'))).toBe(false)
    expect(requests.filter((request) => request.path === '/api/v1/app/orders' && request.method === 'POST')).toHaveLength(2)
    expect(requests.some((request) => request.path.endsWith('/orders/replace') || request.path.endsWith('/cancel'))).toBe(false)
    expect(requests.some((request) => request.path.includes('/payment'))).toBe(false)
    expect(requests.some((request) => request.path.startsWith('/api/v1/manage/orders/'))).toBe(false)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  })
}

test('table details hide party and end-session actions', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByRole('dialog')).toContainText('Window table')
  const collectPayment = page.getByRole('button', { name: '收款', exact: true })
  await expect(collectPayment).toBeVisible()
  await expect(page.locator('.dining-round-actions')).toHaveCSS('justify-content', 'flex-end')
  await expect(collectPayment).toHaveClass(/dining-collect-payment/)
  expect(await collectPayment.evaluate((element) => getComputedStyle(element).color)).not.toBe('rgb(255, 255, 255)')
  await expect(page.getByRole('button', { name: '人数与备注', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '结束桌次', exact: true })).toHaveCount(0)
  expect(requests.some((request) => request.path.endsWith('/session/update'))).toBe(false)
  expect(requests.some((request) => request.path.endsWith('/session/end'))).toBe(false)
})

test('payment QR opens the customer payment entry without starting Huifu directly', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await page.getByRole('button', { name: '收款', exact: true }).click()
  const paymentDialog = page.getByRole('dialog', { name: '订单收款' })
  await expect(page.locator('.dining-payment-qr')).toBeVisible()
  const paymentUrl = 'https://ns-ultimate.wscoffee.com.cn/h5/payment/index.html?orderUuid=10000000-0000-4000-8000-000000000009&storeUuid=10000000-0000-4000-8000-000000000001'
  await expect(page.locator('.dining-payment-url')).toHaveText(paymentUrl)
  await expect(page.getByRole('link', { name: paymentUrl, exact: true })).toHaveAttribute('href', paymentUrl)
  await expect(page.getByRole('button', { name: '生成收款码', exact: true })).toHaveCount(0)
  await expect(page.getByRole('link', { name: '打开用户端收款页', exact: true })).toHaveCount(0)
  const printReceipt = page.getByRole('button', { name: '打印结账小票', exact: true })
  await expect(printReceipt).toBeVisible()
  await expect(printReceipt.locator('svg')).toHaveCount(0)
  await printReceipt.click()
  await expect(paymentDialog).toContainText('打印接口尚未接入')
  expect(await paymentDialog.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
  await expect(page.getByRole('button', { name: '刷新支付状态', exact: true })).toBeVisible()
  expect(requests.some((request) => request.path.includes('huifu-payments') || request.path.includes('/app/orders'))).toBe(false)
})

test('opening a table releases a failed payment invoice before offering collection', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true, failedInvoice: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()

  await expect(page.getByText('支付失败的支付单已释放，现在可以重新支付或修改订单。', { exact: true })).toBeVisible()
  await expect.poll(() => requests.filter((request) => request.path.endsWith(`/trade-orders/${orderId}/payment/close`)).length).toBe(1)
  const close = requests.find((request) => request.path.endsWith(`/trade-orders/${orderId}/payment/close`))!
  expect(close.body).toMatchObject({ invoiceUuid: invoiceId, commandUuid: expect.stringMatching(/^[0-9a-f-]{36}$/), reason: 'Payment failed; release invoice before retry or order changes.' })
  await expect(page.locator('.dining-round')).toContainText('未发起支付记录')
  await expect(page.locator('.dining-round')).not.toContainText('已关联支付单')
  await expect(page.getByRole('button', { name: '收款', exact: true })).toBeVisible()
})

test('failed invoice release requires the independent payment-close grant', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true, failedInvoice: true, paymentCloseAccess: false })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.locator('.dining-round')).toContainText('已关联支付单')
  await page.waitForTimeout(300)
  expect(requests.some((request) => request.path.endsWith('/payment/close') || request.path === `/api/v1/app/orders/${orderId}`)).toBe(false)
  await expect(page.getByRole('button', { name: '收款', exact: true })).toHaveCount(0)
})

test('a payment discovered during invoice release is not offered for a second charge', async ({ page }) => {
  await setup(page, { customerOrder: true, failedInvoice: true, paymentClose: 'already-paid' })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByText('支付渠道确认该订单已经支付，未释放支付单。请刷新订单后再处理，不要重复支付或修改订单。', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '收款', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '结账', exact: true })).toBeDisabled()
})

test('an uncertain failed invoice close is attempted once and leaves the order visible', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true, failedInvoice: true, paymentClose: 'network' })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByText('清理支付失败的支付单失败，订单仍可查看。请稍后刷新重试。', { exact: true })).toBeVisible()
  await expect(page.locator('.dining-round')).toBeVisible()
  await page.waitForTimeout(300)
  expect(requests.filter((request) => request.path.endsWith('/payment/close'))).toHaveLength(1)
  await expect(page.getByRole('button', { name: '收款', exact: true })).toHaveCount(0)

  const dialog = page.getByRole('dialog', { name: '查看桌次', exact: true })
  await dialog.getByRole('button', { name: '关闭', exact: true }).click()
  await expect(dialog).toHaveCount(0)
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect.poll(() => requests.filter((request) => request.path.endsWith('/payment/close')).length).toBe(2)
  const closings = requests.filter((request) => request.path.endsWith('/payment/close'))
  expect(closings[1]!.body).toEqual(closings[0]!.body)
})

test('checkout offers scan payment when the store returns no payment methods', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await page.getByRole('button', { name: '结账', exact: true }).click()
  await page.getByRole('button', { name: '确认并处理订单', exact: true }).click()

  const methods = page.locator('.checkout-payment-methods')
  await expect(page.getByText('订单整理完成', { exact: true })).toBeVisible()
  await expect(methods).toContainText('扫码支付')
  await expect(methods).not.toContainText('门店暂未返回可用的支付方式')
  await methods.getByRole('button', { name: '扫码支付', exact: true }).click()

  await expect(page.getByRole('dialog', { name: '订单收款' })).toBeVisible()
  await expect(page.locator('.dining-payment-qr')).toBeVisible()
  const paymentUrl = 'https://ns-ultimate.wscoffee.com.cn/h5/payment/index.html?orderUuid=10000000-0000-4000-8000-000000000009&storeUuid=10000000-0000-4000-8000-000000000001'
  await expect(page.locator('.dining-payment-url')).toHaveText(paymentUrl)
  expect(requests.some((request) => request.path.includes('huifu-payments') || request.path.includes('/app/orders'))).toBe(false)
})

test('checkout blocks item changes after payment has started', async ({ page }) => {
  const requests = await setup(page, { customerOrder: true, payingOrder: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await page.getByRole('button', { name: '结账', exact: true }).click()

  await expect(page.locator('.checkout-final-review')).toBeVisible()
  await page.getByRole('button', { name: '增加数量 Soup Regular', exact: true }).click()
  await expect(page.getByText('订单已经发起支付，不能调整，加减产品只能另外加单或者退款方式处理', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '确认', exact: true }).click()
  await page.getByRole('button', { name: '减少数量 Soup Regular', exact: true }).click()
  await expect(page.getByText('订单已经发起支付，不能调整，加减产品只能另外加单或者退款方式处理', { exact: true })).toBeVisible()
  expect(requests.filter((request) => request.method === 'POST' && request.path.includes('/counter-operations/'))).toHaveLength(0)
})

test('an edit-only employee does not see the batch replacement action', async ({ page }) => {
  await setup(page, { customerOrder: true, editOnly: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByRole('button', { name: '加单', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
})

test('session readers without counter-order grants see the session but no order rows or amounts', async ({ page }) => {
  const requests = await setup(page, { sessionOnly: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByRole('dialog').getByText('当前账号可查看场次，但没有关联订单及金额的读取权限。', { exact: true })).toBeVisible()
  await expect(page.locator('.dining-round')).toHaveCount(0)
  await expect(page.locator('.dining-detail-hero')).not.toContainText('未结订单金额')
  for (const name of ['加单', '修改批次', '人数与备注', '结束桌次']) await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0)
  expect(requests.some((request) => request.path.endsWith('/catalog'))).toBe(false)
})

test('ordering permission alone does not enable customer-order modification', async ({ page }) => {
  await setup(page, { customerOrder: true, noReplace: true })
  await viewTable(page)
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
})

test('a pending order is visible and can be manually confirmed by cashier staff', async ({ page }) => {
  const requests = await setup(page, { pendingOrder: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(1)
  await expect(page.locator('.dining-round')).toContainText('待处理 (pending)')
  await expect(page.getByRole('button', { name: '结账', exact: true })).toBeDisabled()
  await page.getByRole('button', { name: '确认订单', exact: true }).click()
  await expect(page.locator('.dining-round')).toContainText('已确认 (confirmed)')
  await expect(page.getByRole('button', { name: '结账', exact: true })).toBeEnabled()
  const write = requests.find((request) => request.path.endsWith('/orders/confirm'))!
  expect(write.body).toMatchObject({ orderUuid: '10000000-0000-4000-8000-000000000009', commandUuid: expect.any(String), version: expect.any(String) })
})

test('an eligible order can be cancelled and closes the order dialog', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 568 })
  const requests = await setup(page, { customerOrder: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await page.getByRole('button', { name: '取消订单', exact: true }).click()
  expect(requests.filter((request) => request.path.endsWith('/orders/cancel'))).toHaveLength(0)
  await page.getByRole('checkbox', { name: '选择订单 #10000000', exact: true }).check()
  await page.getByRole('button', { name: '取消所选订单', exact: true }).click()
  const confirmButton = page.getByRole('button', { name: '确认取消', exact: true })
  await confirmButton.click({ trial: true })
  const footerBox = await page.locator('.dining-batch-cancel-confirm .sheet-footer').boundingBox()
  const buttonBox = await confirmButton.boundingBox()
  expect(footerBox).not.toBeNull()
  expect(buttonBox).not.toBeNull()
  expect(footerBox!.x).toBeGreaterThanOrEqual(0)
  expect(buttonBox!.x).toBeGreaterThanOrEqual(0)
  expect(footerBox!.x + footerBox!.width).toBeLessThanOrEqual(320)
  expect(buttonBox!.x + buttonBox!.width).toBeLessThanOrEqual(320)
  expect(footerBox!.y + footerBox!.height).toBeLessThanOrEqual(568)
  await page.getByRole('button', { name: '确认取消', exact: true }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await expect(page.locator('.dining-card-mode')).toHaveCount(0)
  const write = requests.find((request) => request.path.endsWith('/orders/cancel'))!
  expect(write.body).toMatchObject({ orderUuid: '10000000-0000-4000-8000-000000000009', reason: '收银端取消所选未结订单', commandUuid: expect.any(String), version: expect.any(String) })
})

test('cancellation is disabled without its independent grant', async ({ page }) => {
  await setup(page, { customerOrder: true, editOnly: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByRole('button', { name: '取消订单', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '取消订单', exact: true })).toBeDisabled()
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
})

test('cancel-only staff can cancel without ordering or replacement access', async ({ page }) => {
  await setup(page, { customerOrder: true, cancelOnly: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByRole('button', { name: '取消订单', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '取消订单', exact: true })).toBeEnabled()
  await expect(page.getByRole('button', { name: '修改批次', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '加单', exact: true })).toHaveCount(0)
})

test('read-only dining grants never expose commands or load the menu', async ({ page }) => {
  const requests = await setup(page, { readOnly: true, customerOrder: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(1)
  for (const name of ['开台', '加单', '结账', '修改桌次', '结束桌次', '修改批次', '取消批次']) {
    await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0)
  }
  await expect(page.getByRole('button', { name: '取消订单', exact: true })).toBeDisabled()
  expect(requests.some((request) => request.path.endsWith('/catalog') || request.method !== 'GET' && request.path.includes('/counter-operations/'))).toBe(false)
})

test('quote conflicts keep the reviewed cart without pretending an order succeeded', async ({ page }) => {
  await setup(page, { conflict: true, customerOrder: true })
  await viewTable(page); await quote(page)
  await page.getByRole('button', { name: '创建订单', exact: true }).click()
  await expect(page.getByRole('dialog').last().getByRole('alert')).toContainText('记录已变化')
  await expect(page.getByRole('button', { name: '返回修改', exact: true })).toBeEnabled()
  await expect(page.locator('.dining-card')).toContainText('1 订单批次')
})

test('an uncertain order submission retries with the unchanged order payload', async ({ page }) => {
  const requests = await setup(page, { uncertain: true, customerOrder: true })
  await viewTable(page); await quote(page)
  await page.getByRole('button', { name: '创建订单', exact: true }).click()
  await expect(page.getByRole('dialog').last().getByRole('alert')).toContainText('网络异常')
  await page.getByRole('button', { name: '创建订单', exact: true }).click()
  await expect(page.locator('.dining-round')).toHaveCount(2)
  const writes = requests.filter((request) => request.path === '/api/v1/app/orders' && request.method === 'POST')
  expect(writes).toHaveLength(2)
  expect(writes[0]!.body).toEqual(writes[1]!.body)
})

test('offline dining submission is paused before sending a write', async ({ page, context }) => {
  const requests = await setup(page, { customerOrder: true })
  await viewTable(page); await quote(page)
  await context.setOffline(true)
  await page.getByRole('button', { name: '创建订单', exact: true }).click()
  await expect(page.getByRole('dialog').last().getByRole('alert')).toContainText('网络不可用')
  expect(requests.some((request) => request.path === '/api/v1/app/orders' && request.method === 'POST')).toBe(false)
  await context.setOffline(false)
})

test('staff cannot open or order on an empty table', async ({ page }) => {
  const requests = await setup(page, { empty: true })
  await page.getByRole('button', { name: 'Window table', exact: true }).click()
  await expect(page.getByRole('button', { name: '开台', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '加单', exact: true })).toHaveCount(0)
  await expect(page.locator('.dining-round')).toHaveCount(0)
  expect(requests.some((request) => request.method !== 'GET' && request.path.includes('/counter-operations/'))).toBe(false)
})
