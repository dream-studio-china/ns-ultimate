import { printerFontMetrics, textCells } from './printer-paper.mjs'

export const cloneDefinition = value => JSON.parse(JSON.stringify(value))
export const containerTypes = ['style', 'bold', 'each', 'repeat', 'if']
export const labelTypes = ['labelText', 'qr', 'barcode']
export const knownTypes = [...containerTypes, ...labelTypes, 'text', 'string', 'field', 'line', 'separator', 'columns', 'receiptCommand', 'page']
export const definitionErrorKeys = ['Template JSON is too large', 'Template requires kind and nodes', 'Template nesting is too deep', 'Template has too many nodes', 'Each template node needs a type', 'Node children must be an array']

export function parseDefinition(text) {
  if (text.length > 100000) throw new Error('Template JSON is too large')
  const value = JSON.parse(text)
  if (!value || Array.isArray(value) || typeof value !== 'object' || !['receipt', 'label'].includes(value.kind) || !Array.isArray(value.nodes)) {
    throw new Error('Template requires kind and nodes')
  }
  let count = 0
  const visit = (nodes, depth) => {
    if (depth > 20) throw new Error('Template nesting is too deep')
    for (const node of nodes) {
      if (++count > 1000) throw new Error('Template has too many nodes')
      if (!node || Array.isArray(node) || typeof node !== 'object' || typeof node.type !== 'string') throw new Error('Each template node needs a type')
      if ('children' in node) {
        if (!Array.isArray(node.children)) throw new Error('Node children must be an array')
        visit(node.children, depth + 1)
      }
    }
  }
  visit(value.nodes, 0)
  return value
}

// Keep raw drafts separate from the last usable definition. No designer metadata
// is persisted, and snapshots include formatting and invalid intermediate input.
export function createDesignerState(initial) {
  const raw = typeof initial === 'string' ? initial : JSON.stringify(initial ?? {}, null, 2)
  let definition = null
  let error = ''
  try { definition = parseDefinition(raw) } catch (cause) { error = cause.message }
  return { raw, definition, error, past: [], future: [] }
}

export function setDesignerDraft(state, raw) {
  if (raw === state.raw) return state
  let definition = state.definition
  let error = ''
  try { definition = parseDefinition(raw) } catch (cause) { error = cause.message }
  const snapshot = ({ raw, definition, error }) => ({ raw, definition, error })
  return { raw, definition, error, past: [...state.past.slice(-99), snapshot(state)], future: [] }
}

export function designerHistory(state, direction) {
  const from = direction === 'undo' ? state.past : state.future
  if (!from.length) return state
  const current = { raw: state.raw, definition: state.definition, error: state.error }
  const next = from.at(-1)
  return direction === 'undo'
    ? { ...next, past: state.past.slice(0, -1), future: [...state.future, current] }
    : { ...next, past: [...state.past, current], future: state.future.slice(0, -1) }
}

export function nodeAt(definition, path) {
  if (!path.length) return undefined
  return path.reduce((parent, index) => parent?.children?.[index], { children: definition?.nodes })
}

export function changeNode(definition, path, change) {
  const next = cloneDefinition(definition)
  const node = nodeAt(next, path)
  if (!node) return next
  change(node)
  return next
}

export function replaceNode(definition, path, node) {
  const next = cloneDefinition(definition)
  if (!path.length || !nodeAt(next, path)) return next
  const nodes = path.length === 1 ? next.nodes : nodeAt(next, path.slice(0, -1)).children
  nodes[path.at(-1)] = cloneDefinition(node)
  return next
}

export function moveNode(definition, source, target) {
  // Move before a sibling or across containers; never move into a descendant.
  if (!source.length || !target.length || source.every((part, index) => target[index] === part)) return definition
  const next = cloneDefinition(definition)
  const siblings = path => path.length === 1 ? next.nodes : nodeAt(next, path.slice(0, -1))?.children
  const from = siblings(source)
  const to = siblings(target)
  if (!from || !to || !from[source.at(-1)]) return definition
  let index = target.at(-1)
  if (from === to && source.at(-1) < index) index--
  to.splice(index, 0, from.splice(source.at(-1), 1)[0])
  return next
}

export function newTemplateNode(type, kind) {
  const defaults = {
    labelText: { x: 8, y: 8, font: 9, w: 1, h: 1, value: 'Text' },
    style: { align: 'left', width: 1, height: 1, bold: false, children: [{ type: 'text', value: 'Text' }] },
    field: { path: 'order.number' }, text: { value: 'Text' }, string: { value: '{order.number}' },
    qr: { template: '{order.uuid}', size: kind === 'label' ? 2 : 6, ...(kind === 'label' ? { x: 8, y: 64 } : { align: 'center' }) },
    barcode: { template: '{order.number}', x: 8, y: 64, height: 64, format: 'BC128' },
    each: { path: 'items', children: [kind === 'label' ? { type: 'labelText', x: 8, y: 8, font: 9, path: 'item.name' } : { type: 'style', children: [{ type: 'string', value: '{item.name} x{item.quantity}' }] }] },
    repeat: { countPath: 'item.quantity', children: [] }, if: { path: 'order.number', children: [] },
    bold: { children: [{ type: 'text', value: 'Text' }] },
    columns: { columns: [{ value: 'Item', width: 24, align: 'left' }, { path: 'order.amountDue', width: 12, align: 'right' }] },
    receiptCommand: { value: 'BR' }
  }
  return { type, ...cloneDefinition(defaults[type] || {}) }
}

