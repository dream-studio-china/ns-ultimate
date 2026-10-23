import assert from 'node:assert/strict'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { crc32, deflateRawSync } from 'node:zlib'
import {
  ATTACHMENT_ACCEPT,
  addAttachments,
  assertAttachmentsForSend,
  buildAttachmentHistoryContent,
  buildUserMessageContent,
  createAttachment,
  releaseAttachment,
  shouldAcceptAttachmentBatch,
  snapshotAttachmentsForSend
} from '../src/attachments.js'
import { askProvider } from '../src/provider.js'

const encoder = new TextEncoder()

const NS = {
  contentTypes: 'http://schemas.openxmlformats.org/package/2006/content-types',
  relationships: 'http://schemas.openxmlformats.org/package/2006/relationships',
  officeRelationships: 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
  spreadsheet: 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
  wordprocessing: 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
}

const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
const XLSX_MAIN = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'
const DOCX_MAIN = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
const WORKSHEET_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'
const WORKSHEET_REL = `${NS.officeRelationships}/worksheet`

function bytesFile(name, type, bytes) {
  const copy = Uint8Array.from(bytes)
  return {
    name,
    type,
    size: copy.byteLength,
    arrayBuffer: async() => copy.buffer
  }
}

function escapeXml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;')
}

function buildZip(entries) {
  const localChunks = []
  const centralChunks = []
  let offset = 0
  for (const entry of entries) {
    const name = Buffer.from(entry.name, 'ascii')
    const data = Buffer.from(entry.data)
    const compressed = deflateRawSync(data)
    const checksum = crc32(data) >>> 0
    const local = Buffer.alloc(30)
    local.writeUInt32LE(0x04034b50, 0)
    local.writeUInt16LE(20, 4)
    local.writeUInt16LE(0, 6)
    local.writeUInt16LE(8, 8)
    local.writeUInt16LE(0, 10)
    local.writeUInt16LE(0x21, 12)
    local.writeUInt32LE(checksum, 14)
    local.writeUInt32LE(compressed.length, 18)
    local.writeUInt32LE(data.length, 22)
    local.writeUInt16LE(name.length, 26)
    local.writeUInt16LE(0, 28)

    const central = Buffer.alloc(46)
    central.writeUInt32LE(0x02014b50, 0)
    central.writeUInt16LE(20, 4)
    central.writeUInt16LE(20, 6)
    central.writeUInt16LE(0, 8)
    central.writeUInt16LE(8, 10)
    central.writeUInt16LE(0, 12)
    central.writeUInt16LE(0x21, 14)
    central.writeUInt32LE(checksum, 16)
    central.writeUInt32LE(compressed.length, 20)
    central.writeUInt32LE(data.length, 24)
    central.writeUInt16LE(name.length, 28)
    central.writeUInt16LE(0, 30)
    central.writeUInt16LE(0, 32)
    central.writeUInt16LE(0, 34)
    central.writeUInt16LE(0, 36)
    central.writeUInt32LE(0, 38)
    central.writeUInt32LE(offset, 42)

    localChunks.push(local, name, compressed)
    centralChunks.push(central, name)
    offset += local.length + name.length + compressed.length
  }
  const centralDirectory = Buffer.concat(centralChunks)
  const end = Buffer.alloc(22)
  end.writeUInt32LE(0x06054b50, 0)
  end.writeUInt16LE(0, 4)
  end.writeUInt16LE(0, 6)
  end.writeUInt16LE(entries.length, 8)
  end.writeUInt16LE(entries.length, 10)
  end.writeUInt32LE(centralDirectory.length, 12)
  end.writeUInt32LE(offset, 16)
  end.writeUInt16LE(0, 20)
  return Buffer.concat([...localChunks, centralDirectory, end])
}

