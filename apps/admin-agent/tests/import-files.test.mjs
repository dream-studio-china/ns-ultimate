import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { crc32, deflateRawSync } from 'node:zlib'
import {
  IMPORT_LIMITS,
  MAX_IMPORT_FILE_BYTES,
  MAX_IMPORT_RECORDS,
  parseImportFile
} from '../src/import-files.js'

const encoder = new TextEncoder()

const NS = {
  contentTypes: 'http://schemas.openxmlformats.org/package/2006/content-types',
  relationships: 'http://schemas.openxmlformats.org/package/2006/relationships',
  officeRelationships: 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
  spreadsheet: 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
  wordprocessing: 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
}

const XLSX_MAIN = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'
const DOCX_MAIN = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
const WORKSHEET_REL = `${NS.officeRelationships}/worksheet`
const SHARED_STRINGS_REL = `${NS.officeRelationships}/sharedStrings`
const SHARED_STRINGS_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml'

function bytesFile(name, bytes) {
  const copy = Uint8Array.from(bytes)
  return {
    name,
    size: copy.byteLength,
    arrayBuffer: async() => copy.buffer
  }
}

function textFile(name, text) {
  return bytesFile(name, encoder.encode(text))
}

function escapeXml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;')
}

function buildZip(entries, options = {}) {
  const localChunks = []
  const centralChunks = []
  let offset = 0

  for (const entry of entries) {
    const name = Buffer.from(entry.name, 'ascii')
    const data = Buffer.from(entry.data)
    const method = entry.method ?? (options.deflate === false ? 0 : 8)
    const compressed = method === 8 ? deflateRawSync(data) : data
    const checksum = entry.checksum ?? (entry.badCrc ? 0 : crc32(data) >>> 0)
    const flags = entry.flags ?? options.flags ?? 0
    const compressedSize = entry.declaredCompressedSize ?? compressed.length
    const uncompressedSize = entry.declaredUncompressedSize ?? data.length

    const local = Buffer.alloc(30)
    local.writeUInt32LE(0x04034b50, 0)
    local.writeUInt16LE(20, 4)
    local.writeUInt16LE(flags, 6)
    local.writeUInt16LE(method, 8)
    local.writeUInt16LE(0, 10)
    local.writeUInt16LE(0x21, 12)
    local.writeUInt32LE(checksum, 14)
    local.writeUInt32LE(compressedSize, 18)
    local.writeUInt32LE(uncompressedSize, 22)
    local.writeUInt16LE(name.length, 26)
    local.writeUInt16LE(0, 28)

    const central = Buffer.alloc(46)
    central.writeUInt32LE(0x02014b50, 0)
    central.writeUInt16LE(20, 4)
    central.writeUInt16LE(20, 6)
    central.writeUInt16LE(flags, 8)
    central.writeUInt16LE(method, 10)
    central.writeUInt16LE(0, 12)
    central.writeUInt16LE(0x21, 14)
    central.writeUInt32LE(checksum, 16)
    central.writeUInt32LE(compressedSize, 20)
    central.writeUInt32LE(uncompressedSize, 24)
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

function xlsxContentTypes({
  sharedStrings = false,
  main = XLSX_MAIN,
  worksheetContentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'
} = {}) {
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="${NS.contentTypes}">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="${main}"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="${worksheetContentType}"/>
${sharedStrings ? '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' : ''}
</Types>`
}

function xlsxPackage({
  sheetXml,
  sharedStringsXml = null,
  workbookSheets = '<sheet name="Sheet1" sheetId="1" r:id="rId1"/>',
  workbookRelationships = [
    `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
    sharedStringsXml
      ? `<Relationship Id="rId2" Type="${SHARED_STRINGS_REL}" Target="sharedStrings.xml"/>`
      : ''
  ].join(''),
  packageRelationships = `<Relationship Id="rId1" Type="${NS.officeRelationships}/officeDocument" Target="xl/workbook.xml"/>`,
  contentTypes = null,
  extraEntries = [],
  entryOptions = {},
  zipOptions = {}
} = {}) {
  const entries = [
    { name: '[Content_Types].xml', data: contentTypes || xlsxContentTypes({ sharedStrings: Boolean(sharedStringsXml) }) },
    {
      name: '_rels/.rels',
      data: `<Relationships xmlns="${NS.relationships}">${packageRelationships}</Relationships>`
    },
    {
      name: 'xl/workbook.xml',
      data: `<workbook xmlns="${NS.spreadsheet}" xmlns:r="${NS.officeRelationships}"><sheets>${workbookSheets}</sheets></workbook>`
    },
    {
      name: 'xl/_rels/workbook.xml.rels',
      data: `<Relationships xmlns="${NS.relationships}">${workbookRelationships}</Relationships>`
    },
    { name: 'xl/worksheets/sheet1.xml', data: sheetXml },
    ...(sharedStringsXml ? [{ name: 'xl/sharedStrings.xml', data: sharedStringsXml }] : []),
    ...extraEntries
  ].map(entry => ({ ...entry, ...(entryOptions[entry.name] || {}) }))
  return buildZip(entries, zipOptions)
}

function worksheetXml(rows, extra = '') {
  return `<worksheet xmlns="${NS.spreadsheet}"><sheetData>${rows}</sheetData>${extra}</worksheet>`
}

function rowXml(number, cells) {
  return `<row r="${number}">${cells.join('')}</row>`
}

function sharedCell(reference, index) {
  return `<c r="${reference}" t="s"><v>${index}</v></c>`
}

function inlineCell(reference, value) {
  return `<c r="${reference}" t="inlineStr"><is><t>${escapeXml(value)}</t></is></c>`
}

function numberCell(reference, value) {
  return `<c r="${reference}"><v>${value}</v></c>`
}

function booleanCell(reference, value) {
  return `<c r="${reference}" t="b"><v>${value}</v></c>`
}

function sharedStringsXml(values) {
  return `<sst xmlns="${NS.spreadsheet}" count="${values.length}" uniqueCount="${values.length}">${values
    .map(value => `<si><t>${escapeXml(value)}</t></si>`)
    .join('')}</sst>`
}

