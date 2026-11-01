import assert from 'node:assert/strict'
import test from 'node:test'
import { adminSessionIdentity, chatScopeFromToken } from './agent-chat-scope.mjs'

test('local history partition uses stable account identity, never the credential or token expiry', () => {
  const token = payload => `header.${Buffer.from(JSON.stringify(payload)).toString('base64url')}.signature`
  assert.equal(chatScopeFromToken(token({ sub: '42', exp: 1 })), 'account:42')
  assert.equal(chatScopeFromToken(token({ sub: '42', exp: 2 })), 'account:42')
  assert.notEqual(chatScopeFromToken(token({ sub: '43' })), 'account:42')
  for (const invalid of ['', 'opaque-token', token({ sub: {} }), token({ sub: '../bad' }), token({ username: 'name' })]) assert.equal(chatScopeFromToken(invalid), null)
})

test('session identity survives access-token rotation and changes with the account or logout', () => {
  const token = payload => `header.${Buffer.from(JSON.stringify(payload)).toString('base64url')}.signature`
  const first = token({ sub: '42', exp: 1, jti: 'a' })
  const rotated = token({ sub: '42', exp: 2, jti: 'b' })
  assert.equal(adminSessionIdentity(first), adminSessionIdentity(rotated))
  assert.notEqual(adminSessionIdentity(first), adminSessionIdentity(token({ sub: '43' })))
  assert.equal(adminSessionIdentity(''), '')
  assert.equal(adminSessionIdentity('opaque-token'), 'token:opaque-token')
})