function xlsxPackage(sheetXmls, extraEntries = []) {
  const sheets = sheetXmls.map((sheetXml, index) => ({
    name: `xl/worksheets/sheet${index + 1}.xml`,
    data: sheetXml
  }))
  return buildZip([
    {
      name: '[Content_Types].xml',
      data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="${NS.contentTypes}">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="${XLSX_MAIN}"/>
${sheetXmls.map((_, index) => `<Override PartName="/xl/worksheets/sheet${index + 1}.xml" ContentType="${WORKSHEET_CONTENT_TYPE}"/>`).join('')}
</Types>`
    },
    {
      name: '_rels/.rels',
      data: `<Relationships xmlns="${NS.relationships}"><Relationship Id="rId1" Type="${NS.officeRelationships}/officeDocument" Target="xl/workbook.xml"/></Relationships>`
    },
    {
      name: 'xl/workbook.xml',
      data: `<workbook xmlns="${NS.spreadsheet}" xmlns:r="${NS.officeRelationships}"><sheets>${sheetXmls
        .map((_, index) => `<sheet name="Sheet${index + 1}" sheetId="${index + 1}" r:id="rId${index + 1}"/>`)
        .join('')}</sheets></workbook>`
    },
    {
      name: 'xl/_rels/workbook.xml.rels',
      data: `<Relationships xmlns="${NS.relationships}">${sheetXmls
        .map((_, index) => `<Relationship Id="rId${index + 1}" Type="${WORKSHEET_REL}" Target="worksheets/sheet${index + 1}.xml"/>`)
        .join('')}</Relationships>`
    },
    ...sheets,
    ...extraEntries
  ])
}

function worksheetXml(rows) {
  return `<worksheet xmlns="${NS.spreadsheet}"><sheetData>${rows.join('')}</sheetData></worksheet>`
}

function rowXml(number, cells) {
  return `<row r="${number}">${cells.join('')}</row>`
}

function columnName(index) {
  let value = ''
  let current = index + 1
  while (current > 0) {
    const remainder = (current - 1) % 26
    value = String.fromCharCode(65 + remainder) + value
    current = Math.floor((current - 1) / 26)
  }
  return value
}

function inlineCell(reference, value) {
  return `<c r="${reference}" t="inlineStr"><is><t>${escapeXml(value)}</t></is></c>`
}

function formulaRow() {
  return `<row r="2">${inlineCell('A2', 'Tea')}<c r="B2"><f>1+1</f><v>2</v></c></row>`
}

function docxPackage(body, extraEntries = []) {
  return buildZip([
    {
      name: '[Content_Types].xml',
      data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="${NS.contentTypes}">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="${DOCX_MAIN}"/>
</Types>`
    },
    {
      name: '_rels/.rels',
      data: `<Relationships xmlns="${NS.relationships}"><Relationship Id="rId1" Type="${NS.officeRelationships}/officeDocument" Target="word/document.xml"/></Relationships>`
    },
    {
      name: 'word/document.xml',
      data: `<w:document xmlns:w="${NS.wordprocessing}"><w:body>${body}</w:body></w:document>`
    },
    ...extraEntries
  ])
}

function wordTable(rows) {
  return `<w:tbl>${rows.map(row => `<w:tr>${row
    .map(cell => `<w:tc><w:p><w:r><w:t>${escapeXml(cell)}</w:t></w:r></w:p></w:tc>`)
    .join('')}</w:tr>`).join('')}</w:tbl>`
}

function attachmentTextTable(attachment) {
  const marker = 'Table JSON:\n'
  const index = attachment.text.indexOf(marker)
  assert.notEqual(index, -1)
  return JSON.parse(attachment.text.slice(index + marker.length))
}

function denseWorksheet(rows, columns, value) {
  const records = [
    rowXml(1, Array.from({ length: columns }, (_, index) => inlineCell(`${columnName(index)}1`, `column-${index}`)))
  ]
  for (let row = 2; row <= rows + 1; row += 1) {
    records.push(rowXml(row, Array.from({ length: columns }, (_, index) => inlineCell(`${columnName(index)}${row}`, value))))
  }
  return worksheetXml(records)
}

function paddedXlsx(size) {
  return xlsxPackage([
    worksheetXml([
      rowXml(1, [inlineCell('A1', 'Name'), inlineCell('B1', 'Quantity')]),
      rowXml(2, [inlineCell('A2', 'Tea'), inlineCell('B2', '2')])
    ])
  ], [{ name: 'custom/padding.bin', data: randomBytes(size) }])
}

