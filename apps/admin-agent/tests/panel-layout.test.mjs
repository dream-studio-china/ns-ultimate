import assert from 'node:assert/strict'
import test from 'node:test'
import { mountPanelLayout } from '../src/panel-layout.js'

test('expands to the main area, restores size, resizes from top left and cleans up', () => {
  const previousWindow = globalThis.window
  const previousDocument = globalThis.document
  globalThis.window = Object.assign(new EventTarget(), { innerWidth: 1440, innerHeight: 1000 })
  globalThis.document = { documentElement: {} }
  const panel = {
    style: {},
    getBoundingClientRect() {
      const left = parseFloat(this.style.left ?? 990)
      const top = parseFloat(this.style.top ?? 240)
      const width = parseFloat(this.style.width ?? 430)
      const height = parseFloat(this.style.height ?? 680)
      return { left, top, width, height, right: left + width, bottom: top + height }
    }
  }
  const handle = Object.assign(new EventTarget(), { setPointerCapture() {} })
  const button = Object.assign(new EventTarget(), { setAttribute() {} })
  const dispatch = (target, type, props = {}) => target.dispatchEvent(Object.assign(new Event(type, { cancelable: true }), props))
  const dispose = mountPanelLayout(panel, handle, button, {
    getBounds: () => ({ left: 240, top: 84, right: 1440, bottom: 1000 })
  })
  try {
    dispatch(button, 'click')
    assert.equal(panel.style.left, '240px')
    assert.equal(panel.style.top, '84px')
    assert.equal(panel.style.width, '1192px')
    assert.equal(handle.hidden, true)
    dispatch(button, 'click')
    assert.equal(panel.style.width, '430px')
    assert.equal(panel.style.height, '680px')
    assert.equal(handle.hidden, false)
    dispatch(handle, 'pointerdown', { button: 0, clientX: 990, clientY: 240, pointerId: 1 })
    dispatch(handle, 'pointermove', { clientX: 890, clientY: 140 })
    assert.equal(panel.style.width, '530px')
    assert.equal(panel.style.left, '890px')
    assert.equal(panel.style.height, '780px')
    dispatch(handle, 'pointerup')
    dispatch(handle, 'keydown', { key: 'ArrowLeft', shiftKey: false })
    assert.equal(panel.style.width, '540px')
    window.innerWidth = 500
    window.dispatchEvent(new Event('resize'))
    assert.ok(parseFloat(panel.style.width) <= 252)
    dispose()
    const width = panel.style.width
    dispatch(button, 'click')
    assert.equal(panel.style.width, width)
  } finally {
    dispose()
    globalThis.window = previousWindow
    globalThis.document = previousDocument
  }
})
