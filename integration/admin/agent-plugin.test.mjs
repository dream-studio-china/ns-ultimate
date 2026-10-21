import assert from 'node:assert/strict'
import test from 'node:test'
import path from 'node:path'
import { adminAgentPlugin } from './agent-plugin.mjs'

test('injects the optional independent agent loader without editing the admin entry', () => {
  const plugin = adminAgentPlugin(path.resolve('agent/src/entry.js'))
  const html = '<html><body><div id="app"></div></body></html>'
  const output = plugin.transformIndexHtml.handler(html)
  assert.match(output, /virtual:ns-ultimate-agent-config/)
  assert.match(output, /agent-session\.js/)
  assert.match(output, /agent-host\.js/)
  assert.match(output, /agent\/src\/entry\.js/)
  assert.match(output, /<div id="app"><\/div>/)
})

test('leaves an unrecognized host page unchanged', () => {
  const plugin = adminAgentPlugin(path.resolve('agent/src/entry.js'))
  let warned = false
  const output = plugin.transformIndexHtml.handler.call({ warn: () => { warned = true } }, '<html></html>')
  assert.equal(output, '<html></html>')
  assert.equal(warned, true)
})
