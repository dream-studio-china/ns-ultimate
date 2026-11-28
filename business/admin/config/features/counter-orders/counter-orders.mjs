export const endpoint = '/api/v1/manage/counter-orders'

export function counterRouteUuid(value) {
  return typeof value === 'string' && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value)
    ? value.toLowerCase()
    : ''
}

export function counterListQuery(query) {
  const { counter: _counter, ...list } = query
  return list
}

export const states = {
  status: ['draft', 'pending', 'confirmed', 'paid', 'fulfilled', 'completed', 'cancelled', 'refunded', 'merged'],
  payment: ['pending', 'paying', 'paid', 'failed', 'cancelled', 'partial_refunded', 'refunded'],
  operational: ['pending_validation', 'awaiting_inventory', 'accepted', 'rejected', 'fulfillment_pending', 'fulfilling', 'fulfilled', 'verified', 'cancelled'],
  merged: ['yes', 'no'],
  anonymous: ['yes', 'no']
}

export function statusLabel(value) {
  if (!value) return 'Unknown'
  return value.replace(/_/g, ' ').replace(/^./, letter => letter.toUpperCase())
}

const statusTones = {
  draft: 'slate',
  pending: 'amber',
  confirmed: 'blue',
  paid: 'green',
  fulfilled: 'cyan',
  completed: 'emerald',
  cancelled: 'gray',
  refunded: 'brick',
  merged: 'violet'
}

export function statusTone(status) {
  return Object.hasOwn(statusTones, status) ? statusTones[status] : 'slate'
}

function mergeChainTones(nodes, links) {
  const statuses = new Map(nodes.map(({ uuid, status }) => [uuid, status]))
  // A persisted merge source has at most one target.
  const targets = new Map(links.map(({ source, target }) => [source, target]))
  const tones = new Map()
  for (const [source] of targets) {
    const visited = new Set()
    let end = source
    while (targets.has(end) && !visited.has(end) && !tones.has(end)) {
      visited.add(end)
      end = targets.get(end)
    }
    const tone = tones.get(end) || statusTone(visited.has(end) ? undefined : statuses.get(end))
    tones.set(end, tone)
    for (const uuid of visited) tones.set(uuid, tone)
  }
  return tones
}

export function graphFitScale(viewportWidth, canvasWidth) {
  if (viewportWidth <= 0 || canvasWidth <= 0) return 1
  // Keep text legible when a large graph needs scrolling instead of shrinking indefinitely.
  return Math.max(0.8, Math.min(1, viewportWidth / canvasWidth))
}

export function createGraphPan(setDragging) {
  let drag
  return {
    start(event) {
      if (drag || event.pointerType === 'touch' || event.button !== 0 || event.target.closest('button, a, .graph-item-list')) return
      const viewport = event.currentTarget
      if (viewport.scrollWidth <= viewport.clientWidth && viewport.scrollHeight <= viewport.clientHeight) return
      // Scrollbar clicks target the viewport itself and should retain native behavior.
      if (event.target === viewport) {
        const rect = viewport.getBoundingClientRect()
        if (event.clientX >= rect.left + viewport.clientLeft + viewport.clientWidth ||
            event.clientY >= rect.top + viewport.clientTop + viewport.clientHeight) return
      }
      viewport.setPointerCapture(event.pointerId)
      drag = { id: event.pointerId, x: event.clientX, y: event.clientY, left: viewport.scrollLeft, top: viewport.scrollTop }
      setDragging(true)
    },
    move(event) {
      if (event.pointerId !== drag?.id) return
      event.preventDefault()
      event.currentTarget.scrollLeft = drag.left + drag.x - event.clientX
      event.currentTarget.scrollTop = drag.top + drag.y - event.clientY
    },
    end(event) {
      if (event.pointerId !== drag?.id) return
      drag = undefined
      setDragging(false)
    },
    reset() {
      drag = undefined
      setDragging(false)
    }
  }
}

export function orderParams(filters) {
  const params = {}
  for (const key of ['order', 'user', ...Object.keys(states)]) {
    const value = filters[key]
    if (Array.isArray(value) ? value.length : value?.trim()) {
      params[key] = Array.isArray(value) ? value.join(',') : value.trim()
    }
  }
  if (filters.start) params.from = dayBoundary(filters.start)
  if (filters.end) params.before = dayBoundary(filters.end, 1)
  return params
}

function dayBoundary(value, days = 0) {
  const [year, month, day] = value.split('-').map(Number)
  return new Date(year, month - 1, day + days).toISOString().replace('.000Z', 'Z')
}

