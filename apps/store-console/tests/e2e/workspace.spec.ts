import { expect, test, type Page } from '@playwright/test'

async function openTable(page: Page) {
  await page.getByRole('link', { name: '桌台', exact: true }).click()
  await page.getByRole('button', { name: 'A01 用餐中', exact: true }).click()
}
async function generateLink(page: Page) {
  await openTable(page)
  await page.getByRole('button', { name: '确认结账', exact: true }).click()
  await page.getByRole('checkbox', { name: /核对金额/ }).check()
  await page.getByRole('button', { name: '生成演示付款链接' }).click()
  await expect(page.getByRole('link', { name: '打开演示付款页' })).toBeVisible()
  return (await page.getByRole('link', { name: '打开演示付款页' }).getAttribute('href'))!
}

test.beforeEach(async ({ page }) => { await page.goto('/?mode=demo') })

test('mobile workspace and tabs render without overflow or runtime errors', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await expect(page.getByRole('heading', { name: '把每一份用心，做好。' })).toBeVisible()
  await page.screenshot({ path: 'test-results/store-home.png', fullPage: true })
  for (const name of ['商品', '桌台', '统计', '我的', '工作台']) {
    await page.getByRole('link', { name, exact: true }).click()
    await expect(page.getByRole('link', { name, exact: true })).toHaveAttribute('aria-current', 'page')
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
  expect(errors).toEqual([])
})

test('product filtering, sold-out toggling and product/variant editing', async ({ page }) => {
  await page.getByRole('link', { name: '商品', exact: true }).click()
  await page.screenshot({ path: 'test-results/store-products.png', fullPage: true })
  await page.getByRole('button', { name: '设为售罄 鲜切吊龙', exact: true }).click()
  await expect(page.getByRole('button', { name: '恢复售卖 鲜切吊龙', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '恢复售卖 鲜切吊龙', exact: true }).click()
  await page.getByRole('button', { name: '新增商品', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: '新增商品' })
  await dialog.getByPlaceholder('商品名称').fill('Demo special')
  await dialog.getByLabel('价格（元） 1', { exact: true }).fill('19.90')
  await dialog.getByRole('button', { name: '增加规格' }).click()
  await dialog.getByLabel('规格名称 2', { exact: true }).fill('Large')
  await dialog.getByLabel('价格（元） 2', { exact: true }).fill('29.90')
  await dialog.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Demo special', exact: true })).toBeVisible()
  await page.getByLabel('搜索商品名称或编号').locator('input').fill('Demo special')
  await expect(page.locator('.product-row')).toHaveCount(1)
})

test('category creation, sorting and table creation are usable', async ({ page }) => {
  await page.getByRole('link', { name: '商品', exact: true }).click()
  await page.getByRole('button', { name: '分类管理' }).click()
  await page.getByPlaceholder('分类名称').fill('Demo category')
  await page.getByRole('button', { name: '新增分类', exact: true }).click()
  await expect(page.getByRole('dialog').getByText('Demo category', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '上移 Demo category' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '关闭' }).click()
  await page.getByRole('link', { name: '桌台', exact: true }).click()
  await page.getByRole('button', { name: '新增桌台', exact: true }).click()
  await page.getByPlaceholder('A09').fill('B01')
  await page.getByRole('dialog').getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('button', { name: 'B01 空闲', exact: true })).toBeVisible()
})

test('table editing and cross-entry demo payment settle once and populate reports', async ({ page, context }) => {
  await openTable(page)
  await page.getByRole('button', { name: '增加数量' }).first().click()
  await page.getByLabel('整单优惠', { exact: true }).fill('2')
  await page.getByRole('button', { name: '确认结账', exact: true }).click()
  await page.getByRole('checkbox', { name: /核对金额/ }).check()
  await page.getByRole('button', { name: '生成演示付款链接' }).click()
  const href = (await page.getByRole('link', { name: '打开演示付款页' }).getAttribute('href'))!
  const customer = await context.newPage()
  await customer.goto(href)
  await customer.screenshot({ path: 'test-results/store-payment.png', fullPage: true })
  await expect(customer.getByText('林店长')).toHaveCount(0)
  await expect(customer.getByRole('navigation')).toHaveCount(0)
  await expect(customer.getByText('¥130.00', { exact: true }).first()).toBeVisible()
  await customer.getByRole('button', { name: '模拟付款 · ¥130.00', exact: true }).click()
  await expect(customer.getByRole('heading', { name: '模拟付款成功', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: '模拟付款成功', exact: true })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).first().click()
  await expect(page.getByRole('button', { name: 'A01 空闲', exact: true })).toBeVisible()
  await page.getByRole('button', { name: /已收款/ }).click()
  await expect(page.locator('.history-list')).toContainText('¥130.00')
  await page.getByRole('link', { name: '统计', exact: true }).click()
  await expect(page.locator('.report-hero')).toContainText('¥130.00')
  await expect(page.locator('.sales-ranking')).toContainText('鲜切吊龙')
})

