import assert from 'node:assert/strict'
import test from 'node:test'
import { handleComposerFileDragOver, handleComposerFileDrop, planChatCancel } from '../src/entry.js'

function dragEvent(types = ['Files'], files = [{ name: 'photo.png' }]) {
  return {
    prevented: 0,
    preventDefault() { this.prevented += 1 },
    dataTransfer: { types, files, dropEffect: '' }
  }
}

test('file drops are always prevented inside the composer, including while busy', () => {
  const busyEvent = dragEvent()
  const selected = []
  const accepted = handleComposerFileDrop(busyEvent, { busy: true, onFiles: files => selected.push(...files) })
  assert.equal(accepted, false)
  assert.equal(busyEvent.prevented, 1)
  assert.deepEqual(selected, [])

  const readyEvent = dragEvent()
  const acceptedReady = handleComposerFileDrop(readyEvent, { busy: false, onFiles: files => selected.push(...files) })
  assert.equal(acceptedReady, true)
  assert.equal(readyEvent.prevented, 1)
  assert.equal(selected.length, 1)
})

test('file drag-over is always prevented while busy but only highlighted when ready', () => {
  const busyEvent = dragEvent()
  assert.equal(handleComposerFileDragOver(busyEvent, true), false)
  assert.equal(busyEvent.prevented, 1)

  const readyEvent = dragEvent()
  assert.equal(handleComposerFileDragOver(readyEvent, false), true)
  assert.equal(readyEvent.prevented, 1)
  assert.equal(readyEvent.dataTransfer.dropEffect, 'copy')

  const nonFileEvent = dragEvent(['text/plain'], [])
  assert.equal(handleComposerFileDragOver(nonFileEvent, false), false)
  assert.equal(nonFileEvent.prevented, 0)
})

test('chat cancellation clears busy only for the matching knowledge-preprocessing task', () => {
  assert.deepEqual(planChatCancel({ preprocessingGeneration: 4, taskGeneration: 4, writing: false, recovering: false }), {
    clearBusy: true,
    suppressLateResult: true
  })
  assert.equal(planChatCancel({ preprocessingGeneration: 3, taskGeneration: 4 }).clearBusy, false)
  assert.equal(planChatCancel({ preprocessingGeneration: 4, taskGeneration: 4, writing: true }).clearBusy, false)
  assert.equal(planChatCancel({ preprocessingGeneration: 4, taskGeneration: 4, recovering: true }).clearBusy, false)
  assert.equal(planChatCancel({ preprocessingGeneration: null, taskGeneration: 4 }).clearBusy, false)
})
