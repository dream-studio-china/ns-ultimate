import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { midnightPickerTime } from '../../business/admin/config/components/date-picker-defaults.mjs'

test('date picker end times default to the start of the selected day', () => {
  const first = midnightPickerTime()
  const second = midnightPickerTime()
  assert.equal(first.getHours(), 0)
  assert.equal(first.getMinutes(), 0)
  assert.equal(first.getSeconds(), 0)
  assert.notStrictEqual(first, second)
})

test('business end-time pickers explicitly use the midnight default', () => {
  const promotionConfig = readFileSync(new URL('../../business/admin/config/overrides/promotion/promotion.js', import.meta.url), 'utf8')
  const simulation = readFileSync(new URL('../../business/admin/simulation/PromotionSimulation.vue', import.meta.url), 'utf8')

  assert.match(promotionConfig, /type_options:\s*\{\s*'default-time': midnightPickerTime\(\)\s*\}/)
  assert.match(simulation, /v-model="campaignDraft\.endTime"[\s\S]*?:default-time="pickerDefaultTime"/)
  assert.match(simulation, /pickerDefaultTime: midnightPickerTime\(\)/)
})