test('attaches real XLSX and DOCX through the normal file pipeline as bounded untrusted text', async() => {
  const globalFetch = globalThis.fetch
  let globalRequests = 0
  globalThis.fetch = async() => {
    globalRequests += 1
    throw new Error('selection must not fetch')
  }
  try {
    const xlsxBytes = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
    const docxBytes = readFileSync(new URL('./fixtures/import/pandoc-products.docx', import.meta.url))
    const selected = await addAttachments([
      bytesFile('products.xlsx', XLSX_MIME, xlsxBytes),
      bytesFile('products.docx', DOCX_MIME, docxBytes)
    ], [])
    assert.deepEqual(selected.errors, [])
    assert.equal(selected.attachments.length, 2)

    const [xlsx, docx] = selected.attachments
    for (const attachment of [xlsx, docx]) {
      assert.equal(attachment.kind, 'text')
      assert.equal(attachment.byteLength, attachment.rawByteLength)
      assert.equal(attachment.size, attachment.rawByteLength)
      assert.equal(attachment.textByteLength, encoder.encode(attachment.text).byteLength)
      assert.equal(typeof attachment.warnings[0].code, 'string')
      assert.match(attachment.text, /untrusted file content/)
      assert.match(attachment.text, /Parser warnings/)
      assert.doesNotMatch(attachment.text, /base64|data:application/i)
      assert.equal(assertAttachmentsForSend([attachment]), true)
    }
    assert.equal(xlsx.format, 'xlsx')
    assert.equal(xlsx.mimeType, XLSX_MIME)
    assert.equal(xlsx.rowCount, 4)
    assert.equal(xlsx.columnCount, 6)
    assert.deepEqual(xlsx.warnings.map(warning => warning.code), ['formatting_not_applied'])
    const xlsxTable = attachmentTextTable(xlsx)
    assert.deepEqual(xlsxTable.columns, ['Name', 'Category', 'Code', 'Note', 'Quantity', 'Active'])
    assert.deepEqual(xlsxTable.rows[0], ['North', 'Tea', 'TEA-001', 'Line one\nLine two', '2', 'TRUE'])
    assert.match(xlsx.text, /formatting_not_applied/)

    assert.equal(docx.format, 'docx')
    assert.equal(docx.mimeType, DOCX_MIME)
    assert.deepEqual(docx.warnings.map(warning => warning.code), ['docx_ancillary_text_ignored'])
    assert.match(docx.text, /docx_ancillary_text_ignored/)
    assert.equal(attachmentTextTable(docx).rows[1][0], 'South')

    const content = buildUserMessageContent('map these products', selected.attachments)
    assert.equal(typeof content, 'string')
    assert.match(content, /--- Attached file: products\.xlsx \(untrusted data\) ---/)
    assert.match(content, /first_sheet_only|formatting_not_applied/)
    assert.match(content, /North/)

    const history = buildAttachmentHistoryContent('map these products', selected.attachments)
    assert.match(history, /products\.xlsx/)
    assert.match(history, /contents are not retained/)
    assert.doesNotMatch(history, /North|TEA-001|North,Tea|first_sheet_only|Table JSON/)

    let providerRequests = 0
    await askProvider({
      baseUrl: 'https://provider.example/v1',
      model: 'm',
      apiKey: '',
      messages: [{ role: 'user', content }],
      fetchImpl: async() => {
        providerRequests += 1
        return { ok: true, json: async() => ({ choices: [{ message: { content: 'done' } }] }) }
      }
    })
    assert.equal(providerRequests, 1)
    assert.equal(globalRequests, 0)
  } finally {
    globalThis.fetch = globalFetch
  }
})

