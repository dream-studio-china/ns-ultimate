import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import {
  PROMOTION_LIST_LIMIT,
  formatPromotionRecordedAt,
  orderPromotionsFromMetadata,
  promotionPhaseLabel,
  promotionTypeLabel
} from '../../business/admin/config/components/order-promotions.mjs'

const recordedAt = '2026-11-12T10:30:00+08:00'

test('reads the promotion snapshot from order metadata', () => {
  const snapshot = orderPromotionsFromMetadata({
    metadata: {
      _promotion: {
        applied: [
          { id: 3, name: 'Member 10% off', type: 'member_discount', phase: 'inner' },
          { id: 7, name: 'Spend 40 save 5', type: 'full_reduction', phase: 'outer' },
          { id: 9, name: 'Weekend best price', type: 'discount', phase: 'best_price' }
        ],
        recordedAt
      }
    }
  })

  assert.deepEqual(snapshot, {
    applied: [
      { id: 3, name: 'Member 10% off', type: 'member_discount', phase: 'inner' },
      { id: 7, name: 'Spend 40 save 5', type: 'full_reduction', phase: 'outer' },
      { id: 9, name: 'Weekend best price', type: 'discount', phase: 'best_price' }
    ],
    recordedAt
  })
  assert.equal(PROMOTION_LIST_LIMIT, 2)
})

test('accepts JSON-shaped metadata and nested __metadata records', () => {
  assert.equal(orderPromotionsFromMetadata({
    metadata: JSON.stringify({ _promotion: { applied: [{ promotionId: 4, promotionName: 'Serialized' }] } })
  }).applied[0].name, 'Serialized')

  assert.equal(orderPromotionsFromMetadata({
    __metadata: { metadata: { _promotion: { applied: [{ name: 'Nested', phase: 'outer' }] } } }
  }).applied[0].phase, 'outer')
})

test('distinguishes a recorded empty result from a legacy order', () => {
  assert.deepEqual(orderPromotionsFromMetadata({ metadata: { _promotion: { applied: [] } } }), {
    applied: [],
    recordedAt: null
  })
  assert.equal(orderPromotionsFromMetadata({ metadata: { diningModeUuid: 'mode-1' } }), null)
  assert.equal(orderPromotionsFromMetadata(null), null)
})

test('skips malformed entries and falls back to the promotion id', () => {
  const snapshot = orderPromotionsFromMetadata({
    metadata: {
      _promotion: {
        applied: [null, [], { name: '  ' }, { id: 12, phase: 'unexpected' }, { name: 'Named' }]
      }
    }
  })

  assert.deepEqual(snapshot.applied, [
    { id: 12, name: '#12', type: null, phase: 'unknown' },
    { id: null, name: 'Named', type: null, phase: 'unknown' }
  ])
})

test('maps stored phases and types to translation keys', () => {
  assert.equal(promotionPhaseLabel('inner'), 'Inner promotion phase')
  assert.equal(promotionPhaseLabel('outer'), 'Outer promotion phase')
  assert.equal(promotionPhaseLabel('best_price'), 'Best available promotion')
  assert.equal(promotionPhaseLabel('unknown'), 'Promotion')
  assert.equal(promotionTypeLabel('full_reduction'), 'Full reduction')
  assert.equal(promotionTypeLabel('member_discount'), 'Member discount')
  assert.equal(promotionTypeLabel('custom_type'), 'custom_type')
  assert.equal(promotionTypeLabel(null), '')
})

test('formats the recorded time and tolerates invalid values', () => {
  assert.equal(formatPromotionRecordedAt(recordedAt), new Date(recordedAt).toLocaleString())
  assert.equal(formatPromotionRecordedAt('not-a-date'), '')
  assert.equal(formatPromotionRecordedAt(null), '')
})

test('Order management wires the promotion fields into list and detail', () => {
  const source = readFileSync(new URL(
    '../../business/admin/config/overrides/trade/order.js', import.meta.url
  ), 'utf8')
  assert.match(source, /createOrderPromotionsField/)
  assert.match(source, /createOrderPromotionsDetailField/)
  assert.match(source, /promotionsField/)
  assert.match(source, /promotionsDetailField/)

  const factory = readFileSync(new URL(
    '../../business/admin/config/components/OrderPromotions.js', import.meta.url
  ), 'utf8')
  assert.match(factory, /label: t\('Promotions'\)/)
  assert.match(factory, /full_width: true/)
})
