/**
 * Local import parser for the admin bulk-create workbench.
 *
 * The parser reads TXT, CSV, XLSX and DOCX bytes in the browser and returns
 * bounded plain-text cells for UI field mapping. It never uploads or stores the
 * file, never calls the model provider, and never follows relationships,
 * executes formulas or runs macros. Office files are treated as untrusted ZIP
 * and XML input with explicit size, entry, row, cell and string bounds.
 */

const KIB = 1024
const MIB = 1024 * KIB

export const MAX_IMPORT_FILE_BYTES = 5 * MIB
export const MAX_IMPORT_RECORDS = 20
export const MAX_IMPORT_COLUMNS = 64
export const MAX_IMPORT_CELL_CHARS = 4096
export const MAX_IMPORT_WARNINGS = 32
export const MAX_ZIP_ENTRIES = 128
export const MAX_ZIP_ENTRY_BYTES = 8 * MIB
export const MAX_ZIP_TOTAL_BYTES = 20 * MIB
export const MAX_XML_BYTES = 8 * MIB
export const MAX_XML_DEPTH = 64
export const MAX_XML_ELEMENTS = 100_000
export const MAX_XML_ATTRIBUTES = 32
export const MAX_XML_NAME_CHARS = 128
export const MAX_XML_ATTRIBUTE_CHARS = 8192
export const MAX_SHARED_STRINGS = 10_000
export const MAX_SHARED_STRING_CHARS = 1_000_000
export const MAX_XML_TEXT_CHUNKS = 500_000
export const MAX_PACKAGE_XML_ELEMENTS = 250_000
export const MAX_PACKAGE_XML_TEXT_CHUNKS = 750_000

const MAX_ZIP_CENTRAL_DIRECTORY_BYTES = 256 * KIB
const MAX_ZIP_NAME_CHARS = 200
const MAX_ZIP_COMMENT_BYTES = 0xffff
const MAX_TEXT_LINE_CHARS = MAX_IMPORT_CELL_CHARS * MAX_IMPORT_COLUMNS + MAX_IMPORT_COLUMNS
const MAX_RELATIONSHIP_TARGET_CHARS = 2048
const MAX_XML_ENTITY_CHARS = 16

const ZIP_LOCAL_SIGNATURE = 0x04034b50
const ZIP_CENTRAL_SIGNATURE = 0x02014b50
const ZIP_END_SIGNATURE = 0x06054b50
const ZIP64_EXTRA_ID = 0x0001

const NS = Object.freeze({
  contentTypes: 'http://schemas.openxmlformats.org/package/2006/content-types',
  relationships: 'http://schemas.openxmlformats.org/package/2006/relationships',
  officeRelationships: 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
  spreadsheet: 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
  wordprocessing: 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
  math: 'http://schemas.openxmlformats.org/officeDocument/2006/math'
})

const CONTENT_TYPES = Object.freeze({
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
})

const MAIN_PART = Object.freeze({
  xlsx: '/xl/workbook.xml',
  docx: '/word/document.xml'
})

const WORKSHEET_RELATIONSHIP = `${NS.officeRelationships}/worksheet`
const CHARTSHEET_RELATIONSHIP = `${NS.officeRelationships}/chartsheet`
const TABLE_RELATIONSHIP = `${NS.officeRelationships}/table`
const SHARED_STRINGS_RELATIONSHIP = `${NS.officeRelationships}/sharedStrings`
const OFFICE_DOCUMENT_RELATIONSHIP = `${NS.officeRelationships}/officeDocument`
const WORKSHEET_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'
const TABLE_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml'
const SHARED_STRINGS_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sharedstrings+xml'
const WORDPROCESSING_CONTENT_PREFIX = 'application/vnd.openxmlformats-officedocument.wordprocessingml'

const DOCX_FIELD_RELATIONSHIPS = new Map([
  [`${NS.officeRelationships}/header`, { contentType: `${WORDPROCESSING_CONTENT_PREFIX}.header+xml`, root: 'hdr' }],
  [`${NS.officeRelationships}/footer`, { contentType: `${WORDPROCESSING_CONTENT_PREFIX}.footer+xml`, root: 'ftr' }],
  [`${NS.officeRelationships}/footnotes`, { contentType: `${WORDPROCESSING_CONTENT_PREFIX}.footnotes+xml`, root: 'footnotes' }],
  [`${NS.officeRelationships}/endnotes`, { contentType: `${WORDPROCESSING_CONTENT_PREFIX}.endnotes+xml`, root: 'endnotes' }],
  [`${NS.officeRelationships}/comments`, { contentType: `${WORDPROCESSING_CONTENT_PREFIX}.comments+xml`, root: 'comments' }],
  [`${NS.officeRelationships}/glossaryDocument`, { contentType: `${WORDPROCESSING_CONTENT_PREFIX}.document.glossary+xml`, root: 'glossaryDocument' }]
])

const DOCX_FIELD_CONTENT_TYPES = new Map([
  [`${WORDPROCESSING_CONTENT_PREFIX}.document.main+xml`, 'document'],
  [`${WORDPROCESSING_CONTENT_PREFIX}.header+xml`, 'hdr'],
  [`${WORDPROCESSING_CONTENT_PREFIX}.footer+xml`, 'ftr'],
  [`${WORDPROCESSING_CONTENT_PREFIX}.footnotes+xml`, 'footnotes'],
  [`${WORDPROCESSING_CONTENT_PREFIX}.endnotes+xml`, 'endnotes'],
  [`${WORDPROCESSING_CONTENT_PREFIX}.comments+xml`, 'comments'],
  [`${WORDPROCESSING_CONTENT_PREFIX}.document.glossary+xml`, 'glossaryDocument']
])

const UNSAFE_DOCX_FIELD_INSTRUCTION = /\b(?:DDE|DDEAUTO|INCLUDETEXT|INCLUDEPICTURE|INCLUDE|LINK|EMBED|MACROBUTTON|DATABASE|IMPORT|RD|HYPERLINK)\b/i

const FORMAT_BY_EXTENSION = new Map([
  ['txt', 'txt'],
  ['csv', 'csv'],
  ['xlsx', 'xlsx'],
  ['docx', 'docx']
])
const MACRO_EXTENSIONS = new Set(['xlsm', 'xltm', 'xlam', 'docm', 'dotm'])
const LEGACY_BINARY_EXTENSIONS = new Set(['xls', 'xlsb', 'xlt', 'doc', 'dot'])
const UNSUPPORTED_OOXML_EXTENSIONS = new Set(['xltx', 'dotx'])
const MARKUP_EXTENSIONS = new Set(['html', 'htm', 'xhtml', 'xml', 'xsl', 'xslt', 'svg'])

const INVALID_TEXT_CONTROLS = /[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f-\u009f\u2028\u2029]/
const INVALID_XML_CHARS = /[\u0000-\u0008\u000b\u000c\u000e-\u001f\ufffe\uffff]/
const UNSAFE_FILENAME_CONTROLS = /[\u0000-\u001f\u007f-\u009f\u061c\u200e\u200f\u2028\u2029\u202a-\u202e\u2066-\u2069]/g

export const IMPORT_LIMITS = Object.freeze({
  maxFileBytes: MAX_IMPORT_FILE_BYTES,
  maxRecords: MAX_IMPORT_RECORDS,
  maxColumns: MAX_IMPORT_COLUMNS,
  maxCellChars: MAX_IMPORT_CELL_CHARS,
  maxWarnings: MAX_IMPORT_WARNINGS,
  maxZipEntries: MAX_ZIP_ENTRIES,
  maxZipEntryBytes: MAX_ZIP_ENTRY_BYTES,
  maxZipTotalBytes: MAX_ZIP_TOTAL_BYTES,
  maxZipNameChars: MAX_ZIP_NAME_CHARS,
  maxTextLineChars: MAX_TEXT_LINE_CHARS,
  maxRelationshipTargetChars: MAX_RELATIONSHIP_TARGET_CHARS,
  maxXmlBytes: MAX_XML_BYTES,
  maxXmlDepth: MAX_XML_DEPTH,
  maxXmlElements: MAX_XML_ELEMENTS,
  maxXmlAttributes: MAX_XML_ATTRIBUTES,
  maxXmlNameChars: MAX_XML_NAME_CHARS,
  maxXmlAttributeChars: MAX_XML_ATTRIBUTE_CHARS,
  maxSharedStrings: MAX_SHARED_STRINGS,
  maxSharedStringChars: MAX_SHARED_STRING_CHARS,
  maxXmlTextChunks: MAX_XML_TEXT_CHUNKS,
  maxPackageXmlElements: MAX_PACKAGE_XML_ELEMENTS,
  maxPackageXmlTextChunks: MAX_PACKAGE_XML_TEXT_CHUNKS
})

export class ImportFileError extends Error {
  constructor(code, message) {
    super(message)
    this.name = 'ImportFileError'
    this.code = code
  }
}

function fail(code, message) {
  throw new ImportFileError(code, message)
}

function addWarning(warnings, code, message) {
  if (warnings.length >= MAX_IMPORT_WARNINGS || warnings.some(warning => warning.code === code)) return
  warnings.push(Object.freeze({ code, message }))
}

function assertNotAborted(signal) {
  if (signal === undefined || signal === null) return
  if (typeof signal !== 'object' || typeof signal.aborted !== 'boolean') {
    fail('invalid_option', 'signal must be an AbortSignal-compatible object.')
  }
  if (signal.aborted) fail('aborted', 'The import was cancelled.')
}

function importName(value) {
  const raw = typeof value === 'string' ? value : ''
  const basename = raw.replaceAll('\\', '/').split('/').pop() || ''
  const cleaned = basename
    .normalize('NFC')
    .replace(UNSAFE_FILENAME_CONTROLS, '')
    .trim()
  if (!cleaned || cleaned === '.' || cleaned === '..') return 'import'
  if (cleaned.length <= 200) return cleaned
  const dot = cleaned.lastIndexOf('.')
  const extension = dot > 0 && cleaned.length - dot <= 20 ? cleaned.slice(dot) : ''
  return `${cleaned.slice(0, 200 - extension.length)}${extension}`
}

function extensionOf(name) {
  const dot = name.lastIndexOf('.')
  return dot >= 0 ? name.slice(dot + 1).toLowerCase() : ''
}

function resolveFormat(name) {
  const extension = extensionOf(name)
  const format = FORMAT_BY_EXTENSION.get(extension)
  if (format) return format
  if (MACRO_EXTENSIONS.has(extension)) {
    fail('macro_unsupported', 'Macro-enabled Office files are not supported.')
  }
  if (LEGACY_BINARY_EXTENSIONS.has(extension)) {
    fail('legacy_binary_unsupported', 'Legacy binary .xls/.doc files are not supported; save the file as .xlsx or .docx.')
  }
  if (UNSUPPORTED_OOXML_EXTENSIONS.has(extension)) {
    fail('unsupported_format', 'Office template files are not supported; save the file as .xlsx or .docx.')
  }
  if (MARKUP_EXTENSIONS.has(extension)) {
    fail('markup_unsupported', 'HTML, XML and XSL files are not supported import sources.')
  }
  fail('unsupported_format', 'Supported import formats are TXT, CSV, XLSX and DOCX.')
}

function resolveMaxRecords(value) {
  if (value === undefined) return MAX_IMPORT_RECORDS
  if (!Number.isInteger(value) || value < 1 || value > MAX_IMPORT_RECORDS) {
    fail('invalid_option', `maxRecords must be an integer between 1 and ${MAX_IMPORT_RECORDS}.`)
  }
  return value
}

async function readFileBytes(file, signal = undefined) {
  assertNotAborted(signal)
  if (!file || (typeof file !== 'object' && typeof file !== 'function')) {
    fail('invalid_file', 'A file-like object with a name and readable bytes is required.')
  }
  const declaredSize = Number(file.size)
  if (Number.isFinite(declaredSize) && declaredSize > MAX_IMPORT_FILE_BYTES) {
    fail('file_too_large', `Import files are limited to ${MAX_IMPORT_FILE_BYTES} bytes.`)
  }
  let value
  try {
    if (typeof file.arrayBuffer === 'function') value = await file.arrayBuffer()
    else if (file.bytes instanceof Uint8Array) value = file.bytes
    else if (file.data instanceof Uint8Array) value = file.data
    else fail('invalid_file', 'The selected file does not expose readable bytes.')
  } catch (error) {
    if (error instanceof ImportFileError) throw error
    fail('read_failed', 'The selected file could not be read.')
  }
  let bytes
  if (value instanceof Uint8Array) bytes = value
  else if (value instanceof ArrayBuffer) bytes = new Uint8Array(value)
  else if (ArrayBuffer.isView(value)) bytes = new Uint8Array(value.buffer, value.byteOffset, value.byteLength)
  else fail('invalid_file', 'The selected file returned an unsupported byte representation.')
  if (bytes.byteLength > MAX_IMPORT_FILE_BYTES) {
    fail('file_too_large', `Import files are limited to ${MAX_IMPORT_FILE_BYTES} bytes.`)
  }
  assertNotAborted(signal)
  return bytes
}

