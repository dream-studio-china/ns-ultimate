import assert from 'node:assert/strict'
import test from 'node:test'
import { crc32 } from 'node:zlib'
import {
  MAX_ATTACHMENTS,
  MAX_FILE_BYTES,
  MAX_IMAGE_SIDE,
  MAX_RENDERED_IMAGE_DATA_URL_CHARS,
  MAX_TEXT_FILE_BYTES,
  MAX_TOTAL_BYTES,
  addAttachments,
  assertAttachmentsForSend,
  buildAttachmentHistoryContent,
  buildUserMessageContent,
  createAttachment,
  formatFileSize,
  isSafeImageDataUrl,
  releaseAttachment,
  releaseAttachments,
  sanitizeFilename,
  shouldAcceptAttachmentBatch,
  validateImageDataUrl
} from '../src/attachments.js'
import { askProvider } from '../src/provider.js'
import {
  ANIMATED_GIF_BASE64,
  ANIMATED_WEBP_BASE64,
  GIF_3X2_BASE64,
  JPEG_ORIENTATION_6_BASE64,
  JPEG_PROGRESSIVE_BASE64,
  PNG_3X2_BASE64,
  PNG_3X2_DATA_URL,
  PNG_SIDE_LIMIT_BASE64,
  WEBP_3X2_BASE64,
  WEBP_ALPHA_3X2_BASE64,
  WEBP_LOSSY_3X2_BASE64,
  WEBP_PIXEL_LIMIT_BASE64,
  WEBP_VP8L_HEADER_ONLY_BASE64,
  imageBytes
} from './image-fixtures.mjs'

const encoder = new TextEncoder()

function bytesFile(name, type, bytes) {
  const copy = Uint8Array.from(bytes)
  return {
    name,
    type,
    size: copy.byteLength,
    arrayBuffer: async() => copy.buffer
  }
}

function textFile(name, type, text) {
  return bytesFile(name, type, encoder.encode(text))
}

function imageFile(name, type, base64) {
  return bytesFile(name, type, imageBytes(base64))
}

const decode3x2 = async() => ({ width: 3, height: 2 })
const decodeJpegOrientation6 = async() => ({ width: 2, height: 3 })

function writeUint32BigEndian(bytes, offset, value) {
  bytes[offset] = (value >>> 24) & 0xff
  bytes[offset + 1] = (value >>> 16) & 0xff
  bytes[offset + 2] = (value >>> 8) & 0xff
  bytes[offset + 3] = value & 0xff
}

function readUint16(bytes, offset, little) {
  return little
    ? bytes[offset] | (bytes[offset + 1] << 8)
    : (bytes[offset] << 8) | bytes[offset + 1]
}

function readUint32(bytes, offset, little) {
  return little
    ? (bytes[offset] | (bytes[offset + 1] << 8) | (bytes[offset + 2] << 16) | (bytes[offset + 3] << 24)) >>> 0
    : ((bytes[offset] << 24) | (bytes[offset + 1] << 16) | (bytes[offset + 2] << 8) | bytes[offset + 3]) >>> 0
}

function writeUint16(bytes, offset, value, little) {
  bytes[offset] = little ? value & 0xff : (value >> 8) & 0xff
  bytes[offset + 1] = little ? (value >> 8) & 0xff : value & 0xff
}

function setJpegOrientation(bytes, orientation) {
  const copy = Uint8Array.from(bytes)
  const marker = encoder.encode('Exif\u0000\u0000')
  let markerOffset = -1
  for (let index = 0; index <= copy.length - marker.length; index += 1) {
    if (marker.every((value, offset) => copy[index + offset] === value)) {
      markerOffset = index
      break
    }
  }
  assert.notEqual(markerOffset, -1)
  const tiff = markerOffset + marker.length
  const little = copy[tiff] === 0x49 && copy[tiff + 1] === 0x49
  assert.ok(little || (copy[tiff] === 0x4d && copy[tiff + 1] === 0x4d))
  const ifd = tiff + readUint32(copy, tiff + 4, little)
  const count = readUint16(copy, ifd, little)
  for (let index = 0; index < count; index += 1) {
    const entry = ifd + 2 + index * 12
    if (readUint16(copy, entry, little) !== 274) continue
    const valueCount = readUint32(copy, entry + 4, little)
    const valueOffset = valueCount * 2 <= 4 ? entry + 8 : tiff + readUint32(copy, entry + 8, little)
    writeUint16(copy, valueOffset, orientation, little)
    return copy
  }
  throw new Error('JPEG fixture has no EXIF orientation tag')
}

function concatBytes(...parts) {
  const length = parts.reduce((total, part) => total + part.byteLength, 0)
  const bytes = new Uint8Array(length)
  let offset = 0
  for (const part of parts) {
    bytes.set(part, offset)
    offset += part.byteLength
  }
  return bytes
}

function asciiBytes(bytes, start, length) {
  let value = ''
  for (let index = 0; index < length; index += 1) value += String.fromCharCode(bytes[start + index])
  return value
}

function writeUint32LittleEndian(bytes, offset, value) {
  bytes[offset] = value & 0xff
  bytes[offset + 1] = (value >> 8) & 0xff
  bytes[offset + 2] = (value >> 16) & 0xff
  bytes[offset + 3] = (value >> 24) & 0xff
}

