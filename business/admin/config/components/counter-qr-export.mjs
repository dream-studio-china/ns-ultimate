export const DEFAULT_CUSTOMER_ORDERING_BASE_URL = 'https://ns-ultimate.wscoffee.com.cn'

const textEncoder = new TextEncoder()
const crcTable = createCrcTable()

function createCrcTable() {
  const table = new Uint32Array(256)
  for (let index = 0; index < table.length; index += 1) {
    let value = index
    for (let bit = 0; bit < 8; bit += 1) value = value & 1 ? 0xedb88320 ^ (value >>> 1) : value >>> 1
    table[index] = value >>> 0
  }
  return table
}

function crc32(bytes) {
  let crc = 0xffffffff
  for (const byte of bytes) crc = crcTable[(crc ^ byte) & 0xff] ^ (crc >>> 8)
  return (crc ^ 0xffffffff) >>> 0
}

function concatBytes(parts) {
  const length = parts.reduce((total, part) => total + part.length, 0)
  const result = new Uint8Array(length)
  let offset = 0
  for (const part of parts) {
    result.set(part, offset)
    offset += part.length
  }
  return result
}

function uint16(...values) {
  return littleEndian(values, 2)
}

function uint32(...values) {
  return littleEndian(values, 4)
}

function littleEndian(values, byteLength) {
  const bytes = new Uint8Array(values.length * byteLength)
  const view = new DataView(bytes.buffer)
  values.forEach((value, index) => {
    if (byteLength === 2) view.setUint16(index * 2, value, true)
    else view.setUint32(index * 4, value >>> 0, true)
  })
  return bytes
}

function dosDateTime(date) {
  const year = Math.max(1980, date.getFullYear())
  return {
    time: (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2),
    date: ((year - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate()
  }
}

export function buildStoredZip(files, date = new Date()) {
  const localParts = []
  const centralParts = []
  const timestamp = dosDateTime(date)
  let offset = 0

  for (const file of files) {
    const name = textEncoder.encode(file.name)
    const data = file.data instanceof Uint8Array ? file.data : new Uint8Array(file.data)
    const checksum = crc32(data)
    const localHeader = concatBytes([
      uint32(0x04034b50),
      uint16(20, 0x0800, 0, timestamp.time, timestamp.date),
      uint32(checksum, data.length, data.length),
      uint16(name.length, 0)
    ])
    localParts.push(localHeader, name, data)

    const centralHeader = concatBytes([
      uint32(0x02014b50),
      uint16(20, 20, 0x0800, 0, timestamp.time, timestamp.date),
      uint32(checksum, data.length, data.length),
      uint16(name.length, 0, 0, 0, 0),
      uint32(0, offset)
    ])
    centralParts.push(centralHeader, name)
    offset += localHeader.length + name.length + data.length
  }

  const centralDirectory = concatBytes(centralParts)
  const endRecord = concatBytes([
    uint32(0x06054b50),
    uint16(0, 0, files.length, files.length),
    uint32(centralDirectory.length, offset),
    uint16(0)
  ])
  return new Blob([...localParts, centralDirectory, endRecord], { type: 'application/zip' })
}

export function counterOrderingUrl(
  counterUuid,
  storeUuid,
  baseUrl = DEFAULT_CUSTOMER_ORDERING_BASE_URL
) {
  const counterId = String(counterUuid || '').trim()
  if (!counterId) throw new Error('counterUuid is required')
  const storeId = String(storeUuid || '').trim()
  if (!storeId) throw new Error('storeUuid is required')

  const base = new URL(`${String(baseUrl || '').replace(/\/+$/, '')}/`)
  if (!['http:', 'https:'].includes(base.protocol)) throw new Error('Invalid ordering base URL')

  const url = new URL('h5/order/index.html', base)
  url.searchParams.set('counterId', counterId)
  url.searchParams.set('storeUuid', storeId)
  return url.toString()
}

function safeFileStem(value) {
  const cleaned = String(value || '')
    .trim()
    .replace(/[\\/:*?"<>|\u0000-\u001f]/g, '_')
    .replace(/\s+/g, ' ')
    .replace(/[. ]+$/g, '')
    .slice(0, 80)

  if (!cleaned) return '台位'
  return /^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i.test(cleaned) ? `_${cleaned}` : cleaned
}

function uniqueFileName(candidate, usedNames) {
  let name = candidate
  let index = 2
  while (usedNames.has(name.toLowerCase())) {
    name = `${candidate}-${index}`
    index += 1
  }
  usedNames.add(name.toLowerCase())
  return `${name}.png`
}

export function counterQrFileNames(counters) {
  const stems = counters.map(counter => safeFileStem(counter.name || counter.code || counter.uuid))
  const stemCounts = new Map()
  stems.forEach(stem => stemCounts.set(stem, (stemCounts.get(stem) || 0) + 1))

  const usedNames = new Set()
  return counters.map((counter, index) => {
    const stem = stems[index]
    const suffix = safeFileStem(counter.code || counter.uuid.slice(0, 8))
    const candidate = (stemCounts.get(stem) || 0) > 1 ? `${stem}-${suffix}` : stem
    return uniqueFileName(candidate, usedNames)
  })
}

export function counterQrArchiveName(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `台位二维码-${year}-${month}-${day}.zip`
}
