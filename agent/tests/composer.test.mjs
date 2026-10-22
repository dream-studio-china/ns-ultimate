import assert from 'node:assert/strict'
import test from 'node:test'
import { handleComposerKeydown } from '../src/composer.js'

function keyEvent(options = {}) {
  return { key: 'Enter', ctrlKey: false, isComposing: false, keyCode: 13, preventDefault() { this.prevented = true }, ...options }
}

test('Enter submits while Ctrl+Enter inserts a newline at the selection', () => {
  let submitted = 0
  const textarea = {
    value: 'helloworld',
    selectionStart: 4,
    selectionEnd: 4,
    setRangeText(text, start, end, mode) {
      this.value = `${this.value.slice(0, start)}${text}${this.value.slice(end)}`
      if (mode === 'end') this.selectionStart = this.selectionEnd = start + text.length
    }
  }

  const enter = keyEvent()
  handleComposerKeydown(enter, textarea, () => { submitted += 1 })
  assert.equal(enter.prevented, true)
  assert.equal(submitted, 1)

  const controlEnter = keyEvent({ ctrlKey: true })
  handleComposerKeydown(controlEnter, textarea, () => { submitted += 1 })
  assert.equal(controlEnter.prevented, true)
  assert.equal(textarea.value, 'hell\noworld')
  assert.equal(submitted, 1)
})

test('does not intercept Enter during IME composition', () => {
  let submitted = false
  const event = keyEvent({ isComposing: true })
  handleComposerKeydown(event, {}, () => { submitted = true })
  assert.equal(event.prevented, undefined)
  assert.equal(submitted, false)
})