const lookup = (scope, path) => String(path ?? '').split('.').reduce((value, key) => value && Object.hasOwn(value, key) ? value[key] : undefined, scope)
export function sampleNodeText(node, scope = {}) {
  const literal = value => value == null || typeof value === 'object' ? '' : String(value)
  const template = node.template ?? (node.type === 'string' ? node.value : undefined)
  if (typeof template === 'string') {
    return template.replace(/\{\{|\}\}|\{([^{}]+)\}/g, (match, expression) => {
      if (!expression) return match === '{{' ? '{' : '}'
      const [path, ...filters] = expression.split('|')
      let value = literal(lookup(scope, path.trim()))
      for (const filter of filters) {
        const [name, argument] = filter.trim().split(':')
        if (name === 'upper') value = value.toUpperCase()
        if (name === 'lower') value = value.toLowerCase()
        if (name === 'trim') value = value.trim()
        if (name === 'default' && !value) value = argument ?? ''
        if (name === 'truncate') value = Array.from(value).slice(0, Number(argument)).join('')
        if (name === 'money' && /^-?\d+$/.test(value)) {
          const digits = Math.max(0, Math.min(6, Math.round(Number(argument ?? 2) || 0)))
          value = (Number(value) / 100).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits })
        }
      }
      return value
    })
  }
  return Object.hasOwn(node, 'value') ? literal(node.value) : literal(lookup(scope, node.path))
}

export function labelEntries(definition, sample) {
  const result = []
  const visit = (nodes, path, scope) => nodes.forEach((node, index) => {
    const current = [...path, index]
    if (labelTypes.includes(node.type)) result.push({ node, path: current, text: sampleNodeText(node, scope) })
    if (Array.isArray(node.children)) {
      // Design a representative iteration, not the expanded multi-page output.
      const item = node.type === 'each' ? lookup(scope, node.path)?.[0] : undefined
      visit(node.children, current, item ? { ...scope, item, index: 0 } : scope)
    }
  })
  visit(definition?.nodes || [], [], sample)
  return result
}

export function labelBox(node, text, definition) {
  const number = (value, fallback) => Number.isFinite(Number(value)) ? Number(value) : fallback
  const w = number(node.w, 1), h = number(node.h, 1)
  const metrics = printerFontMetrics(node.font || 9)
  const width = node.type === 'labelText' ? Math.max(1, textCells(text)) * metrics.cellMm * 8 * w
    : node.type === 'qr' ? 25 * number(node.size, 2) : Math.max(64, text.length * 12)
  const height = node.type === 'labelText' ? metrics.heightMm * 8 * h : node.type === 'qr' ? width : number(node.height, 64)
  let x = number(node.x, 0)
  if (node.type === 'labelText' && (node.font ?? 9) === 9 && !(node.r ?? 0)) {
    const cells = node.widthMode === 'legacy' ? Array.from(text.trim()).reduce((sum, c) => sum + (c.codePointAt(0) > 127 ? 2 : 1), 0) : textCells(text)
    const measured = cells * 12 * w
    if (node.align === 'center') x = Math.max(0, Math.floor(((definition.widthMm || 40) * 8 - measured) / 2))
    if (node.align === 'right') x = Math.max(0, (definition.widthMm || 40) * 8 - measured)
  }
  const y = number(node.y, 0)
  const rotation = node.r ?? 0
  const corners = [[0, 0], [width, 0], [0, height], [width, height]].map(([a, b]) => rotation === 90 ? [-b, a] : rotation === 180 ? [-a, -b] : rotation === 270 ? [b, -a] : [a, b])
  const minX = Math.min(...corners.map(c => c[0])), minY = Math.min(...corners.map(c => c[1]))
  const maxX = Math.max(...corners.map(c => c[0])), maxY = Math.max(...corners.map(c => c[1]))
  return { x, y, width, height, minX, minY, maxX, maxY, overflow: x + minX < 0 || y + minY < 0 || x + maxX > (definition.widthMm || 40) * 8 || y + maxY > (definition.heightMm || 30) * 8 }
}

export function transformLabel(node, box, dx, dy, operation, snap = true) {
  const quantize = value => Math.max(0, Math.min(2048, Math.round(value / (snap ? 8 : 1)) * (snap ? 8 : 1)))
  if (operation === 'move') {
    const next = { ...node, x: quantize(box.x + dx), y: quantize(box.y + dy) }
    delete next.align // An explicit drag replaces automatic horizontal alignment.
    return next
  }
  const clamp = (value, max) => Math.min(max, Math.max(1, Math.round(value)))
  if (node.type === 'labelText') return { ...node, w: clamp((node.w || 1) * (box.width + dx) / box.width, 10), h: clamp((node.h || 1) * (box.height + dy) / box.height, 10) }
  if (node.type === 'qr') return { ...node, size: clamp((node.size || 2) * (box.width + Math.max(dx, dy)) / box.width, 10) }
  return { ...node, height: clamp((node.height || 64) + dy, 255) }
}