function decodeUtf8(bytes, label) {
  if (typeof TextDecoder !== 'function') {
    fail('unsupported_runtime', 'This browser does not provide the text decoding required for import.')
  }
  const start = bytes.byteLength >= 2
    ? `${bytes[0].toString(16).padStart(2, '0')}${bytes[1].toString(16).padStart(2, '0')}`
    : ''
  if (start === 'fffe' || start === 'feff' || start === '0000') {
    fail('unsupported_encoding', `${label} must be UTF-8; UTF-16 and UTF-32 files are not supported.`)
  }
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  } catch {
    fail('invalid_utf8', `${label} is not valid UTF-8 text.`)
  }
}

function assertTextControls(text, label) {
  if (INVALID_TEXT_CONTROLS.test(text)) {
    fail('invalid_text_control', `${label} contains unsupported control characters.`)
  }
}

function looksLikeMarkup(bytes) {
  const head = new TextDecoder('utf-8', { fatal: false })
    .decode(bytes.subarray(0, 512))
    .replace(/^\uFEFF/, '')
    .trimStart()
    .toLowerCase()
  return head.startsWith('<')
}

function assertPlainText(text) {
  const head = text.replace(/^\uFEFF/, '').trimStart()
  if (/^<[!?/]/.test(head) || /^<[A-Za-z]/.test(head)) {
    fail('markup_unsupported', 'HTML and XML content is not supported import text.')
  }
}

function assertZipMagic(bytes) {
  if (bytes.byteLength >= 8
    && bytes[0] === 0xd0 && bytes[1] === 0xcf && bytes[2] === 0x11 && bytes[3] === 0xe0
    && bytes[4] === 0xa1 && bytes[5] === 0xb1 && bytes[6] === 0x1a && bytes[7] === 0xe1) {
    fail('encrypted_office_unsupported', 'Encrypted or legacy binary Office files are not supported; use an unencrypted .xlsx or .docx file.')
  }
  if (bytes.byteLength >= 4
    && bytes[0] === 0x50 && bytes[1] === 0x4b && bytes[2] === 0x03 && bytes[3] === 0x04) {
    return
  }
  if (looksLikeMarkup(bytes)) {
    fail('markup_unsupported', 'HTML and XML files are not valid Office Open XML import packages.')
  }
  fail('invalid_ooxml', 'The selected file is not a valid Office Open XML package.')
}

function validateCell(value) {
  if (typeof value !== 'string') fail('invalid_ooxml', 'Import cells must be text.')
  if (value.length > MAX_IMPORT_CELL_CHARS) {
    fail('cell_too_long', `Import cells are limited to ${MAX_IMPORT_CELL_CHARS} characters.`)
  }
  return value
}

function validateColumns(columns) {
  if (columns.length === 0) fail('empty_header', 'The import source does not contain a header row.')
  if (columns.length > MAX_IMPORT_COLUMNS) {
    fail('too_many_columns', `Import sources are limited to ${MAX_IMPORT_COLUMNS} columns.`)
  }
  const seen = new Set()
  for (const column of columns) {
    validateCell(column)
    const key = column.trim()
    if (!key) fail('empty_header', 'The header row contains an empty column name.')
    if (seen.has(key)) fail('duplicate_header', 'The header row contains duplicate column names.')
    seen.add(key)
  }
}

function finalizeRows(records, maxRecords, warnings) {
  if (records.length === 0) {
    addWarning(warnings, 'empty_file', 'The import source does not contain any rows.')
    return { columns: [], rows: [] }
  }
  const columns = records[0]
  validateColumns(columns)
  const data = records.slice(1)
  if (data.length > maxRecords) {
    fail('too_many_records', `Import sources are limited to ${maxRecords} data rows; the file was not truncated.`)
  }
  if (data.length === 0) {
    addWarning(warnings, 'no_data_rows', 'The import source contains a header row but no data rows.')
  }
  let paddedRows = 0
  const rows = data.map(record => {
    if (record.length > columns.length) {
      fail('row_too_wide', 'An import row contains more cells than the header row.')
    }
    const row = record.map(validateCell)
    if (row.length < columns.length) {
      paddedRows += 1
      while (row.length < columns.length) row.push('')
    }
    return row
  })
  if (paddedRows > 0) {
    addWarning(warnings, 'short_rows_padded', `${paddedRows} row(s) contained fewer cells than the header row and were padded with empty cells.`)
  }
  return { columns: [...columns], rows }
}

function parseCsvRecords(text, maxRecords) {
  const records = []
  let row = []
  let field = ''
  let inQuotes = false
  let closedQuote = false
  let recordHasSyntax = false
  let index = 0

  const pushField = () => {
    validateCell(field)
    row.push(field)
    if (row.length > MAX_IMPORT_COLUMNS) {
      fail('too_many_columns', `Import sources are limited to ${MAX_IMPORT_COLUMNS} columns.`)
    }
    field = ''
    closedQuote = false
  }

  const pushRecord = () => {
    pushField()
    if (row.length === 1 && row[0] === '' && !recordHasSyntax) {
      row = []
      recordHasSyntax = false
      return
    }
    records.push(row)
    row = []
    recordHasSyntax = false
    if (records.length > maxRecords + 1) {
      fail('too_many_records', `Import sources are limited to ${maxRecords} data rows; the file was not truncated.`)
    }
  }

  while (index < text.length) {
    const character = text[index]
    if (inQuotes) {
      if (character === '"') {
        if (text[index + 1] === '"') {
          field += '"'
          index += 2
        } else {
          inQuotes = false
          closedQuote = true
          index += 1
        }
      } else {
        field += character
        if (field.length > MAX_IMPORT_CELL_CHARS) {
          fail('cell_too_long', `Import cells are limited to ${MAX_IMPORT_CELL_CHARS} characters.`)
        }
        index += 1
      }
      continue
    }

    if (closedQuote && character !== ',' && character !== '\r' && character !== '\n') {
      fail('invalid_csv', 'A quoted CSV field is followed by unexpected characters.')
    }
    if (character === '"') {
      if (field.length !== 0) fail('invalid_csv', 'A quote appears inside an unquoted CSV field.')
      recordHasSyntax = true
      inQuotes = true
      index += 1
      continue
    }
    if (character === ',') {
      recordHasSyntax = true
      pushField()
      index += 1
      continue
    }
    if (character === '\r' || character === '\n') {
      pushRecord()
      if (character === '\r' && text[index + 1] === '\n') index += 1
      index += 1
      continue
    }
    recordHasSyntax = true
    field += character
    if (field.length > MAX_IMPORT_CELL_CHARS) {
      fail('cell_too_long', `Import cells are limited to ${MAX_IMPORT_CELL_CHARS} characters.`)
    }
    index += 1
  }

  if (inQuotes) fail('invalid_csv', 'A quoted CSV field is not terminated.')
  if (closedQuote || field.length > 0 || row.length > 0 || recordHasSyntax) pushRecord()
  return records
}

function parseCsv(text, maxRecords, warnings) {
  assertTextControls(text, 'CSV input')
  return finalizeRows(parseCsvRecords(text, maxRecords), maxRecords, warnings)
}

function collectNonEmptyLines(text, maxLines) {
  const lines = []
  let start = 0
  for (let index = 0; index <= text.length; index += 1) {
    const atEnd = index === text.length
    const character = atEnd ? '' : text[index]
    if (!atEnd && character !== '\r' && character !== '\n') continue
    const line = text.slice(start, index)
    if (line.length > MAX_TEXT_LINE_CHARS) {
      fail('cell_too_long', `Import text lines are limited to ${MAX_TEXT_LINE_CHARS} characters.`)
    }
    if (line.trim() !== '') {
      lines.push(line)
      if (lines.length > maxLines) {
        fail('too_many_records', 'The import source contains more rows than this version supports; the file was not truncated.')
      }
    }
    if (!atEnd && character === '\r' && text[index + 1] === '\n') index += 1
    start = index + 1
  }
  return lines
}

function detectDelimiter(lines) {
  for (const delimiter of ['\t', ';', '|']) {
    const counts = lines.map(line => line.split(delimiter).length)
    if (counts[0] >= 2 && counts.every(count => count === counts[0])) return delimiter
  }
  return null
}

function delimiterName(delimiter) {
  if (delimiter === '\t') return 'tab'
  if (delimiter === ';') return 'semicolon'
  return 'pipe'
}

function parseTxt(text, maxRecords, warnings) {
  assertTextControls(text, 'TXT input')
  const lines = collectNonEmptyLines(text, maxRecords + 1)
  if (lines.length === 0) {
    addWarning(warnings, 'empty_file', 'The import source does not contain any rows.')
    return { columns: [], rows: [] }
  }

  const delimiter = detectDelimiter(lines)
  if (delimiter) {
    const records = lines.map(line => line.split(delimiter).map(cell => validateCell(cell.trim())))
    addWarning(warnings, 'txt_delimiter_inferred', `Columns were split using the inferred ${delimiterName(delimiter)} delimiter.`)
    return finalizeRows(records, maxRecords, warnings)
  }

  const rows = lines.map(line => [validateCell(line.trim())])
  if (rows.length > maxRecords) {
    fail('too_many_records', `Import sources are limited to ${maxRecords} data rows; the file was not truncated.`)
  }
  addWarning(
    warnings,
    'txt_single_column',
    'No consistent tabular delimiter was found; every non-empty line is imported into one unnamed column and column names must be mapped in the UI.'
  )
  return { columns: [''], rows }
}

function readZipName(bytes, label) {
  let name = ''
  for (const byte of bytes) {
    if (byte >= 0x80) fail('invalid_zip', `${label} contains a non-ASCII ZIP entry name.`)
    name += String.fromCharCode(byte)
  }
  return name
}

function validateZipEntryName(name) {
  if (!name || name.length > MAX_ZIP_NAME_CHARS) fail('invalid_zip', 'A ZIP entry name has an unsupported length.')
  if (/[\u0000-\u001f\u007f]/.test(name)) fail('invalid_zip', 'A ZIP entry name contains control characters.')
  if (name.includes('\\') || name.includes(':') || name.includes('?') || name.includes('#')) {
    fail('zip_path_unsafe', 'A ZIP entry name contains an unsupported path character.')
  }
  if (name.startsWith('/') || name.includes('//')) {
    fail('zip_path_unsafe', 'A ZIP entry name contains an absolute or empty path segment.')
  }
  const path = name.endsWith('/') ? name.slice(0, -1) : name
  if (!path) fail('zip_path_unsafe', 'A ZIP entry name is empty.')
  for (const segment of path.split('/')) {
    if (segment === '' || segment === '.' || segment === '..') {
      fail('zip_path_unsafe', 'A ZIP entry name contains a traversal or empty path segment.')
    }
    if (/^[A-Za-z]:/.test(segment)) {
      fail('zip_path_unsafe', 'A ZIP entry name contains a drive-qualified path.')
    }
  }
  return path
}

function rejectZip64Extra(bytes, offset, length, label) {
  let index = offset
  const end = offset + length
  while (index < end) {
    if (index + 4 > end) fail('invalid_zip', `${label} contains a malformed ZIP extra field.`)
    const id = bytes[index] | (bytes[index + 1] << 8)
    const size = bytes[index + 2] | (bytes[index + 3] << 8)
    if (index + 4 + size > end) fail('invalid_zip', `${label} contains a malformed ZIP extra field.`)
    if (id === ZIP64_EXTRA_ID) fail('zip64_unsupported', 'ZIP64 packages are not supported.')
    index += 4 + size
  }
}

function findEndOfCentralDirectory(bytes, view) {
  if (bytes.byteLength < 22) fail('invalid_zip', 'The ZIP package is truncated.')
  const minimum = Math.max(0, bytes.byteLength - 22 - MAX_ZIP_COMMENT_BYTES)
  for (let offset = bytes.byteLength - 22; offset >= minimum; offset -= 1) {
    if (view.getUint32(offset, true) !== ZIP_END_SIGNATURE) continue
    const commentLength = view.getUint16(offset + 20, true)
    if (offset + 22 + commentLength !== bytes.byteLength) continue
    return offset
  }
  fail('invalid_zip', 'The ZIP package has no valid central directory.')
}

