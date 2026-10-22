const INLINE_SPECIALS = /(`[^`\n]+`|!\[[^\]]+\]\([^\s)]+\)|\[[^\]]+\]\([^\s)]+(?:\s+"[^"]*")?\)|\*\*|__|~~|\*(?!\s)|_(?!\s))/g

function safeHref(value) {
  if (/^(?:\/(?!\/)|\.\.?\/|#)/.test(value)) return value
  try {
    const url = new URL(value)
    return ['http:', 'https:', 'mailto:'].includes(url.protocol) ? url.href : null
  } catch {
    return null
  }
}

function findClosing(text, delimiter, start) {
  const index = text.indexOf(delimiter, start)
  return index > start && !/\s/.test(text[index - 1]) ? index : -1
}

export function parseInlineMarkdown(value) {
  const text = String(value || '')
  const nodes = []
  let cursor = 0
  for (const match of text.matchAll(INLINE_SPECIALS)) {
    const token = match[0]
    const index = match.index
    if (index < cursor) continue
    if (index > cursor) nodes.push({ type: 'text', value: text.slice(cursor, index) })

    if (token.startsWith('`')) {
      nodes.push({ type: 'code', value: token.slice(1, -1) })
      cursor = index + token.length
      continue
    }
    if (token.startsWith('![')) {
      const image = token.match(/^!\[([^\]]*)\]\([^)]*\)$/)
      nodes.push({ type: 'text', value: image?.[1] || '' })
      cursor = index + token.length
      continue
    }
    if (token.startsWith('[')) {
      const parsed = token.match(/^\[([^\]]+)\]\(([^\s)]+)(?:\s+"[^"]*")?\)$/)
      const href = parsed && safeHref(parsed[2])
      nodes.push(href
        ? { type: 'link', href, children: parseInlineMarkdown(parsed[1]) }
        : { type: 'text', value: parsed?.[1] || token })
      cursor = index + token.length
      continue
    }

    const delimiter = token
    const closing = findClosing(text, delimiter, index + delimiter.length)
    if (closing < 0) {
      nodes.push({ type: 'text', value: delimiter })
      cursor = index + delimiter.length
      continue
    }
    const inner = text.slice(index + delimiter.length, closing)
    const type = delimiter === '**' || delimiter === '__'
      ? 'strong'
      : delimiter === '~~' ? 'del' : 'em'
    nodes.push({ type, children: parseInlineMarkdown(inner) })
    cursor = closing + delimiter.length
  }
  if (cursor < text.length) nodes.push({ type: 'text', value: text.slice(cursor) })
  return nodes
}