export function formatAmount(amount, currency, locale) {
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(amount / 100)
  } catch {
    return `${(amount / 100).toFixed(2)} ${currency}`
  }
}

export function createLatestRequest() {
  let revision = 0
  return {
    cancel: () => { revision++ },
    async run(load, apply, fail, done) {
      const current = ++revision
      try {
        const result = await load()
        if (revision === current) apply(result)
      } catch (error) {
        if (revision === current) fail(error)
      } finally {
        if (revision === current) done()
      }
    }
  }
}

export function groupHistory(rows) {
  const groups = new Map()
  for (const row of rows) {
    const key = row.sessionUuid || ''
    if (!groups.has(key)) groups.set(key, { key, startedAt: row.sessionStartedAt, orders: [] })
    groups.get(key).orders.push(row)
  }
  return [...groups.values()]
}

export function mergeLayout(nodes, edges, expanded = new Set()) {
  const positions = new Map()
  const pending = new Map(nodes.map(node => [node.uuid, 0]))
  const levels = new Map(nodes.map(node => [node.uuid, 0]))
  const outgoing = new Map(nodes.map(node => [node.uuid, []]))
  const links = edges.filter(({ source, target }) => pending.has(source) && pending.has(target))
  const chainTones = mergeChainTones(nodes, links)
  for (const { source, target } of links) {
    outgoing.get(source).push(target)
    pending.set(target, pending.get(target) + 1)
  }
  const linked = new Set(links.flatMap(({ source, target }) => [source, target]))
  const queue = [...linked].filter(uuid => pending.get(uuid) === 0)
  for (const source of queue) {
    for (const target of outgoing.get(source)) {
      levels.set(target, Math.max(levels.get(target), levels.get(source) + 1))
      pending.set(target, pending.get(target) - 1)
      if (pending.get(target) === 0) queue.push(target)
    }
  }
  // Align a late-added source with the other inputs to its immediate merge.
  const ordered = new Set(queue)
  for (const source of queue.reverse()) {
    const targets = outgoing.get(source)
    if (targets.length && targets.every(target => ordered.has(target))) {
      levels.set(source, Math.min(...targets.map(target => levels.get(target))) - 1)
    }
  }

  const rows = new Map()
  const width = 178
  const height = 124
  const receiptHeights = new Map(nodes.map(({ uuid, itemCount = 0 }) => [
    uuid, itemCount <= 1 ? 96 : expanded.has(uuid) ? Math.min(304, 108 + itemCount * 22) : height
  ]))
  const columnStep = 202
  const rowGap = 56
  for (const node of nodes) {
    if (!linked.has(node.uuid)) continue
    const level = levels.get(node.uuid)
    if (!rows.has(level)) rows.set(level, [])
    rows.get(level).push(node.uuid)
  }
  const widest = Math.max(1, ...[...rows.values()].map(row => row.length))
  const bodyWidth = (widest - 1) * columnStep + width
  let nextY = 20
  for (const [level, row] of [...rows.entries()].sort(([a], [b]) => a - b)) {
    const inset = (widest - row.length) * columnStep / 2
    const rowHeight = Math.max(...row.map(uuid => receiptHeights.get(uuid)))
    row.forEach((uuid, index) => positions.set(uuid, {
      x: 20 + inset + index * columnStep, y: nextY, height: receiptHeights.get(uuid)
    }))
    nextY += rowHeight + rowGap
  }
  // Keep unexpected long-span or cyclic links outside intermediate rows.
  let lanes = 0
  const paths = links.map(({ source, target }) => {
    const a = positions.get(source)
    const b = positions.get(target)
    const x = a.x + width / 2
    const y = a.y + a.height
    const endX = b.x + width / 2
    const endY = b.y - 4
    if (levels.get(target) - levels.get(source) !== 1) {
      const lane = bodyWidth + 26 + 12 * ++lanes
      return `M${x} ${y} V${y + 10} H${lane} V${endY - 8} H${endX} V${endY}`
    }
    const mid = (y + endY) / 2
    return `M${x} ${y} V${mid} H${endX} V${endY}`
  })
  return {
    positions, paths, width, chainTones,
    pathTones: links.map(({ source }) => chainTones.get(source)),
    standalone: nodes.filter(node => !linked.has(node.uuid)),
    canvasWidth: bodyWidth + 40 + 12 * lanes,
    canvasHeight: rows.size ? nextY - rowGap + 20 : height + 40
  }
}