test('keeps TXT and CSV compatible and rejects legacy, markup, binary and disguised files', async() => {
  const txt = await createAttachment(bytesFile('notes.txt', 'text/plain', encoder.encode('alpha\nbeta')))
  assert.deepEqual({ ...txt, id: undefined }, {
    id: undefined,
    kind: 'text',
    name: 'notes.txt',
    mimeType: 'text/plain',
    size: 10,
    byteLength: 10,
    textByteLength: 10,
    text: 'alpha\nbeta'
  })
  const csv = await createAttachment(bytesFile('table.csv', 'text/csv', encoder.encode('name,qty\nTea,2\n')))
  assert.equal(csv.format, undefined)
  assert.match(csv.text, /Tea,2/)

  const xlsxBytes = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  await assert.rejects(() => createAttachment(bytesFile('legacy.xls', 'application/vnd.ms-excel', [0xd0, 0xcf, 0x11, 0xe0, 0xa1, 0xb1, 0x1a, 0xe1])), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('legacy.doc', 'application/msword', [0xd0, 0xcf, 0x11, 0xe0, 0xa1, 0xb1, 0x1a, 0xe1])), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('macro.xlsm', XLSX_MIME, xlsxBytes)), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('template.xltx', XLSX_MIME, xlsxBytes)), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('style.xsl', 'application/xml', encoder.encode('<xsl/>'))), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('page.html', 'text/html', encoder.encode('<p>x</p>'))), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('vector.svg', 'image/svg+xml', encoder.encode('<svg/>'))), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('paper.pdf', 'application/pdf', encoder.encode('%PDF-1.7'))), { code: 'unsupported_type' })

  await assert.rejects(() => createAttachment(bytesFile('claimed-text.xlsx', 'text/plain', xlsxBytes)), { code: 'mime_mismatch' })
  await assert.rejects(() => createAttachment(bytesFile('wrong-main.docx', DOCX_MIME, xlsxBytes)), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('text-package.xlsx', XLSX_MIME, encoder.encode('name,qty'))), {
    code: 'invalid_document'
  })
  await assert.rejects(() => createAttachment(bytesFile('png-package.xlsx', XLSX_MIME, [0x89, 0x50, 0x4e, 0x47])), {
    code: 'invalid_document'
  })
  await assert.rejects(() => createAttachment(bytesFile('legacy-package.xlsx', XLSX_MIME, [0xd0, 0xcf, 0x11, 0xe0, 0xa1, 0xb1, 0x1a, 0xe1])), {
    code: 'unsupported_type'
  })
  await assert.rejects(() => createAttachment(bytesFile('renamed.txt', 'text/plain', xlsxBytes)), { code: 'unsupported_type' })
  assert.doesNotMatch(ATTACHMENT_ACCEPT, /\.xls[,.]|\.doc[,.]|\.pdf|\.svg|\.html|\.xsl/)
  assert.match(ATTACHMENT_ACCEPT, /\.xlsx/)
  assert.match(ATTACHMENT_ACCEPT, /\.docx/)
})

test('surfaces first-sheet and additional-table warnings to the agent instead of suppressing them', async() => {
  const twoSheets = xlsxPackage([
    worksheetXml([
      rowXml(1, [inlineCell('A1', 'Name')]),
      rowXml(2, [inlineCell('A2', 'Tea')])
    ]),
    worksheetXml([
      rowXml(1, [inlineCell('A1', 'Name')]),
      rowXml(2, [inlineCell('A2', 'Coffee')])
    ])
  ])
  const xlsx = await createAttachment(bytesFile('two-sheets.xlsx', XLSX_MIME, twoSheets))
  assert.deepEqual(xlsx.warnings.map(warning => warning.code), ['first_sheet_only'])
  assert.match(xlsx.text, /first_sheet_only: The workbook contains 2 sheets/)
  assert.deepEqual(attachmentTextTable(xlsx).rows, [['Tea']])

  const twoTables = docxPackage([
    wordTable([['Name'], ['Tea']]),
    wordTable([['Name'], ['Coffee']])
  ].join(''))
  const docx = await createAttachment(bytesFile('two-tables.docx', DOCX_MIME, twoTables))
  assert.deepEqual(docx.warnings.map(warning => warning.code), ['docx_additional_tables_ignored'])
  assert.match(docx.text, /docx_additional_tables_ignored/)
  assert.deepEqual(attachmentTextTable(docx).rows, [['Tea']])
  const content = buildUserMessageContent('import', [xlsx, docx])
  assert.match(content, /first_sheet_only/)
  assert.match(content, /docx_additional_tables_ignored/)
})

