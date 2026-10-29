import { expect, test, type Page } from '@playwright/test'

async function addAmericano(page: Page) {
  await page.getByRole('button', { name: '经典美式 ¥18.00' }).click()
  await page.getByRole('button', { name: '加入购物车' }).click()
}
test.beforeEach(async ({ page }) => { await page.goto('/') })

test('complete a touch-friendly basket, cash payment, receipt and shift', async ({ page }) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  await addAmericano(page)
  await page.getByRole('button', { name: '增加数量' }).click()
  await page.getByRole('button', { name: '添加备注' }).click()
  await page.getByPlaceholder('例如：少冰、打包分装…').fill('Demo note')
  await page.getByRole('button', { name: '确认', exact: true }).click()
  await page.getByRole('button', { name: /去收款/ }).click()
  await page.getByLabel('实收金额').fill('50')
  await expect(page.getByRole('dialog')).toContainText('¥14.00')
  await page.getByRole('button', { name: '模拟收款', exact: true }).click()
  await expect(page.getByText('模拟收款成功', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '查看小票' }).click()
  await expect(page.getByRole('dialog', { name: '演示电子小票' })).toContainText('Demo note')
  await page.getByRole('dialog', { name: '演示电子小票' }).getByRole('button', { name: '取消' }).click()
  await page.getByRole('button', { name: '完成并开新单' }).click()
  await expect(page.getByText('购物车还是空的')).toBeVisible()
  await page.getByRole('button', { name: '订单', exact: true }).click()
  await expect(page.getByRole('table')).toContainText('¥36.00')
  await page.getByRole('button', { name: '交接班', exact: true }).click()
  await expect(page.getByText('¥36.00').first()).toBeVisible()
  expect(errors).toEqual([])
})

test('hold and restore, filter products, handle sold-out and quantity limits', async ({ page }) => {
  await expect(page.getByRole('button', { name: '白桃乌龙 ¥24.00' })).toBeDisabled()
  await page.getByLabel('搜索商品').fill('1001')
  await expect(page.getByRole('button', { name: '醇香拿铁 ¥26.00' })).toHaveCount(0)
  await addAmericano(page)
  await page.getByRole('button', { name: '挂单', exact: true }).click()
  await expect(page.getByText('购物车还是空的')).toBeVisible()
  await page.getByRole('button', { name: /挂单列表/ }).click()
  await page.getByRole('button', { name: '取单' }).click()
  await expect(page.getByRole('button', { name: /去收款/ })).toBeEnabled()
  await page.getByRole('button', { name: '清空', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '清空', exact: true }).click()
  await expect(page.getByText('购物车还是空的')).toBeVisible()
})