function docxContentTypes({ main = DOCX_MAIN } = {}) {
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="${NS.contentTypes}">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/word/document.xml" ContentType="${main}"/>
</Types>`
}

function docxPackage({ body, extraEntries = [], contentTypes = null, entryOptions = {} } = {}) {
  const entries = [
    { name: '[Content_Types].xml', data: contentTypes || docxContentTypes() },
    {
      name: '_rels/.rels',
      data: `<Relationships xmlns="${NS.relationships}"><Relationship Id="rId1" Type="${NS.officeRelationships}/officeDocument" Target="word/document.xml"/></Relationships>`
    },
    {
      name: 'word/document.xml',
      data: `<w:document xmlns:w="${NS.wordprocessing}"><w:body>${body}</w:body></w:document>`
    },
    ...extraEntries
  ].map(entry => ({ ...entry, ...(entryOptions[entry.name] || {}) }))
  return buildZip(entries)
}

function wordTable(rows) {
  return `<w:tbl>${rows
    .map(row => `<w:tr>${row
      .map(cell => `<w:tc><w:p><w:r><w:t>${escapeXml(cell)}</w:t></w:r></w:p></w:tc>`)
      .join('')}</w:tr>`)
    .join('')}</w:tbl>`
}

function wordParagraph(text) {
  return `<w:p><w:r><w:t>${escapeXml(text)}</w:t></w:r></w:p>`
}

function wordCellXml(text) {
  return wordParagraph(text)
}

function wordRawTable(rows) {
  return `<w:tbl>${rows
    .map(row => `<w:tr>${row.map(cell => `<w:tc>${cell}</w:tc>`).join('')}</w:tr>`)
    .join('')}</w:tbl>`
}

async function expectImportError(promise, code) {
  await assert.rejects(promise, error => {
    assert.equal(error.name, 'ImportFileError')
    assert.equal(error.code, code)
    assert.equal(typeof error.message, 'string')
    return true
  })
}

test('exports documented import limits', () => {
  assert.equal(MAX_IMPORT_FILE_BYTES, 5 * 1024 * 1024)
  assert.equal(MAX_IMPORT_RECORDS, 20)
  assert.equal(IMPORT_LIMITS.maxRecords, 20)
  assert.equal(IMPORT_LIMITS.maxZipEntries, 128)
  assert.equal(IMPORT_LIMITS.maxZipTotalBytes, 20 * 1024 * 1024)
  assert.ok(Object.isFrozen(IMPORT_LIMITS))
})

test('CSV parses a UTF-8 BOM, CRLF, quoted commas, escaped quotes and embedded newlines', async () => {
  const file = textFile(
    'products.csv',
    '\uFEFF"Name","Note"\r\n"Tea, ""green""","line 1\r\nline 2"\r\n"Coffee","ordinary"\r\n'
  )
  const result = await parseImportFile(file)
  assert.equal(result.name, 'products.csv')
  assert.equal(result.format, 'csv')
  assert.deepEqual(result.columns, ['Name', 'Note'])
  assert.deepEqual(result.rows, [
    ['Tea, "green"', 'line 1\r\nline 2'],
    ['Coffee', 'ordinary']
  ])
  assert.deepEqual(result.warnings, [])
})

test('CSV rejects malformed quoting, invalid UTF-8 and inconsistent row widths', async () => {
  await expectImportError(parseImportFile(textFile('bad.csv', 'a,b\n"unterminated')), 'invalid_csv')
  await expectImportError(parseImportFile(bytesFile('bad.csv', Uint8Array.from([0x61, 0xff, 0x62]))), 'invalid_utf8')
  await expectImportError(parseImportFile(bytesFile('utf16.csv', Uint8Array.from([0xff, 0xfe, 0x61, 0x00]))), 'unsupported_encoding')
  await expectImportError(parseImportFile(textFile('wide.csv', 'a,b\n1,2,3')), 'row_too_wide')
  await expectImportError(parseImportFile(textFile('duplicate.csv', 'a,a\n1,2')), 'duplicate_header')
  await expectImportError(parseImportFile(textFile('blank.csv', ',b\n1,2')), 'empty_header')
})

test('CSV distinguishes blank physical lines from explicit quoted-empty records', async () => {
  const explicit = await parseImportFile(textFile('explicit.csv', 'Name\n""\nTea\n'))
  assert.deepEqual(explicit.columns, ['Name'])
  assert.deepEqual(explicit.rows, [[''], ['Tea']])

  const blank = await parseImportFile(textFile('blank-lines.csv', 'Name\n\n\nTea\n'))
  assert.deepEqual(blank.rows, [['Tea']])

  const explicitCrlf = await parseImportFile(textFile('explicit-crlf.csv', 'Name\r\n""\r\nTea\r\n'))
  assert.deepEqual(explicitCrlf.columns, ['Name'])
  assert.deepEqual(explicitCrlf.rows, [[''], ['Tea']])

  await expectImportError(parseImportFile(textFile('empty-header.csv', '""\nTea')), 'empty_header')

  const escapedRows = ['Name', ...Array.from({ length: MAX_IMPORT_RECORDS + 1 }, () => '""')].join('\n')
  await expectImportError(parseImportFile(textFile('explicit-many.csv', escapedRows)), 'too_many_records')
})

test('CSV pads short rows with a warning and never silently drops extra records', async () => {
  const short = await parseImportFile(textFile('short.csv', 'a,b\n1'))
  assert.deepEqual(short.rows, [['1', '']])
  assert.deepEqual(short.warnings.map(warning => warning.code), ['short_rows_padded'])

  const headerOnly = await parseImportFile(textFile('header.csv', 'a,b'))
  assert.deepEqual(headerOnly.rows, [])
  assert.deepEqual(headerOnly.warnings.map(warning => warning.code), ['no_data_rows'])

  const records = ['name']
  for (let index = 0; index < MAX_IMPORT_RECORDS + 1; index += 1) records.push(`row-${index}`)
  await expectImportError(parseImportFile(textFile('many.csv', records.join('\n'))), 'too_many_records')
  await expectImportError(
    parseImportFile(textFile('limited.csv', 'name\na\nb'), { maxRecords: 1 }),
    'too_many_records'
  )
  await expectImportError(
    parseImportFile(textFile('invalid-option.csv', 'name\na'), { maxRecords: MAX_IMPORT_RECORDS + 1 }),
    'invalid_option'
  )
})

test('TXT infers consistent tabular delimiters and keeps a clear warning', async () => {
  const result = await parseImportFile(textFile('products.txt', 'Name\tQty\nTea\t2\nCoffee\t3\n'))
  assert.equal(result.format, 'txt')
  assert.deepEqual(result.columns, ['Name', 'Qty'])
  assert.deepEqual(result.rows, [['Tea', '2'], ['Coffee', '3']])
  assert.deepEqual(result.warnings.map(warning => warning.code), ['txt_delimiter_inferred'])

  const semicolon = await parseImportFile(textFile('products.txt', 'Name;Qty\nTea;2'))
  assert.deepEqual(semicolon.columns, ['Name', 'Qty'])
})

test('TXT one-column input does not invent header names', async () => {
  const result = await parseImportFile(textFile('notes.txt', 'alpha\n\nbeta\n'))
  assert.deepEqual(result.columns, [''])
  assert.deepEqual(result.rows, [['alpha'], ['beta']])
  assert.deepEqual(result.warnings.map(warning => warning.code), ['txt_single_column'])

  const inconsistent = await parseImportFile(textFile('mixed.txt', 'alpha\nbeta\tgamma'))
  assert.deepEqual(inconsistent.columns, [''])
  assert.deepEqual(inconsistent.rows, [['alpha'], ['beta\tgamma']])
  assert.deepEqual(inconsistent.warnings.map(warning => warning.code), ['txt_single_column'])
})

test('TXT rejects empty files only with an explicit warning and bounds text records', async () => {
  const empty = await parseImportFile(textFile('empty.txt', ' \r\n\t\r\n'))
  assert.deepEqual(empty.columns, [])
  assert.deepEqual(empty.rows, [])
  assert.deepEqual(empty.warnings.map(warning => warning.code), ['empty_file'])

  const lines = Array.from({ length: MAX_IMPORT_RECORDS + 1 }, (_, index) => `value-${index}`)
  await expectImportError(parseImportFile(textFile('many.txt', lines.join('\n'))), 'too_many_records')
  await expectImportError(parseImportFile(textFile('markup.txt', '<?xml version="1.0"?><x/>')), 'markup_unsupported')
})

test('XLSX reads the first worksheet, shared strings, numbers and booleans', async () => {
  const sheet = worksheetXml([
    rowXml(1, [sharedCell('A1', 0), sharedCell('B1', 1), sharedCell('C1', 2)]),
    rowXml(2, [sharedCell('A2', 3), numberCell('B2', 2), booleanCell('C2', 1)]),
    rowXml(3, [sharedCell('A3', 4), numberCell('B3', '3.50'), booleanCell('C3', 0)])
  ].join(''))
  const file = bytesFile('products.xlsx', xlsxPackage({
    sheetXml: sheet,
    sharedStringsXml: sharedStringsXml(['Name', 'Qty', 'Active', 'Tea', 'Coffee']),
    extraEntries: [
      { name: 'xl/styles.xml', data: `<styleSheet xmlns="${NS.spreadsheet}"/>` },
      {
        name: 'xl/worksheets/_rels/sheet1.xml.rels',
        data: `<Relationships xmlns="${NS.relationships}"><Relationship Id="rId2" Type="${NS.officeRelationships}/drawing" Target="../drawings/drawing1.xml"/></Relationships>`
      }
    ]
  }))
  const result = await parseImportFile(file)
  assert.equal(result.name, 'products.xlsx')
  assert.equal(result.format, 'xlsx')
  assert.deepEqual(result.columns, ['Name', 'Qty', 'Active'])
  assert.deepEqual(result.rows, [
    ['Tea', '2', 'TRUE'],
    ['Coffee', '3.50', 'FALSE']
  ])
  assert.deepEqual(result.warnings.map(warning => warning.code), ['formatting_not_applied'])
})

test('XLSX decodes SpreadsheetML string escapes including escaped literals and headers', async () => {
  const sheet = worksheetXml([
    rowXml(1, [sharedCell('A1', 0), sharedCell('B1', 1), sharedCell('C1', 2)]),
    rowXml(2, [sharedCell('A2', 3), sharedCell('B2', 4), sharedCell('C2', 5)]),
    rowXml(3, [inlineCell('A3', 'Inline_x000A_Value'), inlineCell('B3', 'Plain'), inlineCell('C3', '_xD83D__xDE00_')])
  ].join(''))
  const strings = sharedStringsXml([
    'Product_x0020_Name',
    'Code',
    'Note',
    'A_x000D_B',
    '_x005F_x0041_',
    '_x005F__x0041_'
  ])
  const result = await parseImportFile(bytesFile('escaped.xlsx', xlsxPackage({
    sheetXml: sheet,
    sharedStringsXml: strings
  })))
  assert.deepEqual(result.columns, ['Product Name', 'Code', 'Note'])
  assert.deepEqual(result.rows, [
    ['A\rB', '_x0041_', '_A'],
    ['Inline\nValue', 'Plain', '😀']
  ])
})

test('XLSX rejects decoded Control escapes and keeps escaped literals in inline headers', async () => {
  const escapedHeader = await parseImportFile(bytesFile('escaped-header.xlsx', xlsxPackage({
    sheetXml: worksheetXml(
      rowXml(1, [inlineCell('A1', 'Code_x005F_x0041_')])
      + rowXml(2, [inlineCell('A2', 'Tea')])
    )
  })))
  assert.deepEqual(escapedHeader.columns, ['Code_x0041_'])
  assert.deepEqual(escapedHeader.rows, [['Tea']])

  await expectImportError(
    parseImportFile(bytesFile('escaped-control.xlsx', xlsxPackage({
      sheetXml: worksheetXml(
        rowXml(1, [inlineCell('A1', 'Name')])
        + rowXml(2, [inlineCell('A2', 'Bad_x0000_Value')])
      )
    }))),
    'invalid_text_control'
  )
})

test('XLSX resolves shared strings from the workbook relationship instead of a conventional path', async () => {
  const sheet = worksheetXml([
    rowXml(1, [sharedCell('A1', 0), sharedCell('B1', 1)]),
    rowXml(2, [sharedCell('A2', 2), sharedCell('B2', 3)])
  ].join(''))
  const contentTypes = xlsxContentTypes({ sharedStrings: true })
    .replace(
      '</Types>',
      `<Override PartName="/custom/strings.xml" ContentType="${SHARED_STRINGS_CONTENT_TYPE}"/></Types>`
    )
  const result = await parseImportFile(bytesFile('custom-strings.xlsx', xlsxPackage({
    sheetXml: sheet,
    contentTypes,
    workbookRelationships: [
      `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
      `<Relationship Id="rId2" Type="${SHARED_STRINGS_REL}" Target="/custom/strings.xml"/>`
    ].join(''),
    extraEntries: [
      { name: 'custom/strings.xml', data: sharedStringsXml(['Name', 'Qty', 'Tea', '2']) },
      { name: 'xl/sharedStrings.xml', data: sharedStringsXml(['Wrong', 'Wrong', 'Wrong', 'Wrong']) }
    ]
  })))
  assert.deepEqual(result.columns, ['Name', 'Qty'])
  assert.deepEqual(result.rows, [['Tea', '2']])

  await expectImportError(
    parseImportFile(bytesFile('ambiguous-strings.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')])),
      contentTypes,
      workbookRelationships: [
        `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
        '<Relationship Id="rId2" Type="' + SHARED_STRINGS_REL + '" Target="/custom/strings.xml"/>',
        '<Relationship Id="rId3" Type="' + SHARED_STRINGS_REL + '" Target="/custom/strings.xml"/>'
      ].join(''),
      extraEntries: [{ name: 'custom/strings.xml', data: sharedStringsXml(['Name']) }]
    }))),
    'invalid_ooxml'
  )

  await expectImportError(
    parseImportFile(bytesFile('wrong-strings-type.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')])),
      contentTypes: xlsxContentTypes(),
      workbookRelationships: [
        `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
        '<Relationship Id="rId2" Type="' + SHARED_STRINGS_REL + '" Target="/custom/strings.xml"/>'
      ].join(''),
      extraEntries: [{ name: 'custom/strings.xml', data: sharedStringsXml(['Name']) }]
    }))),
    'unsupported_ooxml_type'
  )

  await expectImportError(
    parseImportFile(bytesFile('unrelated-strings.xlsx', xlsxPackage({
      sheetXml: worksheetXml([
        rowXml(1, [sharedCell('A1', 0)]),
        rowXml(2, [sharedCell('A2', 1)])
      ].join('')),
      extraEntries: [{ name: 'xl/sharedStrings.xml', data: sharedStringsXml(['Name', 'Tea']) }]
    }))),
    'invalid_ooxml'
  )
})

