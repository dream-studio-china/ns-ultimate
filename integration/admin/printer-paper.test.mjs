import assert from 'node:assert/strict'
import test from 'node:test'
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { paperElements, paperSize, printerFontMetrics, qrGeometry, receiptMetrics, receiptRows, textCells } from '../../business/admin/config/components/printer-paper.mjs'

const normal = { bold: false, align: 'left', w: 1, h: 1 }

test('receipt preview preserves text, bold and line breaks without interpreting HTML', () => {
  const elements = paperElements('Order &lt1&gt<BR><BOLD>Tea x 2</BOLD><BR>&ltimg src=x onerror=alert(1)&gt')
  assert.deepEqual(elements.slice(0, 4), [
    { type: 'text', value: 'Order <1>', ...normal }, { type: 'line', ...normal },
    { type: 'text', value: 'Tea x 2', ...normal, bold: true }, { type: 'line', ...normal }
  ])
  assert.equal(elements[4].type, 'text')
  assert.equal(elements[4].value, '<img src=x onerror=alert(1)>')
})

test('label preview preserves coordinates and barcode sizes', () => {
  assert.deepEqual(paperElements('<TEXT x="8" y="40" font="9">Demo tea</TEXT><BC128 x="16" y="80" h="64">ABC</BC128><QRC x="8" y="160" s="2" e="L" c="1">demo</QRC>'), [
    { type: 'labelText', format: 'TEXT', value: 'Demo tea', x: 8, y: 40, font: 9, w: 1, h: 1, r: 0 },
    { type: 'code', format: 'BC128', value: 'ABC', x: 16, y: 80, h: 64 },
    { type: 'qr', format: 'QRC', value: 'demo', x: 8, y: 160, s: 2, e: 'L', align: 'left' }
  ])
})

test('paired receipt commands inherit nested state and restore each outer wrapper', () => {
  const elements = paperElements('<C><B>big<BOLD>strong</BOLD><N>normal<WB>wide</WB></N>big again<BR></B><HB>tall</HB><BR></C>left')
  const texts = elements.filter(item => item.type === 'text')
  assert.deepEqual(texts.map(({ value, bold, align, w, h }) => [value, bold, align, w, h]), [
    ['big', false, 'center', 2, 2], ['strong', true, 'center', 2, 2],
    ['normal', false, 'center', 1, 1], ['wide', false, 'center', 2, 1],
    ['big again', false, 'center', 2, 2], ['tall', false, 'center', 1, 2],
    ['left', false, 'left', 1, 1]
  ])
  assert.equal(elements.find(item => item.type === 'line').align, 'center')
  assert.deepEqual(paperElements('<BOLD>a<BOLD>b</BOLD>c</BOLD>d').map(item => item.bold), [true, true, true, false])
})

test('legacy CB, W and H and independent size and emphasis commands are supported', () => {
  const elements = paperElements('<CB>center</CB><R><W>wide</W><H>high</H></R><BOLD><B>large</B>bold</BOLD>normal')
  assert.deepEqual(elements.map(({ align, w, h, bold }) => [align, w, h, bold]), [
    ['center', 2, 2, false], ['right', 2, 1, false], ['right', 1, 2, false],
    ['left', 2, 2, true], ['left', 1, 1, true], ['left', 1, 1, false]
  ])
})

test('vendor second-enlargement tags use triple cells without adding bold or multiplying nested sizes', () => {
  const elements = paperElements('<HB2>high</HB2><WB2>wide</WB2><B2>large<B>double</B>large again</B2>normal')
  assert.deepEqual(elements.map(({ value, w, h, bold }) => [value, w, h, bold]), [
    ['high', 1, 3, false], ['wide', 3, 1, false], ['large', 3, 3, false],
    ['double', 2, 2, false], ['large again', 3, 3, false], ['normal', 1, 1, false]
  ])
  assert.equal(paperElements('&ltB2&gtliteral&lt/B2&gt')[0].value, '<B2>literal</B2>')
})

test('mixed native and enlarged text shares rows until physical cells exceed the printable width', () => {
  const rows = receiptRows(paperElements(`B2: <B2>${'\u8336'.repeat(6)}</B2><BR>`), 32)
  assert.deepEqual(rows.map(row => row.cells), [28, 12])
  assert.deepEqual(rows.map(row => row.h), [3, 3])
  assert.equal(rows[0].items[0].value, 'B2: ')
  assert.equal(rows[0].items[0].h, 1)
  assert.equal(rows[1].items[0].value, '\u8336'.repeat(2))
})