function parseZipDirectory(bytes) {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
  const end = findEndOfCentralDirectory(bytes, view)
  const disk = view.getUint16(end + 4, true)
  const centralDisk = view.getUint16(end + 6, true)
  const diskEntries = view.getUint16(end + 8, true)
  const totalEntries = view.getUint16(end + 10, true)
  const centralSize = view.getUint32(end + 12, true)
  const centralOffset = view.getUint32(end + 16, true)

  if (disk !== 0 || centralDisk !== 0 || diskEntries !== totalEntries) {
    fail('invalid_zip', 'Multi-disk ZIP packages are not supported.')
  }
  if (totalEntries === 0) fail('invalid_zip', 'The ZIP package is empty.')
  if (totalEntries === 0xffff || centralSize === 0xffffffff || centralOffset === 0xffffffff) {
    fail('zip64_unsupported', 'ZIP64 packages are not supported.')
  }
  if (totalEntries > MAX_ZIP_ENTRIES) {
    fail('zip_entry_limit', `ZIP packages are limited to ${MAX_ZIP_ENTRIES} entries.`)
  }
  if (centralSize > MAX_ZIP_CENTRAL_DIRECTORY_BYTES) {
    fail('invalid_zip', 'The ZIP central directory is too large.')
  }
  if (centralOffset + centralSize !== end || centralOffset < 0) {
    fail('invalid_zip', 'The ZIP central directory is inconsistent.')
  }

  const entries = new Map()
  const lowerNames = new Set()
  let offset = centralOffset
  let totalUncompressed = 0

  for (let count = 0; count < totalEntries; count += 1) {
    if (offset + 46 > centralOffset + centralSize) fail('invalid_zip', 'A ZIP central directory entry is truncated.')
    if (view.getUint32(offset, true) !== ZIP_CENTRAL_SIGNATURE) {
      fail('invalid_zip', 'A ZIP central directory entry is malformed.')
    }
    const flags = view.getUint16(offset + 8, true)
    const method = view.getUint16(offset + 10, true)
    const checksum = view.getUint32(offset + 16, true)
    const compressedSize = view.getUint32(offset + 20, true)
    const uncompressedSize = view.getUint32(offset + 24, true)
    const nameLength = view.getUint16(offset + 28, true)
    const extraLength = view.getUint16(offset + 30, true)
    const commentLength = view.getUint16(offset + 32, true)
    const startDisk = view.getUint16(offset + 34, true)
    const localOffset = view.getUint32(offset + 42, true)
    const next = offset + 46 + nameLength + extraLength + commentLength
    if (next > centralOffset + centralSize) fail('invalid_zip', 'A ZIP central directory entry is truncated.')
    if (compressedSize === 0xffffffff || uncompressedSize === 0xffffffff || localOffset === 0xffffffff) {
      fail('zip64_unsupported', 'ZIP64 packages are not supported.')
    }
    if (startDisk !== 0) fail('invalid_zip', 'Multi-disk ZIP packages are not supported.')
    if (flags & 0x0001 || flags & 0x0040 || flags & 0x2000 || (flags & ~0x080e) !== 0) {
      fail('zip_encrypted', 'Encrypted or unsupported ZIP entries are not supported.')
    }
    if (method !== 0 && method !== 8) {
      fail('invalid_zip', 'The ZIP package uses an unsupported compression method.')
    }

    const nameStart = offset + 46
    const nameBytes = bytes.subarray(nameStart, nameStart + nameLength)
    const extraStart = nameStart + nameLength
    rejectZip64Extra(bytes, extraStart, extraLength, 'ZIP entry')
    const name = readZipName(nameBytes, 'The ZIP package')
    const path = validateZipEntryName(name)
    const lowerName = path.toLowerCase()
    if (lowerNames.has(lowerName)) fail('zip_duplicate_entry', 'The ZIP package contains duplicate entry names.')
    lowerNames.add(lowerName)

    if (uncompressedSize > MAX_ZIP_ENTRY_BYTES) {
      fail('zip_size_limit', `ZIP entries are limited to ${MAX_ZIP_ENTRY_BYTES} uncompressed bytes.`)
    }
    totalUncompressed += uncompressedSize
    if (!Number.isSafeInteger(totalUncompressed) || totalUncompressed > MAX_ZIP_TOTAL_BYTES) {
      fail('zip_size_limit', `ZIP packages are limited to ${MAX_ZIP_TOTAL_BYTES} total uncompressed bytes.`)
    }
    if (compressedSize > bytes.byteLength || localOffset >= centralOffset) {
      fail('invalid_zip', 'A ZIP entry points outside the package data.')
    }

    if (localOffset + 30 > centralOffset) fail('invalid_zip', 'A ZIP local entry header is truncated.')
    if (view.getUint32(localOffset, true) !== ZIP_LOCAL_SIGNATURE) {
      fail('invalid_zip', 'A ZIP local entry header is malformed.')
    }
    const localFlags = view.getUint16(localOffset + 6, true)
    const localMethod = view.getUint16(localOffset + 8, true)
    const localChecksum = view.getUint32(localOffset + 14, true)
    const localCompressedSize = view.getUint32(localOffset + 18, true)
    const localUncompressedSize = view.getUint32(localOffset + 22, true)
    const localNameLength = view.getUint16(localOffset + 26, true)
    const localExtraLength = view.getUint16(localOffset + 28, true)
    if (localFlags !== flags || localMethod !== method) {
      fail('invalid_zip', 'A ZIP local entry header does not match its central directory entry.')
    }
    if (localNameLength !== nameLength) {
      fail('invalid_zip', 'A ZIP local entry name does not match its central directory entry.')
    }
    const localNameStart = localOffset + 30
    const localNameEnd = localNameStart + localNameLength
    if (localNameEnd > centralOffset) fail('invalid_zip', 'A ZIP local entry name is truncated.')
    for (let index = 0; index < nameLength; index += 1) {
      if (bytes[localNameStart + index] !== nameBytes[index]) {
        fail('invalid_zip', 'A ZIP local entry name does not match its central directory entry.')
      }
    }
    rejectZip64Extra(bytes, localNameEnd, localExtraLength, 'ZIP local entry')
    const dataOffset = localNameEnd + localExtraLength
    if (dataOffset + compressedSize > centralOffset) {
      fail('invalid_zip', 'A ZIP entry data range overlaps the central directory.')
    }
    const usesDataDescriptor = (flags & 0x0008) !== 0
    if (!usesDataDescriptor
      && (localChecksum !== checksum
        || localCompressedSize !== compressedSize
        || localUncompressedSize !== uncompressedSize)) {
      fail('invalid_zip', 'A ZIP local entry header does not match its central directory entry.')
    }

    const isDirectory = name.endsWith('/')
    if (isDirectory) {
      if (uncompressedSize !== 0 || compressedSize !== 0) {
        fail('invalid_zip', 'A ZIP directory entry contains data.')
      }
      if (checksum !== 0) {
        fail('zip_crc_mismatch', 'A ZIP directory entry failed its integrity check.')
      }
    } else {
      entries.set(name, {
        name,
        method,
        flags,
        checksum,
        compressedSize,
        uncompressedSize,
        dataOffset
      })
    }
    offset = next
  }

  if (offset !== centralOffset + centralSize) {
    fail('invalid_zip', 'The ZIP central directory contains unsupported trailing data.')
  }
  return entries
}

let crcTable

function crc32Update(value, bytes) {
  if (!crcTable) {
    crcTable = new Uint32Array(256)
    for (let index = 0; index < 256; index += 1) {
      let tableValue = index
      for (let bit = 0; bit < 8; bit += 1) {
        tableValue = (tableValue & 1) ? (0xedb88320 ^ (tableValue >>> 1)) : (tableValue >>> 1)
      }
      crcTable[index] = tableValue
    }
  }
  for (const byte of bytes) value = crcTable[(value ^ byte) & 0xff] ^ (value >>> 8)
  return value
}

function crc32(bytes) {
  return (crc32Update(0xffffffff, bytes) ^ 0xffffffff) >>> 0
}

async function readChunkWithAbort(reader, signal, cancelReader) {
  assertNotAborted(signal)
  if (!signal || typeof signal.addEventListener !== 'function' || typeof signal.removeEventListener !== 'function') {
    return reader.read()
  }
  let onAbort
  const aborted = new Promise((_, reject) => {
    onAbort = () => {
      reject(new ImportFileError('aborted', 'The import was cancelled.'))
      void cancelReader()
    }
    signal.addEventListener('abort', onAbort, { once: true })
  })
  const read = reader.read()
  read.catch(() => {})
  try {
    return await Promise.race([read, aborted])
  } finally {
    signal.removeEventListener('abort', onAbort)
  }
}

async function inflateRaw(bytes, maximumBytes, { signal = undefined, retain = true } = {}) {
  if (typeof DecompressionStream !== 'function' || typeof Blob !== 'function') {
    fail('decompression_unsupported', 'This browser does not provide the decompression required for Office import.')
  }
  let stream
  try {
    stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'))
  } catch {
    fail('decompression_unsupported', 'This browser does not provide raw DEFLATE decompression.')
  }
  const reader = stream.getReader()
  const chunks = []
  let total = 0
  let checksum = 0xffffffff
  let cancelPromise = null
  const cancelReader = () => {
    if (!cancelPromise) {
      cancelPromise = (async() => {
        try { await reader.cancel() } catch { /* Cleanup is best effort; the parse failure is authoritative. */ }
      })()
    }
    return cancelPromise
  }
  try {
    while (true) {
      const { done, value } = await readChunkWithAbort(reader, signal, cancelReader)
      if (done) break
      if (!(value instanceof Uint8Array)) fail('invalid_deflate', 'The compressed ZIP entry produced invalid data.')
      total += value.byteLength
      if (total > maximumBytes) {
        fail('zip_size_mismatch', 'A ZIP entry decompressed beyond its declared size.')
      }
      checksum = crc32Update(checksum, value)
      if (retain) chunks.push(value)
    }
  } catch (error) {
    await cancelReader()
    if (error instanceof ImportFileError) throw error
    fail('invalid_deflate', 'A ZIP entry contains invalid compressed data.')
  } finally {
    try { reader.releaseLock() } catch { /* A cancelled stream may already have released the lock. */ }
  }
  let output = null
  if (retain) {
    output = new Uint8Array(total)
    let offset = 0
    for (const chunk of chunks) {
      output.set(chunk, offset)
      offset += chunk.byteLength
    }
  }
  return { output, size: total, checksum: (checksum ^ 0xffffffff) >>> 0 }
}

async function readZipEntry(bytes, entry, budget, { retain = true, signal = undefined } = {}) {
  assertNotAborted(signal)
  if (budget.bytes + entry.uncompressedSize > MAX_ZIP_TOTAL_BYTES) {
    fail('zip_size_limit', `ZIP packages are limited to ${MAX_ZIP_TOTAL_BYTES} total uncompressed bytes.`)
  }
  const compressed = bytes.subarray(entry.dataOffset, entry.dataOffset + entry.compressedSize)
  let output = null
  let size
  let checksum
  if (entry.method === 0) {
    if (entry.compressedSize !== entry.uncompressedSize) {
      fail('zip_size_mismatch', 'A stored ZIP entry has inconsistent sizes.')
    }
    size = compressed.byteLength
    checksum = crc32(compressed)
    if (retain) output = compressed
  } else {
    const inflated = await inflateRaw(compressed, entry.uncompressedSize, { signal, retain })
    size = inflated.size
    checksum = inflated.checksum
    output = inflated.output
  }
  if (size !== entry.uncompressedSize) {
    fail('zip_size_mismatch', 'A ZIP entry did not decompress to its declared size.')
  }
  if (checksum !== entry.checksum) {
    fail('zip_crc_mismatch', 'A ZIP entry failed its integrity check.')
  }
  budget.bytes += size
  if (budget.bytes > MAX_ZIP_TOTAL_BYTES) {
    fail('zip_size_limit', `ZIP packages are limited to ${MAX_ZIP_TOTAL_BYTES} total uncompressed bytes.`)
  }
  return output
}

async function validateAllZipEntries(bytes, zip, signal = undefined) {
  const budget = { bytes: 0 }
  for (const entry of zip.values()) {
    await readZipEntry(bytes, entry, budget, { retain: false, signal })
  }
}

function assertXmlCharacters(value, label) {
  if (INVALID_XML_CHARS.test(value)) {
    fail('xml_invalid', `${label} contains characters that are not allowed in XML.`)
  }
  for (let index = 0; index < value.length; index += 1) {
    const code = value.charCodeAt(index)
    if (code >= 0xd800 && code <= 0xdbff) {
      const next = value.charCodeAt(index + 1)
      if (!(next >= 0xdc00 && next <= 0xdfff)) fail('xml_invalid', `${label} contains an invalid Unicode surrogate.`)
      index += 1
    } else if (code >= 0xdc00 && code <= 0xdfff) {
      fail('xml_invalid', `${label} contains an invalid Unicode surrogate.`)
    }
  }
}