test('rejects formulas and unsafe DOCX fields through the actual attachment entry before any request', async() => {
  const formula = xlsxPackage([
    worksheetXml([
      rowXml(1, [inlineCell('A1', 'Name'), inlineCell('B1', 'Total')]),
      formulaRow()
    ])
  ])
  let globalRequests = 0
  const originalFetch = globalThis.fetch
  globalThis.fetch = async() => {
    globalRequests += 1
    throw new Error('rejected documents must not fetch')
  }
  try {
    await assert.rejects(() => createAttachment(bytesFile('formula.xlsx', XLSX_MIME, formula)), error => {
      assert.equal(error.code, 'invalid_document')
      assert.equal(error.params.reason, 'formula_unsupported')
      return true
    })

    const field = docxPackage(`<w:tbl><w:tr><w:tc><w:p>
      <w:r><w:fldChar w:fldCharType="begin"/></w:r>
      <w:r><w:instrText>DDEAUTO c:\\evil</w:instrText></w:r>
      <w:r><w:fldChar w:fldCharType="end"/></w:r>
    </w:p></w:tc></w:tr></w:tbl>`)
    await assert.rejects(() => createAttachment(bytesFile('field.docx', DOCX_MIME, field)), error => {
      assert.equal(error.code, 'invalid_document')
      assert.equal(error.params.reason, 'docx_field_unsupported')
      return true
    })
    assert.equal(globalRequests, 0)
  } finally {
    globalThis.fetch = originalFetch
  }
})

test('bounds transformed document text and keeps raw and text budgets separate', async() => {
  const columns = 64
  const rows = []
  rows.push(rowXml(1, Array.from({ length: columns }, (_, index) => inlineCell(`${columnName(index)}1`, `column-${index}`))))
  for (let row = 2; row <= 21; row += 1) {
    rows.push(rowXml(row, Array.from({ length: columns }, (_, index) => inlineCell(`${columnName(index)}${row}`, 'x'.repeat(4096)))))
  }
  const oversized = xlsxPackage([worksheetXml(rows)])
  await assert.rejects(() => createAttachment(bytesFile('oversized.xlsx', XLSX_MIME, oversized)), { code: 'text_too_large' })

  const fixture = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  const rawHeavy = []
  for (let index = 0; index < 3; index += 1) {
    rawHeavy.push(await createAttachment(bytesFile(
      `heavy-${index}.xlsx`,
      XLSX_MIME,
      paddedXlsx(3.5 * 1024 * 1024)
    )))
  }
  assert.ok(rawHeavy.reduce((total, attachment) => total + attachment.rawByteLength, 0) > 10 * 1024 * 1024)
  assert.throws(() => assertAttachmentsForSend(rawHeavy), { code: 'total_too_large' })
  assert.throws(() => assertAttachmentsForSend(snapshotAttachmentsForSend(rawHeavy)), { code: 'total_too_large' })

  const dense = await createAttachment(bytesFile(
    'dense.xlsx',
    XLSX_MIME,
    xlsxPackage([denseWorksheet(20, 64, 'x'.repeat(80))])
  ))
  const textA = await createAttachment(bytesFile('a.txt', 'text/plain', encoder.encode('a'.repeat(100 * 1024))))
  const textB = await createAttachment(bytesFile('b.txt', 'text/plain', encoder.encode('b'.repeat(100 * 1024))))
  assert.throws(() => assertAttachmentsForSend([dense, textA, textB]), { code: 'text_total_too_large' })

  const valid = await createAttachment(bytesFile('products.xlsx', XLSX_MIME, fixture))
  assert.throws(() => assertAttachmentsForSend([{ ...valid }]), { code: 'invalid_metadata' })
  assert.equal(assertAttachmentsForSend(snapshotAttachmentsForSend([valid])), true)
})

