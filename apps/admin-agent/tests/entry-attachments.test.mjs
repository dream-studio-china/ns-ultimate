import assert from 'node:assert/strict'
import test from 'node:test'
import { handleComposerFileDragOver, handleComposerFileDrop, handleComposerImagePaste, pastedImageFiles, planChatCancel } from '../src/entry.js'

function dragEvent(types = ['Files'], files = [{ name: 'photo.png' }]) {
  return {
    prevented: 0,
    preventDefault() { this.prevented += 1 },
    dataTransfer: { types, files, dropEffect: '' }
  }
}

function clipboardEvent(items = [], files = []) {
  return {
    prevented: 0,
    preventDefault() { this.prevented += 1 },
    clipboardData: { items, files }
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

test('pasted images enter the existing attachment pipeline without hijacking text paste', () => {
  const pastedImage = { name: 'image.png', type: 'image/png' }
  const pasteEvent = clipboardEvent([
    { kind: 'string', type: 'text/plain', getAsFile: () => null },
    { kind: 'file', type: 'image/png', getAsFile: () => pastedImage }
  ])
  const selected = []
  assert.equal(handleComposerImagePaste(pasteEvent, { onFiles: files => selected.push(...files) }), true)
  assert.equal(pasteEvent.prevented, 1)
  assert.deepEqual(selected, [pastedImage])

  const busyEvent = clipboardEvent([
    { kind: 'file', type: 'image/png', getAsFile: () => pastedImage }
  ])
  const blocked = []
  assert.equal(handleComposerImagePaste(busyEvent, { busy: true, onFiles: files => blocked.push(...files) }), false)
  assert.equal(busyEvent.prevented, 1)
  assert.deepEqual(blocked, [])

  const textEvent = clipboardEvent([
    { kind: 'string', type: 'text/plain', getAsFile: () => null }
  ])
  assert.equal(handleComposerImagePaste(textEvent), false)
  assert.equal(textEvent.prevented, 0)

  const pdfEvent = clipboardEvent([
    { kind: 'file', type: 'application/pdf', getAsFile: () => ({ name: 'doc.pdf', type: 'application/pdf' }) }
  ])
  assert.equal(handleComposerImagePaste(pdfEvent), false)
  assert.equal(pdfEvent.prevented, 0)
})

test('pasted images fall back to clipboard files and ignore untitled non-images', () => {
  const image = { name: 'shot.png', type: 'image/png' }
  const fileEvent = clipboardEvent([], [image, { name: 'blob', type: 'application/octet-stream' }])
  assert.deepEqual(pastedImageFiles(fileEvent), [image])

  const missingFileEvent = clipboardEvent([{ kind: 'file', type: 'image/png', getAsFile: () => null }])
  assert.deepEqual(pastedImageFiles(missingFileEvent), [])
  assert.equal(handleComposerImagePaste({ clipboardData: null }), false)
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
