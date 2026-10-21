// Parse only the renderer's allowlisted commands; never insert command text as HTML.
export function paperElements(content) {
  const elements = []
  let bold = false
  const decode = value => value.replace(/&lt;?/g, '<').replace(/&gt;?/g, '>')
  const tokens = String(content).split(/(<TEXT\b[^>]*>[\s\S]*?<\/TEXT>|<BC(?:128|39)\b[^>]*>[\s\S]*?<\/BC(?:128|39)>|<QRC\b[^>]*>[\s\S]*?<\/QRC>|<BR>|<\/?BOLD>)/g)
  for (const token of tokens) {
    if (!token) continue
    if (token === '<BOLD>') { bold = true; continue }
    if (token === '</BOLD>') { bold = false; continue }
    if (token === '<BR>') { elements.push({ type: 'line' }); continue }
    const match = token.match(/^<(TEXT|BC128|BC39|QRC)\b([^>]*)>([\s\S]*)<\/\1>$/)
    if (!match) { elements.push({ type: 'text', value: decode(token), bold }); continue }
    const attributes = Object.fromEntries([...match[2].matchAll(/(x|y|h|font)="(\d+)"/g)].map(([, key, value]) => [key, Number(value)]))
    elements.push({ type: match[1] === 'TEXT' ? 'labelText' : 'code', format: match[1], value: decode(match[3]), ...attributes })
  }
  return elements
}

export function paperSize(document) {
  const dimension = (value, fallback) => Number.isFinite(Number(value)) && Number(value) >= 10 && Number(value) <= 200 ? Number(value) : fallback
  return {
    width: dimension(document.widthMm, document.kind === 'label' ? 40 : 80),
    height: dimension(document.heightMm, 30)
  }
}