test('uses explicit trust for legitimate send snapshots and rejects forged document objects', async() => {
  const fixture = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  const attachment = await createAttachment(bytesFile('products.xlsx', XLSX_MIME, fixture))
  const snapshot = snapshotAttachmentsForSend([attachment])[0]
  assert.equal(assertAttachmentsForSend([snapshot]), true)
  assert.match(buildUserMessageContent('import', [snapshot]), /untrusted file content/)

  assert.throws(() => assertAttachmentsForSend([{ ...attachment }]), { code: 'invalid_metadata' })
  assert.throws(() => snapshotAttachmentsForSend([{ ...attachment }]), { code: 'invalid_metadata' })
  for (const forged of [
    { ...snapshot, size: 0, byteLength: 0, rawByteLength: 0 },
    { ...snapshot, rawByteLength: 0 },
    { ...snapshot, textByteLength: 1 },
    { ...snapshot, mimeType: 'text/plain' },
    { ...snapshot, name: 'foreign.xlsx' },
    { ...snapshot, warnings: [{ code: '', message: 'x' }] },
    { ...snapshot, text: snapshot.text.replace('North', 'Fake'), textByteLength: encoder.encode(snapshot.text.replace('North', 'Fake')).byteLength }
  ]) {
    assert.throws(() => assertAttachmentsForSend([forged]), { code: 'invalid_metadata' })
  }
  assert.throws(() => assertAttachmentsForSend([{
    ...snapshot,
    name: 'arbitrary.xlsx',
    text: 'Document attachment: arbitrary.xlsx (XLSX).\nTable JSON:\n{"format":"xlsx","columns":["a"],"rows":[["b"]]}'
  }]), { code: 'invalid_metadata' })

  const tampered = snapshotAttachmentsForSend([attachment])[0]
  tampered.format = undefined
  tampered.text = `${tampered.text}\n`
  tampered.textByteLength = encoder.encode(tampered.text).byteLength
  assert.throws(() => assertAttachmentsForSend([tampered]), { code: 'invalid_metadata' })
  assert.throws(() => snapshotAttachmentsForSend([tampered]), { code: 'invalid_metadata' })

  const mutable = await createAttachment(bytesFile('mutable.xlsx', XLSX_MIME, fixture))
  mutable.size = 0
  mutable.byteLength = 0
  mutable.rawByteLength = 0
  assert.throws(() => assertAttachmentsForSend([mutable]), { code: 'invalid_metadata' })

  await assert.rejects(() => addAttachments([bytesFile('mutable.xlsx', XLSX_MIME, fixture)], [{
    ...snapshot,
    size: 0,
    byteLength: 0,
    rawByteLength: 0
  }]), { code: 'invalid_metadata' })
})

test('plain text matching generated document text is still accepted as ordinary TXT', async() => {
  const fixture = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  const document = await createAttachment(bytesFile('products.xlsx', XLSX_MIME, fixture))
  const copiedText = await createAttachment(bytesFile('copy.txt', 'text/plain', encoder.encode(document.text)))
  assert.equal(assertAttachmentsForSend([copiedText]), true)
  assert.match(buildUserMessageContent('import', [copiedText]), /Document attachment: products\.xlsx/)

  const snapshot = snapshotAttachmentsForSend([copiedText])[0]
  assert.equal(assertAttachmentsForSend([snapshot]), true)
  const added = await addAttachments([bytesFile('another.txt', 'text/plain', encoder.encode(document.text))], [copiedText])
  assert.equal(added.errors.length, 0)
  assert.equal(added.attachments.length, 2)
})

test('keeps a trusted snapshot valid after releasing its original and creating more than 256 documents', async() => {
  const fixture = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  const original = await createAttachment(bytesFile('held.xlsx', XLSX_MIME, fixture))
  const snapshot = snapshotAttachmentsForSend([original])[0]
  releaseAttachment(original)
  for (let index = 0; index < 257; index += 1) {
    const temporary = await createAttachment(bytesFile(`temporary-${index}.xlsx`, XLSX_MIME, fixture))
    releaseAttachment(temporary)
  }
  assert.equal(assertAttachmentsForSend([snapshot]), true)
  assert.match(buildUserMessageContent('import', [snapshot]), /products\.xlsx|held\.xlsx/)
})

