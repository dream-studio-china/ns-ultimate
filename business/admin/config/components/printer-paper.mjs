// Tokenize before decoding: escaped printer commands must remain literal text.
const decode = value => value.replace(/&lt;?/g, '<').replace(/&gt;?/g, '>')
const alignments = { L: 'left', C: 'center', R: 'right' }
const scales = { B: [2, 2], WB: [2, 1], HB: [1, 2], B2: [3, 3], WB2: [3, 1], HB2: [1, 3], W: [2, 1], H: [1, 2], N: [1, 1], CB: [2, 2] }

function attributes(source) {
  const result = {}
  for (const [, key, quoted, bare] of source.matchAll(/(?:^|\s)([a-z]+)=(?:"([^"<>]*)"|([^\s"'<>`=]+))(?=\s|$)/g)) {
    if (!Object.hasOwn(result, key)) result[key] = quoted ?? bare
  }
  return result
}

function integer(value, min, max, fallback) {
  return /^\d+$/.test(value ?? '') && Number.isSafeInteger(Number(value)) && Number(value) >= min && Number(value) <= max ? Number(value) : fallback
}

export function paperElements(content) {
  const elements = []
  let state = { bold: false, align: 'left', w: 1, h: 1 }
  const stack = []
  const tokens = String(content).split(/(<TEXT\b[^>]*>[\s\S]*?<\/TEXT>|<BC128\b[^>]*>[\s\S]*?<\/BC128>|<BC39\b[^>]*>[\s\S]*?<\/BC39>|<QRCODE\b[^>]*>[\s\S]*?<\/QRCODE>|<QRC\b[^>]*>[\s\S]*?<\/QRC>|<BR\s*\/?>|<\/?(?:BOLD|CB|WB2?|HB2?|B2?|C|L|R|W|H|N)>|\r\n|\r|\n)/g)
  // Legacy receipts use unpaired commands as persistent setters. Only matched
  // wrappers save state; a later closing tag must not pop an earlier setter.
  const pending = new Map()
  const paired = new Map()
  for (const [index, token] of tokens.entries()) {
    const command = token.match(/^<(\/?)(BOLD|CB|WB2?|HB2?|B2?|C|L|R|W|H|N)>$/)
    if (!command) continue
    const [, close, tag] = command
    if (!pending.has(tag)) pending.set(tag, [])
    if (!close) pending.get(tag).push(index)
    else if (pending.get(tag).length) paired.set(pending.get(tag).pop(), index)
  }
  for (const [tokenIndex, token] of tokens.entries()) {
    if (!token) continue
    const command = token.match(/^<(\/?)(BOLD|CB|WB2?|HB2?|B2?|C|L|R|W|H|N)>$/)
    if (command) {
      const [, close, tag] = command
      if (close) {
        const index = stack.findLastIndex(entry => entry.end === tokenIndex)
        if (index !== -1) { state = stack[index].previous; stack.splice(index) }
      } else {
        if (paired.has(tokenIndex)) stack.push({ end: paired.get(tokenIndex), previous: state })
        state = { ...state }
        if (tag === 'BOLD') state.bold = true
        if (alignments[tag]) state.align = alignments[tag]
        if (scales[tag]) [state.w, state.h] = scales[tag]
        if (tag === 'CB') state.align = 'center'
      }
      continue
    }
    if (/^(?:<BR\s*\/?>|\r\n|\r|\n)$/.test(token)) { elements.push({ type: 'line', ...state }); continue }
    const match = token.match(/^<(TEXT|BC128|BC39|QRC|QRCODE)\b([^>]*)>([\s\S]*)<\/\1>$/)
    if (!match) { elements.push({ type: 'text', value: decode(token), ...state }); continue }
    const [, format, source, value] = match
    const attrs = attributes(source)
    const position = { x: integer(attrs.x, 0, 2048, 0), y: integer(attrs.y, 0, 2048, 0) }
    if (format === 'TEXT') {
      elements.push({ type: 'labelText', format, value: decode(value), ...position, font: integer(attrs.font, 1, 9, 9), w: integer(attrs.w, 1, 10, 1), h: integer(attrs.h, 1, 10, 1), r: [0, 90, 180, 270].includes(integer(attrs.r, 0, 270, -1)) ? Number(attrs.r) : 0 })
    } else if (format === 'QRC' || format === 'QRCODE') {
      elements.push({ type: 'qr', format, value: decode(value), ...position, s: integer(attrs.s, 1, format === 'QRC' ? 10 : 16, format === 'QRC' ? 2 : 6), e: /^[LMQH]$/.test(attrs.e ?? '') ? attrs.e : 'L', align: ['left', 'center', 'right'].includes(attrs.l) ? attrs.l : state.align })
    } else {
      elements.push({ type: 'code', format, value: decode(value), ...position, h: integer(attrs.h, 1, 255, 64) })
    }
  }
  return elements
}

