/** Fixed-position assistant layout, constrained to the host application's main area. */
export function mountPanelLayout(panel, handle, expandButton, { getBounds, onExpanded = () => {} }) {
  let expanded = false
  let floating = null
  let drag = null
  const finite = (value, fallback) => Number.isFinite(value) ? value : fallback
  const bounds = () => {
    const area = getBounds?.()
    const left = Math.max(8, finite(area?.left, 8))
    const top = Math.max(8, finite(area?.top, 8))
    return {
      left,
      top,
      right: Math.min(window.innerWidth - 8, finite(area?.right, window.innerWidth - 8)),
      bottom: Math.min(window.innerHeight - 8, finite(area?.bottom, window.innerHeight - 8))
    }
  }
  const apply = rect => {
    const area = bounds()
    const width = Math.max(0, Math.min(rect.width, area.right - area.left))
    const height = Math.max(0, Math.min(rect.height, area.bottom - area.top))
    Object.assign(panel.style, {
      left: `${Math.max(area.left, Math.min(rect.left, area.right - width))}px`,
      top: `${Math.max(area.top, Math.min(rect.top, area.bottom - height))}px`,
      width: `${width}px`, height: `${height}px`, right: 'auto', bottom: 'auto'
    })
  }
  const sync = () => {
    if (expanded) {
      const area = bounds()
      apply({ left: area.left, top: area.top, width: area.right - area.left, height: area.bottom - area.top })
    } else if (floating) apply(floating)
  }
  const toggle = () => {
    if (!expanded) {
      const { left, top, width, height } = panel.getBoundingClientRect()
      floating = { left, top, width, height }
    }
    expanded = !expanded
    handle.hidden = expanded
    expandButton.setAttribute('aria-pressed', String(expanded))
    onExpanded(expanded)
    sync()
  }
  const start = event => {
    if (expanded || event.button !== 0) return
    event.preventDefault()
    handle.focus?.()
    drag = { x: event.clientX, y: event.clientY, rect: panel.getBoundingClientRect() }
    try { handle.setPointerCapture(event.pointerId) } catch { /* Dragging still works without capture. */ }
  }
  const move = event => {
    if (!drag) return
    const { rect, x, y } = drag
    const area = bounds()
    const width = Math.min(Math.max(Math.min(340, area.right - area.left), rect.width + x - event.clientX), rect.right - area.left)
    const height = Math.min(Math.max(Math.min(360, area.bottom - area.top), rect.height + y - event.clientY), rect.bottom - area.top)
    floating = { left: rect.right - width, top: rect.bottom - height, width, height }
    apply(floating)
  }
  const stop = () => { drag = null }
  const keydown = event => {
    if (expanded || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return
    event.preventDefault()
    const rect = panel.getBoundingClientRect()
    const step = event.shiftKey ? 40 : 10
    const dx = event.key === 'ArrowLeft' ? step : event.key === 'ArrowRight' ? -step : 0
    const dy = event.key === 'ArrowUp' ? step : event.key === 'ArrowDown' ? -step : 0
    const width = Math.max(280, rect.width + dx)
    const height = Math.max(320, rect.height + dy)
    floating = { left: rect.right - width, top: rect.bottom - height, width, height }
    apply(floating)
  }
  expandButton.addEventListener('click', toggle)
  handle.addEventListener('pointerdown', start)
  handle.addEventListener('pointermove', move)
  handle.addEventListener('pointerup', stop)
  handle.addEventListener('lostpointercapture', stop)
  handle.addEventListener('keydown', keydown)
  window.addEventListener('resize', sync)
  window.addEventListener('scroll', sync, true)
  const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(sync) : null
  observer?.observe(document.documentElement)
  return () => {
    observer?.disconnect()
    window.removeEventListener('resize', sync)
    window.removeEventListener('scroll', sync, true)
    expandButton.removeEventListener('click', toggle)
    handle.removeEventListener('pointerdown', start)
    handle.removeEventListener('pointermove', move)
    handle.removeEventListener('pointerup', stop)
    handle.removeEventListener('lostpointercapture', stop)
    handle.removeEventListener('keydown', keydown)
  }
}