test('derives aggregate text bytes from actual content and rejects inconsistent text metadata', async() => {
  const first = await createAttachment(bytesFile('one.txt', 'text/plain', encoder.encode('a'.repeat(128 * 1024))))
  const second = await createAttachment(bytesFile('two.txt', 'text/plain', encoder.encode('b'.repeat(128 * 1024))))
  const fixture = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  const aggregate = await addAttachments([bytesFile('products.xlsx', XLSX_MIME, fixture)], [first, second])
  assert.equal(aggregate.attachments.length, 2)
  assert.equal(aggregate.errors[0].code, 'text_total_too_large')

  await assert.rejects(() => addAttachments([bytesFile('products.xlsx', XLSX_MIME, fixture)], [
    { ...first, textByteLength: 0 },
    { ...second, textByteLength: 0 }
  ]), { code: 'invalid_metadata' })

  const legacy = await addAttachments([bytesFile('new.txt', 'text/plain', encoder.encode('new'))], [
    { kind: 'text', name: 'legacy.txt', size: 100 }
  ])
  assert.equal(legacy.errors.length, 0)
  assert.equal(legacy.attachments.length, 2)
  assert.equal(legacy.attachments[0].textByteLength, undefined)
})


test('cancels local document parsing without resurrecting a stale attachment batch', async() => {
  const xlsxBytes = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  let checks = 0
  await assert.rejects(() => createAttachment(bytesFile('products.xlsx', XLSX_MIME, xlsxBytes), {
    isCancelled: () => {
      checks += 1
      return checks >= 4
    }
  }), { code: 'cancelled' })
  assert.ok(checks >= 4)

  let reads = 0
  const controller = new AbortController()
  controller.abort()
  const existing = [{ kind: 'text', name: 'keep.txt', size: 1 }]
  const result = await addAttachments([{
    name: 'products.xlsx',
    type: XLSX_MIME,
    size: xlsxBytes.byteLength,
    arrayBuffer: async() => {
      reads += 1
      return Uint8Array.from(xlsxBytes).buffer
    }
  }], existing, { signal: controller.signal })
  assert.equal(result.cancelled, true)
  assert.deepEqual(result.attachments, existing)
  assert.equal(reads, 0)
  assert.equal(shouldAcceptAttachmentBatch(2, 3, false), false)
  assert.equal(shouldAcceptAttachmentBatch(3, 3, false), true)
})

test('aborts an in-flight local document parse through addAttachments and drops the stale generation', async() => {
  const OriginalDecompressionStream = globalThis.DecompressionStream
  const xlsxBytes = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  let releaseGate
  const gate = new Promise(resolve => { releaseGate = resolve })
  let markStarted
  const started = new Promise(resolve => { markStarted = resolve })
  let startedOnce = false
  class ControlledDecompressionStream {
    constructor(format) {
      const inner = new OriginalDecompressionStream(format)
      const gateStream = new TransformStream({
        async transform(chunk, controller) {
          if (!startedOnce) {
            startedOnce = true
            markStarted()
          }
          await gate
          controller.enqueue(chunk)
        }
      })
      this.writable = inner.writable
      this.readable = inner.readable.pipeThrough(gateStream)
    }
  }

  let reads = 0
  const file = {
    name: 'products.xlsx',
    type: XLSX_MIME,
    size: xlsxBytes.byteLength,
    arrayBuffer: async() => {
      reads += 1
      return Uint8Array.from(xlsxBytes).buffer
    }
  }
  const controller = new AbortController()
  let generation = 1
  let result
  globalThis.DecompressionStream = ControlledDecompressionStream
  try {
    const pending = addAttachments([file], [], {
      signal: controller.signal,
      isCancelled: () => generation !== 1
    })
    let timeout
    const didStart = await Promise.race([
      started.then(() => true),
      new Promise(resolve => { timeout = setTimeout(() => resolve(false), 2000) })
    ])
    clearTimeout(timeout)
    assert.equal(didStart, true)
    generation = 2
    controller.abort()
    result = await pending
  } finally {
    controller.abort()
    releaseGate()
    globalThis.DecompressionStream = OriginalDecompressionStream
  }
  assert.equal(reads, 1)
  assert.equal(result.cancelled, true)
  assert.deepEqual(result.attachments, [])
  assert.equal(shouldAcceptAttachmentBatch(1, generation, false), false)
  await Promise.resolve()
  assert.deepEqual(result.attachments, [])
})
