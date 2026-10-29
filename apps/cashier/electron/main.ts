import { app, BrowserWindow, ipcMain, net, protocol, screen, session } from 'electron'
import type { IpcMainInvokeEvent } from 'electron'
import { dirname, resolve, sep, extname } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { customerSnapshotSchema, type CustomerSnapshot, type DesktopStatus } from '../shared/desktop'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../dist')
const devUrl = !app.isPackaged && process.env.CASHIER_DEV_URL === 'http://127.0.0.1:5178' ? process.env.CASHIER_DEV_URL : undefined
const baseUrl = devUrl ?? 'cashier://app'
let operator: BrowserWindow | null = null
let customer: BrowserWindow | null = null
let latest: CustomerSnapshot | null = null
let customerDisplayId: number | null = null
let customerWanted = false

protocol.registerSchemesAsPrivileged([{ scheme: 'cashier', privileges: { standard: true, secure: true, supportFetchAPI: true } }])
app.enableSandbox()

function status(): DesktopStatus {
  return {
    displays: screen.getAllDisplays().map((display) => ({ id: display.id, label: display.label || `Display ${display.id}`, width: display.size.width, height: display.size.height, primary: display.id === screen.getPrimaryDisplay().id })),
    customerOpen: !!customer && !customer.isDestroyed(), customerDisplayId,
    fullscreen: operator?.isFullScreen() ?? false,
  }
}
function broadcastStatus() {
  for (const window of [operator, customer]) if (window && !window.isDestroyed()) window.webContents.send('desktop:updated', status())
}
function authorize(event: IpcMainInvokeEvent, operatorOnly = false) {
  const window = BrowserWindow.fromWebContents(event.sender)
  if (!window || (window !== operator && window !== customer) || (operatorOnly && window !== operator) || event.senderFrame !== event.sender.mainFrame) throw new Error('Unauthorized IPC sender')
  const url = new URL(event.senderFrame.url)
  if (devUrl ? url.origin !== devUrl : url.protocol !== 'cashier:' || url.host !== 'app') throw new Error('Untrusted IPC origin')
}
function makeWindow(role: 'operator' | 'customer', displayId?: number) {
  const display = screen.getAllDisplays().find((item) => item.id === displayId) ?? screen.getPrimaryDisplay()
  const external = role === 'customer' && display.id !== screen.getDisplayMatching(operator?.getBounds() ?? display.bounds).id
  const window = new BrowserWindow({
    title: role === 'operator' ? 'NS Cashier' : 'NS Cashier · Customer',
    width: role === 'operator' ? 1440 : 1100, height: 960,
    minWidth: role === 'operator' ? 1024 : 640, minHeight: 680,
    x: display.workArea.x + 20, y: display.workArea.y + 20,
    backgroundColor: '#f6f7f4', show: false, autoHideMenuBar: true,
    webPreferences: { preload: resolve(here, 'preload.cjs'), contextIsolation: true, nodeIntegration: false, sandbox: true, webSecurity: true },
  })
  window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }))
  window.webContents.on('will-navigate', (event) => event.preventDefault())
  window.webContents.on('will-attach-webview', (event) => event.preventDefault())
  window.webContents.on('before-input-event', (event, input) => {
    if (input.type === 'keyDown' && input.key === 'F11') { event.preventDefault(); window.setFullScreen(!window.isFullScreen()) }
    if (input.type === 'keyDown' && input.key === 'Escape' && window.isFullScreen()) window.setFullScreen(false)
  })
  window.on('enter-full-screen', broadcastStatus)
  window.on('leave-full-screen', broadcastStatus)
  window.once('ready-to-show', () => {
    if (external) { window.setBounds(display.bounds); window.setFullScreen(true) }
    window.show()
  })
  void window.loadURL(`${baseUrl}/?screen=${role}`)
  return window
}
function openCustomer(displayId?: number) {
  const operatorDisplay = operator ? screen.getDisplayMatching(operator.getBounds()).id : screen.getPrimaryDisplay().id
  const display = screen.getAllDisplays().find((item) => item.id === displayId)
    ?? screen.getAllDisplays().find((item) => item.id !== operatorDisplay)
    ?? screen.getPrimaryDisplay()
  customerWanted = true
  customerDisplayId = display.id
  if (customer && !customer.isDestroyed()) {
    customer.setFullScreen(false)
    customer.setBounds(display.id === operatorDisplay ? { x: display.workArea.x + 40, y: display.workArea.y + 40, width: 1100, height: 760 } : display.bounds)
    if (display.id !== operatorDisplay) customer.setFullScreen(true)
    customer.show()
  } else {
    customer = makeWindow('customer', display.id)
    customer.on('closed', () => { customer = null; customerDisplayId = null; broadcastStatus() })
  }
  broadcastStatus()
  return status()
}