test('newlines and BR retain alignment and empty rows, including BR inside closing alignment', () => {
  const rows = receiptRows(paperElements('<C>center<BR></C><R>right\r\n\n</R>left\rfinal'))
  assert.deepEqual(rows.map(row => [row.align, row.items.map(item => item.value).join('')]), [
    ['center', 'center'], ['right', 'right'], ['right', ''], ['left', 'left'], ['left', 'final']
  ])
})

test('unpaired legacy setters persist while paired wrappers restore the current enclosing state', () => {
  const elements = paperElements('<CB>Heading\n<L><N>body<HB>table</HB>normal<R>right<C>center<L>left\n<CB><B>product</B>heading size')
  assert.deepEqual(elements.filter(item => item.type === 'text').map(({ value, align, w, h, bold }) => [value, align, w, h, bold]), [
    ['Heading', 'center', 2, 2, false], ['body', 'left', 1, 1, false],
    ['table', 'left', 1, 2, false], ['normal', 'left', 1, 1, false],
    ['right', 'right', 1, 1, false], ['center', 'center', 1, 1, false], ['left', 'left', 1, 1, false],
    ['product', 'center', 2, 2, false], ['heading size', 'center', 2, 2, false]
  ])
  assert.deepEqual(paperElements('</CB></C></L></R></N></HB></B>plain'), [{ type: 'text', value: 'plain', ...normal }])
})

// Sanitized values preserve the command order and newlines of OrderService's
// sendToPrinter, renderScene1Ticket, renderSceneListTicket and renderSettleTicket.
const legacyReceipts = {
  order: '<CB>*Store*\n<L><N>--------------------------------\n<CB>--A1--\n<L><N>Created: 2026/10/21 12:00:00\nOrder: ABC123\n**************Items**************\n<BR><B>Tea</B>iced<BR><R><B>10.00 x 2</B>\n\n\n<L><N>********************************\nTotal: <B>20.00</B>\n\nStaff: <B>Alex</B>\nServer: <B>Sam</B>\nBooking: <B>Lee</B>\n\n--------------------------------\n<C><HB>**#42 End**\n',
  perQuantity: '<CB>A1[Add]\n\n<CB><B>Tea</B>\n\n<C><N>Fresh\n',
  list: '<C><HB>***Store[Add]***\n\n<CB>** A1 **\n<L><N>Created: 2026/10/21 12:00:00\nOrder: ABC123\n\n<C>***Items***\n<CB>Tea x 2</CB>\n<CB>Coffee x 1</CB>\n\n<C><HB>** End **\n',
  paid: '<CB>***Store***\n<C><HB>** A1 **\n\n<C><B>Paid</B>\n',
  settle: '<CB>***Store***\n<C><HB>** A1 **\n<L><N>Created: 2026/10/21 12:00:00\nOrder: ABC123\n\n<C>*** Items ***\n<L><N><HB>Item               Price     Qty</HB>\n<HB>Tea                10.00      x2</HB>\n\n<C>*** Settlement ***\n<L><HB>Total: 20.00</HB>\n<L><HB>Discount: 0.00</HB>\n<L><HB>Received: 20.00</HB>\n\n<C>*** Payment QR ***\n\n<C><QRCODE s=8 e=L l=center>https://example.test/pay?order=42</QRCODE>\n<C><HB>** End **\n'
}

test('full legacy order receipt retains its literal blank lines and right-aligned item amounts', () => {
  const rows = receiptRows(paperElements(legacyReceipts.order), 48)
  assert.deepEqual(rows.map(row => row.items.map(item => item.value).join('')), [
    '*Store*', '--------------------------------', '--A1--', 'Created: 2026/10/21 12:00:00', 'Order: ABC123',
    '**************Items**************', '', 'Teaiced', '10.00 x 2', '', '',
    '********************************', 'Total: 20.00', '', 'Staff: Alex', 'Server: Sam', 'Booking: Lee', '',
    '--------------------------------', '**#42 End**'
  ])
  assert.equal(rows[8].align, 'right')
  assert.equal(rows[11].align, 'left')
  assert.equal(rows[11].h, 1)
  assert.equal(rows.at(-1).h, 2)
  assert.equal(rows.at(-1).items[0].w, 1)
})