// CJK/full-width characters occupy two half-width display cells.
export function textCells(value) {
  return Array.from(value, character => {
    if (/\p{Mark}/u.test(character)) return 0
    const code = character.codePointAt(0)
    return code >= 0x1100 && (code <= 0x115f || code === 0x2329 || code === 0x232a || (code >= 0x2e80 && code <= 0xa4cf) || (code >= 0xac00 && code <= 0xd7a3) || (code >= 0xf900 && code <= 0xfaff) || (code >= 0xfe10 && code <= 0xfe6f) || (code >= 0xff01 && code <= 0xff60) || (code >= 0xffe0 && code <= 0xffe6) || code >= 0x1f300) ? 2 : 1
  }).reduce((total, cells) => total + cells, 0)
}

export function receiptRows(elements, columns = 32) {
  const rows = []
  let row = { type: 'row', align: 'left', h: 1, cells: 0, items: [] }
  const flush = () => { rows.push(row); row = { type: 'row', align: row.align, h: 1, cells: 0, items: [] } }
  for (const element of elements) {
    if (element.type === 'line') {
      if (!row.items.length) { row.align = element.align; row.h = element.h }
      flush()
    } else if (element.type === 'text') {
      for (const character of element.value) {
        const cells = textCells(character) * element.w
        // Keep padding within the current line, but never wrap trailing spaces
        // into extra blank rows before the template's explicit newline.
        if (/^[\t ]$/.test(character) && row.cells + cells > columns) continue
        if (row.cells && row.cells + cells > columns) flush()
        row.align = element.align
        row.h = Math.max(row.h, element.h)
        row.cells += cells
        const last = row.items.at(-1)
        if (last && last.bold === element.bold && last.w === element.w && last.h === element.h) last.value += character
        else row.items.push({ ...element, value: character })
      }
    } else {
      if (row.items.length) flush()
      rows.push(element)
    }
  }
  if (row.items.length) flush()
  return rows
}

// The caller supplies the existing qrcode package, keeping parser tests dependency-free.
export function qrGeometry(element, create) {
  try {
    if (!element.value || Array.from(element.value).length > 4096) return null
    const { modules } = create(element.value, { errorCorrectionLevel: element.e })
    const margin = 4
    let path = ''
    for (let y = 0; y < modules.size; y++) {
      for (let x = 0; x < modules.size; x++) {
        if (modules.get(y, x)) path += `M${x + margin} ${y + margin}h1v1h-1z`
      }
    }
    return { path, size: modules.size + margin * 2, widthMm: (modules.size + margin * 2) * element.s / 8 }
  } catch {
    return null
  }
}

export function paperSize(document) {
  const dimension = (value, fallback) => Number.isFinite(Number(value)) && Number(value) >= 10 && Number(value) <= 200 ? Number(value) : fallback
  return {
    width: dimension(document.widthMm, document.kind === 'label' ? 40 : 80),
    height: dimension(document.heightMm, 30)
  }
}

// Native label font cells from Xpyun's TEXT command (dots at 8 dots/mm).
export function printerFontMetrics(font = 9) {
  const [width, height] = ({ 1: [8, 12], 2: [12, 20], 3: [16, 24], 4: [24, 32], 5: [32, 48], 6: [14, 19], 7: [21, 27], 8: [14, 25], 9: [12, 24] })[font] || [12, 24]
  return { cellMm: width / 8, heightMm: height / 8 }
}

export function receiptMetrics(widthMm) {
  const cellMm = 1.5
  const columns = Math.max(1, Math.min(widthMm > 58 ? 48 : 32, Math.floor(widthMm / cellMm)))
  return {
    columns, cellMm, fontMm: 3, gapMm: 0.75,
    // Paper width changes margins and wrapping, never the native character size.
    marginMm: Math.max(0, (widthMm - columns * cellMm) / 2)
  }
}
