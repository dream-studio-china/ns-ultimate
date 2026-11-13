import assert from 'node:assert/strict'
import test from 'node:test'
import {
  nextRecommendationLevel,
  normalizeRecommendationLevel,
  productRecommendationUpdate,
  productSettingUpdate,
  productSortOrderUpdate,
  productSoldoutUpdate
} from '../../business/admin/config/components/product-list-updates.mjs'

test('product list settings merge with existing values and allow an empty settings object', () => {
  assert.deepEqual(productSettingUpdate({
    settings: {
      kitchenPrinterEnable: true,
      kitchenTicketDisabled: false,
      startSaleTime: '09:30',
      endSaleTime: '21:45',
      custom: 'preserved'
    }
  }, 'kitchenTicketDisabled', true), {
    settings: {
      kitchenPrinterEnable: true,
      kitchenTicketDisabled: true,
      startSaleTime: '09:30',
      endSaleTime: '21:45',
      custom: 'preserved'
    }
  })

  assert.deepEqual(productSettingUpdate({ settings: null }, 'kitchenPrinterEnable', false), {
    settings: { kitchenPrinterEnable: false }
  })
  assert.throws(() => productSettingUpdate({}, 'unexpected', true), /Unsupported product list setting/)
})

test('product list direct mutations validate sold-out and recommendation values', () => {
  assert.deepEqual(productSoldoutUpdate(true), { isSoldout: true })
  assert.deepEqual(productRecommendationUpdate(4), { recommendationLevel: 4 })
  assert.deepEqual(productSortOrderUpdate(12), { sortOrder: 12 })
  assert.equal(nextRecommendationLevel(3, 3), 0)
  assert.equal(nextRecommendationLevel(3, 5), 5)
  assert.equal(normalizeRecommendationLevel(9), 5)
  assert.equal(normalizeRecommendationLevel('invalid'), 0)
  assert.throws(() => productRecommendationUpdate(1.5), /integer from 0 to 5/)
  assert.throws(() => productSoldoutUpdate('yes'), /boolean value/)
  assert.throws(() => productSortOrderUpdate(-1), /integer from 0 to 1000000/)
  assert.throws(() => productSortOrderUpdate(1.5), /integer from 0 to 1000000/)
})