function makePngChunk(type, data) {
  const chunk = new Uint8Array(12 + data.byteLength)
  writeUint32BigEndian(chunk, 0, data.byteLength)
  chunk.set(encoder.encode(type), 4)
  chunk.set(data, 8)
  writeUint32BigEndian(chunk, 8 + data.byteLength, crc32(Buffer.from(chunk.subarray(4, 8 + data.byteLength))))
  return chunk
}

function apngChunkData(type) {
  if (type === 'acTL') {
    const data = new Uint8Array(8)
    writeUint32BigEndian(data, 0, 2)
    writeUint32BigEndian(data, 4, 0)
    return data
  }
  if (type === 'fcTL') {
    const data = new Uint8Array(26)
    writeUint32BigEndian(data, 0, 1)
    writeUint32BigEndian(data, 4, 3)
    writeUint32BigEndian(data, 8, 2)
    writeUint32BigEndian(data, 12, 0)
    writeUint32BigEndian(data, 16, 0)
    data[21] = 1
    data[23] = 10
    return data
  }
  if (type === 'fdAT') {
    const data = new Uint8Array(5)
    writeUint32BigEndian(data, 0, 2)
    return data
  }
  throw new Error(`Unsupported APNG chunk ${type}`)
}

function pngWithApngChunk(type) {
  const source = imageBytes(PNG_3X2_BASE64)
  const ihdrEnd = 8 + 12 + readUint32(source, 8, false)
  const chunk = makePngChunk(type, apngChunkData(type))
  if (type === 'fdAT') {
    const iend = source.byteLength - 12
    return concatBytes(source.subarray(0, iend), chunk, source.subarray(iend))
  }
  return concatBytes(source.subarray(0, ihdrEnd), chunk, source.subarray(ihdrEnd))
}

function pngWithApngAnimation() {
  const source = imageBytes(PNG_3X2_BASE64)
  const ihdrEnd = 8 + 12 + readUint32(source, 8, false)
  const iend = source.byteLength - 12
  return concatBytes(
    source.subarray(0, ihdrEnd),
    makePngChunk('acTL', apngChunkData('acTL')),
    makePngChunk('fcTL', apngChunkData('fcTL')),
    source.subarray(ihdrEnd, iend),
    makePngChunk('fdAT', apngChunkData('fdAT')),
    source.subarray(iend)
  )
}

function pngWithoutImageData() {
  const source = imageBytes(PNG_3X2_BASE64)
  const kept = []
  let offset = 8
  while (offset < source.byteLength) {
    const length = readUint32(source, offset, false)
    const type = asciiBytes(source, offset + 4, 4)
    const end = offset + 12 + length
    if (type === 'IHDR' || type === 'IEND') kept.push(source.subarray(offset, end))
    offset = end
  }
  return concatBytes(source.subarray(0, 8), ...kept)
}

function pngWithDuplicateIhdr() {
  const source = imageBytes(PNG_3X2_BASE64)
  const ihdrEnd = 8 + 12 + 13
  return concatBytes(source.subarray(0, ihdrEnd), source.subarray(8, ihdrEnd), source.subarray(ihdrEnd))
}

function pngWithBadCrc() {
  const result = Uint8Array.from(imageBytes(PNG_3X2_BASE64))
  let offset = 8
  while (offset < result.byteLength) {
    const length = readUint32(result, offset, false)
    const type = asciiBytes(result, offset + 4, 4)
    if (type === 'IDAT') {
      result[offset + 8] ^= 0x01
      return result
    }
    offset += 12 + length
  }
  throw new Error('PNG fixture has no IDAT chunk')
}

function gifWithTrailingData() {
  return concatBytes(imageBytes(GIF_3X2_BASE64), Uint8Array.from([0x00]))
}

function jpegWithoutScan() {
  const source = imageBytes(JPEG_ORIENTATION_6_BASE64)
  let offset = 2
  while (offset < source.byteLength) {
    if (source[offset] !== 0xff) throw new Error('Unexpected JPEG fixture marker boundary')
    const markerStart = offset
    while (offset < source.byteLength && source[offset] === 0xff) offset += 1
    const marker = source[offset]
    offset += 1
    if (marker === 0xda) return concatBytes(source.subarray(0, markerStart), Uint8Array.from([0xff, 0xd9]))
    if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) continue
    if (marker === 0xd9) break
    const length = readUint16(source, offset, false)
    offset += length
  }
  throw new Error('JPEG fixture has no scan header')
}

function webpWithVp8HeaderOnly() {
  const source = imageBytes(WEBP_LOSSY_3X2_BASE64)
  const frameStart = 20
  const header = source.subarray(frameStart, frameStart + 10)
  const chunk = new Uint8Array(8)
  chunk.set(encoder.encode('VP8 '), 0)
  writeUint32LittleEndian(chunk, 4, 10)
  const result = concatBytes(source.subarray(0, 12), chunk, header)
  writeUint32LittleEndian(result, 4, result.byteLength - 8)
  return result
}

function webpVp8xOnly() {
  const source = imageBytes(WEBP_ALPHA_3X2_BASE64)
  const size = readUint32(source, 16, true)
  const firstEnd = 20 + size + (size & 1)
  const result = concatBytes(source.subarray(0, firstEnd))
  writeUint32LittleEndian(result, 4, result.byteLength - 8)
  return result
}

