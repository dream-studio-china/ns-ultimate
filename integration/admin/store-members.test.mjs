import assert from 'node:assert/strict'
import test from 'node:test'
import {
  filterMemberRows,
  memberRows,
  storeMemberPayload,
  storeMembersPath
} from '../../business/admin/config/components/store-members.mjs'

const storeUuid = 'ed7e411c-e9b8-4228-b39d-48bc321d7210'
const userUuid = '27fdb3f8-e282-4a61-bd58-de03f8f1a9e8'

test('store member endpoints and payloads use existing validated API fields', () => {
  assert.equal(storeMembersPath('/api/v1', storeUuid), `/api/v1/stores/${storeUuid}/members`)
  assert.deepEqual(storeMemberPayload(` ${userUuid} `, 'MANAGER'), { userUuid, role: 'manager' })
  assert.throws(() => storeMembersPath('/api/v1', '../stores'), /store_uuid_invalid/)
  assert.throws(() => storeMemberPayload('invalid', 'manager'), /user_uuid_invalid/)
  assert.throws(() => storeMemberPayload(userUuid, 'administrator'), /role_invalid/)
})

test('store member rows resolve account labels and can be searched', () => {
  const rows = memberRows([{
    uuid: '2c57d9df-01de-4c5d-914e-40ca71edb9e9',
    userUuid,
    role: 'manager',
    status: 'active'
  }], [{
    uuid: userUuid,
    __toString: '13318637449'
  }])

  assert.equal(rows.length, 1)
  assert.equal(rows[0].accountLabel, '13318637449')
  assert.equal(filterMemberRows(rows, '133186')[0], rows[0])
  assert.equal(filterMemberRows(rows, 'manager')[0], rows[0])
  assert.deepEqual(filterMemberRows(rows, 'missing'), [])
})