test('full legacy per-quantity, list and paid receipts use absolute, not multiplied, sizes', () => {
  for (const [name, expected] of Object.entries({
    perQuantity: [['A1[Add]', 'center', 2, 2], ['Tea', 'center', 2, 2], ['Fresh', 'center', 1, 1]],
    list: [['***Store[Add]***', 'center', 1, 2], ['** A1 **', 'center', 2, 2], ['Created: 2026/10/21 12:00:00', 'left', 1, 1], ['Order: ABC123', 'left', 1, 1], ['***Items***', 'center', 1, 1], ['Tea x 2', 'center', 2, 2], ['Coffee x 1', 'center', 2, 2], ['** End **', 'center', 1, 2]],
    paid: [['***Store***', 'center', 2, 2], ['** A1 **', 'center', 1, 2], ['Paid', 'center', 2, 2]]
  })) {
    const rows = receiptRows(paperElements(legacyReceipts[name]), 48).filter(row => row.items.length)
    assert.deepEqual(rows.map(row => [row.items.map(item => item.value).join(''), row.align, row.items[0].w, row.h]), expected, name)
    assert.ok(rows.every(row => row.items.every(item => !item.bold)), name)
  }
  assert.deepEqual(receiptRows(paperElements(legacyReceipts.perQuantity), 48).map(row => row.items.map(item => item.value).join('')), ['A1[Add]', '', 'Tea', '', 'Fresh'])
})

test('full legacy settlement restores normal text after paired table and totals, preserving QR order', () => {
  const rows = receiptRows(paperElements(legacyReceipts.settle), 32)
  const textRow = value => rows.find(row => row.type === 'row' && row.items.map(item => item.value).join('') === value)
  for (const value of ['Item               Price     Qty', 'Tea                10.00      x2', 'Total: 20.00', 'Discount: 0.00', 'Received: 20.00']) {
    const row = textRow(value)
    assert.equal(row.align, 'left', value)
    assert.equal(row.h, 2, value)
    assert.ok(row.items.every(item => item.w === 1 && !item.bold), value)
  }
  for (const value of ['*** Items ***', '*** Settlement ***', '*** Payment QR ***']) {
    assert.equal(textRow(value).h, 1, value)
    assert.equal(textRow(value).align, 'center', value)
  }
  const qrIndex = rows.findIndex(row => row.type === 'qr')
  assert.equal(rows[qrIndex].align, 'center')
  assert.equal(rows[qrIndex + 1].items.map(item => item.value).join(''), '')
  assert.equal(rows[qrIndex + 2].items.map(item => item.value).join(''), '** End **')
})

test('spaces preserve column padding without wrapping into invented blank rows', () => {
  for (const newline of ['\n', '\r\n', '\r', '<BR>']) {
    const rows = receiptRows(paperElements(`${'a'.repeat(32)}    ${newline}  ${newline}  next${newline}`))
    assert.deepEqual(rows.map(row => row.items.map(item => item.value).join('')), ['a'.repeat(32), '  ', '  next'])
  }
  assert.equal(receiptRows(paperElements(' '.repeat(100) + '\n')).length, 1)
  assert.deepEqual(receiptRows(paperElements('a'.repeat(31) + '  b')).map(row => row.items.map(item => item.value).join('')), ['a'.repeat(31) + ' ', 'b'])
})

test('receipt wrapping uses 32 half-width cells including independent width and height scaling', () => {
  assert.equal(textCells('ABC茶全角'), 9)
  assert.equal(textCells('e\u0301'), 1)
  const rows = receiptRows(paperElements(`${'a'.repeat(31)}茶<BR><WB>${'茶'.repeat(9)}</WB><BR><HB>tall</HB>`))
  assert.deepEqual(rows.map(row => row.cells), [31, 2, 32, 4, 4])
  assert.equal(rows.at(-1).h, 2)
  assert.equal(rows.at(-1).items[0].w, 1)
  assert.deepEqual(receiptRows(paperElements('a'.repeat(49)), 48).map(row => row.cells), [48, 1])
})