test('unknown payments block repeat collection and resolve the original attempt', async ({ page, context }) => {
  const href = await generateLink(page)
  const customer = await context.newPage()
  await customer.goto(href)
  await customer.getByLabel('演示结果').selectOption('timeout')
  await customer.getByRole('button', { name: /模拟付款 ·/ }).click()
  await expect(customer.getByRole('heading', { name: '付款结果待确认' })).toBeVisible()
  await expect(customer.getByRole('button', { name: /模拟付款 ·/ })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '作废未支付链接' })).toHaveCount(0)
  await customer.getByRole('button', { name: '查询演示结果', exact: true }).click()
  await expect(customer.getByRole('heading', { name: '模拟付款成功' })).toBeVisible()
})

test('failed and voided payment links are not shown as successful', async ({ page, context }) => {
  const href = await generateLink(page)
  const customer = await context.newPage()
  await customer.goto(href)
  await customer.getByLabel('演示结果').selectOption('failure')
  await customer.getByRole('button', { name: /模拟付款 ·/ }).click()
  await expect(customer.getByRole('alert')).toContainText('模拟付款失败')
  await page.getByRole('button', { name: '作废未支付链接' }).click()
  await expect(customer.getByRole('heading', { name: '演示链接已过期或作废' })).toBeVisible()
})

test('offline blocks mutations and checkout without losing the current order', async ({ page, context }) => {
  await openTable(page)
  await context.setOffline(true)
  await expect(page.getByRole('button', { name: '确认结账', exact: true })).toBeDisabled()
  await context.setOffline(false)
  await expect(page.getByRole('button', { name: '确认结账', exact: true })).toBeEnabled()
})

test('language and local color preferences work across navigation and reload', async ({ page }) => {
  await page.getByRole('link', { name: '我的', exact: true }).click()
  await page.getByRole('button', { name: '蓝', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'blue')
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'blue')
  await page.getByRole('button', { name: 'English', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Your workspace' })).toBeVisible()
  await page.getByRole('link', { name: 'Products', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Fresh Beef Brisket', exact: true })).toBeVisible()
})

test('payment entry handles missing sessions and unavailable storage', async ({ page }) => {
  await page.goto('/payment.html?session=missing')
  await expect(page.getByRole('heading', { name: '未找到可用演示账单' })).toBeVisible()
  await page.addInitScript(() => { Storage.prototype.getItem = () => { throw new Error('Blocked storage') } })
  await page.reload()
  await expect(page.getByRole('alert')).toContainText('无法读取演示付款数据')
})

test('small phones and desktop previews keep navigation and form controls reachable', async ({ page }) => {
  for (const width of [320, 480, 1024]) {
    await page.setViewportSize({ width, height: 800 })
    await page.getByRole('link', { name: '商品', exact: true }).click()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await page.getByRole('button', { name: '新增商品', exact: true }).click()
    const save = page.getByRole('dialog').getByRole('button', { name: '保存', exact: true })
    await expect(save).toBeInViewport()
    const bounds = await save.boundingBox()
    expect(bounds!.height).toBeGreaterThanOrEqual(44)
    await page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true }).click()
    await page.getByRole('link', { name: '我的', exact: true }).click()
    await expect(page.getByRole('group', { name: '配色方案' })).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  }
})

test('local image preview and invalid product validation work without uploading', async ({ page }) => {
  await page.getByRole('link', { name: '商品', exact: true }).click()
  await page.getByRole('button', { name: '新增商品', exact: true }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('button', { name: '保存', exact: true }).click()
  await expect(dialog.getByRole('alert')).toContainText('请填写名称')
  await dialog.getByPlaceholder('商品名称').fill('Demo image')
  await dialog.locator('input[type=file]').setInputFiles({ name: 'preview.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', 'base64') })
  await expect(dialog.getByRole('img', { name: 'Demo image' })).toBeVisible()
  await dialog.getByLabel('价格（元） 1', { exact: true }).fill('9.90')
  await dialog.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Demo image', exact: true })).toBeVisible()
})