test('XLSX strictly validates shared string items without shifting indexes', async () => {
  const sheet = worksheetXml([
    rowXml(1, [sharedCell('A1', 0)]),
    rowXml(2, [sharedCell('A2', 1)])
  ].join(''))
  const sst = content => `<sst xmlns="${NS.spreadsheet}" count="1" uniqueCount="1">${content}</sst>`

  await expectImportError(
    parseImportFile(bytesFile('spoofed-shared-text.xlsx', xlsxPackage({
      sheetXml: sheet,
      sharedStringsXml: sst('<si><t xmlns="urn:spoof">LOST</t></si>')
    }))),
    'invalid_ooxml'
  )

  await expectImportError(
    parseImportFile(bytesFile('foreign-shared-item.xlsx', xlsxPackage({
      sheetXml: sheet,
      sharedStringsXml: sst('<si xmlns="urn:spoof"><t>LOST</t></si><si><t>Name</t></si>')
    }))),
    'invalid_ooxml'
  )

  await expectImportError(
    parseImportFile(bytesFile('duplicate-shared-text.xlsx', xlsxPackage({
      sheetXml: sheet,
      sharedStringsXml: sst('<si><t>ONE</t><t>TWO</t></si>')
    }))),
    'unsupported_cell_structure'
  )

  await expectImportError(
    parseImportFile(bytesFile('shared-root-text.xlsx', xlsxPackage({
      sheetXml: sheet,
      sharedStringsXml: sst('LOST<si><t>Name</t></si>')
    }))),
    'invalid_ooxml'
  )

  await expectImportError(
    parseImportFile(bytesFile('shared-root-extension.xlsx', xlsxPackage({
      sheetXml: sheet,
      sharedStringsXml: sst('<extLst/><si><t>Name</t></si>')
    }))),
    'invalid_ooxml'
  )

  const rich = await parseImportFile(bytesFile('rich-shared-text.xlsx', xlsxPackage({
    sheetXml: sheet,
    sharedStringsXml: sst('<si><r><rPr><b/></rPr><t>Rich</t></r><r><t> Text</t></r></si><si><t>Tea</t></si>')
  })))
  assert.deepEqual(rich.columns, ['Rich Text'])
  assert.deepEqual(rich.rows, [['Tea']])
})

