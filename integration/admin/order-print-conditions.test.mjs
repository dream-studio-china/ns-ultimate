import test from 'node:test'
import assert from 'node:assert/strict'
import { conditionValueType, initialConditionValue, changeConditionOperator, withConditionValueType } from '../../business/admin/config/components/order-print-conditions.mjs'

test('condition value types retain strict JSON scalar types', () => {
  for (const type of ['string', 'integer', 'boolean', 'null']) {
    assert.equal(conditionValueType(initialConditionValue(type)), type)
  }
  assert.equal(conditionValueType('true'), 'string')
  assert.equal(conditionValueType(true), 'boolean')
})

test('null comparisons retain a marker through generic form cleanup', () => {
  const condition = withConditionValueType({ field: 'metadata.flag', operator: 'equals', value: '' }, 'null')
  assert.deepEqual(condition, { field: 'metadata.flag', operator: 'equals', value: null, valueKind: 'null' })
  assert.deepEqual(changeConditionOperator(condition, 'exists'), { field: 'metadata.flag', operator: 'exists' })
  assert.deepEqual(withConditionValueType(condition, 'boolean'), { field: 'metadata.flag', operator: 'equals', value: false })
})

test('existence operators remove the value and comparisons restore a scalar', () => {
  const original = { field: 'metadata.flag', operator: 'equals', value: true }
  const exists = changeConditionOperator(original, 'exists')
  assert.deepEqual(exists, { field: 'metadata.flag', operator: 'exists' })
  assert.equal(original.value, true)
  assert.deepEqual(changeConditionOperator(exists, 'not_equals'), {
    field: 'metadata.flag', operator: 'not_equals', value: ''
  })
})