function decodeXmlEntities(value, label) {
  const parts = []
  let index = 0
  let ampersand = value.indexOf('&', index)
  while (ampersand >= 0) {
    parts.push(value.slice(index, ampersand))
    const semicolon = value.indexOf(';', ampersand + 1)
    if (semicolon < 0 || semicolon - ampersand > MAX_XML_ENTITY_CHARS) {
      fail('xml_entity_unsupported', `${label} contains an unsupported XML entity reference.`)
    }
    const reference = value.slice(ampersand + 1, semicolon)
    if (reference === 'amp') parts.push('&')
    else if (reference === 'lt') parts.push('<')
    else if (reference === 'gt') parts.push('>')
    else if (reference === 'quot') parts.push('"')
    else if (reference === 'apos') parts.push("'")
    else if (/^#x[0-9A-Fa-f]{1,6}$/.test(reference) || /^#[0-9]{1,7}$/.test(reference)) {
      const code = reference[1] === 'x'
        ? Number.parseInt(reference.slice(2), 16)
        : Number.parseInt(reference.slice(1), 10)
      if (!(code === 0x9 || code === 0xa || code === 0xd
        || (code >= 0x20 && code <= 0xd7ff)
        || (code >= 0xe000 && code <= 0xfffd)
        || (code >= 0x10000 && code <= 0x10ffff))) {
        fail('xml_entity_unsupported', `${label} contains an invalid XML character reference.`)
      }
      parts.push(String.fromCodePoint(code))
    } else {
      fail('xml_entity_unsupported', `${label} contains an unsupported XML entity reference.`)
    }
    index = semicolon + 1
    ampersand = value.indexOf('&', index)
  }
  parts.push(value.slice(index))
  const output = parts.join('')
  assertXmlCharacters(output, label)
  return output
}

function readXmlName(text, index, label) {
  const chunk = text.slice(index, index + MAX_XML_NAME_CHARS + 1)
  const match = /^[A-Za-z_][A-Za-z0-9_.:-]*/.exec(chunk)
  if (!match) fail('xml_invalid', `${label} contains an invalid XML name.`)
  if (match[0].length > MAX_XML_NAME_CHARS) fail('xml_invalid', `${label} contains an overlong XML name.`)
  return { name: match[0], next: index + match[0].length }
}

function skipXmlWhitespace(text, index) {
  let next = index
  while (next < text.length && (text[next] === ' ' || text[next] === '\t' || text[next] === '\n' || text[next] === '\r')) {
    next += 1
  }
  return next
}