test('XLSX also reads stored (uncompressed) ZIP entries', async () => {
  const sheet = worksheetXml([
    rowXml(1, [inlineCell('A1', 'Name'), inlineCell('B1', 'Qty')]),
    rowXml(2, [inlineCell('A2', 'Tea'), numberCell('B2', 2)])
  ].join(''))
  const result = await parseImportFile(bytesFile('stored.xlsx', xlsxPackage({
    sheetXml: sheet,
    zipOptions: { deflate: false }
  })))
  assert.deepEqual(result.columns, ['Name', 'Qty'])
  assert.deepEqual(result.rows, [['Tea', '2']])
})

test('XLSX warns when only the first worksheet is read', async () => {
  const sheet = worksheetXml(rowXml(1, [inlineCell('A1', 'Name')]) + rowXml(2, [inlineCell('A2', 'Tea')]))
  const file = bytesFile('multi.xlsx', xlsxPackage({
    sheetXml: sheet,
    workbookSheets: '<sheet name="One" sheetId="1" r:id="rId1"/><sheet name="Two" sheetId="2" r:id="rId2"/>',
    workbookRelationships: [
      `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
      `<Relationship Id="rId2" Type="${WORKSHEET_REL}" Target="worksheets/sheet2.xml"/>`
    ].join(''),
    extraEntries: [{
      name: 'xl/worksheets/sheet2.xml',
      data: worksheetXml(rowXml(1, [inlineCell('A1', 'Other')]))
    }]
  }))
  const result = await parseImportFile(file)
  assert.deepEqual(result.columns, ['Name'])
  assert.deepEqual(result.rows, [['Tea']])
  assert.deepEqual(result.warnings.map(warning => warning.code), ['first_sheet_only'])
})

test('XLSX rejects formulas, merged cells and unsupported cell values', async () => {
  const formulaSheet = worksheetXml([
    rowXml(1, [inlineCell('A1', 'Name'), inlineCell('B1', 'Qty')]),
    rowXml(2, [inlineCell('A2', 'Tea'), '<c r="B2"><f>1+1</f><v>2</v></c>'])
  ].join(''))
  await expectImportError(
    parseImportFile(bytesFile('formula.xlsx', xlsxPackage({ sheetXml: formulaSheet }))),
    'formula_unsupported'
  )

  const mergedSheet = worksheetXml(
    rowXml(1, [inlineCell('A1', 'Name'), inlineCell('B1', 'Qty')]) + rowXml(2, [inlineCell('A2', 'Tea'), numberCell('B2', 2)]),
    '<mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells>'
  )
  await expectImportError(
    parseImportFile(bytesFile('merged.xlsx', xlsxPackage({ sheetXml: mergedSheet }))),
    'merged_cells_unsupported'
  )

  const errorSheet = worksheetXml(rowXml(1, [inlineCell('A1', 'Name')]) + rowXml(2, ['<c r="A2" t="e"><v>#N/A</v></c>']))
  await expectImportError(
    parseImportFile(bytesFile('error-cell.xlsx', xlsxPackage({ sheetXml: errorSheet }))),
    'invalid_ooxml'
  )

  const tableFormula = `<table xmlns="${NS.spreadsheet}"><tableColumns><tableColumn><calculatedColumnFormula>1+1</calculatedColumnFormula></tableColumn></tableColumns></table>`
  await expectImportError(
    parseImportFile(bytesFile('table-formula.xlsx', xlsxPackage({
      sheetXml: errorSheet.replace('<c r="A2" t="e"><v>#N/A</v></c>', inlineCell('A2', 'Tea')),
      extraEntries: [{ name: 'xl/tables/table1.xml', data: tableFormula }]
    }))),
    'formula_unsupported'
  )

  const renamedTableContentTypes = xlsxContentTypes()
    .replace(
      '</Types>',
      `<Override PartName="/custom/table.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/></Types>`
    )
  await expectImportError(
    parseImportFile(bytesFile('renamed-table-formula.xlsx', xlsxPackage({
      sheetXml: errorSheet.replace('<c r="A2" t="e"><v>#N/A</v></c>', inlineCell('A2', 'Tea')),
      contentTypes: renamedTableContentTypes,
      extraEntries: [{ name: 'custom/table.xml', data: tableFormula }]
    }))),
    'formula_unsupported'
  )

  const defaultWorksheetExtension = xlsxContentTypes()
    .replace(
      '</Types>',
      `<Default Extension="sheet" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>`
    )
  await expectImportError(
    parseImportFile(bytesFile('default-extension-formula.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')])),
      contentTypes: defaultWorksheetExtension,
      extraEntries: [{
        name: 'custom/extra.sheet',
        data: worksheetXml(
          rowXml(1, [inlineCell('A1', 'Other')])
          + rowXml(2, ['<c r="A2"><f>1+1</f><v>2</v></c>'])
        )
      }]
    }))),
    'formula_unsupported'
  )

  const defaultTableExtension = xlsxContentTypes()
    .replace(
      '</Types>',
      `<Default Extension="tbl" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/></Types>`
    )
  await expectImportError(
    parseImportFile(bytesFile('default-extension-table-formula.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')])),
      contentTypes: defaultTableExtension,
      extraEntries: [{ name: 'custom/table.tbl', data: tableFormula }]
    }))),
    'formula_unsupported'
  )
})

test('XLSX validates cell value and inline-string structure strictly', async () => {
  const spoofedValue = worksheetXml(
    rowXml(1, [inlineCell('A1', 'Name')])
    + rowXml(2, ['<c r="A2"><v xmlns="urn:spoof">LOST</v></c>'])
  )
  await expectImportError(
    parseImportFile(bytesFile('spoofed-value.xlsx', xlsxPackage({ sheetXml: spoofedValue }))),
    'invalid_ooxml'
  )

  const nestedValue = worksheetXml(
    rowXml(1, [inlineCell('A1', 'Name')])
    + rowXml(2, ['<c r="A2"><v><t>LOST</t></v></c>'])
  )
  await expectImportError(
    parseImportFile(bytesFile('nested-value.xlsx', xlsxPackage({ sheetXml: nestedValue }))),
    'invalid_ooxml'
  )

  const multipleInline = worksheetXml(
    rowXml(1, [inlineCell('A1', 'Name')])
    + rowXml(2, ['<c r="A2" t="inlineStr"><is><t>One</t></is><is><t>Two</t></is></c>'])
  )
  await expectImportError(
    parseImportFile(bytesFile('multiple-inline.xlsx', xlsxPackage({ sheetXml: multipleInline }))),
    'invalid_ooxml'
  )

  const duplicateInlineText = worksheetXml(
    rowXml(1, [inlineCell('A1', 'Name')])
    + rowXml(2, ['<c r="A2" t="inlineStr"><is><t>One</t><t>Two</t></is></c>'])
  )
  await expectImportError(
    parseImportFile(bytesFile('duplicate-inline-text.xlsx', xlsxPackage({ sheetXml: duplicateInlineText }))),
    'unsupported_cell_structure'
  )

  const richInline = worksheetXml(
    rowXml(1, [inlineCell('A1', 'Name')])
    + rowXml(2, ['<c r="A2" t="inlineStr"><is><r><rPr><b/></rPr><t>Tea</t></r><r><t> Time</t></r></is></c>'])
  )
  const rich = await parseImportFile(bytesFile('rich-inline.xlsx', xlsxPackage({ sheetXml: richInline })))
  assert.deepEqual(rich.columns, ['Name'])
  assert.deepEqual(rich.rows, [['Tea Time']])
})

