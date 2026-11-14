import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { createRequire } from 'node:module'
import { applyPalette, getPalette, initializePalette, palettes, PALETTE_STORAGE_KEY } from '../../business/admin/appearance/palette.mjs'
import { mergeEntities, mergeRoutes, moveRouteAfter, moveRouteBefore } from './merge-config.mjs'
import appearanceRoute from '../../business/admin/appearance/route.mjs'

function browser(t, stored = {}) {
  const storage = new Map(Object.entries(stored))
  const root = { dataset: { theme: 'dark' } }
  const originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document')
  const originalStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
  Object.defineProperty(globalThis, 'document', { configurable: true, value: { documentElement: root } })
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) }
  })
  t.after(() => {
    for (const [key, descriptor] of [['document', originalDocument], ['localStorage', originalStorage]]) {
      if (descriptor) Object.defineProperty(globalThis, key, descriptor)
      else delete globalThis[key]
    }
  })
  return { storage, root }
}

test('each palette applies immediately and persists without changing the core theme', t => {
  const { storage, root } = browser(t, { app_theme: 'dark' })
  for (const palette of palettes.slice(1)) {
    assert.equal(applyPalette(palette.value), palette.value)
    assert.equal(root.dataset.adminPalette, palette.value)
    assert.equal(getPalette(), palette.value)
    assert.equal(storage.get(PALETTE_STORAGE_KEY), palette.value)
    assert.equal(root.dataset.theme, 'dark')
    assert.equal(storage.get('app_theme'), 'dark')
  }
  applyPalette('default')
  assert.equal(Object.hasOwn(root.dataset, 'adminPalette'), false)
  assert.equal(storage.get(PALETTE_STORAGE_KEY), 'default')
})

test('initialization restores preferences and invalid values safely fall back', t => {
  const { root, storage } = browser(t, { [PALETTE_STORAGE_KEY]: 'violet' })
  initializePalette()
  assert.equal(root.dataset.adminPalette, 'violet')
  storage.set(PALETTE_STORAGE_KEY, '<invalid>')
  assert.equal(initializePalette(), 'default')
  assert.equal(root.dataset.adminPalette, undefined)
  assert.equal(storage.get(PALETTE_STORAGE_KEY), '<invalid>')
  assert.equal(applyPalette('unknown'), 'default')
  assert.equal(storage.get(PALETTE_STORAGE_KEY), 'default')
})

test('blocked storage does not break startup or in-session selection', t => {
  const { root } = browser(t)
  globalThis.localStorage.getItem = () => { throw new Error('Blocked') }
  globalThis.localStorage.setItem = () => { throw new Error('Blocked') }
  assert.equal(initializePalette(), 'default')
  assert.equal(applyPalette('rose'), 'rose')
  assert.equal(root.dataset.adminPalette, 'rose')
})

