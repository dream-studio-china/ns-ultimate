import assert from 'node:assert/strict'
import test from 'node:test'
import { collectEntities } from './collect-entities.mjs'

test('does not treat colocated Vue action components as entity configurations', () => {
  const counter = { entity: { name: 'Counter', plural: 'counters' } }
  const entities = collectEntities({
    './collections/counter/Counter.js': { default: { Counter: counter } },
    './collections/counter/CounterImportAction.jsx': {
      default: { name: 'CounterImportAction', render() {} }
    },
    './collections/counter/CounterSortAction.jsx': {
      default: { name: 'CounterSortAction', render() {} }
    }
  })

  assert.deepEqual(entities, { Counter: counter })
})