test('XLSX rejects duplicate data containers, wrong part types and main-part relationship mismatches', async () => {
  const row = rowXml(1, [inlineCell('A1', 'Name')])
  const duplicateSheetData = `<worksheet xmlns="${NS.spreadsheet}"><sheetData>${row}</sheetData><sheetData>${row}</sheetData></worksheet>`
  await expectImportError(
    parseImportFile(bytesFile('duplicate-sheetdata.xlsx', xlsxPackage({ sheetXml: duplicateSheetData }))),
    'invalid_ooxml'
  )

  await expectImportError(
    parseImportFile(bytesFile('wrong-worksheet-type.xlsx', xlsxPackage({
      sheetXml: worksheetXml(row),
      contentTypes: xlsxContentTypes({ worksheetContentType: 'application/x-msdownload' })
    }))),
    'unsupported_ooxml_type'
  )

  await expectImportError(
    parseImportFile(bytesFile('wrong-main-relationship.xlsx', xlsxPackage({
      sheetXml: worksheetXml(row),
      packageRelationships: `<Relationship Id="rId1" Type="${NS.officeRelationships}/officeDocument" Target="custom/workbook.xml"/>`
    }))),
    'invalid_ooxml'
  )

  await expectImportError(
    parseImportFile(bytesFile('duplicate-main-relationship.xlsx', xlsxPackage({
      sheetXml: worksheetXml(row),
      packageRelationships: [
        `<Relationship Id="rId1" Type="${NS.officeRelationships}/officeDocument" Target="xl/workbook.xml"/>`,
        `<Relationship Id="rId2" Type="${NS.officeRelationships}/officeDocument" Target="xl/workbook.xml"/>`
      ].join('')
    }))),
    'invalid_ooxml'
  )

  const worksheetByDefaultType = xlsxContentTypes()
    .replace(/<Override PartName="\/xl\/worksheets\/sheet1\.xml"[^>]*\/>/, '')
    .replace(
      '</Types>',
      `<Default Extension="sheet" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>`
    )
  await expectImportError(
    parseImportFile(bytesFile('default-worksheet-type.xlsx', xlsxPackage({
      sheetXml: worksheetXml(row),
      contentTypes: worksheetByDefaultType
    }))),
    'unsupported_ooxml_type'
  )

  const headerWithEmptyStructuralCell = worksheetXml(
    rowXml(1, [inlineCell('A1', 'Name'), '<c r="B1"/>'])
    + rowXml(2, [inlineCell('A2', 'Tea'), '<c r="B2"/>'])
  )
  await expectImportError(
    parseImportFile(bytesFile('empty-header-cell.xlsx', xlsxPackage({ sheetXml: headerWithEmptyStructuralCell }))),
    'empty_header'
  )
})

test('XLSX rejects formulas in non-selected worksheets and macro sheets hidden behind renamed parts', async () => {
  const firstSheet = worksheetXml(rowXml(1, [inlineCell('A1', 'Name')]) + rowXml(2, [inlineCell('A2', 'Tea')]))
  const secondSheet = worksheetXml(rowXml(1, [inlineCell('A1', 'Other')]) + rowXml(2, ['<c r="A2"><f>1+1</f><v>2</v></c>']))
  await expectImportError(
    parseImportFile(bytesFile('second-sheet-formula.xlsx', xlsxPackage({
      sheetXml: firstSheet,
      workbookSheets: '<sheet name="One" sheetId="1" r:id="rId1"/><sheet name="Two" sheetId="2" r:id="rId2"/>',
      workbookRelationships: [
        `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
        `<Relationship Id="rId2" Type="${WORKSHEET_REL}" Target="worksheets/sheet2.xml"/>`
      ].join(''),
      extraEntries: [{ name: 'xl/worksheets/sheet2.xml', data: secondSheet }]
    }))),
    'formula_unsupported'
  )

  const customWorksheet = worksheetXml(
    rowXml(1, [inlineCell('A1', 'Other')])
    + rowXml(2, ['<c r="A2"><f>1+1</f><v>2</v></c>'])
  )
  const customWorksheetContentTypes = xlsxContentTypes()
    .replace(
      '</Types>',
      `<Override PartName="/custom/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>`
    )
  await expectImportError(
    parseImportFile(bytesFile('renamed-second-sheet-formula.xlsx', xlsxPackage({
      sheetXml: firstSheet,
      workbookSheets: '<sheet name="One" sheetId="1" r:id="rId1"/><sheet name="Two" sheetId="2" r:id="rId2"/>',
      workbookRelationships: [
        `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
        `<Relationship Id="rId2" Type="${WORKSHEET_REL}" Target="/custom/sheet2.xml"/>`
      ].join(''),
      contentTypes: customWorksheetContentTypes,
      extraEntries: [{ name: 'custom/sheet2.xml', data: customWorksheet }]
    }))),
    'formula_unsupported'
  )

  const macroContentTypes = xlsxContentTypes()
    .replace('</Types>', '<Override PartName="/custom/macro.xml" ContentType="application/vnd.ms-excel.macrosheet+xml"/></Types>')
  await expectImportError(
    parseImportFile(bytesFile('renamed-macro-type.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')])),
      contentTypes: macroContentTypes,
      extraEntries: [{ name: 'custom/macro.xml', data: `<macrosheet xmlns="${NS.spreadsheet}"/>` }]
    }))),
    'macro_unsupported'
  )

  await expectImportError(
    parseImportFile(bytesFile('renamed-macro-relationship.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')])),
      workbookRelationships: [
        `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
        '<Relationship Id="rId2" Type="http://schemas.microsoft.com/office/2006/relationships/xlMacrosheet" Target="/custom/macro.xml"/>'
      ].join(''),
      extraEntries: [{ name: 'custom/macro.xml', data: `<macrosheet xmlns="${NS.spreadsheet}"/>` }]
    }))),
    'macro_unsupported'
  )

  const defaultMacroContentTypes = xlsxContentTypes()
    .replace(
      '<Default Extension="xml" ContentType="application/xml"/>',
      '<Default Extension="bin" ContentType="application/vnd.ms-excel.macrosheet+xml"/><Default Extension="xml" ContentType="application/xml"/>'
    )
  await expectImportError(
    parseImportFile(bytesFile('renamed-macro-default-type.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')])),
      contentTypes: defaultMacroContentTypes,
      extraEntries: [{ name: 'custom/macro.bin', data: `<macrosheet xmlns="${NS.spreadsheet}"/>` }]
    }))),
    'macro_unsupported'
  )

  const externalContentTypes = xlsxContentTypes()
    .replace(
      '</Types>',
      `<Override PartName="/custom/external.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.externalLink+xml"/></Types>`
    )
  await expectImportError(
    parseImportFile(bytesFile('renamed-external-type.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')])),
      contentTypes: externalContentTypes,
      extraEntries: [{ name: 'custom/external.xml', data: `<externalLink xmlns="${NS.spreadsheet}"/>` }]
    }))),
    'external_relationship_unsupported'
  )
})

