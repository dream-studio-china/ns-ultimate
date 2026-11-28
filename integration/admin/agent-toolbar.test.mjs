import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import test from 'node:test'
import { mountAdminAgentLauncher } from './agent-toolbar.mjs'
import { mountAdminAgent } from '../../apps/admin-agent/src/entry.js'

const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
const { JSDOM } = require('jsdom')
const navbar = '<div class="navbar"><div class="right-menu"><button>Theme</button></div></div>'

test('mounts an icon-only launcher in the toolbar without nesting the panel, and cleans up on disposal', async t => {
  const { window } = new JSDOM('<div id="app"></div>')
  const { document } = window
  const { attachShadow } = window.Element.prototype
  t.mock.method(window.Element.prototype, 'attachShadow', function () {
    return attachShadow.call(this, { mode: 'open' })
  })
  Object.assign(globalThis, { window, document })
  t.after(() => {
    delete globalThis.window
    delete globalThis.document
    window.close()
  })
  const app = document.getElementById('app')
  const dispose = mountAdminAgent({ mountLauncher: mountAdminAgentLauncher })
  try {
    assert.equal(document.querySelector('ns-admin-agent-launcher'), null)
    app.innerHTML = navbar
    await Promise.resolve()
    const launcher = document.querySelector('ns-admin-agent-launcher')
    const button = launcher.shadowRoot.querySelector('button')
    const panelHost = document.querySelector('ns-admin-agent')
    const panel = panelHost.shadowRoot.querySelector('.panel')
    assert.equal(app.querySelector('.right-menu').firstElementChild, launcher)
    assert.equal(panelHost.parentElement, document.body)
    assert.equal(button.textContent, '🤖')
    assert.equal(button.getAttribute('aria-label'), 'Open admin assistant')
    assert.equal(button.title, 'Open admin assistant')
    assert.equal(panel.classList.contains('hidden'), true)
    button.click()
    assert.equal(button.getAttribute('aria-expanded'), 'true')
    assert.equal(panel.classList.contains('hidden'), false)
    panel.querySelector('button[aria-label="Close"]').click()
    assert.equal(button.getAttribute('aria-expanded'), 'false')
    assert.equal(panel.classList.contains('hidden'), true)

    app.innerHTML = navbar
    await Promise.resolve()
    assert.equal(app.querySelector('.right-menu').firstElementChild, launcher)
    assert.equal(document.querySelectorAll('ns-admin-agent-launcher').length, 1)

    dispose()
    assert.equal(launcher.isConnected, false)
    assert.equal(panelHost.isConnected, false)
    app.innerHTML = navbar
    await Promise.resolve()
    assert.equal(launcher.isConnected, false)
  } finally {
    dispose()
  }
})