function parseXml(text, label, budget = null) {
  if (text.length > MAX_XML_BYTES) fail('xml_too_large', `${label} is too large.`)
  const document = {
    name: '#document',
    localName: '#document',
    namespaceUri: null,
    attrs: [],
    children: [],
    textChunks: [],
    textLength: 0,
    textCache: null
  }
  const stack = [{ node: document, namespaces: new Map([['xml', 'http://www.w3.org/XML/1998/namespace']]) }]
  let index = 0
  let elements = 0
  let textChunks = 0

  if (text.startsWith('<?xml', index)) {
    const declarationEnd = text.indexOf('?>', index)
    if (declarationEnd < 0) fail('xml_invalid', `${label} contains an unterminated XML declaration.`)
    const declaration = text.slice(index, declarationEnd + 2)
    const encoding = /encoding\s*=\s*(['"])([^'"]+)\1/i.exec(declaration)
    if (encoding && encoding[2].toLowerCase() !== 'utf-8') {
      fail('unsupported_encoding', `${label} uses an unsupported XML encoding.`)
    }
    index = declarationEnd + 2
  } else if (text.startsWith('<?', index)) {
    fail('xml_processing_instruction_unsupported', `${label} contains an unsupported XML processing instruction.`)
  }

  const appendText = (raw, decode = true) => {
    const value = decode ? decodeXmlEntities(raw, label) : raw
    assertXmlCharacters(value, label)
    if (stack.length === 1) {
      if (value.trim() !== '') fail('xml_invalid', `${label} contains text outside its root element.`)
      return
    }
    const node = stack[stack.length - 1].node
    textChunks += 1
    if (textChunks > MAX_XML_TEXT_CHUNKS) fail('xml_too_large', `${label} contains too many XML text fragments.`)
    if (budget) {
      budget.xmlTextChunks += 1
      if (budget.xmlTextChunks > MAX_PACKAGE_XML_TEXT_CHUNKS) {
        fail('xml_too_large', 'The Office package contains too many XML text fragments.')
      }
    }
    node.textChunks.push(value)
    node.textLength += value.length
    if (node.textLength > MAX_XML_BYTES) fail('xml_too_large', `${label} contains too much text.`)
  }

  while (index < text.length) {
    const opening = text.indexOf('<', index)
    if (opening < 0) {
      appendText(text.slice(index))
      break
    }
    if (opening > index) appendText(text.slice(index, opening))
    index = opening

    if (text.startsWith('<!--', index)) {
      const ending = text.indexOf('-->', index + 4)
      if (ending < 0) fail('xml_invalid', `${label} contains an unterminated XML comment.`)
      index = ending + 3
      continue
    }
    if (text.startsWith('<![CDATA[', index)) {
      if (stack.length === 1) fail('xml_invalid', `${label} contains CDATA outside its root element.`)
      const ending = text.indexOf(']]>', index + 9)
      if (ending < 0) fail('xml_invalid', `${label} contains unterminated CDATA.`)
      appendText(text.slice(index + 9, ending), false)
      index = ending + 3
      continue
    }
    if (text.startsWith('<!', index)) {
      fail('xml_dtd_unsupported', `${label} contains an unsupported document type or markup declaration.`)
    }
    if (text.startsWith('<?', index)) {
      fail('xml_processing_instruction_unsupported', `${label} contains an unsupported XML processing instruction.`)
    }
    if (text.startsWith('</', index)) {
      if (stack.length === 1) fail('xml_invalid', `${label} contains an unmatched closing tag.`)
      const parsed = readXmlName(text, index + 2, label)
      const afterName = skipXmlWhitespace(text, parsed.next)
      if (text[afterName] !== '>') fail('xml_invalid', `${label} contains a malformed closing tag.`)
      const current = stack[stack.length - 1]
      if (current.node.name !== parsed.name) fail('xml_invalid', `${label} contains mismatched XML tags.`)
      stack.pop()
      index = afterName + 1
      continue
    }

    const parsedName = readXmlName(text, index + 1, label)
    const attributes = []
    let cursor = parsedName.next
    let selfClosing = false
    while (true) {
      cursor = skipXmlWhitespace(text, cursor)
      if (text[cursor] === '>') {
        cursor += 1
        break
      }
      if (text.startsWith('/>', cursor)) {
        cursor += 2
        selfClosing = true
        break
      }
      if (cursor >= text.length) fail('xml_invalid', `${label} contains an unterminated start tag.`)
      const attribute = readXmlName(text, cursor, label)
      cursor = skipXmlWhitespace(text, attribute.next)
      if (text[cursor] !== '=') fail('xml_invalid', `${label} contains a malformed XML attribute.`)
      cursor = skipXmlWhitespace(text, cursor + 1)
      const quote = text[cursor]
      if (quote !== '"' && quote !== "'") fail('xml_invalid', `${label} contains an unquoted XML attribute.`)
      const valueEnd = text.indexOf(quote, cursor + 1)
      if (valueEnd < 0) fail('xml_invalid', `${label} contains an unterminated XML attribute.`)
      const rawValue = text.slice(cursor + 1, valueEnd)
      if (rawValue.length > MAX_XML_ATTRIBUTE_CHARS) fail('xml_invalid', `${label} contains an overlong XML attribute.`)
      if (rawValue.includes('<')) fail('xml_invalid', `${label} contains a malformed XML attribute.`)
      const value = decodeXmlEntities(rawValue, label)
      if (attributes.some(existing => existing.name === attribute.name)) {
        fail('xml_invalid', `${label} contains duplicate XML attributes.`)
      }
      attributes.push({ name: attribute.name, value })
      if (attributes.length > MAX_XML_ATTRIBUTES) fail('xml_invalid', `${label} contains too many XML attributes.`)
      cursor = valueEnd + 1
    }

    const namespaces = new Map(stack[stack.length - 1].namespaces)
    for (const attribute of attributes) {
      if (attribute.name === 'xmlns') namespaces.set('', attribute.value)
      else if (attribute.name.startsWith('xmlns:')) namespaces.set(attribute.name.slice(6), attribute.value)
    }
    const resolvePrefix = prefix => {
      if (prefix === 'xml') return 'http://www.w3.org/XML/1998/namespace'
      if (!namespaces.has(prefix)) fail('xml_invalid', `${label} uses an undeclared XML namespace prefix.`)
      return namespaces.get(prefix)
    }
    const colon = parsedName.name.indexOf(':')
    const prefix = colon >= 0 ? parsedName.name.slice(0, colon) : ''
    const localName = colon >= 0 ? parsedName.name.slice(colon + 1) : parsedName.name
    if (!localName) fail('xml_invalid', `${label} contains an invalid XML element name.`)
    const namespaceUri = prefix ? resolvePrefix(prefix) : (namespaces.get('') || null)
    const resolvedAttributes = []
    const expandedAttributeNames = new Set()
    for (const attribute of attributes) {
      if (attribute.name === 'xmlns' || attribute.name.startsWith('xmlns:')) continue
      const attributeColon = attribute.name.indexOf(':')
      const attributePrefix = attributeColon >= 0 ? attribute.name.slice(0, attributeColon) : ''
      const attributeLocalName = attributeColon >= 0 ? attribute.name.slice(attributeColon + 1) : attribute.name
      const attributeNamespace = attributePrefix ? resolvePrefix(attributePrefix) : null
      const expandedName = `${attributeNamespace || ''}\u0000${attributeLocalName}`
      if (expandedAttributeNames.has(expandedName)) {
        fail('xml_invalid', `${label} contains duplicate XML namespace attributes.`)
      }
      expandedAttributeNames.add(expandedName)
      resolvedAttributes.push({
        name: attribute.name,
        localName: attributeLocalName,
        namespaceUri: attributeNamespace,
        value: attribute.value
      })
    }
    if (stack.length > MAX_XML_DEPTH) fail('xml_too_deep', `${label} exceeds the maximum XML nesting depth.`)
    elements += 1
    if (elements > MAX_XML_ELEMENTS) fail('xml_too_large', `${label} contains too many XML elements.`)
    if (budget) {
      budget.xmlElements += 1
      if (budget.xmlElements > MAX_PACKAGE_XML_ELEMENTS) {
        fail('xml_too_large', 'The Office package contains too many XML elements.')
      }
    }
    const node = {
      name: parsedName.name,
      localName,
      namespaceUri,
      attrs: resolvedAttributes,
      children: [],
      textChunks: [],
      textLength: 0,
      textCache: null
    }
    stack[stack.length - 1].node.children.push(node)
    if (!selfClosing) stack.push({ node, namespaces })
    index = cursor
  }

  if (stack.length !== 1) fail('xml_invalid', `${label} contains an unclosed XML element.`)
  if (document.children.length !== 1) fail('xml_invalid', `${label} does not contain exactly one XML root element.`)
  return document.children[0]
}

function decodeXmlBytes(bytes, label, budget = null) {
  if (bytes.byteLength > MAX_XML_BYTES) fail('xml_too_large', `${label} is too large.`)
  const text = decodeUtf8(bytes, label)
  return parseXml(text, label, budget)
}

function textOf(node) {
  if (node.textCache === null) node.textCache = node.textChunks.join('')
  return node.textCache
}

function attrNoNamespace(node, localName) {
  return node.attrs.find(attribute => attribute.localName === localName && attribute.namespaceUri === null)?.value
}

function attrInNamespace(node, namespaceUri, localName) {
  return node.attrs.find(attribute => attribute.localName === localName && attribute.namespaceUri === namespaceUri)?.value
}

function childElements(node, localName, namespaceUri = undefined) {
  return node.children.filter(child => child.localName === localName
    && (namespaceUri === undefined || child.namespaceUri === namespaceUri))
}

function hasDescendant(node, localName, namespaceUri) {
  for (const child of node.children) {
    if (child.localName === localName && (!namespaceUri || child.namespaceUri === namespaceUri)) return true
    if (hasDescendant(child, localName, namespaceUri)) return true
  }
  return false
}

function countDescendants(node, localName, namespaceUri) {
  let count = 0
  for (const child of node.children) {
    if (child.localName === localName && (!namespaceUri || child.namespaceUri === namespaceUri)) count += 1
    count += countDescendants(child, localName, namespaceUri)
  }
  return count
}

function assertNamespace(node, namespaceUri, label) {
  if (!node || node.namespaceUri !== namespaceUri) {
    fail('invalid_ooxml', `${label} uses an unsupported XML namespace.`)
  }
}

function createPackageContext(bytes, zip, signal = undefined) {
  return {
    bytes,
    zip,
    signal,
    budget: { bytes: 0, xmlElements: 0, xmlTextChunks: 0 },
    xml: new Map()
  }
}

async function readPackageXml(context, name, required = true) {
  if (context.xml.has(name)) return context.xml.get(name)
  const entry = context.zip.get(name)
  if (!entry) {
    if (!required) return null
    fail('invalid_ooxml', `The import package is missing the required part ${name}.`)
  }
  assertNotAborted(context.signal)
  const bytes = await readZipEntry(context.bytes, entry, context.budget, { signal: context.signal })
  assertNotAborted(context.signal)
  const root = decodeXmlBytes(bytes, name, context.budget)
  context.xml.set(name, root)
  return root
}

function contentTypesHaveExtension(zip, extension) {
  const suffix = `.${extension.toLowerCase()}`
  return [...zip.keys()].some(name => name.toLowerCase().endsWith(suffix))
}

function effectiveContentType(name, contentTypesInfo) {
  const lowerName = name.toLowerCase()
  const override = contentTypesInfo.overrides.get(`/${lowerName}`)
  if (override) return override.contentType
  const dot = lowerName.lastIndexOf('.')
  if (dot < 0 || dot === lowerName.length - 1) return null
  return contentTypesInfo.defaults.get(lowerName.slice(dot + 1)) || null
}

function isPackageInfrastructurePart(name) {
  const lower = name.toLowerCase()
  return lower === '[content_types].xml' || lower.endsWith('.rels')
}

function assertContentTypes(root, label, format, zip) {
  assertNamespace(root, NS.contentTypes, label)
  if (root.localName !== 'Types') fail('invalid_ooxml', `${label} has an unexpected root element.`)
  const mainPart = MAIN_PART[format].toLowerCase()
  const expected = CONTENT_TYPES[format].toLowerCase()
  const seenParts = new Set()
  const overrides = new Map()
  const defaults = new Map()
  let mainContentType = null
  for (const child of root.children) {
    if (child.namespaceUri !== NS.contentTypes) continue
    if (child.localName !== 'Default' && child.localName !== 'Override') {
      fail('invalid_ooxml', `${label} contains an unsupported content type declaration.`)
    }
    const contentType = attrNoNamespace(child, 'ContentType')
    if (!contentType) fail('invalid_ooxml', `${label} contains a content type declaration without a value.`)
    const lowerContentType = contentType.toLowerCase()
    const isMacroContent = lowerContentType.includes('macroenabled')
      || lowerContentType.includes('vbaproject')
      || lowerContentType.includes('macrosheet')
    const isEmbeddedContent = lowerContentType.includes('activex')
      || lowerContentType.includes('oleobject')
    const isExternalContent = lowerContentType.includes('externallink')
      || lowerContentType.includes('connections')
      || lowerContentType.includes('querytable')
    if (child.localName === 'Default') {
      const extension = attrNoNamespace(child, 'Extension')
      if (!extension) fail('invalid_ooxml', `${label} contains a default content type without an extension.`)
      if (isMacroContent && contentTypesHaveExtension(zip, extension)) {
        fail('macro_unsupported', 'Macro-enabled Office files are not supported.')
      }
      if (isEmbeddedContent && contentTypesHaveExtension(zip, extension)) {
        fail('embedded_object_unsupported', 'Office files with embedded ActiveX or OLE objects are not supported.')
      }
      if (isExternalContent && contentTypesHaveExtension(zip, extension)) {
        fail('external_relationship_unsupported', 'Workbooks with external data connections or links are not supported.')
      }
      defaults.set(extension.toLowerCase(), lowerContentType)
      continue
    }
    if (isMacroContent) fail('macro_unsupported', 'Macro-enabled Office files are not supported.')
    if (isEmbeddedContent) {
      fail('embedded_object_unsupported', 'Office files with embedded ActiveX or OLE objects are not supported.')
    }
    if (isExternalContent) {
      fail('external_relationship_unsupported', 'Workbooks with external data connections or links are not supported.')
    }
    const partName = attrNoNamespace(child, 'PartName')
    if (!partName || !partName.startsWith('/')) {
      fail('invalid_ooxml', `${label} contains an invalid part override.`)
    }
    const lowerPartName = partName.toLowerCase()
    if (seenParts.has(lowerPartName)) fail('invalid_ooxml', `${label} contains duplicate part overrides.`)
    seenParts.add(lowerPartName)
    overrides.set(lowerPartName, { partName, contentType: lowerContentType })
    if (lowerPartName === mainPart) mainContentType = lowerContentType
  }
  if (mainContentType !== expected) {
    fail('unsupported_ooxml_type', `The selected file is not a supported ${format.toUpperCase()} document.`)
  }
  return { overrides, defaults }
}

function assertNoUnsupportedParts(zip, format) {
  for (const name of zip.keys()) {
    const lower = name.toLowerCase()
    if (lower === 'vbaproject.bin' || lower.endsWith('/vbaproject.bin')) {
      fail('macro_unsupported', 'Macro-enabled Office files are not supported.')
    }
    if (lower.includes('/activex/') || lower.startsWith('xl/ctrlprops/')) {
      fail('embedded_object_unsupported', 'Office files with ActiveX controls are not supported.')
    }
    if (lower.includes('/embeddings/') || lower.includes('/oleobjects/')) {
      fail('embedded_object_unsupported', 'Office files with embedded objects are not supported.')
    }
    if (format === 'xlsx') {
      if (lower.startsWith('xl/externallinks/') || lower === 'xl/connections.xml' || lower.startsWith('xl/querytables/')) {
        fail('external_relationship_unsupported', 'Workbooks with external data connections or links are not supported.')
      }
      if (lower.startsWith('xl/macrosheets/')) {
        fail('macro_unsupported', 'Macro-enabled Office files are not supported.')
      }
    }
  }
}

function relationshipBaseSegments(label) {
  const segments = label.split('/')
  const marker = segments.lastIndexOf('_rels')
  if (marker >= 0) return segments.slice(0, marker)
  return segments.slice(0, -1)
}

function resolvePackageTarget(target, baseSegments, label) {
  if (!target || target.includes('?')) {
    fail('invalid_ooxml', `${label} contains a relationship target that is not a package part.`)
  }
  const segments = target.startsWith('/') ? target.slice(1).split('/') : [...baseSegments, ...target.split('/')]
  const resolved = []
  for (const segment of segments) {
    if (!segment || segment === '.') {
      fail('zip_path_unsafe', 'An Office relationship target contains an unsafe path segment.')
    }
    if (segment === '..') {
      if (resolved.length === 0) {
        fail('zip_path_unsafe', 'An Office relationship target escapes the package root.')
      }
      resolved.pop()
      continue
    }
    resolved.push(segment)
  }
  if (resolved.length === 0) fail('invalid_ooxml', `${label} contains an empty relationship target.`)
  return resolved.join('/')
}

function parseRelationships(root, label) {
  assertNamespace(root, NS.relationships, label)
  if (root.localName !== 'Relationships') fail('invalid_ooxml', `${label} has an unexpected root element.`)
  const relationships = new Map()
  const baseSegments = relationshipBaseSegments(label)
  for (const child of root.children) {
    if (child.namespaceUri !== NS.relationships || child.localName !== 'Relationship') {
      fail('invalid_ooxml', `${label} contains an unsupported relationship declaration.`)
    }
    const id = attrNoNamespace(child, 'Id')
    const type = attrNoNamespace(child, 'Type')
    const target = attrNoNamespace(child, 'Target')
    const mode = attrNoNamespace(child, 'TargetMode')
    if (!id || !type || !target) fail('invalid_ooxml', `${label} contains an incomplete relationship.`)
    if (target.length > MAX_RELATIONSHIP_TARGET_CHARS) fail('invalid_ooxml', `${label} contains an overlong relationship target.`)
    if (relationships.has(id)) fail('invalid_ooxml', `${label} contains duplicate relationship identifiers.`)
    const lowerType = type.toLowerCase()
    if (lowerType.includes('xlmacrosheet') || lowerType.includes('macrosheet') || lowerType.includes('vbaproject')) {
      fail('macro_unsupported', 'Macro-enabled Office files are not supported.')
    }
    if (lowerType.includes('activex') || lowerType.includes('oleobject') || lowerType.endsWith('/package')) {
      fail('embedded_object_unsupported', 'Office files with embedded ActiveX or OLE objects are not supported.')
    }
    if (lowerType.includes('externallink')
      || lowerType.endsWith('/connections')
      || lowerType.includes('querytable')) {
      fail('external_relationship_unsupported', 'Workbooks with external data connections or links are not supported.')
    }
    const lowerMode = (mode || 'Internal').toLowerCase()
    if (lowerMode !== 'internal' && lowerMode !== 'external') {
      fail('invalid_ooxml', `${label} contains an unsupported relationship target mode.`)
    }
    if (lowerMode === 'external') {
      fail('external_relationship_unsupported', 'Office files with external relationships are not supported.')
    }
    if (/^[A-Za-z][A-Za-z0-9+.-]*:/.test(target) || target.startsWith('//') || target.includes('\\')) {
      fail('external_relationship_unsupported', 'Office files with external relationships are not supported.')
    }
    const resolvedTarget = target.includes('#') ? null : resolvePackageTarget(target, baseSegments, label)
    relationships.set(id, { id, type, target, mode: lowerMode, resolvedTarget })
  }
  return relationships
}

async function assertRelationshipsSafe(context) {
  const relationshipsByLabel = new Map()
  for (const name of context.zip.keys()) {
    if (!name.toLowerCase().endsWith('.rels')) continue
    relationshipsByLabel.set(name, parseRelationships(await readPackageXml(context, name), name))
  }
  return relationshipsByLabel
}

function assertMainPartRelationship(relationshipsByLabel, format) {
  const packageRelationships = relationshipsByLabel.get('_rels/.rels')
  if (!packageRelationships) {
    fail('invalid_ooxml', 'The Office package is missing its package relationships part.')
  }
  const matches = [...packageRelationships.values()]
    .filter(relationship => relationship.type === OFFICE_DOCUMENT_RELATIONSHIP)
  if (matches.length !== 1) {
    fail('invalid_ooxml', 'The Office package must contain exactly one officeDocument relationship.')
  }
  const expected = MAIN_PART[format].slice(1)
  if (matches[0].resolvedTarget !== expected) {
    fail('invalid_ooxml', 'The package officeDocument relationship does not match the expected main part.')
  }
}

function collectTextElements(node, localName) {
  let output = ''
  const visit = current => {
    for (const child of current.children) {
      if (child.namespaceUri !== NS.spreadsheet) {
        visit(child)
        continue
      }
      if (child.localName === 'rPh') continue
      if (child.localName === localName) {
        output += textOf(child)
        if (output.length > MAX_IMPORT_CELL_CHARS) {
          fail('cell_too_long', `Import cells are limited to ${MAX_IMPORT_CELL_CHARS} characters.`)
        }
      } else {
        visit(child)
      }
    }
  }
  visit(node)
  return output
}

function decodeSpreadsheetString(value) {
  let output = ''
  let last = 0
  const pattern = /_x([0-9A-Fa-f]{4})_/g
  let match
  while ((match = pattern.exec(value)) !== null) {
    output += value.slice(last, match.index)
    output += String.fromCharCode(Number.parseInt(match[1], 16))
    last = pattern.lastIndex
  }
  output += value.slice(last)
  return output
}

function assertDecodedCellText(value) {
  for (let index = 0; index < value.length; index += 1) {
    const code = value.charCodeAt(index)
    if (code === 0x09 || code === 0x0a || code === 0x0d) continue
    if (code < 0x20 || (code >= 0x7f && code <= 0x9f)) {
      fail('invalid_text_control', 'Spreadsheet text contains unsupported control characters.')
    }
    if (code >= 0xd800 && code <= 0xdbff) {
      const next = value.charCodeAt(index + 1)
      if (!(next >= 0xdc00 && next <= 0xdfff)) {
        fail('invalid_ooxml', 'Spreadsheet text contains an invalid Unicode surrogate.')
      }
      index += 1
    } else if (code >= 0xdc00 && code <= 0xdfff) {
      fail('invalid_ooxml', 'Spreadsheet text contains an invalid Unicode surrogate.')
    } else if (code === 0xfffe || code === 0xffff) {
      fail('invalid_text_control', 'Spreadsheet text contains an unsupported character.')
    }
  }
  return value
}

function parseSharedStrings(root, label) {
  assertNamespace(root, NS.spreadsheet, label)
  if (root.localName !== 'sst') fail('invalid_ooxml', `${label} has an unexpected root element.`)
  if (root.textChunks.some(chunk => chunk.trim() !== '')) {
    fail('invalid_ooxml', `${label} contains text outside shared string items.`)
  }
  const items = []
  for (const child of root.children) {
    if (child.namespaceUri !== NS.spreadsheet || child.localName !== 'si') {
      fail('invalid_ooxml', `${label} contains an unsupported shared string item.`)
    }
    items.push(child)
  }
  if (items.length > MAX_SHARED_STRINGS) {
    fail('xml_too_large', `Workbooks are limited to ${MAX_SHARED_STRINGS} shared strings.`)
  }
  const strings = []
  let total = 0
  for (const item of items) {
    assertStringContainerStructure(item)
    const value = assertDecodedCellText(decodeSpreadsheetString(collectTextElements(item, 't')))
    total += value.length
    if (total > MAX_SHARED_STRING_CHARS) {
      fail('xml_too_large', `Workbook shared strings exceed ${MAX_SHARED_STRING_CHARS} characters.`)
    }
    strings.push(value)
  }
  return strings
}

function columnIndex(letters) {
  let index = 0
  for (const letter of letters) index = index * 26 + (letter.charCodeAt(0) - 64)
  return index - 1
}

function cellReferenceColumn(reference) {
  const match = /^([A-Z]{1,3})([1-9][0-9]{0,9})$/.exec(reference)
  if (!match) fail('invalid_ooxml', 'A worksheet cell reference is invalid.')
  const index = columnIndex(match[1])
  if (index < 0 || index >= MAX_IMPORT_COLUMNS) {
    fail('too_many_columns', `Import sources are limited to ${MAX_IMPORT_COLUMNS} columns.`)
  }
  return index
}

function assertStringContainerStructure(container) {
  const assertNoContainerText = node => {
    if (node.textChunks.some(chunk => chunk.trim() !== '')) {
      fail('invalid_ooxml', 'String container content must appear inside text elements.')
    }
  }
  const assertTextElement = node => {
    if (node.namespaceUri !== NS.spreadsheet || node.localName !== 't' || node.children.length > 0) {
      fail('unsupported_cell_structure', 'The string container contains an unsupported text element.')
    }
  }
  const visitProperties = node => {
    assertNoContainerText(node)
    for (const child of node.children) {
      if (child.namespaceUri !== NS.spreadsheet) {
        fail('invalid_ooxml', 'The string container contains an unsupported XML namespace.')
      }
      if (child.localName === 't' || child.localName === 'v' || child.localName === 'is') {
        fail('unsupported_cell_structure', 'The string container contains an unsupported value inside formatting properties.')
      }
      visitProperties(child)
    }
  }
  const visitRun = node => {
    assertNoContainerText(node)
    let sawProperties = false
    let sawText = false
    for (const child of node.children) {
      if (child.namespaceUri !== NS.spreadsheet) {
        fail('invalid_ooxml', 'The string container contains an unsupported XML namespace.')
      }
      if (child.localName === 't') {
        if (sawText) fail('unsupported_cell_structure', 'The string run contains multiple text elements.')
        sawText = true
        assertTextElement(child)
      } else if (child.localName === 'rPr') {
        if (sawProperties) fail('unsupported_cell_structure', 'The string run contains multiple properties containers.')
        sawProperties = true
        visitProperties(child)
      } else {
        fail('unsupported_cell_structure', 'The string container contains an unsupported run element.')
      }
    }
  }
  const visitPhonetic = node => {
    assertNoContainerText(node)
    let sawText = false
    for (const child of node.children) {
      if (child.namespaceUri !== NS.spreadsheet || child.localName !== 't') {
        fail('unsupported_cell_structure', 'The string container contains an unsupported phonetic element.')
      }
      if (sawText) fail('unsupported_cell_structure', 'The string container phonetic run contains multiple text elements.')
      sawText = true
      assertTextElement(child)
    }
  }

  assertNoContainerText(container)
  let sawText = false
  let sawRun = false
  let sawPhoneticProperties = false
  for (const child of container.children) {
    if (child.namespaceUri !== NS.spreadsheet) {
      fail('invalid_ooxml', 'The string container contains an unsupported XML namespace.')
    }
    if (child.localName === 't') {
      if (sawText || sawRun) fail('unsupported_cell_structure', 'The string container mixes or duplicates text containers.')
      sawText = true
      assertTextElement(child)
    } else if (child.localName === 'r') {
      if (sawText) fail('unsupported_cell_structure', 'The string container mixes plain text and text runs.')
      sawRun = true
      visitRun(child)
    } else if (child.localName === 'rPh') {
      visitPhonetic(child)
    } else if (child.localName === 'phoneticPr') {
      if (sawPhoneticProperties) fail('unsupported_cell_structure', 'The string container contains multiple phonetic properties containers.')
      sawPhoneticProperties = true
      assertNoContainerText(child)
      if (child.children.length > 0) {
        fail('unsupported_cell_structure', 'The string container contains an unsupported phonetic properties structure.')
      }
    } else {
      fail('unsupported_cell_structure', 'The string container contains an unsupported element.')
    }
  }
}

function validateCellStructure(cell, type) {
  if (cell.textChunks.some(chunk => chunk.trim() !== '')) {
    fail('invalid_ooxml', 'A worksheet cell contains text outside its value containers.')
  }
  let value = null
  let inline = null
  let formula = null
  for (const child of cell.children) {
    if (child.namespaceUri !== NS.spreadsheet) {
      fail('invalid_ooxml', 'A worksheet cell contains an unsupported XML namespace.')
    }
    if (child.localName === 'v') {
      if (value) fail('invalid_ooxml', 'A worksheet cell contains multiple value containers.')
      value = child
    } else if (child.localName === 'is') {
      if (inline) fail('invalid_ooxml', 'A worksheet cell contains multiple inline string containers.')
      inline = child
    } else if (child.localName === 'f') {
      if (formula) fail('invalid_ooxml', 'A worksheet cell contains multiple formula containers.')
      formula = child
    } else {
      fail('unsupported_cell_structure', 'The worksheet contains an unsupported cell element.')
    }
  }
  if (value && inline) {
    fail('invalid_ooxml', 'A worksheet cell contains both stored and inline values.')
  }
  if (value && value.children.length > 0) {
    fail('invalid_ooxml', 'A worksheet value container contains child elements.')
  }
  if (formula && formula.children.length > 0) {
    fail('unsupported_cell_structure', 'A worksheet formula container contains unsupported child elements.')
  }
  if (inline) {
    if (type !== 'inlineStr') {
      fail('invalid_ooxml', 'An inline string container requires an inline string cell type.')
    }
    assertStringContainerStructure(inline)
  } else if (type === 'inlineStr') {
    fail('invalid_ooxml', 'An inline string cell must contain exactly one inline string container.')
  }
  return { value, inline }
}

function cellText(cell, sharedStrings, warnings) {
  if (hasDescendant(cell, 'f', NS.spreadsheet)) {
    fail('formula_unsupported', 'Spreadsheet formulas are not supported; convert formulas to values before importing.')
  }
  const type = attrNoNamespace(cell, 't') || 'n'
  const containers = validateCellStructure(cell, type)
  const value = containers.value ? textOf(containers.value) : ''
  if (type === 's') {
    if (!sharedStrings) fail('invalid_ooxml', 'A worksheet references shared strings that are not present.')
    if (!/^[0-9]+$/.test(value)) fail('invalid_ooxml', 'A shared string reference is invalid.')
    const index = Number(value)
    if (!Number.isSafeInteger(index) || index < 0 || index >= sharedStrings.length) {
      fail('invalid_ooxml', 'A shared string reference points outside the shared string table.')
    }
    return validateCell(sharedStrings[index])
  }
  if (type === 'inlineStr') {
    return validateCell(assertDecodedCellText(decodeSpreadsheetString(collectTextElements(containers.inline, 't'))))
  }
  if (type === 'str') {
    return validateCell(assertDecodedCellText(decodeSpreadsheetString(value)))
  }
  if (type === 'b') {
    if (value === '1') return 'TRUE'
    if (value === '0') return 'FALSE'
    fail('invalid_ooxml', 'A boolean worksheet cell has an invalid value.')
  }
  if (type === 'n') return validateCell(value)
  if (type === 'd') {
    addWarning(warnings, 'date_cells_not_normalized', 'Date cells are returned as their stored text; apply date formatting during field mapping.')
    return validateCell(value)
  }
  if (type === 'e') fail('invalid_ooxml', 'Spreadsheet error cells are not supported.')
  fail('unsupported_cell_type', 'The worksheet contains an unsupported cell type.')
}

function trimTrailingEmptyCells(cells) {
  let length = cells.length
  while (length > 0 && cells[length - 1] === '') length -= 1
  cells.length = length
  return cells
}

function parseWorksheet(root, sharedStrings, maxRecords, warnings) {
  assertNamespace(root, NS.spreadsheet, 'The worksheet part')
  if (root.localName !== 'worksheet') fail('invalid_ooxml', 'The worksheet part has an unexpected root element.')
  if (hasDescendant(root, 'mergeCell', NS.spreadsheet)) {
    fail('merged_cells_unsupported', 'Merged worksheet cells are not supported; unmerge cells before importing.')
  }
  if (hasDescendant(root, 'externalReference', NS.spreadsheet)
    || hasDescendant(root, 'externalReferences', NS.spreadsheet)) {
    fail('external_relationship_unsupported', 'Workbooks with external references are not supported.')
  }
  const sheetDataContainers = childElements(root, 'sheetData', NS.spreadsheet)
  if (sheetDataContainers.length !== 1) {
    fail('invalid_ooxml', 'The worksheet must contain exactly one sheet data container.')
  }
  const sheetData = sheetDataContainers[0]
  const rows = childElements(sheetData, 'row', NS.spreadsheet)
  if (countDescendants(sheetData, 'row', NS.spreadsheet) !== rows.length) {
    fail('invalid_ooxml', 'The worksheet contains rows in an unsupported structure.')
  }
  for (const child of sheetData.children) {
    if (child.namespaceUri !== NS.spreadsheet) {
      fail('invalid_ooxml', 'The worksheet contains data in an unsupported XML namespace.')
    }
  }

  const records = []
  for (const row of rows) {
    const cells = childElements(row, 'c', NS.spreadsheet)
    if (countDescendants(row, 'c', NS.spreadsheet) !== cells.length) {
      fail('invalid_ooxml', 'The worksheet contains cells in an unsupported structure.')
    }
    for (const child of row.children) {
      if (child.namespaceUri !== NS.spreadsheet) {
        fail('invalid_ooxml', 'A worksheet row contains data in an unsupported XML namespace.')
      }
    }
    if (cells.length === 0) continue
    const values = []
    let nextIndex = 0
    for (const cell of cells) {
      let index = nextIndex
      const reference = attrNoNamespace(cell, 'r')
      if (reference) {
        index = cellReferenceColumn(reference)
        if (index < nextIndex) fail('invalid_ooxml', 'Worksheet cells are not in column order.')
      }
      if (index >= MAX_IMPORT_COLUMNS) {
        fail('too_many_columns', `Import sources are limited to ${MAX_IMPORT_COLUMNS} columns.`)
      }
      const value = cellText(cell, sharedStrings, warnings)
      while (values.length < index) values.push('')
      values[index] = value
      nextIndex = index + 1
    }
    if (records.length > 0) trimTrailingEmptyCells(values)
    if (values.every(value => value === '')) continue
    records.push(values)
    if (records.length > maxRecords + 1) {
      fail('too_many_records', `Import sources are limited to ${maxRecords} data rows; the file was not truncated.`)
    }
  }
  return finalizeRows(records, maxRecords, warnings)
}

function collectWorksheetParts(zip, contentTypesInfo, relationshipsByLabel) {
  const parts = new Set()
  for (const name of zip.keys()) {
    if (/^xl\/worksheets\/[^/]+\.xml$/i.test(name)
      || (!isPackageInfrastructurePart(name)
        && effectiveContentType(name, contentTypesInfo) === WORKSHEET_CONTENT_TYPE)) {
      parts.add(name)
    }
  }
  for (const relationships of relationshipsByLabel.values()) {
    for (const relationship of relationships.values()) {
      if (relationship.type === WORKSHEET_RELATIONSHIP && relationship.resolvedTarget) {
        parts.add(relationship.resolvedTarget)
      }
    }
  }
  for (const override of contentTypesInfo.overrides.values()) {
    if (override.contentType === WORKSHEET_CONTENT_TYPE) parts.add(override.partName.slice(1))
  }
  return parts
}

async function assertNoWorksheetFormulas(context, worksheetParts) {
  for (const name of worksheetParts) {
    if (!context.zip.has(name)) {
      fail('invalid_ooxml', `The worksheet part ${name} declared by the package is missing.`)
    }
    const root = await readPackageXml(context, name)
    assertNamespace(root, NS.spreadsheet, name)
    if (root.localName !== 'worksheet') {
      fail('invalid_ooxml', 'A worksheet relationship points to a part that is not a worksheet.')
    }
    if (hasDescendant(root, 'f', NS.spreadsheet)) {
      fail('formula_unsupported', 'Spreadsheet formulas are not supported; convert formulas to values before importing.')
    }
  }
}

function collectTableParts(zip, contentTypesInfo, relationshipsByLabel) {
  const parts = new Set()
  for (const name of zip.keys()) {
    if (/^xl\/tables\/[^/]+\.xml$/i.test(name)
      || (!isPackageInfrastructurePart(name)
        && effectiveContentType(name, contentTypesInfo) === TABLE_CONTENT_TYPE)) {
      parts.add(name)
    }
  }
  for (const relationships of relationshipsByLabel.values()) {
    for (const relationship of relationships.values()) {
      if (relationship.type === TABLE_RELATIONSHIP && relationship.resolvedTarget) {
        parts.add(relationship.resolvedTarget)
      }
    }
  }
  for (const override of contentTypesInfo.overrides.values()) {
    if (override.contentType === TABLE_CONTENT_TYPE) parts.add(override.partName.slice(1))
  }
  return parts
}

async function assertNoTableFormulas(context, contentTypesInfo, relationshipsByLabel) {
  for (const name of collectTableParts(context.zip, contentTypesInfo, relationshipsByLabel)) {
    if (!context.zip.has(name)) {
      fail('invalid_ooxml', `The table part ${name} declared by the package is missing.`)
    }
    const root = await readPackageXml(context, name)
    assertNamespace(root, NS.spreadsheet, name)
    if (root.localName !== 'table') {
      fail('invalid_ooxml', 'A table relationship points to a part that is not a table.')
    }
    if (hasDescendant(root, 'calculatedColumnFormula', NS.spreadsheet)
      || hasDescendant(root, 'totalsRowFormula', NS.spreadsheet)
      || hasDescendant(root, 'f', NS.spreadsheet)) {
      fail('formula_unsupported', 'Workbook table formulas are not supported; convert formulas to values before importing.')
    }
  }
}

async function readSharedStrings(context, workbookRelationships, contentTypesInfo) {
  const relationships = [...workbookRelationships.values()]
    .filter(relationship => relationship.type === SHARED_STRINGS_RELATIONSHIP)
  if (relationships.length === 0) return null
  if (relationships.length > 1) {
    fail('invalid_ooxml', 'The workbook must not declare more than one shared string table relationship.')
  }
  const relationship = relationships[0]
  if (!relationship.resolvedTarget) {
    fail('invalid_ooxml', 'The shared string table relationship does not point to a package part.')
  }
  if (!context.zip.has(relationship.resolvedTarget)) {
    fail('invalid_ooxml', 'The shared string table referenced by the workbook is missing.')
  }
  if (effectiveContentType(relationship.resolvedTarget, contentTypesInfo) !== SHARED_STRINGS_CONTENT_TYPE) {
    fail('unsupported_ooxml_type', 'The selected shared string table part is not a supported shared string table.')
  }
  return parseSharedStrings(
    await readPackageXml(context, relationship.resolvedTarget),
    relationship.resolvedTarget
  )
}

async function parseXlsx(bytes, maxRecords, warnings, signal = undefined) {
  assertZipMagic(bytes)
  const zip = parseZipDirectory(bytes)
  await validateAllZipEntries(bytes, zip, signal)
  assertNoUnsupportedParts(zip, 'xlsx')
  const context = createPackageContext(bytes, zip, signal)
  const contentTypes = await readPackageXml(context, '[Content_Types].xml')
  const contentTypesInfo = assertContentTypes(contentTypes, '[Content_Types].xml', 'xlsx', zip)
  const relationshipsByLabel = await assertRelationshipsSafe(context)
  assertMainPartRelationship(relationshipsByLabel, 'xlsx')
  await assertNoTableFormulas(context, contentTypesInfo, relationshipsByLabel)
  await assertNoWorksheetFormulas(context, collectWorksheetParts(zip, contentTypesInfo, relationshipsByLabel))

  const workbook = await readPackageXml(context, 'xl/workbook.xml')
  assertNamespace(workbook, NS.spreadsheet, 'xl/workbook.xml')
  if (workbook.localName !== 'workbook') fail('invalid_ooxml', 'The workbook part has an unexpected root element.')
  if (childElements(workbook, 'chartsheets', NS.spreadsheet).length > 0) {
    fail('unsupported_ooxml_type', 'Workbooks with chartsheets are not supported.')
  }
  if (childElements(workbook, 'externalReferences', NS.spreadsheet).length > 0) {
    fail('external_relationship_unsupported', 'Workbooks with external references are not supported.')
  }
  const sheetContainers = childElements(workbook, 'sheets', NS.spreadsheet)
  if (sheetContainers.length !== 1) fail('invalid_ooxml', 'The workbook does not contain a sheet collection.')
  const sheets = childElements(sheetContainers[0], 'sheet', NS.spreadsheet)
  if (sheets.length === 0) fail('invalid_ooxml', 'The workbook does not contain any worksheets.')

  const workbookRelationships = parseRelationships(
    await readPackageXml(context, 'xl/_rels/workbook.xml.rels'),
    'xl/_rels/workbook.xml.rels'
  )
  const worksheetTargets = []
  for (const sheet of sheets) {
    const relationshipId = attrInNamespace(sheet, NS.officeRelationships, 'id')
    if (!relationshipId) fail('invalid_ooxml', 'A workbook sheet has no relationship identifier.')
    const relationship = workbookRelationships.get(relationshipId)
    if (!relationship) fail('invalid_ooxml', 'A workbook sheet relationship is missing.')
    if (relationship.type === CHARTSHEET_RELATIONSHIP) continue
    if (relationship.type !== WORKSHEET_RELATIONSHIP) {
      fail('unsupported_ooxml_type', 'The workbook contains an unsupported sheet type.')
    }
    if (!relationship.resolvedTarget) {
      fail('invalid_ooxml', 'A worksheet relationship does not point to a package part.')
    }
    worksheetTargets.push(relationship.resolvedTarget)
  }
  const worksheetTarget = worksheetTargets[0]
  if (!worksheetTarget) fail('unsupported_ooxml_type', 'The workbook does not contain a readable worksheet.')
  if (!zip.has(worksheetTarget)) fail('invalid_ooxml', 'The worksheet part referenced by the workbook is missing.')
  const worksheetContentType = contentTypesInfo.overrides.get(`/${worksheetTarget.toLowerCase()}`)
  if (!worksheetContentType || worksheetContentType.contentType !== WORKSHEET_CONTENT_TYPE) {
    fail('unsupported_ooxml_type', 'The selected worksheet part is not a supported spreadsheet worksheet.')
  }

  const sharedStrings = await readSharedStrings(context, workbookRelationships, contentTypesInfo)
  const worksheet = await readPackageXml(context, worksheetTarget)
  if (sheets.length > 1) {
    addWarning(warnings, 'first_sheet_only', `The workbook contains ${sheets.length} sheets; only the first worksheet was read.`)
  }
  if (zip.has('xl/styles.xml')) {
    addWarning(warnings, 'formatting_not_applied', 'Cell number formats and styles are not applied; cells are returned as stored text.')
  }
  if (zip.keys().some(name => /^xl\/(comments[^/]*|threadedComments\/[^/]+)\.xml$/i.test(name))) {
    addWarning(warnings, 'xlsx_comments_ignored', 'Worksheet comments are not imported.')
  }
  if (zip.keys().some(name => /^xl\/tables\//i.test(name))) {
    addWarning(warnings, 'xlsx_table_definitions_not_applied', 'Workbook table definitions are not applied to field mapping; cached cell values are imported as stored text.')
  }
  if (zip.keys().some(name => /^xl\/(drawings|media)\//i.test(name))) {
    addWarning(warnings, 'xlsx_drawing_content_ignored', 'Worksheet drawings, images and shapes are not imported.')
  }
  return parseWorksheet(worksheet, sharedStrings, maxRecords, warnings)
}

function assertSafeDocxFieldInstruction(instruction) {
  const trimmed = instruction.trim()
  if (!trimmed) {
    fail('docx_field_unsupported', 'DOCX field instructions must not be empty.')
  }
  if (trimmed.startsWith('=') || UNSAFE_DOCX_FIELD_INSTRUCTION.test(trimmed)) {
    fail('docx_field_unsupported', 'DOCX formula and external-data field instructions are not supported.')
  }
}

function assertNoUnsafeDocxFields(root) {
  const fields = []
  const visit = node => {
    for (const child of node.children) {
      if (child.namespaceUri !== NS.wordprocessing) {
        visit(child)
        continue
      }
      if (child.localName === 'fldSimple') {
        const instruction = attrInNamespace(child, NS.wordprocessing, 'instr')
        if (instruction === undefined) {
          fail('docx_field_unsupported', 'DOCX simple fields must include an instruction.')
        }
        assertSafeDocxFieldInstruction(instruction)
        visit(child)
        continue
      }
      if (child.localName === 'fldChar') {
        const type = attrInNamespace(child, NS.wordprocessing, 'fldCharType')
        const normalizedType = typeof type === 'string' ? type.toLowerCase() : ''
        if (normalizedType === 'begin') {
          fields.push('')
        } else if (normalizedType === 'separate') {
          if (fields.length === 0) fail('docx_field_unsupported', 'DOCX field separators must follow a field start.')
        } else if (normalizedType === 'end') {
          if (fields.length === 0) fail('docx_field_unsupported', 'DOCX field endings must follow a field start.')
          assertSafeDocxFieldInstruction(fields.pop())
        } else {
          fail('docx_field_unsupported', 'DOCX field structures are not supported.')
        }
        continue
      }
      if (child.localName === 'instrText') {
        if (fields.length === 0) {
          fail('docx_field_unsupported', 'DOCX field instructions must appear inside a field.')
        }
        fields[fields.length - 1] += textOf(child)
        continue
      }
      visit(child)
    }
  }
  visit(root)
  if (fields.length > 0) fail('docx_field_unsupported', 'DOCX field structures must be terminated.')
}

function docxFieldRootForPath(name) {
  const lower = name.toLowerCase()
  const match = /^word\/(document|header[^/]*|footer[^/]*|footnotes|endnotes|comments)\.xml$/.exec(lower)
  if (match) {
    if (match[1] === 'document') return 'document'
    if (match[1].startsWith('header')) return 'hdr'
    if (match[1].startsWith('footer')) return 'ftr'
    return match[1]
  }
  if (lower === 'word/glossary/document.xml') return 'glossaryDocument'
  return null
}

function collectDocxFieldParts(zip, contentTypesInfo, relationshipsByLabel) {
  const parts = new Map()
  const unsupportedContentTypes = new Set()
  const add = (name, expectedRoot) => {
    const existing = parts.get(name)
    if (existing && existing !== expectedRoot) {
      fail('invalid_ooxml', `The DOCX package declares conflicting field-part types for ${name}.`)
    }
    parts.set(name, expectedRoot)
  }

  for (const name of zip.keys()) {
    if (isPackageInfrastructurePart(name)) continue
    const expectedRoot = DOCX_FIELD_CONTENT_TYPES.get(effectiveContentType(name, contentTypesInfo))
    if (expectedRoot) add(name, expectedRoot)
  }

  for (const relationships of relationshipsByLabel.values()) {
    for (const relationship of relationships.values()) {
      const declaration = DOCX_FIELD_RELATIONSHIPS.get(relationship.type)
      if (!declaration) continue
      if (!relationship.resolvedTarget) {
        fail('invalid_ooxml', 'A DOCX content relationship does not point to a package part.')
      }
      const contentType = effectiveContentType(relationship.resolvedTarget, contentTypesInfo)
      if (contentType !== declaration.contentType) {
        unsupportedContentTypes.add(relationship.resolvedTarget)
      }
      add(relationship.resolvedTarget, declaration.root)
    }
  }

  for (const name of zip.keys()) {
    const expectedRoot = docxFieldRootForPath(name)
    if (expectedRoot) add(name, expectedRoot)
  }
  return { parts, unsupportedContentTypes }
}

async function assertNoUnsafeDocxPackageFields(context, contentTypesInfo, relationshipsByLabel) {
  const { parts, unsupportedContentTypes } = collectDocxFieldParts(context.zip, contentTypesInfo, relationshipsByLabel)
  for (const [name, expectedRoot] of parts) {
    if (!context.zip.has(name)) {
      fail('invalid_ooxml', `The DOCX part ${name} declared by the package is missing.`)
    }
    const root = await readPackageXml(context, name)
    assertNamespace(root, NS.wordprocessing, name)
    if (root.localName !== expectedRoot) {
      fail('invalid_ooxml', `The DOCX part ${name} has an unexpected root element for its declared content type.`)
    }
    assertNoUnsafeDocxFields(root)
  }
  if (unsupportedContentTypes.size > 0) {
    fail('unsupported_ooxml_type', 'A DOCX content relationship points to a part with an unsupported content type.')
  }
}

function assertSupportedWordText(child) {
  if (child.localName === 'sym') {
    fail('docx_unsupported_structure', 'DOCX symbol characters are not supported; replace them with plain text.')
  }
  if (child.namespaceUri === NS.math || child.localName === 'oMath' || child.localName === 'oMathPara') {
    fail('docx_unsupported_structure', 'DOCX equations are not supported; replace them with plain text.')
  }
}

function collectWordParagraphText(paragraph) {
  const parts = []
  const visit = current => {
    for (const child of current.children) {
      assertSupportedWordText(child)
      if (child.namespaceUri === NS.wordprocessing) {
        if (child.localName === 't') parts.push(textOf(child))
        else if (child.localName === 'tab' || child.localName === 'ptab') parts.push('\t')
        else if (child.localName === 'br' || child.localName === 'cr') parts.push('\n')
        else if (child.localName === 'noBreakHyphen') parts.push('-')
        else if (child.localName === 'softHyphen') parts.push('\u00ad')
        else if (child.localName === 'tbl') fail('docx_unsupported_structure', 'Nested tables in DOCX documents are not supported.')
        else if (child.localName === 'pict' || child.localName === 'drawing' || child.localName === 'object') continue
        else visit(child)
      } else {
        visit(child)
      }
    }
  }
  visit(paragraph)
  return parts.join('')
}

function collectWordCellText(cell) {
  const blocks = []
  const inline = []
  const flushInline = () => {
    if (inline.length === 0) return
    blocks.push(inline.join(''))
    inline.length = 0
  }
  const visit = node => {
    for (const child of node.children) {
      assertSupportedWordText(child)
      if (child.namespaceUri !== NS.wordprocessing) {
        visit(child)
        continue
      }
      if (child.localName === 'tcPr') continue
      if (child.localName === 'tbl') {
        fail('docx_unsupported_structure', 'Nested tables in DOCX documents are not supported.')
      }
      if (child.localName === 'p') {
        flushInline()
        blocks.push(collectWordParagraphText(child))
        continue
      }
      if (child.localName === 't') {
        inline.push(textOf(child))
        continue
      }
      if (child.localName === 'tab' || child.localName === 'ptab') {
        inline.push('\t')
        continue
      }
      if (child.localName === 'br' || child.localName === 'cr') {
        inline.push('\n')
        continue
      }
      if (child.localName === 'noBreakHyphen') {
        inline.push('-')
        continue
      }
      if (child.localName === 'softHyphen') {
        inline.push('\u00ad')
        continue
      }
      if (child.localName === 'pict' || child.localName === 'drawing' || child.localName === 'object') continue
      visit(child)
    }
  }
  visit(cell)
  flushInline()
  return blocks.join('\n')
}

function parseDocxTable(table, maxRecords, warnings) {
  const records = []
  const rows = childElements(table, 'tr', NS.wordprocessing)
  if (countDescendants(table, 'tr', NS.wordprocessing) !== rows.length) {
    fail('docx_unsupported_structure', 'DOCX table rows must appear directly in the table for this version.')
  }
  for (const row of rows) {
    if (hasDescendant(row, 'gridBefore', NS.wordprocessing) || hasDescendant(row, 'gridAfter', NS.wordprocessing)) {
      fail('docx_unsupported_structure', 'DOCX table rows with omitted grid cells are not supported.')
    }
    const cells = childElements(row, 'tc', NS.wordprocessing)
    if (countDescendants(row, 'tc', NS.wordprocessing) !== cells.length) {
      fail('docx_unsupported_structure', 'DOCX table cells must appear directly in the row for this version.')
    }
    if (cells.length === 0) continue
    const values = []
    for (const cell of cells) {
      if (values.length >= MAX_IMPORT_COLUMNS) {
        fail('too_many_columns', `Import sources are limited to ${MAX_IMPORT_COLUMNS} columns.`)
      }
      if (hasDescendant(cell, 'tbl', NS.wordprocessing)) {
        fail('docx_unsupported_structure', 'Nested tables in DOCX documents are not supported.')
      }
      if (hasDescendant(cell, 'vMerge', NS.wordprocessing) || hasDescendant(cell, 'hMerge', NS.wordprocessing)) {
        fail('merged_cells_unsupported', 'Merged DOCX table cells are not supported; unmerge cells before importing.')
      }
      const spans = []
      const collectSpans = node => {
        for (const child of node.children) {
          if (child.localName === 'gridSpan') spans.push(child)
          collectSpans(child)
        }
      }
      collectSpans(cell)
      for (const span of spans) {
        const value = attrInNamespace(span, NS.wordprocessing, 'val')
        if (value !== '1') {
          fail('merged_cells_unsupported', 'Merged DOCX table cells are not supported; unmerge cells before importing.')
        }
      }
      const text = collectWordCellText(cell).replace(/\r\n?/g, '\n').trim()
      values.push(validateCell(text))
    }
    if (records.length > 0) trimTrailingEmptyCells(values)
    if (values.every(value => value === '')) continue
    records.push(values)
    if (records.length > maxRecords + 1) {
      fail('too_many_records', `Import sources are limited to ${maxRecords} data rows; the file was not truncated.`)
    }
  }
  return finalizeRows(records, maxRecords, warnings)
}

async function parseDocx(bytes, maxRecords, warnings, signal = undefined) {
  assertZipMagic(bytes)
  const zip = parseZipDirectory(bytes)
  await validateAllZipEntries(bytes, zip, signal)
  assertNoUnsupportedParts(zip, 'docx')
  const context = createPackageContext(bytes, zip, signal)
  const contentTypes = await readPackageXml(context, '[Content_Types].xml')
  const contentTypesInfo = assertContentTypes(contentTypes, '[Content_Types].xml', 'docx', zip)
  const relationshipsByLabel = await assertRelationshipsSafe(context)
  assertMainPartRelationship(relationshipsByLabel, 'docx')
  await assertNoUnsafeDocxPackageFields(context, contentTypesInfo, relationshipsByLabel)

  const document = await readPackageXml(context, 'word/document.xml')
  assertNamespace(document, NS.wordprocessing, 'word/document.xml')
  if (document.localName !== 'document') fail('invalid_ooxml', 'The document part has an unexpected root element.')
  const bodies = childElements(document, 'body', NS.wordprocessing)
  if (bodies.length !== 1) fail('invalid_ooxml', 'The document must contain exactly one body.')
  const body = bodies[0]

  if (hasDescendant(body, 'altChunk', NS.wordprocessing)) {
    fail('docx_unsupported_structure', 'DOCX documents with embedded alternate content are not supported.')
  }
  if (hasDescendant(body, 'subDoc', NS.wordprocessing) || hasDescendant(body, 'contentPart', NS.wordprocessing)) {
    fail('docx_unsupported_structure', 'DOCX documents with linked subdocuments or content parts are not supported.')
  }
  if (hasDescendant(body, 'sdt', NS.wordprocessing)) {
    fail('docx_unsupported_structure', 'DOCX content controls are not supported; convert them to plain tables before importing.')
  }
  for (const element of ['ins', 'del', 'moveFrom', 'moveTo', 'cellIns', 'cellDel', 'cellMerge']) {
    if (hasDescendant(body, element, NS.wordprocessing)) {
      fail('docx_tracked_changes_unsupported', 'DOCX tracked changes are not supported; accept or reject them before importing.')
    }
  }
  for (const child of body.children) {
    if (child.namespaceUri !== NS.wordprocessing) {
      fail('docx_unsupported_structure', 'DOCX body content must use the wordprocessing namespace.')
    }
    if (['customXml', 'smartTag', 'subDoc', 'contentPart'].includes(child.localName)) {
      fail('docx_unsupported_structure', 'DOCX body wrappers other than plain paragraphs and tables are not supported.')
    }
  }

  if (zip.keys().some(name => /^word\/(header|footer)[^/]*\.xml$/i.test(name))) {
    addWarning(warnings, 'docx_headers_footers_ignored', 'Document headers and footers are not imported.')
  }
  if (zip.keys().some(name => /^word\/(footnotes|endnotes|comments)\.xml$/i.test(name))) {
    addWarning(warnings, 'docx_ancillary_text_ignored', 'Footnotes, endnotes and comments are not imported.')
  }
  if (hasDescendant(body, 'drawing', NS.wordprocessing)
    || hasDescendant(body, 'pict', NS.wordprocessing)
    || hasDescendant(body, 'object', NS.wordprocessing)) {
    addWarning(warnings, 'docx_drawing_content_ignored', 'Drawing, text-box and embedded-object content is not imported.')
  }

  const tables = childElements(body, 'tbl', NS.wordprocessing)
  const prose = childElements(body, 'p', NS.wordprocessing)
    .filter(paragraph => collectWordParagraphText(paragraph).trim() !== '')
  if (tables.length === 0) {
    if (hasDescendant(body, 'tbl', NS.wordprocessing)) {
      fail('docx_unsupported_structure', 'DOCX tables must appear directly in the document body for this version.')
    }
    if (prose.length > 0) {
      fail('docx_prose_unsupported', 'DOCX import supports tables only; no table was found and prose was not converted into fields.')
    }
    addWarning(warnings, 'empty_file', 'The import source does not contain any rows.')
    return { columns: [], rows: [] }
  }
  if (countDescendants(body, 'tbl', NS.wordprocessing) !== tables.length) {
    fail('docx_unsupported_structure', 'DOCX tables nested in unsupported document structures are not supported.')
  }
  if (prose.length > 0) {
    addWarning(warnings, 'docx_prose_ignored', `${prose.length} prose paragraph(s) outside the table were ignored.`)
  }
  const dataTables = tables.filter(table => childElements(table, 'tr', NS.wordprocessing).length > 0
    || hasDescendant(table, 'tr', NS.wordprocessing))
  if (dataTables.length === 0) {
    addWarning(warnings, 'empty_file', 'The import source does not contain any table rows.')
    return { columns: [], rows: [] }
  }
  if (dataTables.length > 1) {
    addWarning(warnings, 'docx_additional_tables_ignored', `The document contains ${tables.length} tables; only the first non-empty table was read.`)
  }
  return parseDocxTable(dataTables[0], maxRecords, warnings)
}

/**
 * Parse a selected browser file into bounded plain-text cells for UI field mapping.
 * This function performs no upload, storage write, network request or model call.
 *
 * @param {{name?: string, size?: number, arrayBuffer?: () => Promise<ArrayBuffer>, bytes?: Uint8Array}} file
 * @param {{maxRecords?: number, signal?: {aborted: boolean}}} [options]
 * @returns {Promise<{name: string, format: 'txt'|'csv'|'xlsx'|'docx', columns: string[], rows: string[][], warnings: Array<{code: string, message: string}>}>}
 */
export async function parseImportFile(file, options = {}) {
  if (typeof TextDecoder !== 'function') {
    fail('unsupported_runtime', 'This browser does not provide the text decoding required for import.')
  }
  if (!file || (typeof file !== 'object' && typeof file !== 'function')) {
    fail('invalid_file', 'A file-like object with a name and readable bytes is required.')
  }
  if (options !== null && typeof options !== 'object') {
    fail('invalid_option', 'Import options must be an object.')
  }
  const signal = options?.signal
  assertNotAborted(signal)
  const maxRecords = resolveMaxRecords(options?.maxRecords)
  const name = importName(file.name)
  const format = resolveFormat(name)
  const bytes = await readFileBytes(file, signal)
  const warnings = []

  let table
  if (format === 'csv') {
    assertNotAborted(signal)
    const text = decodeUtf8(bytes, 'CSV input')
    assertPlainText(text)
    table = parseCsv(text, maxRecords, warnings)
  } else if (format === 'txt') {
    assertNotAborted(signal)
    const text = decodeUtf8(bytes, 'TXT input')
    assertPlainText(text)
    table = parseTxt(text, maxRecords, warnings)
  } else if (format === 'xlsx') {
    table = await parseXlsx(bytes, maxRecords, warnings, signal)
  } else {
    table = await parseDocx(bytes, maxRecords, warnings, signal)
  }
  assertNotAborted(signal)

  return {
    name,
    format,
    columns: table.columns,
    rows: table.rows,
    warnings
  }
}