test('appearance joins System Options without changing existing routes or role restrictions', () => {
  const coreSource = readFileSync(new URL('../../core/crud-admin/src/configs/routes.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'routes =')
  const coreContext = { r: entity => [{ path: `/dummy/${entity}/list`, name: `${entity}List` }], t: key => key, Layout: {}, routes: null }
  runInNewContext(coreSource, coreContext)
  const source = readFileSync(new URL('../../business/admin/router.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace('export default', 'routes =')
  const context = {
    ...coreContext,
    routes: null,
    coreRoutes: coreContext.routes,
    OrderRefundRecords: {}
  }
  runInNewContext(source, context)
  const originalSystem = coreContext.routes.find(route => route.name === 'SystemOptionManage')
  const originalChildren = [...originalSystem.children]
  const integrationSource = readFileSync(new URL('./config.ts', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'result =')
  const integration = {
    coreConfig: { routes: coreContext.routes, entities: {} }, businessRoutes: context.routes,
    businessConfig: {}, mergeEntities, mergeRoutes, moveRouteAfter, moveRouteBefore, appearanceRoute,
    t: key => key, installMenuIcons() {}, initializePalette() {}, result: null
  }
  runInNewContext(integrationSource, integration)
  const routes = integration.result.routes
  const parent = routes.find(route => route.name === 'SystemOptionManage')
  assert.equal(parent.path, '/system-option')
  assert.equal(parent.meta, originalSystem.meta)
  assert.deepEqual([...parent.meta.roles], ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])
  assert.deepEqual([...parent.children.slice(0, -1)], originalChildren)
  assert.deepEqual([...originalSystem.children], originalChildren)
  const page = parent.children.at(-1)
  assert.equal(page.path, 'appearance')
  assert.equal(page.name, 'AppearanceSettings')
  assert.equal(page.meta.title, 'Appearance settings')
  assert.equal(typeof page.component, 'function')
  const legacy = routes.find(route => route.path === '/settings/appearance')
  assert.equal(legacy.redirect, '/system-option/appearance')
  assert.equal(legacy.hidden, true)
  assert.equal(routes.some(route => route.path === '/settings'), false)
})

test('all ten accent presets have matching CSS tokens and stable unique identities', () => {
  assert.equal(palettes.length, 11)
  assert.equal(new Set(palettes.map(palette => palette.value)).size, palettes.length)
  const css = readFileSync(new URL('../../business/admin/appearance/palette.css', import.meta.url), 'utf8')
  for (const palette of palettes.slice(1)) {
    const rule = css.split(`html[data-admin-palette='${palette.value}'] {`)[1]?.split('}')[0]
    assert.ok(rule, palette.value)
    assert.ok(rule.includes(`--admin-palette-color: ${palette.color};`))
    assert.match(rule, /--admin-palette-bright: #[0-9a-f]{6};/)
    assert.match(rule, /--admin-palette-deep: #[0-9a-f]{6};/)
  }
})

test('appearance menu uses a registered palette icon rather than the system settings gear', () => {
  const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
  const { JSDOM } = require('jsdom')
  const { window } = new JSDOM('<!doctype html><body></body>')
  try {
    const icon = readFileSync(new URL('../../business/admin/icons/appearance.svg', import.meta.url), 'utf8')
    const source = readFileSync(new URL('../../business/admin/install-menu-icons.js', import.meta.url), 'utf8')
      .replace(/^import .*$/gm, '').replace('export default function', 'function')
    runInNewContext(`${source}\ninstallMenuIcons(); installMenuIcons();`, {
      document: window.document, DOMParser: window.DOMParser,
      appearanceIcon: icon, wechatIcon: '<svg/>', counterIcon: '<svg/>'
    })
    assert.equal(appearanceRoute.meta.icon, 'appearance')
    const symbols = window.document.querySelectorAll('#icon-appearance')
    assert.equal(symbols.length, 1)
    assert.equal(symbols[0].getAttribute('viewBox'), '0 0 24 24')
    assert.equal(symbols[0].querySelectorAll('circle').length, 4)
    assert.ok(symbols[0].querySelector('path[stroke="currentColor"]'))
  } finally {
    window.close()
  }
})

test('appearance page compiles and all locales contain the appearance messages', async() => {
  const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
  const { parse, compileScript, compileTemplate } = require('@vue/compiler-sfc')
  const source = readFileSync(new URL('../../business/admin/appearance/AppearanceSettings.vue', import.meta.url), 'utf8')
  const { descriptor, errors } = parse(source)
  assert.deepEqual(errors, [])
  assert.ok(compileScript(descriptor, { id: 'appearance-test' }))
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'AppearanceSettings.vue', id: 'appearance-test' }).errors, [])
  const keys = ['System Options', ...Array.from(source.matchAll(/\$t\('([^']+)'\)/g), match => match[1]), ...palettes.map(item => item.label)]
  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const { default: messages } = await import(`../../business/admin/i18n/${locale}.js`)
    for (const key of keys) assert.equal(typeof messages[key], 'string', `${locale}: ${key}`)
  }
})
