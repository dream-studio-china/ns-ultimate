import assert from 'node:assert/strict'
import { mkdir, mkdtemp, rm } from 'node:fs/promises'
import { resolve } from 'node:path'
import { _electron } from 'playwright'
import electronPath from 'electron'

const env = { ...process.env }
delete env.ELECTRON_RUN_AS_NODE
delete env.CASHIER_DEV_URL
const packagedExecutable = process.argv[2]
await mkdir('test-results', { recursive: true })
const profile = await mkdtemp(resolve('test-results/desktop-profile-'))
const launchOptions = {
  executablePath: packagedExecutable ?? electronPath,
  args: [...(packagedExecutable ? [] : ['.']), `--user-data-dir=${profile}`],
  env,
}
const desktop = await _electron.launch(launchOptions)
let closed = false
try {
  const operator = await desktop.firstWindow()
  const errors = []
  operator.on('pageerror', (error) => errors.push(error.message))
  await operator.getByRole('button', { name: '经典美式 ¥18.00' }).waitFor()
  assert.equal(new URL(operator.url()).protocol, 'cashier:')
  const preferences = await desktop.evaluate(({ BrowserWindow }) => {
    const preferences = BrowserWindow.getAllWindows()[0].webContents.getLastWebPreferences()
    return { sandbox: preferences.sandbox, contextIsolation: preferences.contextIsolation, nodeIntegration: preferences.nodeIntegration }
  })
  assert.deepEqual(preferences, { sandbox: true, contextIsolation: true, nodeIntegration: false })
  await operator.getByRole('button', { name: '经典美式 ¥18.00' }).click()
  await operator.getByRole('button', { name: '加入购物车' }).click()
  const nextWindow = desktop.waitForEvent('window')
  await operator.evaluate(() => window.cashierDesktop.openCustomer())
  const customer = await nextWindow
  await customer.getByText('经典美式').waitFor()
  assert.equal(await customer.getByText('林店长').count(), 0)
  const denied = await customer.evaluate(async () => {
    try { await window.cashierDesktop.publish({ locale: 'en', theme: 'green', storeName: 'Demo', online: true, lines: [], total: 0, status: 'idle' }); return false } catch { return true }
  })
  assert.equal(denied, true, 'Customer windows must not publish or control transactions')
  await operator.getByRole('button', { name: '设置', exact: true }).click()
  await operator.getByRole('button', { name: '紫色', exact: true }).click()
  await customer.waitForFunction(() => document.documentElement.dataset.theme === 'purple')
  await operator.reload()
  await operator.waitForFunction(() => document.documentElement.dataset.theme === 'purple')
  assert.equal(await operator.evaluate(() => localStorage.getItem('ns-cashier.theme.v1')), 'purple')
  await operator.getByRole('button', { name: '经典美式 ¥18.00' }).click()
  await operator.getByRole('button', { name: '加入购物车' }).click()
  assert.equal(await customer.evaluate(async () => {
    try { await window.cashierDesktop.openCustomer(); return false } catch { return true }
  }), true, 'Customer windows must not control desktop windows')
  await operator.getByRole('button', { name: /去收款/ }).click()
  await operator.getByRole('button', { name: '模拟收款', exact: true }).click()
  await customer.getByText('演示订单已完成').waitFor()
  await operator.getByRole('button', { name: '完成并开新单' }).click()
  await customer.getByText('期待为你准备下一杯好咖啡').waitFor()
  await mkdir('test-results', { recursive: true })
  await operator.screenshot({ path: 'test-results/desktop-workspace.png' })
  await operator.evaluate(() => window.cashierDesktop.closeCustomer())
  assert.equal((await operator.evaluate(() => window.cashierDesktop.status())).customerOpen, false)
  await operator.evaluate(() => window.cashierDesktop.toggleFullscreen())
  await operator.waitForFunction(async () => (await window.cashierDesktop.status()).fullscreen)
  await operator.keyboard.press('Escape')
  await operator.waitForFunction(async () => !(await window.cashierDesktop.status()).fullscreen)
  assert.deepEqual(errors, [])
  await desktop.close()
  closed = true
  const restarted = await _electron.launch(launchOptions)
  try {
    const restored = await restarted.firstWindow()
    await restored.waitForFunction(() => document.documentElement.dataset.theme === 'purple')
    await restored.getByRole('button', { name: '设置', exact: true }).click()
    assert.equal(await restored.getByRole('button', { name: '紫色', exact: true }).getAttribute('aria-pressed'), 'true')
  } finally { await restarted.close() }
  console.log('Desktop smoke passed: packaged-origin UI, sandbox, customer sync, IPC role isolation, demo checkout and theme persistence across restart.')
} finally {
  if (!closed) await desktop.close()
  await rm(profile, { recursive: true, force: true })
}