test('OOXML rejects macros, embedded objects, external relationships and markup spoofing', async () => {
  const sheet = worksheetXml(rowXml(1, [inlineCell('A1', 'Name')]))
  await expectImportError(
    parseImportFile(bytesFile('macro.xlsx', xlsxPackage({
      sheetXml: sheet,
      extraEntries: [{ name: 'xl/vbaProject.bin', data: 'not a real macro' }]
    }))),
    'macro_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('embedded.xlsx', xlsxPackage({
      sheetXml: sheet,
      extraEntries: [{ name: 'xl/embeddings/oleObject1.bin', data: 'not a real object' }]
    }))),
    'embedded_object_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('external.xlsx', xlsxPackage({
      sheetXml: sheet,
      workbookRelationships: [
        `<Relationship Id="rId1" Type="${WORKSHEET_REL}" Target="worksheets/sheet1.xml"/>`,
        `<Relationship Id="rId2" Type="${WORKSHEET_REL}" Target="https://example.invalid/sheet.xml" TargetMode="External"/>`
      ].join('')
    }))),
    'external_relationship_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('spoofed.xlsx', encoder.encode('<!DOCTYPE html><html></html>'))),
    'markup_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('encrypted.xlsx', Uint8Array.from([0xd0, 0xcf, 0x11, 0xe0, 0xa1, 0xb1, 0x1a, 0xe1]))),
    'encrypted_office_unsupported'
  )
  await expectImportError(parseImportFile(textFile('legacy.xls', 'not read')), 'legacy_binary_unsupported')
})

test('ZIP handling rejects traversal, duplicate entries, encryption, bombs and corruption', async () => {
  const sheet = worksheetXml(rowXml(1, [inlineCell('A1', 'Name')]))
  await expectImportError(
    parseImportFile(bytesFile('traversal.xlsx', xlsxPackage({
      sheetXml: sheet,
      extraEntries: [{ name: '../evil.xml', data: 'x' }]
    }))),
    'zip_path_unsafe'
  )
  await expectImportError(
    parseImportFile(bytesFile('duplicate.xlsx', xlsxPackage({
      sheetXml: sheet,
      extraEntries: [{ name: '[Content_Types].xml', data: 'duplicate' }]
    }))),
    'zip_duplicate_entry'
  )
  await expectImportError(
    parseImportFile(bytesFile('encrypted.xlsx', xlsxPackage({ sheetXml: sheet, zipOptions: { flags: 1 } }))),
    'zip_encrypted'
  )
  await expectImportError(
    parseImportFile(bytesFile('bomb.xlsx', xlsxPackage({
      sheetXml: sheet,
      entryOptions: { 'xl/workbook.xml': { declaredUncompressedSize: IMPORT_LIMITS.maxZipEntryBytes + 1 } }
    }))),
    'zip_size_limit'
  )
  await expectImportError(
    parseImportFile(bytesFile('total-bomb.xlsx', xlsxPackage({
      sheetXml: sheet,
      extraEntries: [
        { name: 'xl/a.bin', data: 'a', declaredUncompressedSize: IMPORT_LIMITS.maxZipEntryBytes },
        { name: 'xl/b.bin', data: 'b', declaredUncompressedSize: IMPORT_LIMITS.maxZipEntryBytes },
        { name: 'xl/c.bin', data: 'c', declaredUncompressedSize: IMPORT_LIMITS.maxZipEntryBytes }
      ]
    }))),
    'zip_size_limit'
  )
  await expectImportError(
    parseImportFile(bytesFile('truncated.xlsx', xlsxPackage({ sheetXml: sheet }).subarray(0, 20))),
    'invalid_zip'
  )
  await expectImportError(
    parseImportFile(bytesFile('bad-crc.xlsx', xlsxPackage({
      sheetXml: sheet,
      entryOptions: { 'xl/workbook.xml': { badCrc: true } }
    }))),
    'zip_crc_mismatch'
  )
  await expectImportError(
    parseImportFile(bytesFile('bad-directory-crc.xlsx', xlsxPackage({
      sheetXml: sheet,
      extraEntries: [{ name: 'ignored/', data: '', method: 0, checksum: 1 }]
    }))),
    'zip_crc_mismatch'
  )
  await expectImportError(
    parseImportFile(bytesFile('actual-size-mismatch.xlsx', xlsxPackage({
      sheetXml: sheet,
      entryOptions: { 'xl/workbook.xml': { declaredUncompressedSize: 10 } }
    }))),
    'zip_size_mismatch'
  )
  await expectImportError(
    parseImportFile(bytesFile('unused-bad-crc.xlsx', xlsxPackage({
      sheetXml: sheet,
      extraEntries: [{ name: 'extra.xml', data: 'unused', badCrc: true }]
    }))),
    'zip_crc_mismatch'
  )
  await expectImportError(
    parseImportFile(bytesFile('unused-size-mismatch.xlsx', xlsxPackage({
      sheetXml: sheet,
      extraEntries: [{ name: 'extra.xml', data: 'unused', declaredUncompressedSize: 1 }]
    }))),
    'zip_size_mismatch'
  )
  await expectImportError(
    parseImportFile(bytesFile('relationship-traversal.xlsx', xlsxPackage({
      sheetXml: sheet,
      workbookRelationships: [
        '<Relationship Id="rId1" Type="' + WORKSHEET_REL + '" Target="../../evil.xml"/>'
      ].join('')
    }))),
    'zip_path_unsafe'
  )
})

test('ZIP decompression cancels and releases the reader on an in-flight abort', async () => {
  const originalBlob = globalThis.Blob
  const originalDecompressionStream = globalThis.DecompressionStream
  let resolveStarted
  const started = new Promise(resolve => { resolveStarted = resolve })
  let releaseRead
  let cancelCalls = 0
  let releaseCalls = 0
  const reader = {
    read() {
      resolveStarted()
      return new Promise(resolve => { releaseRead = resolve })
    },
    cancel() {
      cancelCalls += 1
      if (releaseRead) releaseRead({ done: true })
      return Promise.resolve()
    },
    releaseLock() {
      releaseCalls += 1
    }
  }
  class ControlledBlob {
    constructor(parts) { this.parts = parts }

    stream() {
      return { pipeThrough: () => ({ getReader: () => reader }) }
    }
  }
  class ControlledDecompressionStream {
    constructor(format) { this.format = format }
  }
  globalThis.Blob = ControlledBlob
  globalThis.DecompressionStream = ControlledDecompressionStream
  try {
    const controller = new AbortController()
    const file = bytesFile('abort-deflate.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, [inlineCell('A1', 'Name')]))
    }))
    const pending = expectImportError(parseImportFile(file, { signal: controller.signal }), 'aborted')
    await started
    controller.abort()
    await pending
    assert.equal(cancelCalls, 1)
    assert.equal(releaseCalls, 1)
  } finally {
    globalThis.Blob = originalBlob
    globalThis.DecompressionStream = originalDecompressionStream
  }
})

test('XML parsing rejects DOCTYPE, custom entities and malformed documents', async () => {
  const sheet = worksheetXml(rowXml(1, [inlineCell('A1', 'Name')]))
  await expectImportError(
    parseImportFile(bytesFile('doctype.xlsx', xlsxPackage({
      sheetXml: sheet,
      sharedStringsXml: '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY x "y">]><sst xmlns="' + NS.spreadsheet + '"><si><t>&x;</t></si></sst>'
    }))),
    'xml_dtd_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('entity.xlsx', xlsxPackage({
      sheetXml: worksheetXml(rowXml(1, ['<c r="A1" t="inlineStr"><is><t>&custom;</t></is></c>']))
    }))),
    'xml_entity_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('malformed.xlsx', xlsxPackage({
      sheetXml: `<worksheet xmlns="${NS.spreadsheet}"><sheetData><row></worksheet>`
    }))),
    'xml_invalid'
  )
})