test('unknown result cannot be dismissed or collected again; lookup resolves it once', async ({ page }) => {
  await addAmericano(page)
  await page.getByRole('button', { name: /去收款/ }).click()
  await page.getByLabel('模拟返回结果').selectOption('unknown')
  await page.getByRole('button', { name: '模拟收款', exact: true }).click()
  await expect(page.getByText('收款结果待确认', { exact: true })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog')).toBeVisible()
  await expect(page.getByRole('button', { name: '模拟收款', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '查询模拟结果' }).click()
  await expect(page.getByText('模拟收款成功', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '完成并开新单' }).click()
  await page.getByRole('button', { name: '订单', exact: true }).click()
  await expect(page.locator('tbody tr')).toHaveCount(1)
})

test('failed payment can return to the basket without an order', async ({ page }) => {
  await addAmericano(page)
  await page.getByRole('button', { name: /去收款/ }).click()
  await page.getByLabel('模拟返回结果').selectOption('failed')
  await page.getByRole('button', { name: '模拟收款', exact: true }).click()
  await expect(page.getByText('模拟收款失败', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '返回购物车' }).click()
  await expect(page.getByRole('button', { name: /去收款/ })).toBeEnabled()
  await page.getByRole('button', { name: '订单', exact: true }).click()
  await expect(page.getByText('还没有订单')).toBeVisible()
})

test('offline blocks payment, online restores it', async ({ page, context }) => {
  await addAmericano(page)
  await context.setOffline(true)
  await expect(page.getByRole('button', { name: /去收款/ })).toBeDisabled()
  await expect(page.getByRole('alert')).toContainText('网络不可用')
  await context.setOffline(false)
  await expect(page.getByRole('button', { name: /去收款/ })).toBeEnabled()
})

test('customer preview synchronizes basket, locale and payment, without employee information', async ({ page, context }) => {
  const customer = await context.newPage()
  await customer.goto('/?screen=customer')
  await expect(customer.getByText('期待为你准备下一杯好咖啡')).toBeVisible()
  await addAmericano(page)
  await expect(customer.getByText('经典美式')).toBeVisible()
  await expect(customer.getByText('¥18.00').last()).toBeVisible()
  await expect(customer.getByText('林店长')).toHaveCount(0)
  await page.getByRole('button', { name: '设置', exact: true }).click()
  await page.getByRole('button', { name: 'English', exact: true }).click()
  await expect(customer.getByText('Americano')).toBeVisible()
  await page.getByRole('button', { name: 'Cashier', exact: true }).click()
  await page.getByRole('button', { name: /Checkout/ }).click()
  await page.getByRole('button', { name: 'Simulate payment', exact: true }).click()
  await expect(customer.getByText('Demo order completed')).toBeVisible()
})

test('touch viewport keeps checkout accessible and handles repeated add limit', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 768 })
  await addAmericano(page)
  const checkout = page.getByRole('button', { name: /去收款/ })
  const box = await checkout.boundingBox()
  expect(box!.y + box!.height).toBeLessThanOrEqual(768)
  expect(box!.height).toBeGreaterThanOrEqual(48)
  await page.getByRole('button', { name: '经典美式 ¥18.00' }).click()
  for (let i = 1; i < 99; i++) await page.getByRole('dialog').getByRole('button', { name: '增加数量' }).click()
  await page.getByRole('button', { name: '加入购物车' }).click()
  await expect(page.getByRole('status')).toContainText('购物车数量已达上限')
  await expect(checkout).toBeEnabled()
})

test('all seven color schemes change the UI, persist and synchronize with the customer display', async ({ page, context }) => {
  const customer = await context.newPage()
  await customer.goto('/?screen=customer')
  await page.getByRole('button', { name: '设置', exact: true }).click()
  const schemes = [
    ['red', '红色'], ['orange', '橙色'], ['yellow', '黄色'], ['green', '绿色'],
    ['blue', '蓝色'], ['indigo', '靛色'], ['purple', '紫色'],
  ] as const
  const primaryColors = new Set<string>()
  for (const [id, name] of schemes) {
    const button = page.getByRole('button', { name, exact: true })
    await button.click()
    await expect(button).toHaveAttribute('aria-pressed', 'true')
    await expect(page.locator('html')).toHaveAttribute('data-theme', id)
    await expect(customer.locator('html')).toHaveAttribute('data-theme', id)
    expect(await page.evaluate(() => localStorage.getItem('ns-cashier.theme.v1'))).toBe(id)
    const primary = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--primary'))
    primaryColors.add(primary)
    expect(await customer.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--primary'))).toBe(primary)
    expect(await page.locator('.sidebar').evaluate((element) => getComputedStyle(element).backgroundColor)).toBe(
      await page.evaluate(() => {
        const probe = document.createElement('span')
        probe.style.color = 'var(--sidebar)'
        document.body.append(probe)
        const color = getComputedStyle(probe).color
        probe.remove()
        return color
      }),
    )
  }
  expect(primaryColors.size).toBe(7)
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'purple')
  await page.getByRole('button', { name: '设置', exact: true }).click()
  await expect(page.getByRole('button', { name: '紫色', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await customer.reload()
  await expect(customer.locator('html')).toHaveAttribute('data-theme', 'purple')
})

test('unavailable preference storage does not break theme selection', async ({ page }) => {
  await page.evaluate(() => {
    Storage.prototype.setItem = () => { throw new DOMException('Storage unavailable', 'QuotaExceededError') }
  })
  await page.getByRole('button', { name: '设置', exact: true }).click()
  await page.getByRole('button', { name: '蓝色', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'blue')
  await expect(page.getByRole('alert')).toContainText('本机存储不可用')
})
