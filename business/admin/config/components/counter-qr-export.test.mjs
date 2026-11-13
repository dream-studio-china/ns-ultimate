import assert from 'node:assert/strict'
import test from 'node:test'
import {
  DEFAULT_CUSTOMER_ORDERING_BASE_URL,
  buildStoredZip,
  counterOrderingUrl,
  counterQrArchiveName,
  counterQrFileNames
} from './counter-qr-export.mjs'

test('builds the customer ordering URL for a counter', () => {
  const url = counterOrderingUrl(
    '00000000-0000-4000-8000-000000000006',
    '00000000-0000-4000-8000-000000000001',
    DEFAULT_CUSTOMER_ORDERING_BASE_URL
  )
  assert.equal(
    url,
    'https://ns-ultimate.wscoffee.com.cn/h5/order/index.html?counterId=00000000-0000-4000-8000-000000000006&storeUuid=00000000-0000-4000-8000-000000000001'
  )
})

test('uses counter names as PNG file names and disambiguates duplicates', () => {
  const names = counterQrFileNames([
    { uuid: 'a', name: 'A01', code: 'A01' },
    { uuid: 'b', name: 'A02', code: 'A02' },
    { uuid: 'c', name: '窗边', code: 'C01' },
    { uuid: 'd', name: '窗边', code: 'C02' }
  ])
  assert.deepEqual(names, ['A01.png', 'A02.png', '窗边-C01.png', '窗边-C02.png'])
})

test('prevents collisions across stores when names and codes are the same', () => {
  const names = counterQrFileNames([
    { uuid: 'aaaaaaaa', name: 'A01', code: 'A01' },
    { uuid: 'bbbbbbbb', name: 'A01', code: 'A01' }
  ])
  assert.deepEqual(names, ['A01-A01.png', 'A01-A01-2.png'])
})

test('sanitizes invalid and reserved file names', () => {
  const names = counterQrFileNames([
    { uuid: 'a', name: ' A/01:*? ', code: 'A01' },
    { uuid: 'b', name: 'CON', code: 'B01' },
    { uuid: 'c', name: '', code: 'C01' }
  ])
  assert.deepEqual(names, ['A_01___.png', '_CON.png', 'C01.png'])
})

test('builds a dated archive name', () => {
  assert.equal(counterQrArchiveName(new Date(2026, 10, 13)), '台位二维码-2026-11-13.zip')
})

test('builds a valid ZIP archive with PNG entries', async () => {
  const archive = buildStoredZip([
    { name: 'A01.png', data: new Uint8Array([0x89, 0x50, 0x4e, 0x47]) },
    { name: '窗边.png', data: new Uint8Array([0x89, 0x50, 0x4e, 0x47, 1]) }
  ], new Date(2026, 10, 13, 12, 30, 0))
  const bytes = new Uint8Array(await archive.arrayBuffer())
  const view = new DataView(bytes.buffer)

  assert.equal(view.getUint32(0, true), 0x04034b50)
  let endRecord = bytes.length - 22
  while (endRecord >= 0 && view.getUint32(endRecord, true) !== 0x06054b50) endRecord -= 1
  assert.ok(endRecord > 0)
  assert.equal(view.getUint16(endRecord + 10, true), 2)
  assert.match(new TextDecoder().decode(bytes), /A01\.png/)
  assert.match(new TextDecoder().decode(bytes), /窗边\.png/)
})
