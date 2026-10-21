import assert from 'node:assert/strict'
import test from 'node:test'
import { paperElements, paperSize } from '../../business/admin/config/components/printer-paper.mjs'

test('receipt preview preserves text, bold and line breaks without interpreting HTML', () => {
  const elements = paperElements('Order &lt1&gt<BR><BOLD>Tea x 2</BOLD><BR>&ltimg src=x onerror=alert(1)&gt')
  assert.deepEqual(elements.slice(0, 4), [
    { type: 'text', value: 'Order <1>', bold: false }, { type: 'line' },
    { type: 'text', value: 'Tea x 2', bold: true }, { type: 'line' }
  ])
  assert.equal(elements[4].type, 'text')
  assert.equal(elements[4].value, '<img src=x onerror=alert(1)>')
})

test('label preview preserves coordinates and barcode sizes', () => {
  assert.deepEqual(paperElements('<TEXT x="8" y="40" font="9">Demo tea</TEXT><BC128 x="16" y="80" h="64">ABC</BC128><QRC x="8" y="160" s="2" e="L" c="1">demo</QRC>'), [
    { type: 'labelText', format: 'TEXT', value: 'Demo tea', x: 8, y: 40, font: 9 },
    { type: 'code', format: 'BC128', value: 'ABC', x: 16, y: 80, h: 64 },
    { type: 'code', format: 'QRC', value: 'demo', x: 8, y: 160 }
  ])
})

test('paper dimensions follow the document with safe defaults', () => {
  assert.deepEqual(paperSize({ kind: 'receipt' }), { width: 80, height: 30 })
  assert.deepEqual(paperSize({ kind: 'label', widthMm: 50, heightMm: 40 }), { width: 50, height: 40 })
  assert.deepEqual(paperSize({ kind: 'label', widthMm: 'invalid', heightMm: -1 }), { width: 40, height: 30 })
})