test('label TEXT supports native font9 scaling, rotation and bounded integer attributes', () => {
  const [valid, invalid] = paperElements('<TEXT x="2048" y="8" font="9" w="10" h="2" r="270">茶ABC</TEXT><TEXT x="-1" y="1e3" font="99" w="11" h="0" r="45">safe</TEXT>')
  assert.deepEqual(valid, { type: 'labelText', format: 'TEXT', value: '茶ABC', x: 2048, y: 8, font: 9, w: 10, h: 2, r: 270 })
  assert.deepEqual(invalid, { type: 'labelText', format: 'TEXT', value: 'safe', x: 0, y: 0, font: 9, w: 1, h: 1, r: 0 })
  for (const r of [0, 90, 180, 270]) assert.equal(paperElements(`<TEXT r=${r}>A</TEXT>`)[0].r, r)
  const [malicious] = paperElements('<TEXT data-x="24" x="999999999999999999999" y="Infinity" w="2;position:fixed" onclick="alert(1)" r="90deg">&lt;img src=x onerror=alert(1)&gt;</TEXT>')
  assert.equal(malicious.x, 0)
  assert.equal(malicious.y, 0)
  assert.equal(malicious.w, 1)
  assert.equal(malicious.r, 0)
  assert.equal(malicious.value, '<img src=x onerror=alert(1)>')
  assert.equal(malicious.onclick, undefined)
})

test('receipt and label QR parse quoted and unquoted options without executing escaped commands', () => {
  const [receipt, label] = paperElements('<QRCODE s=8 e=L l=center>&lt;C&gt;https://example.test/?a=1&amp;b=2</QRCODE><QRC x="8" y="16" s="10" e="H" c="1">&ltBOLD&gtdata&lt/BOLD&gt</QRC>')
  assert.deepEqual(receipt, { type: 'qr', format: 'QRCODE', value: '<C>https://example.test/?a=1&amp;b=2', x: 0, y: 0, s: 8, e: 'L', align: 'center' })
  assert.deepEqual(label, { type: 'qr', format: 'QRC', value: '<BOLD>data</BOLD>', x: 8, y: 16, s: 10, e: 'H', align: 'left' })
  const invalid = paperElements('<R><QRCODE s="-1" e="bad" l="url(javascript:evil)">data</QRCODE></R><QRC s="11" x="-8" y="2049">data</QRC>')
  assert.equal(invalid[0].s, 6)
  assert.equal(invalid[0].e, 'L')
  assert.equal(invalid[0].align, 'right')
  assert.equal(invalid[1].s, 2)
  assert.equal(invalid[1].x, 0)
  assert.equal(invalid[1].y, 0)
  assert.deepEqual(paperElements('&ltC&gtliteral&lt/C&gt')[0], { type: 'text', value: '<C>literal</C>', ...normal })
})

test('QR geometry uses local modules and a four-module quiet zone, with safe failure', () => {
  const geometry = qrGeometry({ value: '<script>safe</script>', s: 2, e: 'H' }, (value, options) => {
    assert.equal(value, '<script>safe</script>')
    assert.deepEqual(options, { errorCorrectionLevel: 'H' })
    return { modules: { size: 2, get: (row, col) => row === col } }
  })
  assert.deepEqual(geometry, { path: 'M4 4h1v1h-1zM5 5h1v1h-1z', size: 10, widthMm: 2.5 })
  assert.equal(qrGeometry({ value: '' }, () => assert.fail('must not encode empty content')), null)
  assert.equal(qrGeometry({ value: 'a'.repeat(4097) }, () => assert.fail('must bound encoder input')), null)
  assert.equal(qrGeometry({ value: 'invalid' }, () => { throw new Error('encoding failed') }), null)
})

let qrCode
try { qrCode = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))('qrcode') } catch { /* Core dependencies are optional for pure parser tests. */ }
test('installed core QR encoder creates real matrices for receipt and label content', { skip: !qrCode && 'Core npm dependencies are not installed' }, () => {
  for (const element of paperElements('<QRCODE s=8 e=M l=center>https://example.test/茶</QRCODE><QRC s=2 e=H>ABC123</QRC>')) {
    const geometry = qrGeometry(element, qrCode.create)
    assert.ok(geometry.size >= 29)
    assert.match(geometry.path, /^M4 4h1v1h-1z/)
    assert.match(geometry.path, /^(?:M\d+ \d+h1v1h-1z)+$/)
    assert.equal(geometry.widthMm, geometry.size * element.s / 8)
  }
})

