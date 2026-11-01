import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { runInNewContext } from 'node:vm'
import { adminSessionIdentity } from './agent-chat-scope.mjs'

const source = readFileSync(new URL('./agent-session.js', import.meta.url), 'utf8')
  .replace(/^import .*$/gm, '')
  .replace('export function monitorAdminSession', 'function monitorAdminSession')
const token = (sub, jti = 'first') => `header.${Buffer.from(JSON.stringify({ sub, jti })).toString('base64url')}.signature`
const settle = () => new Promise(resolve => setImmediate(resolve))

function monitor(onLogin, onLogout = () => {}) {
  let currentToken = token('42')
  let now = 0
  let poll
  const warnings = []
  const stop = runInNewContext(`${source}\nmonitorAdminSession(onLogin, onLogout)`, {
    adminSessionIdentity, getToken: () => currentToken, onLogin, onLogout,
    Date: { now: () => now }, console: { warn: (...args) => warnings.push(args) },
    window: {
      setInterval(callback, delay) { assert.equal(delay, 800); poll = callback; return 1 },
      clearInterval(id) { assert.equal(id, 1); poll = null }
    }
  })
  return {
    stop, warnings,
    setToken(value) { currentToken = value },
    async tick(ms = 800) { now += ms; poll?.(); await settle() }
  }
}

test('retries a synchronous mount failure and retains the mounted instance across token rotation', async () => {
  let attempts = 0
  let disposals = 0
  const session = monitor(() => {
    if (++attempts === 1) throw new Error('Temporary failure')
    return () => disposals++
  })
  await settle()
  assert.equal(attempts, 1)
  assert.equal(session.warnings.length, 1)
  await session.tick(799)
  assert.equal(attempts, 1)
  session.setToken(token('42', 'rotated'))
  await session.tick(1)
  assert.equal(attempts, 2)
  await session.tick(10000)
  assert.equal(attempts, 2)
  assert.equal(disposals, 0)
  session.stop()
  assert.equal(disposals, 1)
})

test('backs off asynchronous failures, caps attempts and resets the budget after logout', async () => {
  let attempts = 0
  let logouts = 0
  const session = monitor(async () => { attempts++; throw new Error('Unavailable') }, () => logouts++)
  await settle()
  await session.tick()
  assert.equal(attempts, 2)
  await session.tick()
  assert.equal(attempts, 2)
  await session.tick()
  assert.equal(attempts, 3)
  session.setToken(token('42', 'rotated'))
  await session.tick(10000)
  assert.equal(attempts, 3)
  assert.equal(session.warnings.length, 3)
  session.setToken('')
  await session.tick()
  assert.equal(logouts, 1)
  session.setToken(token('42', 'new-session'))
  await session.tick()
  assert.equal(attempts, 4)
  session.stop()
})

test('does not duplicate pending mounts and discards results from earlier account generations', async () => {
  const pending = []
  const disposed = []
  const session = monitor(() => new Promise((resolve, reject) => pending.push({ resolve, reject })))
  await settle()
  await session.tick(10000)
  assert.equal(pending.length, 1)
  session.setToken(token('43'))
  await session.tick()
  session.setToken(token('42', 'returned'))
  await session.tick()
  assert.equal(pending.length, 3)
  pending[2].resolve(() => disposed.push('current'))
  pending[0].resolve(() => disposed.push('stale'))
  pending[1].reject(new Error('Stale failure'))
  await settle()
  assert.deepEqual(disposed, ['stale'])
  assert.equal(session.warnings.length, 0)
  await session.tick(10000)
  assert.equal(pending.length, 3)
  session.stop()
  assert.deepEqual(disposed, ['stale', 'current'])
})

test('logout and disposal cancel retries and release late mount results', async () => {
  let attempts = 0
  const session = monitor(async () => { attempts++; throw new Error('Unavailable') })
  await settle()
  session.setToken('')
  await session.tick(10000)
  assert.equal(attempts, 1)
  session.stop()
  await session.tick(10000)
  assert.equal(attempts, 1)

  let resolveMount
  let disposals = 0
  const pending = monitor(() => new Promise(resolve => { resolveMount = resolve }))
  await settle()
  pending.stop()
  resolveMount(() => disposals++)
  await settle()
  assert.equal(disposals, 1)
})