function webpWithTruncatedSecondChunk() {
  const source = imageBytes(WEBP_3X2_BASE64)
  const firstSize = readUint32(source, 16, true)
  const firstEnd = 20 + firstSize + (firstSize & 1)
  const second = new Uint8Array(8)
  second.set(encoder.encode('ICCP'), 0)
  writeUint32LittleEndian(second, 4, 100)
  const result = concatBytes(source.subarray(0, firstEnd), second)
  writeUint32LittleEndian(result, 4, result.byteLength - 8)
  return result
}

function dataUrlForBytes(mimeType, bytes) {
  return `data:${mimeType};base64,${Buffer.from(bytes).toString('base64')}`
}

function manualImageAttachment(mimeType, bytes) {
  return {
    kind: 'image',
    name: 'corrupt-image',
    mimeType,
    size: bytes.byteLength,
    byteLength: bytes.byteLength,
    width: 3,
    height: 2,
    headerWidth: 3,
    headerHeight: 2,
    orientation: 1,
    dataUrl: dataUrlForBytes(mimeType, bytes)
  }
}

function pngWithTextChunk(extraBytes, { afterImageData = false } = {}) {
  const source = imageBytes(PNG_3X2_BASE64)
  const insertOffset = afterImageData ? source.byteLength - 12 : 8 + 12 + readUint32(source, 8, false)
  const text = encoder.encode(`Comment\u0000${'x'.repeat(extraBytes)}`)
  const chunk = new Uint8Array(12 + text.byteLength)
  writeUint32BigEndian(chunk, 0, text.byteLength)
  chunk.set(encoder.encode('tEXt'), 4)
  chunk.set(text, 8)
  writeUint32BigEndian(chunk, 8 + text.byteLength, crc32(Buffer.from(chunk.subarray(4, 8 + text.byteLength))))
  return concatBytes(source.subarray(0, insertOffset), chunk, source.subarray(insertOffset))
}

function deferredFile(name, type, bytes) {
  let resolveRead
  let started
  const startPromise = new Promise(resolve => { started = resolve })
  const file = {
    name,
    type,
    size: bytes.byteLength,
    arrayBuffer: () => new Promise(resolve => {
      started()
      resolveRead = () => resolve(Uint8Array.from(bytes).buffer)
    })
  }
  return { file, started: startPromise, resolve: () => resolveRead() }
}

test('accepts strict UTF-8 text attachments and preserves bounded content', async() => {
  const attachment = await createAttachment(textFile('sales report.csv', 'text/csv', 'region,amount\nEast,12\n'))
  assert.equal(attachment.kind, 'text')
  assert.equal(attachment.name, 'sales report.csv')
  assert.equal(attachment.mimeType, 'text/csv')
  assert.equal(attachment.size, attachment.byteLength)
  assert.match(attachment.text, /East,12/)
  assert.equal(attachment.dataUrl, undefined)
})

test('accepts UTF-8 BOM text and keeps raw and normalized byte metadata consistent', async() => {
  const attachment = await createAttachment(textFile('bom.txt', 'text/plain', '\uFEFFhello'))
  assert.equal(attachment.text, 'hello')
  assert.equal(attachment.byteLength, 8)
  assert.equal(assertAttachmentsForSend([attachment]), true)
})

test('rejects invalid UTF-8, binary text, empty files, PDFs, SVG, HTML, and unsupported binaries', async() => {
  await assert.rejects(() => createAttachment(bytesFile('broken.txt', 'text/plain', [0xc3, 0x28])), { code: 'invalid_text' })
  await assert.rejects(() => createAttachment(bytesFile('binary.txt', 'text/plain', [0x41, 0x00, 0x42])), { code: 'invalid_text' })
  await assert.rejects(() => createAttachment(bytesFile('empty.txt', 'text/plain', [])), { code: 'empty_file' })
  await assert.rejects(() => createAttachment(textFile('paper.pdf', 'application/pdf', '%PDF-1.7')), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(textFile('renamed.txt', 'text/plain', '  %PDF-1.7')), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('renamed.json', 'application/json', [0x50, 0x4b, 0x03, 0x04])), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(textFile('vector.svg', 'image/svg+xml', '<svg/>')), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(textFile('page.html', 'text/html', '<p>hello</p>')), { code: 'unsupported_type' })
  await assert.rejects(() => createAttachment(bytesFile('archive.bin', 'application/octet-stream', [1, 2, 3, 4])), { code: 'unsupported_type' })
})

