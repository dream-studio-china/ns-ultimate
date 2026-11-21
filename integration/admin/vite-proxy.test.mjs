import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createServer as createHttpServer } from 'node:http'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
const { createServer, loadConfigFromFile } = require('vite')

test('direct appearance navigation serves the SPA while system API requests still reach the backend', async t => {
  const backend = createHttpServer((request, response) => {
    response.setHeader('Content-Type', 'application/json')
    response.end(JSON.stringify({ backendPath: request.url }))
  })
  await new Promise(resolve => backend.listen(0, '127.0.0.1', resolve))
  t.after(() => new Promise(resolve => backend.close(resolve)))
  const originalTarget = process.env.VITE_PROXY_TARGET
  let config
  try {
    process.env.VITE_PROXY_TARGET = `http://127.0.0.1:${backend.address().port}`
    const loaded = await loadConfigFromFile(
      { command: 'serve', mode: 'development' },
      fileURLToPath(new URL('./vite.config.ts', import.meta.url))
    )
    config = loaded.config
  } finally {
    if (originalTarget === undefined) delete process.env.VITE_PROXY_TARGET
    else process.env.VITE_PROXY_TARGET = originalTarget
  }
  const vite = await createServer({
    ...config,
    configFile: false,
    logLevel: 'error',
    optimizeDeps: { noDiscovery: true, include: [] },
    server: { ...config.server, host: '127.0.0.1', port: 0, strictPort: false, hmr: false, watch: null, preTransformRequests: false }
  })
  t.after(() => vite.close())
  await vite.listen()
  const origin = `http://127.0.0.1:${vite.httpServer.address().port}`

  for (const path of ['/system-option/appearance', '/system-option/appearance?preview=1', '/settings/appearance', '/store/list']) {
    const response = await fetch(`${origin}${path}`, { headers: { Accept: 'text/html' } })
    assert.equal(response.status, 200, path)
    assert.match(response.headers.get('content-type'), /text\/html/, path)
    assert.match(await response.text(), /<div id="app"><\/div>/, path)
  }
  for (const path of ['/system', '/system?check=1', '/system/status', '/api/example', '/health', '/metrics', '/uploads/example.png']) {
    const response = await fetch(`${origin}${path}`)
    assert.equal(response.status, 200, path)
    assert.deepEqual(await response.json(), { backendPath: path })
  }

  await t.test('the composed fixed-header layout supplies full viewport bounds for record dialogs', async() => {
    const entry = await readFile(new URL('./config.ts', import.meta.url), 'utf8')
    assert.match(entry, /^import '\.\/layout\.css'$/m)
    const layoutPath = fileURLToPath(new URL('./layout.css', import.meta.url))
    const response = await fetch(`${origin}/@fs${layoutPath}?direct`)
    assert.equal(response.status, 200)
    assert.match(await response.text(), /\.main-container\s*>\s*\.fixed-header\s*\+\s*\.app-main\s*\{\s*min-height:\s*100vh;\s*min-height:\s*100dvh;/)
  })
})
