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

export function mergeLayout(nodes, edges) {
  const positions = new Map()
  const pending = new Map(nodes.map(node => [node.uuid, 0]))
  const columns = new Map(nodes.map(node => [node.uuid, 0]))
  const outgoing = new Map(nodes.map(node => [node.uuid, []]))
  const links = edges.filter(({ source, target }) => pending.has(source) && pending.has(target))
  for (const { source, target } of links) {
    outgoing.get(source).push(target)
    pending.set(target, pending.get(target) + 1)
  }
  const queue = nodes.map(node => node.uuid).filter(uuid => pending.get(uuid) === 0)
  for (const source of queue) {
    for (const target of outgoing.get(source)) {
      columns.set(target, Math.max(columns.get(target), columns.get(source) + 1))
      pending.set(target, pending.get(target) - 1)
      if (pending.get(target) === 0) queue.push(target)
    }
  }
  const counts = new Map()
  const width = 178
  for (const node of nodes) {
    const col = columns.get(node.uuid)
    const row = counts.get(col) || 0
    positions.set(node.uuid, { x: 20 + col * 256, y: 20 + row * 118 })
    counts.set(col, row + 1)
  }
  // Draw long-span or corrupt back-edges in separate lanes below the cards.
  const bottom = 22 + Math.max(1, ...counts.values()) * 118
  let lanes = 0
  const paths = links.map(({ source, target }) => {
    const a = positions.get(source)
    const b = positions.get(target)
    const x = a.x + width
    const y = a.y + 47
    const endX = b.x - 5
    const endY = b.y + 47
    if (b.x - a.x !== 256) {
      const lane = bottom + 12 * ++lanes
      return `M${x} ${y} H${x + 10} V${lane} H${endX - 10} V${endY} H${endX}`
    }
    const mid = (x + endX) / 2
    return `M${x} ${y} H${mid} V${endY} H${endX}`
  })
  return {
    positions, paths, width,
    canvasWidth: Math.max(0, ...columns.values()) * 256 + width + 40,
    canvasHeight: bottom + 12 * lanes + (lanes ? 12 : 0)
  }
}