test('requires a successful decoder for every image and accepts real raster fixtures', async() => {
  await assert.rejects(() => createAttachment(imageFile('no-decoder.png', 'image/png', PNG_3X2_BASE64)), { code: 'decoder_required' })

  const png = await createAttachment(imageFile('a.png', 'image/png', PNG_3X2_BASE64), { decodeImage: decode3x2 })
  assert.equal(png.mimeType, 'image/png')
  assert.equal(png.width, 3)
  assert.equal(png.height, 2)
  assert.equal(png.headerWidth, 3)
  assert.equal(png.headerHeight, 2)
  assert.equal(png.orientation, 1)
  assert.equal(png.size, png.byteLength)

  const jpeg = await createAttachment(imageFile('rotated.jpg', 'image/jpeg', JPEG_ORIENTATION_6_BASE64), {
    decodeImage: decodeJpegOrientation6
  })
  assert.equal(jpeg.orientation, 6)
  assert.equal(jpeg.headerWidth, 3)
  assert.equal(jpeg.headerHeight, 2)
  assert.equal(jpeg.width, 2)
  assert.equal(jpeg.height, 3)

  const jpegUnrotated = await createAttachment(imageFile('rotated.jpg', 'image/jpeg', JPEG_ORIENTATION_6_BASE64), {
    decodeImage: decode3x2
  })
  assert.equal(jpegUnrotated.width, 3)
  assert.equal(jpegUnrotated.height, 2)

  assert.equal((await createAttachment(imageFile('a.webp', 'image/webp', WEBP_3X2_BASE64), { decodeImage: decode3x2 })).mimeType, 'image/webp')
  assert.equal((await createAttachment(imageFile('lossy.webp', 'image/webp', WEBP_LOSSY_3X2_BASE64), { decodeImage: decode3x2 })).mimeType, 'image/webp')
  assert.equal((await createAttachment(imageFile('alpha.webp', 'image/webp', WEBP_ALPHA_3X2_BASE64), { decodeImage: decode3x2 })).mimeType, 'image/webp')
  assert.equal((await createAttachment(imageFile('progressive.jpg', 'image/jpeg', JPEG_PROGRESSIVE_BASE64), { decodeImage: decode3x2 })).mimeType, 'image/jpeg')
  assert.equal((await createAttachment(bytesFile('trailing-text.png', 'image/png', pngWithTextChunk(16, { afterImageData: true })), { decodeImage: decode3x2 })).mimeType, 'image/png')
  assert.equal((await createAttachment(imageFile('a.gif', 'image/gif', GIF_3X2_BASE64), { decodeImage: decode3x2 })).mimeType, 'image/gif')
})