test('DOCX imports the first table and explicitly warns about prose and additional tables', async () => {
  const body = [
    wordParagraph('This prose is not part of the table.'),
    wordTable([['Name', 'Qty'], ['Tea', '2'], ['Coffee', '3']]),
    wordParagraph('Trailing prose.'),
    wordTable([['Extra', 'Table'], ['ignored', 'row']])
  ].join('')
  const result = await parseImportFile(bytesFile('products.docx', docxPackage({ body })))
  assert.equal(result.format, 'docx')
  assert.deepEqual(result.columns, ['Name', 'Qty'])
  assert.deepEqual(result.rows, [['Tea', '2'], ['Coffee', '3']])
  const warningCodes = result.warnings.map(warning => warning.code)
  assert.ok(warningCodes.includes('docx_prose_ignored'))
  assert.ok(warningCodes.includes('docx_additional_tables_ignored'))
})

test('DOCX applies the same macro, embedded-object and external-relationship checks', async () => {
  await expectImportError(
    parseImportFile(bytesFile('macro.docx', docxPackage({
      body: wordTable([['Name']]),
      extraEntries: [{ name: 'word/vbaProject.bin', data: 'not a real macro' }]
    }))),
    'macro_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('embedded.docx', docxPackage({
      body: wordTable([['Name']]),
      extraEntries: [{ name: 'word/embeddings/oleObject1.bin', data: 'not a real object' }]
    }))),
    'embedded_object_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('external.docx', docxPackage({
      body: wordTable([['Name']]),
      extraEntries: [{
        name: 'word/_rels/document.xml.rels',
        data: `<Relationships xmlns="${NS.relationships}"><Relationship Id="rId1" Type="${NS.officeRelationships}/hyperlink" Target="https://example.invalid/" TargetMode="External"/></Relationships>`
      }]
    }))),
    'external_relationship_unsupported'
  )
})

test('DOCX rejects formula, DDEAUTO and INCLUDETEXT fields, including cached results', async () => {
  const formulaCell = '<w:p><w:fldSimple w:instr="=SUM(ABOVE)"><w:r><w:t>3</w:t></w:r></w:fldSimple></w:p>'
  await expectImportError(
    parseImportFile(bytesFile('formula-field.docx', docxPackage({
      body: wordRawTable([[wordCellXml('Name'), wordCellXml('Value')], [wordCellXml('Tea'), formulaCell]])
    }))),
    'docx_field_unsupported'
  )

  const ddeCell = '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText>DDEAUTO Excel "Sheet1!A1"</w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>cached</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>'
  await expectImportError(
    parseImportFile(bytesFile('dde-field.docx', docxPackage({
      body: wordRawTable([[wordCellXml('Name'), wordCellXml('Value')], [wordCellXml('Tea'), ddeCell]])
    }))),
    'docx_field_unsupported'
  )

  const includeCell = '<w:p><w:fldSimple w:instr="INCLUDETEXT &quot;C:\\\\secret.docx&quot;"><w:r><w:t>cached</w:t></w:r></w:fldSimple></w:p>'
  await expectImportError(
    parseImportFile(bytesFile('include-field.docx', docxPackage({
      body: wordRawTable([[wordCellXml('Name'), wordCellXml('Value')], [wordCellXml('Tea'), includeCell]])
    }))),
    'docx_field_unsupported'
  )

  const safePageField = '<w:p><w:fldSimple w:instr="PAGE"><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p>'
  const safe = await parseImportFile(bytesFile('safe-field.docx', docxPackage({
    body: wordRawTable([[wordCellXml('Name'), wordCellXml('Page')], [wordCellXml('Tea'), safePageField]])
  })))
  assert.deepEqual(safe.rows, [['Tea', '1']])
})

test('DOCX rejects unsafe field instructions in declared and ignored package parts too', async () => {
  const header = `<w:hdr xmlns:w="${NS.wordprocessing}"><w:p><w:fldSimple w:instr="INCLUDETEXT &quot;C:\\\\secret.docx&quot;"><w:r><w:t>cached</w:t></w:r></w:fldSimple></w:p></w:hdr>`
  await expectImportError(
    parseImportFile(bytesFile('header-field.docx', docxPackage({
      body: wordTable([['Name'], ['Tea']]),
      extraEntries: [{ name: 'word/header1.xml', data: header }]
    }))),
    'docx_field_unsupported'
  )

  const renamedHeaderContentTypes = docxContentTypes()
    .replace(
      '</Types>',
      `<Override PartName="/custom/header.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/></Types>`
    )
  await expectImportError(
    parseImportFile(bytesFile('renamed-header-field.docx', docxPackage({
      body: wordTable([['Name'], ['Tea']]),
      contentTypes: renamedHeaderContentTypes,
      extraEntries: [
        {
          name: 'word/_rels/document.xml.rels',
          data: `<Relationships xmlns="${NS.relationships}"><Relationship Id="rId1" Type="${NS.officeRelationships}/header" Target="/custom/header.xml"/></Relationships>`
        },
        { name: 'custom/header.xml', data: header }
      ]
    }))),
    'docx_field_unsupported'
  )

  await expectImportError(
    parseImportFile(bytesFile('renamed-header-no-content-type.docx', docxPackage({
      body: wordTable([['Name'], ['Tea']]),
      extraEntries: [
        {
          name: 'word/_rels/document.xml.rels',
          data: `<Relationships xmlns="${NS.relationships}"><Relationship Id="rId1" Type="${NS.officeRelationships}/header" Target="/custom/header.xml"/></Relationships>`
        },
        { name: 'custom/header.xml', data: header }
      ]
    }))),
    'docx_field_unsupported'
  )

  const safeHeader = `<w:hdr xmlns:w="${NS.wordprocessing}"><w:p><w:r><w:t>Safe header</w:t></w:r></w:p></w:hdr>`
  await expectImportError(
    parseImportFile(bytesFile('renamed-header-wrong-type.docx', docxPackage({
      body: wordTable([['Name'], ['Tea']]),
      extraEntries: [
        {
          name: 'word/_rels/document.xml.rels',
          data: `<Relationships xmlns="${NS.relationships}"><Relationship Id="rId1" Type="${NS.officeRelationships}/header" Target="/custom/header.xml"/></Relationships>`
        },
        { name: 'custom/header.xml', data: safeHeader }
      ]
    }))),
    'unsupported_ooxml_type'
  )

  const footnote = `<w:footnotes xmlns:w="${NS.wordprocessing}"><w:footnote w:id="1"><w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText>DDEAUTO Excel "Sheet1!A1"</w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>cached</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:footnote></w:footnotes>`
  await expectImportError(
    parseImportFile(bytesFile('footnote-field.docx', docxPackage({
      body: wordTable([['Name'], ['Tea']]),
      extraEntries: [{ name: 'word/footnotes.xml', data: footnote }]
    }))),
    'docx_field_unsupported'
  )
})

test('DOCX rejects body-level customXml wrappers instead of dropping their table', async () => {
  const body = `<w:customXml>${wordTable([['Name'], ['Tea']])}</w:customXml>`
  await expectImportError(
    parseImportFile(bytesFile('body-customxml.docx', docxPackage({ body }))),
    'docx_unsupported_structure'
  )
})

