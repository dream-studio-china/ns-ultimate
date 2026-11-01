import assert from 'node:assert/strict'
import test from 'node:test'
import {
  diningModeFromOrderCounterBindings,
  diningModeFromOrderMetadata
} from './order-dining-mode.mjs'

test('reads the mode uuid recorded by the customer order metadata', () => {
  assert.deepEqual(diningModeFromOrderMetadata({
    metadata: {
      diningModeUuid: 'mode-hotpot',
      diningModeName: '火锅'
    }
  }), { uuid: 'mode-hotpot', name: '火锅' })

  assert.deepEqual(diningModeFromOrderMetadata({
    metadata: JSON.stringify({ dining_mode_uuid: 'mode-breakfast' })
  }), { uuid: 'mode-breakfast', name: null })

  assert.deepEqual(diningModeFromOrderMetadata({
    __metadata: { diningModeUuid: 'mode-snack' }
  }), { uuid: 'mode-snack', name: null })

  assert.equal(diningModeFromOrderMetadata({ metadata: null }), null)
})

test('keeps the frozen counter session mode ahead of order metadata', () => {
  const fromSession = diningModeFromOrderCounterBindings([{
    counterSession: {
      diningModeUuid: 'mode-session',
      diningModeSnapshot: JSON.stringify({ uuid: 'mode-session', name: '晚餐' })
    }
  }])

  assert.deepEqual(fromSession, { uuid: 'mode-session', name: '晚餐' })
})