test('rejects incomplete images, MIME spoofing, malformed metadata, and unsafe decoders even with a permissive decoder', async() => {
  const incompletePng = imageBytes(PNG_3X2_BASE64).slice(0, 24)
  await assert.rejects(() => createAttachment(bytesFile('incomplete.png', 'image/png', incompletePng), {
    decodeImage: decode3x2
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(bytesFile('truncated.jpg', 'image/jpeg', imageBytes(JPEG_ORIENTATION_6_BASE64).slice(0, -2)), {
    decodeImage: decodeJpegOrientation6
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(bytesFile('truncated.gif', 'image/gif', imageBytes(GIF_3X2_BASE64).slice(0, -1)), {
    decodeImage: decode3x2
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(imageFile('spoof.jpg', 'image/jpeg', PNG_3X2_BASE64), {
    decodeImage: decode3x2
  }), { code: 'mime_mismatch' })
  await assert.rejects(() => createAttachment(bytesFile('spoof.png', 'image/png', encoder.encode('not an image')), {
    decodeImage: decode3x2
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(imageFile('animated.gif', 'image/gif', ANIMATED_GIF_BASE64), {
    decodeImage: decode3x2
  }), { code: 'animated_gif' })
  await assert.rejects(() => createAttachment(imageFile('animated.webp', 'image/webp', ANIMATED_WEBP_BASE64), {
    decodeImage: decode3x2
  }), { code: 'animated_webp' })
  await assert.rejects(() => createAttachment(bytesFile('vp8x-only.webp', 'image/webp', webpVp8xOnly()), {
    decodeImage: decode3x2
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(bytesFile('trailing.gif', 'image/gif', gifWithTrailingData()), {
    decodeImage: decode3x2
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(imageFile('decode.png', 'image/png', PNG_3X2_BASE64), {
    decodeImage: async() => { throw new Error('decoder failed') }
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(imageFile('mismatch.png', 'image/png', PNG_3X2_BASE64), {
    decodeImage: async() => ({ width: 2, height: 2 })
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(imageFile('side.png', 'image/png', PNG_SIDE_LIMIT_BASE64), {
    decodeImage: async() => ({ width: MAX_IMAGE_SIDE + 1, height: 1 })
  }), { code: 'image_dimensions' })
  await assert.rejects(() => createAttachment(imageFile('pixels.webp', 'image/webp', WEBP_PIXEL_LIMIT_BASE64), {
    decodeImage: async() => ({ width: 4096, height: 4096 })
  }), { code: 'image_dimensions' })
  await assert.rejects(() => createAttachment(bytesFile('orientation-spoof.jpg', 'image/jpeg', setJpegOrientation(imageBytes(JPEG_ORIENTATION_6_BASE64), 9)), {
    decodeImage: decodeJpegOrientation6
  }), { code: 'invalid_image' })
  await assert.rejects(() => createAttachment(bytesFile('orientation-one.jpg', 'image/jpeg', setJpegOrientation(imageBytes(JPEG_ORIENTATION_6_BASE64), 1)), {
    decodeImage: decodeJpegOrientation6
  }), { code: 'invalid_image' })
})

test('rejects container corruption across shared validation, creation, send, and provider before fetch', async() => {
  const cases = [
    { name: 'no-idat.png', mimeType: 'image/png', bytes: pngWithoutImageData(), decoder: decode3x2 },
    { name: 'duplicate-ihdr.png', mimeType: 'image/png', bytes: pngWithDuplicateIhdr(), decoder: decode3x2 },
    { name: 'bad-crc.png', mimeType: 'image/png', bytes: pngWithBadCrc(), decoder: decode3x2 },
    { name: 'no-scan.jpg', mimeType: 'image/jpeg', bytes: jpegWithoutScan(), decoder: decodeJpegOrientation6 },
    { name: 'truncated-second.webp', mimeType: 'image/webp', bytes: webpWithTruncatedSecondChunk(), decoder: decode3x2 }
  ]
  for (const item of cases) {
    const dataUrl = dataUrlForBytes(item.mimeType, item.bytes)
    assert.throws(() => validateImageDataUrl(dataUrl), { code: 'invalid_image' }, item.name)
    await assert.rejects(
      () => createAttachment(bytesFile(item.name, item.mimeType, item.bytes), { decodeImage: item.decoder }),
      { code: 'invalid_image' },
      item.name
    )
    const attachment = manualImageAttachment(item.mimeType, item.bytes)
    assert.throws(() => assertAttachmentsForSend([attachment], { visionEnabled: true }), { code: 'invalid_image' }, item.name)
    assert.throws(() => buildUserMessageContent('describe', [attachment], { visionEnabled: true }), { code: 'invalid_image' }, item.name)

    let calls = 0
    await assert.rejects(() => askProvider({
      baseUrl: 'https://provider.example/v1',
      model: 'm',
      apiKey: '',
      messages: [{
        role: 'user',
        content: [{ type: 'text', text: 'describe' }, { type: 'image_url', image_url: { url: dataUrl } }]
      }],
      fetchImpl: async() => {
        calls += 1
        return { ok: true, json: async() => ({ choices: [{ message: { content: 'must not run' } }] }) }
      }
    }), { code: 'invalid_image' }, item.name)
    assert.equal(calls, 0, `${item.name} must not call fetch`)
  }
})

test('rejects APNG animation chunks with valid CRCs across shared validation, creation, send, and provider', async() => {
  const cases = [
    { name: 'actl.png', bytes: pngWithApngChunk('acTL') },
    { name: 'fctl.png', bytes: pngWithApngChunk('fcTL') },
    { name: 'fdat.png', bytes: pngWithApngChunk('fdAT') },
    { name: 'animation.png', bytes: pngWithApngAnimation() }
  ]
  for (const item of cases) {
    const dataUrl = dataUrlForBytes('image/png', item.bytes)
    assert.throws(() => validateImageDataUrl(dataUrl), { code: 'unsupported_type' }, item.name)
    await assert.rejects(
      () => createAttachment(bytesFile(item.name, 'image/png', item.bytes), { decodeImage: decode3x2 }),
      { code: 'unsupported_type' },
      item.name
    )
    const attachment = manualImageAttachment('image/png', item.bytes)
    assert.throws(() => assertAttachmentsForSend([attachment], { visionEnabled: true }), { code: 'unsupported_type' }, item.name)
    assert.throws(() => buildUserMessageContent('describe', [attachment], { visionEnabled: true }), { code: 'unsupported_type' }, item.name)

    let calls = 0
    await assert.rejects(() => askProvider({
      baseUrl: 'https://provider.example/v1',
      model: 'm',
      apiKey: '',
      messages: [{
        role: 'user',
        content: [{ type: 'text', text: 'describe' }, { type: 'image_url', image_url: { url: dataUrl } }]
      }],
      fetchImpl: async() => {
        calls += 1
        return { ok: true, json: async() => ({ choices: [{ message: { content: 'must not run' } }] }) }
      }
    }), { code: 'unsupported_type' }, item.name)
    assert.equal(calls, 0, `${item.name} must not call fetch`)
  }
})

test('rejects WebP VP8L and VP8 frames with only fixed headers across all shared paths', async() => {
  const cases = [
    { name: 'vp8l-header-only.webp', bytes: imageBytes(WEBP_VP8L_HEADER_ONLY_BASE64) },
    { name: 'vp8-header-only.webp', bytes: webpWithVp8HeaderOnly() }
  ]
  for (const item of cases) {
    const dataUrl = dataUrlForBytes('image/webp', item.bytes)
    assert.throws(() => validateImageDataUrl(dataUrl), { code: 'invalid_image' }, item.name)
    await assert.rejects(
      () => createAttachment(bytesFile(item.name, 'image/webp', item.bytes), { decodeImage: decode3x2 }),
      { code: 'invalid_image' },
      item.name
    )
    const attachment = manualImageAttachment('image/webp', item.bytes)
    assert.throws(() => assertAttachmentsForSend([attachment], { visionEnabled: true }), { code: 'invalid_image' }, item.name)
    assert.throws(() => buildUserMessageContent('describe', [attachment], { visionEnabled: true }), { code: 'invalid_image' }, item.name)

    let calls = 0
    await assert.rejects(() => askProvider({
      baseUrl: 'https://provider.example/v1',
      model: 'm',
      apiKey: '',
      messages: [{
        role: 'user',
        content: [{ type: 'text', text: 'describe' }, { type: 'image_url', image_url: { url: dataUrl } }]
      }],
      fetchImpl: async() => {
        calls += 1
        return { ok: true, json: async() => ({ choices: [{ message: { content: 'must not run' } }] }) }
      }
    }), { code: 'invalid_image' }, item.name)
    assert.equal(calls, 0, `${item.name} must not call fetch`)
  }
})

test('enforces per-file and total text limits without truncating', async() => {
  await assert.rejects(() => createAttachment({
    name: 'large.png', type: 'image/png', size: MAX_FILE_BYTES + 1, arrayBuffer: async() => {
      throw new Error('must not read an oversized file')
    }
  }), { code: 'file_too_large' })
  await assert.rejects(() => createAttachment(textFile('large.txt', 'text/plain', 'x'.repeat(MAX_TEXT_FILE_BYTES + 1))), { code: 'text_too_large' })
  const existing = [{ kind: 'text', name: 'existing.txt', size: 200 * 1024 }]
  const result = await addAttachments([textFile('more.txt', 'text/plain', 'y'.repeat(100 * 1024))], existing)
  assert.equal(result.attachments.length, 1)
  assert.equal(result.errors[0].code, 'text_total_too_large')
})

test('enforces attachment count and total byte limits', async() => {
  const files = Array.from({ length: MAX_ATTACHMENTS + 1 }, (_, index) => textFile(`note-${index}.txt`, 'text/plain', `note ${index}`))
  const counted = await addAttachments(files, [])
  assert.equal(counted.attachments.length, MAX_ATTACHMENTS)
  assert.equal(counted.errors[0].code, 'too_many')

  const existing = [
    { kind: 'image', name: 'one.png', size: MAX_TOTAL_BYTES / 2 },
    { kind: 'image', name: 'two.png', size: MAX_TOTAL_BYTES / 2 }
  ]
  const total = await addAttachments([imageFile('three.png', 'image/png', PNG_3X2_BASE64)], existing, { decodeImage: decode3x2 })
  assert.equal(total.attachments.length, 2)
  assert.equal(total.errors[0].code, 'total_too_large')
})

test('normalizes untrusted filenames and limits them to 200 characters', () => {
  const name = sanitizeFilename('\u202E../folder/<script>alert(1)</script>\u0000' + 'x'.repeat(300) + '.txt')
  assert.ok(name.length <= 200)
  assert.doesNotMatch(name, /[\\/<>`\u0000\u202e]/)
  assert.equal(sanitizeFilename('..'), 'attachment')
  assert.equal(sanitizeFilename('path/to/report.csv'), 'report.csv')
})

test('rejects images on send unless vision is explicitly enabled and builds multimodal content', async() => {
  const image = await createAttachment(imageFile('photo.png', 'image/png', PNG_3X2_BASE64), { decodeImage: decode3x2 })
  assert.throws(() => assertAttachmentsForSend([image], { visionEnabled: false }), { code: 'vision_required' })
  assert.throws(() => buildUserMessageContent('describe', [image], { visionEnabled: false }), { code: 'vision_required' })

  const content = buildUserMessageContent('describe', [image], { visionEnabled: true })
  assert.equal(Array.isArray(content), true)
  assert.equal(content[0].type, 'text')
  assert.match(content[0].text, /describe/)
  assert.equal(content[1].type, 'image_url')
  assert.equal(content[1].image_url.url, PNG_3X2_DATA_URL)
  assert.equal(isSafeImageDataUrl(content[1].image_url.url, 'image/png'), true)
  assert.equal(isSafeImageDataUrl('data:image/svg+xml;base64,PHN2Zz4=', 'image/svg+xml'), false)
})

test('keeps text-only and no-attachment provider messages as strings', async() => {
  assert.equal(buildUserMessageContent('hello', []), 'hello')
  const text = await createAttachment(textFile('notes.md', 'text/markdown', '# Notes\nBounded text.'))
  const content = buildUserMessageContent('summarize', [text], { visionEnabled: false })
  assert.equal(typeof content, 'string')
  assert.match(content, /Bounded text\./)
  assert.match(content, /notes\.md/)
})

test('keeps attachment history bounded and free of raw text, base64, and image data', async() => {
  const text = await createAttachment(textFile('secret.csv', 'text/csv', 'token,value\nprivate,42\n'))
  const image = await createAttachment(imageFile('photo.png', 'image/png', PNG_3X2_BASE64), { decodeImage: decode3x2 })
  const history = buildAttachmentHistoryContent('summarize the files', [text, image])
  assert.match(history, /secret\.csv/)
  assert.match(history, /photo\.png/)
  assert.doesNotMatch(history, /private,42/)
  assert.doesNotMatch(history, /base64/)
  assert.doesNotMatch(history, /data:image/)
})

test('freezes send snapshots and releases local preview URLs on removal, reset, cancel, and disposal', async() => {
  const revoked = []
  const first = await createAttachment(imageFile('one.png', 'image/png', PNG_3X2_BASE64), {
    decodeImage: decode3x2,
    createObjectURL: () => 'blob:preview-one'
  })
  const second = await createAttachment(imageFile('two.png', 'image/png', PNG_3X2_BASE64), {
    decodeImage: decode3x2,
    createObjectURL: () => 'blob:preview-two'
  })
  const snapshot = [first].map(attachment => ({ ...attachment }))
  assert.equal(first.previewUrl, 'blob:preview-one')
  releaseAttachment(first, { revokeObjectURL: url => revoked.push(url) })
  assert.deepEqual(revoked, ['blob:preview-one'])
  assert.equal(first.dataUrl, '')
  assert.match(buildUserMessageContent('still here', snapshot, { visionEnabled: true })[0].text, /still here/)

  releaseAttachments([second], { revokeObjectURL: url => revoked.push(url) })
  assert.deepEqual(revoked, ['blob:preview-one', 'blob:preview-two'])
  assert.equal(second.previewUrl, '')
  releaseAttachment(second, { revokeObjectURL: url => revoked.push(url) })
  assert.deepEqual(revoked, ['blob:preview-one', 'blob:preview-two'])
})

test('send-time validation derives actual text and image bytes instead of trusting metadata', async() => {
  const text = await createAttachment(textFile('notes.txt', 'text/plain', 'bounded text'))
  const image = await createAttachment(imageFile('photo.png', 'image/png', PNG_3X2_BASE64), { decodeImage: decode3x2 })

  assert.throws(() => assertAttachmentsForSend([{ ...text, size: -1 }]), { code: 'invalid_metadata' })
  assert.throws(() => assertAttachmentsForSend([{ ...text, byteLength: text.byteLength + 10 }]), { code: 'invalid_metadata' })
  const oversizedText = 'x'.repeat(MAX_TEXT_FILE_BYTES + 1)
  assert.throws(() => assertAttachmentsForSend([{
    ...text,
    size: encoder.encode(oversizedText).byteLength,
    byteLength: encoder.encode(oversizedText).byteLength,
    text: oversizedText
  }]), { code: 'text_too_large' })
  assert.throws(() => assertAttachmentsForSend([{ ...image, byteLength: image.byteLength + 1 }]), { code: 'invalid_metadata' })
  assert.throws(() => assertAttachmentsForSend([{ ...image, dataUrl: 'http://169.254.169.254/latest/meta-data' }], { visionEnabled: true }), { code: 'invalid_image' })
  assert.throws(() => assertAttachmentsForSend([{ ...image, dataUrl: 'data:image/svg+xml;base64,PHN2Zz4=' }], { visionEnabled: true }), { code: 'invalid_image' })
  assert.throws(() => assertAttachmentsForSend([{ ...image, dataUrl: 'data:image/png;base64,AA==' }], { visionEnabled: true }), { code: 'invalid_image' })
  assert.throws(() => assertAttachmentsForSend([{ ...image, width: 99 }], { visionEnabled: true }), { code: 'invalid_image' })
})

test('send-time validation compares actual decoded image bytes against per-file and total limits', () => {
  const perFileBytes = pngWithTextChunk(MAX_FILE_BYTES)
  assert.ok(perFileBytes.byteLength > MAX_FILE_BYTES)
  const perFile = {
    kind: 'image', name: 'large.png', mimeType: 'image/png',
    size: perFileBytes.byteLength, byteLength: perFileBytes.byteLength,
    width: 3, height: 2, headerWidth: 3, headerHeight: 2, orientation: 1,
    dataUrl: `data:image/png;base64,${Buffer.from(perFileBytes).toString('base64')}`
  }
  assert.throws(() => assertAttachmentsForSend([perFile], { visionEnabled: true }), { code: 'file_too_large' })

  const chunkBytes = Math.floor(3.5 * 1024 * 1024)
  const pieces = Array.from({ length: 3 }, () => pngWithTextChunk(chunkBytes))
  const attachments = pieces.map((bytes, index) => ({
    kind: 'image', name: `large-${index}.png`, mimeType: 'image/png',
    size: bytes.byteLength, byteLength: bytes.byteLength,
    width: 3, height: 2, headerWidth: 3, headerHeight: 2, orientation: 1,
    dataUrl: `data:image/png;base64,${Buffer.from(bytes).toString('base64')}`
  }))
  assert.ok(attachments.reduce((total, attachment) => total + attachment.byteLength, 0) > MAX_TOTAL_BYTES)
  assert.throws(() => assertAttachmentsForSend(attachments, { visionEnabled: true }), { code: 'total_too_large' })
})

test('send-time validation applies exact text aggregate limits and BOM allowance', async() => {
  const first = await createAttachment(textFile('one.txt', 'text/plain', 'a'.repeat(100 * 1024)))
  const second = await createAttachment(textFile('two.txt', 'text/plain', 'b'.repeat(100 * 1024)))
  assert.equal(assertAttachmentsForSend([first, second]), true)
  const third = await createAttachment(textFile('three.txt', 'text/plain', 'c'.repeat(100 * 1024)))
  assert.throws(() => assertAttachmentsForSend([first, second, third]), { code: 'text_total_too_large' })
  const bom = await createAttachment(textFile('bom.txt', 'text/plain', '\uFEFFbounded'))
  assert.equal(assertAttachmentsForSend([bom]), true)
})

test('cancels an attachment batch after the first read and never reads or decodes remaining files', async() => {
  const first = deferredFile('first.png', 'image/png', imageBytes(PNG_3X2_BASE64))
  let secondReads = 0
  const second = {
    name: 'second.png',
    type: 'image/png',
    size: imageBytes(PNG_3X2_BASE64).byteLength,
    arrayBuffer: async() => {
      secondReads += 1
      return imageBytes(PNG_3X2_BASE64).buffer
    }
  }
  let decodeCalls = 0
  const controller = new AbortController()
  const existing = [{ kind: 'text', name: 'keep.txt', size: 1 }]
  const pending = addAttachments([first.file, second], existing, {
    signal: controller.signal,
    isCancelled: () => controller.signal.aborted,
    decodeImage: async() => {
      decodeCalls += 1
      return { width: 3, height: 2 }
    }
  })
  await first.started
  controller.abort()
  first.resolve()
  const result = await pending
  assert.equal(result.cancelled, true)
  assert.deepEqual(result.attachments, existing)
  assert.equal(secondReads, 0)
  assert.equal(decodeCalls, 0)
})

test('cancelled batches revoke created previews and do not mutate a newer batch', async() => {
  const revoked = []
  let decoded = false
  let secondReads = 0
  const first = imageFile('first.png', 'image/png', PNG_3X2_BASE64)
  const second = {
    name: 'second.png',
    type: 'image/png',
    size: imageBytes(PNG_3X2_BASE64).byteLength,
    arrayBuffer: async() => {
      secondReads += 1
      return imageBytes(PNG_3X2_BASE64).buffer
    }
  }
  const existing = [{ kind: 'text', name: 'keep.txt', size: 1 }]
  const result = await addAttachments([first, second], existing, {
    createObjectURL: () => 'blob:cancelled-preview',
    revokeObjectURL: url => revoked.push(url),
    isCancelled: () => decoded,
    decodeImage: async() => {
      decoded = true
      return { width: 3, height: 2 }
    }
  })
  assert.equal(result.cancelled, true)
  assert.deepEqual(result.attachments, existing)
  assert.deepEqual(revoked, ['blob:cancelled-preview'])
  assert.equal(secondReads, 0)
})

test('browser FileReader reads are aborted when the batch signal aborts', async() => {
  const OriginalFileReader = globalThis.FileReader
  let latest
  class FakeFileReader {
    constructor() { latest = this }
    readAsArrayBuffer(blob) { this.blob = blob }
    abort() {
      this.aborted = true
      this.onabort?.()
    }
  }
  globalThis.FileReader = FakeFileReader
  let decodeCalls = 0
  try {
    const blob = new Blob([imageBytes(PNG_3X2_BASE64)], { type: 'image/png' })
    blob.name = 'reader.png'
    const controller = new AbortController()
    const pending = addAttachments([blob], [], {
      signal: controller.signal,
      isCancelled: () => controller.signal.aborted,
      decodeImage: async() => {
        decodeCalls += 1
        return { width: 3, height: 2 }
      }
    })
    await Promise.resolve()
    controller.abort()
    const result = await pending
    assert.equal(result.cancelled, true)
    assert.equal(latest.aborted, true)
    assert.equal(decodeCalls, 0)
  } finally {
    if (OriginalFileReader === undefined) delete globalThis.FileReader
    else globalThis.FileReader = OriginalFileReader
  }
})

test('disposed contexts cancel before reading any file', async() => {
  let reads = 0
  const file = {
    name: 'first.png', type: 'image/png', size: imageBytes(PNG_3X2_BASE64).byteLength,
    arrayBuffer: async() => {
      reads += 1
      return imageBytes(PNG_3X2_BASE64).buffer
    }
  }
  const result = await addAttachments([file], [], { isCancelled: () => true, decodeImage: decode3x2 })
  assert.equal(result.cancelled, true)
  assert.equal(reads, 0)
})

test('drops stale attachment batches instead of resurrecting them after reset or disposal', () => {
  assert.equal(shouldAcceptAttachmentBatch(3, 3, false), true)
  assert.equal(shouldAcceptAttachmentBatch(2, 3, false), false)
  assert.equal(shouldAcceptAttachmentBatch(3, 3, true), false)
})

test('file selection and vision rejection perform no request before send', async() => {
  let globalRequests = 0
  const originalFetch = globalThis.fetch
  globalThis.fetch = async() => {
    globalRequests += 1
    throw new Error('selection must not fetch')
  }
  try {
    const selected = await addAttachments([textFile('notes.txt', 'text/plain', 'local only')], [])
    assert.equal(selected.attachments.length, 1)
    buildUserMessageContent('read this', selected.attachments)
    assert.equal(globalRequests, 0)

    const image = await createAttachment(imageFile('photo.png', 'image/png', PNG_3X2_BASE64), { decodeImage: decode3x2 })
    assert.throws(() => buildUserMessageContent('describe', [image], { visionEnabled: false }), { code: 'vision_required' })
    assert.equal(globalRequests, 0)

    let providerRequests = 0
    await askProvider({
      baseUrl: 'https://provider.example/v1',
      model: 'm',
      apiKey: '',
      messages: [{ role: 'user', content: buildUserMessageContent('read this', selected.attachments) }],
      fetchImpl: async() => {
        providerRequests += 1
        return { ok: true, json: async() => ({ choices: [{ message: { content: 'done' } }] }) }
      }
    })
    assert.equal(globalRequests, 0)
    assert.equal(providerRequests, 1)
  } finally {
    globalThis.fetch = originalFetch
  }
})

test('bounds rendered image data URL memory above one full attachment batch', () => {
  assert.ok(MAX_RENDERED_IMAGE_DATA_URL_CHARS >= Math.ceil(MAX_TOTAL_BYTES / 3) * 4)
})

test('formats sizes for localized limits', () => {
  assert.equal(formatFileSize(512), '512 B')
  assert.equal(formatFileSize(128 * 1024), '128 KiB')
  assert.equal(formatFileSize(5 * 1024 * 1024), '5.0 MiB')
})