function renderPaper(document) {
  const source = readFileSync(new URL('../../business/admin/config/components/PrinterPaper.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
  const context = {
    defineComponent: value => value, ref: value => ({ value }), resolveComponent: name => name,
    h: (type, props, children) => Array.isArray(props) || typeof props === 'string'
      ? { type, props: {}, children: props } : { type, props: props || {}, children },
    t: key => key, paperElements, paperSize, printerFontMetrics, qrGeometry, receiptMetrics, receiptRows, textCells,
    QRCode: { create: () => ({ modules: { size: 21, get: (row, col) => row === col } }) }, globalThis: {}
  }
  runInNewContext(source, context)
  const nodes = []
  const visit = node => {
    if (!node || typeof node !== 'object') return
    nodes.push(node)
    if (Array.isArray(node.children)) node.children.forEach(visit)
  }
  visit(context.globalThis.component.setup({ document })())
  return nodes
}

test('label rendering uses safe native cells, transforms and local SVG; only barcodes are placeholders', () => {
  const nodes = renderPaper({ kind: 'label', widthMm: 50, heightMm: 40, pages: ['<TEXT x="8" y="16" w="2" h="3" r="90">茶A</TEXT><QRC x="16" y="24" s="2">&ltimg src=x onerror=alert(1)&gt</QRC>'] })
  const text = nodes.find(node => node.props.style?.transform === 'rotate(90deg) scale(2, 3)')
  assert.equal(text.props.style.left, '1mm')
  assert.equal(text.props.style.top, '2mm')
  assert.equal(text.props.style.fontSize, '3mm')
  assert.equal(text.children[0].props.style.width, '3mm')
  assert.equal(text.children[1].props.style.width, '1.5mm')
  const svg = nodes.find(node => node.type === 'svg')
  assert.equal(svg.props['aria-label'], '<img src=x onerror=alert(1)>')
  assert.equal(svg.props.style.width, '7.25mm')
  assert.match(svg.children[0].props.d, /^M4 4h1v1h-1z/)
  assert.ok(nodes.every(node => node.type !== 'img' && !Object.hasOwn(node.props, 'innerHTML')))
  assert.ok(!nodes.some(node => node.children === 'Code preview placeholder help'))
  assert.ok(renderPaper({ kind: 'label', pages: ['<BC39 h="80">ABC</BC39>'] }).some(node => node.children === 'Code preview placeholder help'))
})

test('raw legacy label PAGE wrappers and indentation do not render as label content', () => {
  const nodes = renderPaper({ kind: 'label', pages: ['<PAGE>\n    <TEXT x="24" y="24" w="2" h="2">Header</TEXT>\n    <TEXT x="48" y="96" w="2" h="2">Tea</TEXT>\n    <TEXT x="72" y="168" w="2" h="2">Fresh</TEXT>\n</PAGE>'] })
  assert.equal(nodes.filter(node => node.props.style?.position === 'absolute').length, 3)
  assert.ok(!nodes.some(node => node.type === 'br' || (typeof node.children === 'string' && /PAGE|^\s+$/.test(node.children))))
})

test('receipt rendering places aligned and independently scaled rows around real QR blocks', () => {
  const nodes = renderPaper({ kind: 'receipt', widthMm: 80, pages: ['<C><WB>Wide<BR></WB></C><R><HB>Tall<BR></HB></R><QRCODE s=8 e=L l=center>local</QRCODE>'] })
  assert.ok(nodes.some(node => node.props.style?.textAlign === 'center' && node.props.style.height))
  assert.ok(nodes.some(node => node.props.style?.textAlign === 'right' && node.props.style.height))
  assert.ok(nodes.some(node => node.props.style?.transform === 'scale(2, 1)'))
  assert.ok(nodes.some(node => node.props.style?.transform === 'scale(1, 2)'))
  assert.equal(nodes.find(node => node.type === 'svg').props.style.width, '29mm')
  assert.ok(!nodes.some(node => node.children === 'Code preview placeholder help'))
})

test('mixed size rows reserve scaled cells and bottom-align their physical glyph boxes', () => {
  const nodes = renderPaper({ kind: 'receipt', widthMm: 58, pages: ['HB: <HB>Tall</HB><BR><R>B: <B>Large</B><BR></R><BOLD>Bold</BOLD>'] })
  const rows = nodes.filter(node => node.props.style?.display === 'flex' && node.props.style?.height)
  assert.equal(rows.length, 3)
  assert.equal(rows[0].props.style.alignItems, 'flex-end')
  assert.equal(rows[0].props.style.justifyContent, 'flex-start')
  assert.equal(rows[0].props.style.height, '6.75mm')
  assert.equal(rows[0].props.style.paddingBottom, '0.75mm')
  assert.equal(rows[0].props.style.boxSizing, 'border-box')
  assert.equal(rows[0].children[0].props.style.height, '3mm')
  assert.equal(rows[0].children[1].props.style.height, '6mm')
  assert.equal(rows[1].props.style.justifyContent, 'flex-end')
  const enlarged = rows[1].children[1]
  assert.equal(enlarged.props.style.flexShrink, 0)
  assert.equal(enlarged.props.style.width, '15mm')
  assert.equal(enlarged.children[0].props.style.transform, 'scale(2, 2)')
  assert.equal(enlarged.children[0].props.style.fontWeight, 400)
  assert.equal(rows[2].children[0].children[0].props.style.fontWeight, 700)
})

test('center and right alignment position scaled slots without aligning the unscaled glyphs a second time', () => {
  for (const widthMm of [58, 80]) {
    const nodes = renderPaper({ kind: 'receipt', widthMm, pages: ['<CB>ABCDEFGHIJKLMNOP<BR></CB><R><B>ABCDEFGHIJKLMNOP<BR></B></R><C><HB>Heading<BR></HB></C>'] })
    const rows = nodes.filter(node => node.props.style?.display === 'flex' && node.props.style?.height)
    assert.deepEqual(rows.map(row => row.props.style.justifyContent), ['center', 'flex-end', 'center'])
    for (const row of rows) {
      for (const slot of row.children) {
        const ink = slot.children[0]
        assert.equal(slot.props.style.textAlign, 'left')
        assert.equal(ink.props.style.textAlign, 'left')
        assert.equal(ink.props.style.display, 'block')
        const scale = Number(ink.props.style.transform.match(/scale\((\d+),/)[1])
        assert.equal(parseFloat(ink.props.style.width) * scale, parseFloat(slot.props.style.width))
      }
    }
    assert.ok(nodes.some(node => node.props.style?.background === '#fff' && node.props.style.textAlign === 'left'))
  }
})

test('paper dimensions follow the document with safe defaults', () => {
  assert.deepEqual(paperSize({ kind: 'receipt' }), { width: 80, height: 30 })
  assert.deepEqual(paperSize({ kind: 'label', widthMm: 50, heightMm: 40 }), { width: 50, height: 40 })
  assert.deepEqual(paperSize({ kind: 'label', widthMm: 'invalid', heightMm: -1 }), { width: 40, height: 30 })
})

test('native receipt character size stays fixed when paper width changes', () => {
  assert.deepEqual(receiptMetrics(58), { columns: 32, cellMm: 1.5, fontMm: 3, gapMm: 0.75, marginMm: 5 })
  assert.deepEqual(receiptMetrics(80), { columns: 48, cellMm: 1.5, fontMm: 3, gapMm: 0.75, marginMm: 4 })
  for (const width of [58, 80]) {
    const nodes = renderPaper({ kind: 'receipt', widthMm: width, pages: ['<N>Normal<BR></N><HB>Tall<BR></HB>'] })
    assert.ok(nodes.some(node => node.props.style?.fontSize === '3mm'))
    assert.ok(nodes.some(node => node.props.style?.height === '3.75mm'))
    assert.ok(nodes.some(node => node.props.style?.height === '6.75mm'))
  }
})

test('label fonts use their own documented dot cells rather than font9 for everything', () => {
  assert.deepEqual(printerFontMetrics(1), { cellMm: 1, heightMm: 1.5 })
  assert.deepEqual(printerFontMetrics(5), { cellMm: 4, heightMm: 6 })
  assert.deepEqual(printerFontMetrics(9), { cellMm: 1.5, heightMm: 3 })
  const nodes = renderPaper({ kind: 'label', pages: ['<TEXT x="8" y="8" font="1">ABC</TEXT>'] })
  const text = nodes.find(node => node.props.style?.transform === 'rotate(0deg) scale(1, 1)')
  assert.equal(text.props.style.fontSize, '1.5mm')
  assert.equal(text.children[0].props.style.width, '1mm')
})
