import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { starterTemplate, templateFormValue, newTemplateNode, previewData, nodeAt, replaceNode, transformLabel } from '../../business/admin/config/components/printer-template-designer.mjs'
import { paperElements, receiptRows, labelElementBox } from '../../business/admin/config/components/printer-paper.mjs'

const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
const ts = require('typescript')
const saveSource = readFileSync(new URL('../../core/crud-admin/src/easyadmin/application/usecases/save-record.ts', import.meta.url), 'utf8')
const code = ts.transpileModule(saveSource, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText
const saveHelpers = {}
new Function('exports', code)(saveHelpers)
const sample = JSON.parse(readFileSync(new URL('../../business/admin/config/components/printer-sample-data.json', import.meta.url), 'utf8'))
const renderer = fileURLToPath(new URL('../../business/backend/Printer/src/Service/PrintDocumentRenderer.php', import.meta.url))
const normalizer = fileURLToPath(new URL('../../business/backend/Printer/src/Service/TemplateDefinitionInput.php', import.meta.url))

function render(definition, data = sample, editable = false) {
  const payload = { definition: templateFormValue(definition) }
  // Execute the actual generic form cleaner before the business API input and
  // renderer. No database, Symfony container or provider is involved.
  saveHelpers.cleanBlankAttributes(payload)
  payload.data = data
  payload.editable = editable
  const result = spawnSync('php', ['-r', `
    require $argv[1]; require $argv[2];
    $input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $definition = \\NsUltimate\\Business\\Printer\\Service\\TemplateDefinitionInput::normalize($input['definition']);
    $document = (new \\NsUltimate\\Business\\Printer\\Service\\PrintDocumentRenderer())->render($definition, $input['data'], $input['editable']);
    echo json_encode(['definition' => $definition, 'document' => $document], JSON_THROW_ON_ERROR);
  `, renderer, normalizer], { input: JSON.stringify(payload), encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr || result.error?.message)
  return JSON.parse(result.stdout)
}

test('receipt starter survives the real form-save cleaner and renders all order items', () => {
  const { definition, document } = render(starterTemplate('receipt'))
  assert.equal(definition.kind, 'receipt')
  assert.equal(document.pages.length, 1)
  assert.ok(document.content.includes(sample.order.items[0].name))
  assert.ok(document.content.includes(sample.order.items.at(-1).name))
  assert.ok(document.content.includes('211.00'))
})

test('label starter produces separate labels with real sample data', () => {
  const { document } = render(starterTemplate('label'))
  assert.equal(document.pages.length, sample.order.items.length)
  assert.equal(document.widthMm, 50)
  assert.ok(document.content.includes('<SIZE>50,40</SIZE>'))
  assert.ok(document.pages[0].includes(sample.order.items[0].name.slice(0, 12)))
})

test('explicit null conditions and unknown null properties survive submission and rendering', () => {
  const definition = { kind: 'receipt', custom: { keep: null }, nodes: [
    { type: 'if', path: 'order.paidAt', equals: null, children: [{ type: 'text', value: 'Unpaid' }] }
  ] }
  const saved = render(definition)
  assert.deepEqual(saved.definition, definition)
  assert.equal(saved.document.content, 'Unpaid')
})

test('new loop and legacy-command defaults are accepted by the real renderer', () => {
  for (const kind of ['receipt', 'label']) assert.ok(render({ kind, nodes: [newTemplateNode('each', kind)] }).document.pages.length)
  const command = newTemplateNode('receiptCommand', 'receipt')
  assert.equal(render({ kind: 'receipt', nodes: [{ type: 'text', value: 'Text' }, command] }).document.content, 'Text<BR>')
})

test('per-unit starter preview matches one live-adapter-shaped ticket', () => {
  const data = previewData(sample, 'per_unit')
  const { document } = render(starterTemplate('label'), data)
  assert.equal(document.pages.length, 1)
  assert.equal(data.item.index, 1)
  assert.equal(data.item.total, sample.order.items[0].quantity)
  assert.equal(sample.item, undefined)
  assert.ok(sample.order.items.length > 1)
})

test('actual rendered label elements map back to the editable node across expanded pages', () => {
  const template = starterTemplate('label')
  const { document } = render(template, sample, true)
  assert.equal(document.sourcePages.length, document.pages.length)
  for (const [index, page] of document.sourcePages.entries()) {
    const elements = paperElements(page, { sources: true })
    assert.deepEqual(elements.map(({ sourcePath, blockPath, ...element }) => element), paperElements(document.pages[index]))
    assert.ok(elements.every(element => nodeAt(template, element.sourcePath.split('.').map(Number))))
  }
  const element = paperElements(document.sourcePages[0], { sources: true })[1]
  const path = element.sourcePath.split('.').map(Number)
  const moved = transformLabel(nodeAt(template, path), labelElementBox(element), 16, 8, 'move', false)
  const edited = replaceNode(template, path, moved)
  const result = render(edited, sample, true)
  const printed = paperElements(result.document.sourcePages[0], { sources: true }).find(item => item.sourcePath === element.sourcePath)
  assert.equal(printed.x, element.x + 16)
  assert.equal(printed.y, element.y + 8)
  assert.equal(printed.value, element.value)
  assert.ok(!JSON.stringify(result.definition).includes('DESIGNER'))
  assert.ok(!result.document.content.includes('<DESIGNER'))
})

test('annotated receipt pages preserve the same row geometry and printer commands', () => {
  const template = starterTemplate('receipt')
  const plain = render(template).document
  const mapped = render(template, sample, true).document
  const { sourcePages, ...clean } = mapped
  assert.deepEqual(clean, plain)
  const geometry = rows => rows.map(row => ({ align: row.align, h: row.h, cells: row.cells,
    glyphs: (row.items || []).flatMap(item => Array.from(item.value, value => ({ value, bold: item.bold, w: item.w, h: item.h }))) }))
  assert.deepEqual(geometry(receiptRows(paperElements(sourcePages[0], { sources: true }), 48)), geometry(receiptRows(paperElements(plain.pages[0]), 48)))
  assert.ok(receiptRows(paperElements(sourcePages[0], { sources: true }), 48).some(row => row.blockPath === '3.0'))
})