if (!app.requestSingleInstanceLock()) app.quit()
else {
  app.on('second-instance', () => { operator?.show(); operator?.focus() })
  void app.whenReady().then(() => {
    protocol.handle('cashier', (request) => {
      const url = new URL(request.url)
      let path: string
      try { path = decodeURIComponent(url.pathname) } catch { return new Response(null, { status: 400 }) }
      const file = resolve(root, `.${path === '/' ? '/index.html' : path}`)
      if (url.host !== 'app' || !file.startsWith(root + sep) || !['.html', '.js', '.css', '.svg', '.woff2'].includes(extname(file))) return new Response(null, { status: 403 })
      return net.fetch(pathToFileURL(file).href)
    })
    session.defaultSession.setPermissionRequestHandler((_contents, _permission, callback) => callback(false))
    session.defaultSession.setPermissionCheckHandler(() => false)
    session.defaultSession.webRequest.onHeadersReceived((details, callback) => callback({ responseHeaders: {
      ...details.responseHeaders,
      'Content-Security-Policy': [devUrl
        ? "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' ws://127.0.0.1:5178; object-src 'none'; base-uri 'none'; frame-src 'none'"
        : "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-src 'none'"],
    } }))
    ipcMain.handle('desktop:status', (event) => { authorize(event); return status() })
    ipcMain.handle('desktop:open-customer', (event, id: unknown) => {
      authorize(event, true)
      if (id !== undefined && (typeof id !== 'number' || !screen.getAllDisplays().some((display) => display.id === id))) throw new Error('Invalid display')
      return openCustomer(id as number | undefined)
    })
    ipcMain.handle('desktop:close-customer', (event) => { authorize(event, true); customerWanted = false; customer?.close(); return status() })
    ipcMain.handle('desktop:fullscreen', (event) => { authorize(event, true); operator?.setFullScreen(!operator.isFullScreen()); return status() })
    ipcMain.handle('customer:publish', (event, payload: unknown) => {
      authorize(event, true)
      latest = customerSnapshotSchema.parse(payload)
      customer?.webContents.send('customer:updated', latest)
    })
    ipcMain.handle('customer:snapshot', (event) => { authorize(event); return latest })
    operator = makeWindow('operator')
    operator.on('closed', () => { operator = null; customerWanted = false; customer?.close(); latest = null })
    const refreshDisplays = () => {
      const displays = screen.getAllDisplays()
      if (customerWanted && customerDisplayId !== null && !displays.some((display) => display.id === customerDisplayId)) {
        customer?.close()
      }
      const operatorDisplay = operator ? screen.getDisplayMatching(operator.getBounds()).id : screen.getPrimaryDisplay().id
      if (customerWanted && !customer) {
        const external = displays.find((display) => display.id !== operatorDisplay)
        if (external) openCustomer(external.id)
      }
      if (customer && customerDisplayId !== null && customer.isFullScreen()) {
        const display = displays.find((item) => item.id === customerDisplayId)
        if (display) customer.setBounds(display.bounds)
      }
      broadcastStatus()
    }
    screen.on('display-added', refreshDisplays)
    screen.on('display-removed', refreshDisplays)
    screen.on('display-metrics-changed', refreshDisplays)
  })
  app.on('window-all-closed', () => app.quit())
}