const isFence = line => /^\s*```/.test(line)
const headingMatch = line => line.match(/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/)
const listMatch = line => line.match(/^\s{0,3}([-+*]|\d+[.)])\s+(.+)$/)
const isRule = line => /^\s{0,3}(?:(?:\*\s*){3,}|(?:-\s*){3,}|(?:_\s*){3,})$/.test(line)

function splitTableRow(line) {
  const source = line.trim().replace(/^\|/, '').replace(/\|$/, '')
  const cells = []
  let cell = ''
  let escaped = false
  for (const character of source) {
    if (character === '|' && !escaped) {
      cells.push(cell.trim())
      cell = ''
    } else {
      cell += character
    }
    if (character === '\\' && !escaped) escaped = true
    else escaped = false
  }
  cells.push(cell.trim())
  return cells
}

function tableAlignments(line, columnCount) {
  const cells = splitTableRow(line)
  if (cells.length !== columnCount || cells.some(cell => !/^:?-{3,}:?$/.test(cell))) return null
  return cells.map(cell => cell.startsWith(':') && cell.endsWith(':')
    ? 'center'
    : cell.endsWith(':') ? 'right' : cell.startsWith(':') ? 'left' : null)
}

function isTableStart(lines, index) {
  if (index + 1 >= lines.length) return false
  const headers = splitTableRow(lines[index])
  return headers.length > 1 && tableAlignments(lines[index + 1], headers.length) !== null
}

function startsBlock(lines, index) {
  const line = lines[index] || ''
  return isFence(line) || headingMatch(line) || listMatch(line) || isRule(line) || /^\s*>/.test(line) || isTableStart(lines, index)
}

export function parseMarkdown(value) {
  const lines = String(value || '').replace(/\r\n?/g, '\n').split('\n')
  const blocks = []
  let index = 0

  while (index < lines.length) {
    const line = lines[index]
    if (!line.trim()) { index += 1; continue }

    if (isFence(line)) {
      const language = line.trim().slice(3).trim().split(/\s+/)[0] || ''
      index += 1
      const body = []
      while (index < lines.length && !isFence(lines[index])) body.push(lines[index++])
      if (index < lines.length) index += 1
      blocks.push({ type: 'codeBlock', language, value: body.join('\n') })
      continue
    }

    const heading = headingMatch(line)
    if (heading) {
      blocks.push({ type: 'heading', level: heading[1].length, children: parseInlineMarkdown(heading[2]) })
      index += 1
      continue
    }

    if (isRule(line)) {
      blocks.push({ type: 'rule' })
      index += 1
      continue
    }

    if (isTableStart(lines, index)) {
      const headers = splitTableRow(line).map(parseInlineMarkdown)
      const alignments = tableAlignments(lines[index + 1], headers.length)
      index += 2
      const rows = []
      while (index < lines.length && lines[index].trim() && lines[index].includes('|')) {
        const cells = splitTableRow(lines[index++])
        rows.push(Array.from({ length: headers.length }, (_, cellIndex) => parseInlineMarkdown(cells[cellIndex] || '')))
      }
      blocks.push({ type: 'table', headers, alignments, rows })
      continue
    }

    if (/^\s*>/.test(line)) {
      const quote = []
      while (index < lines.length && /^\s*>/.test(lines[index])) quote.push(lines[index++].replace(/^\s*> ?/, ''))
      blocks.push({ type: 'quote', children: parseMarkdown(quote.join('\n')) })
      continue
    }

    const firstItem = listMatch(line)
    if (firstItem) {
      const ordered = /^\d/.test(firstItem[1])
      const items = []
      let start = ordered ? Number.parseInt(firstItem[1], 10) : 1
      while (index < lines.length) {
        const item = listMatch(lines[index])
        if (!item || /^\d/.test(item[1]) !== ordered) break
        if (items.length === 0 && ordered) start = Number.parseInt(item[1], 10)
        items.push(parseInlineMarkdown(item[2]))
        index += 1
      }
      blocks.push({ type: 'list', ordered, start, items })
      continue
    }

    const paragraph = [line.trim()]
    index += 1
    while (index < lines.length && lines[index].trim() && !startsBlock(lines, index)) paragraph.push(lines[index++].trim())
    blocks.push({ type: 'paragraph', children: parseInlineMarkdown(paragraph.join('\n')) })
  }

  return blocks
}

function renderInline(document, nodes, parent) {
  for (const node of nodes) {
    if (node.type === 'text') {
      parent.append(document.createTextNode(node.value))
      continue
    }
    if (node.type === 'link') {
      const link = document.createElement('a')
      link.href = node.href
      link.target = '_blank'
      link.rel = 'noopener noreferrer'
      renderInline(document, node.children, link)
      parent.append(link)
      continue
    }
    const tag = { strong: 'strong', em: 'em', del: 'del', code: 'code' }[node.type]
    if (!tag) continue
    const child = document.createElement(tag)
    if (node.type === 'code') child.textContent = node.value
    else renderInline(document, node.children, child)
    parent.append(child)
  }
}

function renderBlocks(document, blocks, parent) {
  for (const block of blocks) {
    if (block.type === 'rule') {
      parent.append(document.createElement('hr'))
      continue
    }
    if (block.type === 'codeBlock') {
      const pre = document.createElement('pre')
      const code = document.createElement('code')
      if (/^[A-Za-z0-9_+-]{1,40}$/.test(block.language)) code.dataset.language = block.language
      code.textContent = block.value
      pre.append(code)
      parent.append(pre)
      continue
    }
    if (block.type === 'quote') {
      const quote = document.createElement('blockquote')
      renderBlocks(document, block.children, quote)
      parent.append(quote)
      continue
    }
    if (block.type === 'list') {
      const list = document.createElement(block.ordered ? 'ol' : 'ul')
      if (block.ordered && block.start > 1) list.start = block.start
      for (const item of block.items) {
        const li = document.createElement('li')
        renderInline(document, item, li)
        list.append(li)
      }
      parent.append(list)
      continue
    }
    if (block.type === 'table') {
      const table = document.createElement('table')
      const head = document.createElement('thead')
      const headerRow = document.createElement('tr')
      for (const [index, content] of block.headers.entries()) {
        const cell = document.createElement('th')
        if (block.alignments[index]) cell.style.textAlign = block.alignments[index]
        renderInline(document, content, cell)
        headerRow.append(cell)
      }
      head.append(headerRow)
      table.append(head)
      if (block.rows.length) {
        const body = document.createElement('tbody')
        for (const row of block.rows) {
          const tableRow = document.createElement('tr')
          row.forEach((content, index) => {
            const cell = document.createElement('td')
            if (block.alignments[index]) cell.style.textAlign = block.alignments[index]
            renderInline(document, content, cell)
            tableRow.append(cell)
          })
          body.append(tableRow)
        }
        table.append(body)
      }
      parent.append(table)
      continue
    }
    const tag = block.type === 'heading' ? `h${block.level}` : 'p'
    const element = document.createElement(tag)
    renderInline(document, block.children, element)
    parent.append(element)
  }
}

export function renderMarkdown(document, value) {
  const container = document.createElement('div')
  container.className = 'markdown'
  renderBlocks(document, parseMarkdown(value), container)
  return container
}