test('DOCX preserves wrapped cell paragraphs, no-break hyphens and structural header cells', async () => {
  const wrappedCell = '<w:p><w:r><w:t>First</w:t></w:r></w:p><w:customXml><w:p><w:r><w:t>Second</w:t></w:r></w:p></w:customXml>'
  const hyphenCell = '<w:p><w:r><w:t>A</w:t><w:noBreakHyphen/><w:t>B</w:t></w:r></w:p>'
  const result = await parseImportFile(bytesFile('wrapped.docx', docxPackage({
    body: wordRawTable([[wordCellXml('Name'), wordCellXml('Value')], [wrappedCell, hyphenCell]])
  })))
  assert.deepEqual(result.columns, ['Name', 'Value'])
  assert.deepEqual(result.rows, [['First\nSecond', 'A-B']])

  const emptyHeader = wordRawTable([
    [wordCellXml('Name'), '<w:p/>'],
    [wordCellXml('Tea'), '<w:p/>']
  ])
  await expectImportError(
    parseImportFile(bytesFile('empty-docx-header.docx', docxPackage({ body: emptyHeader }))),
    'empty_header'
  )
})

test('DOCX preserves internal blank paragraphs and rejects symbols and equations', async () => {
  const blankParagraphCell = '<w:p><w:r><w:t>First</w:t></w:r></w:p><w:p/><w:p><w:r><w:t>Second</w:t></w:r></w:p>'
  const result = await parseImportFile(bytesFile('blank-paragraph.docx', docxPackage({
    body: wordRawTable([[wordCellXml('Name'), wordCellXml('Value')], [wordCellXml('Tea'), blankParagraphCell]])
  })))
  assert.deepEqual(result.columns, ['Name', 'Value'])
  assert.deepEqual(result.rows, [['Tea', 'First\n\nSecond']])

  const symbolCell = '<w:p><w:r><w:t>A</w:t><w:sym w:font="Wingdings" w:char="F04A"/><w:t>B</w:t></w:r></w:p>'
  await expectImportError(
    parseImportFile(bytesFile('symbol.docx', docxPackage({
      body: wordRawTable([[wordCellXml('Name'), wordCellXml('Value')], [wordCellXml('Tea'), symbolCell]])
    }))),
    'docx_unsupported_structure'
  )

  const equationCell = '<w:p><w:r><w:t>A</w:t></w:r><m:oMath xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"><m:t>x</m:t></m:oMath><w:r><w:t>B</w:t></w:r></w:p>'
  await expectImportError(
    parseImportFile(bytesFile('equation.docx', docxPackage({
      body: wordRawTable([[wordCellXml('Name'), wordCellXml('Value')], [wordCellXml('Tea'), equationCell]])
    }))),
    'docx_unsupported_structure'
  )
})

test('DOCX rejects prose-only input, merged cells and tracked changes', async () => {
  await expectImportError(
    parseImportFile(bytesFile('prose.docx', docxPackage({ body: wordParagraph('Only prose, no table.') }))),
    'docx_prose_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('merged.docx', docxPackage({
      body: `<w:tbl><w:tr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>Name</w:t></w:r></w:p></w:tc></w:tr></w:tbl>`
    }))),
    'merged_cells_unsupported'
  )
  await expectImportError(
    parseImportFile(bytesFile('tracked.docx', docxPackage({
      body: `<w:p><w:ins w:id="1" w:author="A"><w:r><w:t>changed</w:t></w:r></w:ins></w:p>${wordTable([['Name']])}`
    }))),
    'docx_tracked_changes_unsupported'
  )
})

test('file size and unsupported formats fail before reading file content', async () => {
  let read = false
  const oversized = {
    name: 'too-large.csv',
    size: MAX_IMPORT_FILE_BYTES + 1,
    arrayBuffer: async() => {
      read = true
      return new ArrayBuffer(0)
    }
  }
  await expectImportError(parseImportFile(oversized), 'file_too_large')
  assert.equal(read, false)

  const unsupported = {
    name: 'legacy.xls',
    size: 1,
    arrayBuffer: async() => {
      read = true
      return new ArrayBuffer(0)
    }
  }
  await expectImportError(parseImportFile(unsupported), 'legacy_binary_unsupported')
  assert.equal(read, false)

  const macro = { name: 'macro.xlsm', size: 0, arrayBuffer: async() => new ArrayBuffer(0) }
  await expectImportError(parseImportFile(macro), 'macro_unsupported')
  await expectImportError(parseImportFile(null), 'invalid_file')
})

test('returns sanitized names and rejects non-object options', async () => {
  const result = await parseImportFile(textFile('../folder/notes.txt', 'alpha'))
  assert.equal(result.name, 'notes.txt')
  assert.deepEqual(result.rows, [['alpha']])

  const longName = `${'a'.repeat(250)}.csv`
  const longResult = await parseImportFile(textFile(longName, 'name\nalpha'))
  assert.equal(longResult.name.length, 200)
  assert.ok(longResult.name.endsWith('.csv'))

  const withSignal = await parseImportFile(textFile('signal.csv', 'name\na'), {
    signal: { aborted: false },
    maxRecords: 1
  })
  assert.deepEqual(withSignal.rows, [['a']])

  await expectImportError(
    parseImportFile(textFile('aborted.csv', 'name\na'), { signal: { aborted: true } }),
    'aborted'
  )
  await expectImportError(
    parseImportFile(textFile('bad-signal.csv', 'name\na'), { signal: { aborted: 'no' } }),
    'invalid_option'
  )

  await expectImportError(parseImportFile(textFile('notes.txt', 'alpha'), 'invalid'), 'invalid_option')
  await expectImportError(parseImportFile(textFile('notes.txt', 'alpha'), { maxRecords: 0 }), 'invalid_option')
})

test('parses standard-tool-generated benign XLSX and DOCX compatibility fixtures', async () => {
  const xlsx = readFileSync(new URL('./fixtures/import/sheetjs-products.xlsx', import.meta.url))
  const xlsxResult = await parseImportFile(bytesFile('sheetjs-products.xlsx', xlsx))
  assert.equal(xlsxResult.format, 'xlsx')
  assert.deepEqual(xlsxResult.columns, ['Name', 'Category', 'Code', 'Note', 'Quantity', 'Active'])
  assert.deepEqual(xlsxResult.rows, [
    ['North', 'Tea', 'TEA-001', 'Line one\nLine two', '2', 'TRUE'],
    ['South', 'Coffee', 'COF-002', "O'Brien, Ltd", '3', 'FALSE'],
    ['East', 'Green tea', 'GRN-003', 'He said "hello"', '4', 'TRUE'],
    ['West', 'Herbal', 'HRB-005', '', '5', 'FALSE']
  ])
  assert.deepEqual(xlsxResult.warnings.map(warning => warning.code), ['formatting_not_applied'])

  const docx = readFileSync(new URL('./fixtures/import/pandoc-products.docx', import.meta.url))
  const docxResult = await parseImportFile(bytesFile('pandoc-products.docx', docx))
  assert.equal(docxResult.format, 'docx')
  assert.deepEqual(docxResult.columns, ['Name', 'Category', 'Code', 'Note', 'Quantity', 'Active'])
  assert.deepEqual(docxResult.rows, [
    ['North', 'Tea', 'TEA-001', "O'Brien, Ltd", '2', 'TRUE'],
    ['South', 'Coffee', 'COF-002', 'He said "hello"', '3', 'FALSE'],
    ['East', 'Green tea', 'GRN-003', 'A-B', '4', 'TRUE'],
    ['West', 'Herbal', 'HRB-005', 'Plain note', '5', 'FALSE']
  ])
  assert.deepEqual(docxResult.warnings.map(warning => warning.code), ['docx_ancillary_text_ignored'])
})
