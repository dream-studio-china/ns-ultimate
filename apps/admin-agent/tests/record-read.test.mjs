import assert from 'node:assert/strict'
import test from 'node:test'
import {
  MAX_RECORD_READ_FIELD_CHARACTERS,
  prepareRecordRead,
  projectRecordRead,
  validateReadFields
} from '../src/record-read.js'

const catalog = [{
  key: 'Content',
  name: 'Content',
  readable: true,
  path: '/api/v1/manage/contents'
}]
const operations = [
  { method: 'GET', path: '/api/v1/manage/contents' },
  { method: 'GET', path: '/api/v1/manage/contents/{id}' }
]
const policy = { Content: { fields: ['title', 'body'] } }

test('resolves only policy entities, exact identifiers and documented detail routes', () => {
  assert.deepEqual(prepareRecordRead({ entity: 'Content', identifier: '12' }, { catalog, policy, operations }), {
    key: 'Content',
    name: 'Content',
    path: '/api/v1/manage/contents/12',
    identifier: '12',
    fields: ['title', 'body']
  })
  assert.throws(() => prepareRecordRead({ entity: 'Content', identifier: '12', fields: ['body'] }, { catalog, policy, operations }), /unsupported parameters/)
  assert.throws(() => prepareRecordRead({ entity: 'User', identifier: '12' }, { catalog, policy, operations }), /no approved record read/)
  assert.throws(() => prepareRecordRead({ entity: 'Content', identifier: '../12' }, { catalog, policy, operations }), /positive numeric ID or UUID/)
  assert.throws(() => prepareRecordRead({ entity: 'Content', identifier: '12' }, {
    catalog, policy, operations: [{ method: 'GET', path: '/api/v1/manage/contents' }]
  }), /documented detail endpoint/)
})

test('rejects unsafe or malformed read policy fields', () => {
  assert.throws(() => validateReadFields([]), /invalid field list/)
  assert.throws(() => validateReadFields(['body', 'body']), /repeats a field/)
  assert.throws(() => validateReadFields(['metadata']), /not allowlisted/)
  assert.throws(() => validateReadFields(['settings.apiKey']), /not allowlisted/)
  assert.throws(() => validateReadFields(['__proto__']), /not allowlisted/)
})

test('projects only allowlisted scalar fields and reports bounded truncation', () => {
  const result = projectRecordRead({
    id: 12,
    title: 'Article',
    body: 'x'.repeat(MAX_RECORD_READ_FIELD_CHARACTERS + 5),
    metadata: { secret: true }
  }, ['title', 'body'])
  assert.equal(result.fields.title, 'Article')
  assert.equal(result.fields.body.length, MAX_RECORD_READ_FIELD_CHARACTERS)
  assert.deepEqual(result.truncatedFields, ['body'])
  assert.deepEqual(result.omittedFields, [])
  assert.equal(result.truncated, true)
  assert.equal('metadata' in result.fields, false)
  assert.ok(JSON.stringify(result).length <= 14000)
  assert.deepEqual(projectRecordRead({ title: 'Only title', extra: 'ignored' }, ['title', 'missing']), {
    fields: { title: 'Only title' }, truncatedFields: [], omittedFields: ['missing'], truncated: true
  })
})

test('drops trailing fields when the whole projection exceeds the transport bound', () => {
  const result = projectRecordRead({ title: 'a'.repeat(8000), body: 'b'.repeat(8000) }, ['title', 'body'])
  assert.equal(JSON.stringify(result.fields).length <= 14000, true)
  assert.equal(result.truncated, true)
  assert.ok(result.omittedFields.includes('body'))
})
