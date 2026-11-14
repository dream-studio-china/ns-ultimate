<template>
  <section class="printer-designer" :aria-label="t('Template designer')" @keydown="shortcut">
    <div class="designer-toolbar">
      <button v-for="item in views" :key="item" type="button" :aria-pressed="view === item" @click="view = item">{{ t(item) }}</button>
      <button type="button" :disabled="!state.past.length" @click="history('undo')">{{ t('Undo') }}</button>
      <button type="button" :disabled="!state.future.length" @click="history('redo')">{{ t('Redo') }}</button>
      <button type="button" :disabled="!!state.error" @click="formatJson">{{ t('Format JSON') }}</button>
      <button type="button" :disabled="!!state.error || !!sampleError" @click="refreshPreview">{{ t('Update preview') }}</button>
    </div>
    <p class="designer-help">{{ t('Designer sync help') }}</p>
    <p v-if="state.error" role="alert" class="designer-error">{{ t('Invalid template draft') }}: {{ translatedError }}. {{ t('Last valid layout retained') }}</p>
    <p v-if="kindMismatch" role="alert" class="designer-error">{{ t('Template kind mismatch') }}</p>
    <div class="designer-panes" :class="{ split: view === 'Split view' }">
      <div v-show="view !== 'JSON'" class="designer-visual">
        <template v-if="definition">
          <fieldset class="paper-settings" :disabled="blocked">
            <label>{{ t('Paper width mm') }} <input :value="definition.widthMm ?? (isLabel ? 40 : 80)" type="number" min="10" max="200" @change="setDimension('widthMm', $event)"></label>
            <label v-if="isLabel">{{ t('Paper height mm') }} <input :value="definition.heightMm ?? 30" type="number" min="10" max="200" @change="setDimension('heightMm', $event)"></label>
            <label v-if="isLabel"><input v-model="snap" type="checkbox"> {{ t('Snap to 1 mm grid') }}</label>
            <label v-if="isLabel">{{ t('Zoom') }} <select v-model.number="zoom"><option :value="0.75">75%</option><option :value="1">100%</option><option :value="1.5">150%</option><option :value="2">200%</option><option :value="3">300%</option></select></label>
          </fieldset>
          <div class="designer-workspace">
            <aside class="designer-library">
              <strong>{{ t('Components') }}</strong>
              <label><input v-model="addInside" type="checkbox" :disabled="!selectedContainer"> {{ t('Add inside selected block') }}</label>
              <button v-for="type in palette" :key="type" type="button" :disabled="blocked" @click="addNode(type)">{{ t(nodeLabels[type] || type) }}</button>
            </aside>
            <div class="designer-stage">
              <p class="designer-help">{{ t(isLabel ? 'Label canvas help' : 'Receipt canvas help') }}</p>
              <div v-if="isLabel" class="label-scroll">
                <div class="label-sheet" :class="{ grid: snap }" :style="sheetStyle">
                  <div
                    v-for="entry in entries" :key="entry.path.join('.')" class="label-element"
                    :class="{ active: selectedKey === entry.path.join('.'), overflowing: entry.box.overflow }"
                    :style="elementStyle(entry)" role="button" tabindex="0"
                    :aria-label="entry.node.type + ': ' + entry.text" :aria-pressed="selectedKey === entry.path.join('.')"
                    @focus="select(entry.path)" @keydown="nudge($event, entry)"
                    @pointerdown="beginGesture($event, entry, 'move')"
                    @pointermove="moveGesture" @pointerup="endGesture" @pointercancel="cancelGesture" @lostpointercapture="cancelGesture"
                  >
                    <span v-if="entry.node.type === 'labelText'" class="label-glyphs" :style="glyphStyle(entry)">{{ entry.text || t('Empty text') }}</span>
                    <span v-else class="code-placeholder">{{ entry.node.type === 'qr' ? '▦ QR' : '▥ ' + (entry.node.format || 'BC128') }}<small>{{ entry.text }}</small></span>
                    <button
                      v-if="selectedKey === entry.path.join('.') && !(entry.node.r ?? 0)" type="button" class="resize-handle"
                      :aria-label="t('Resize element')" @pointerdown.stop="beginGesture($event, entry, 'resize')"
                      @pointermove.stop="moveGesture" @pointerup.stop="endGesture" @pointercancel.stop="cancelGesture" @lostpointercapture.stop="cancelGesture"
                    />
                  </div>
                </div>
              </div>
              <p v-if="entries.some(entry => entry.box.overflow) && isLabel" role="status" class="designer-error">{{ t('Elements exceed paper bounds') }}</p>
              <strong>{{ t(isLabel ? 'Layout tree' : 'Receipt blocks') }}</strong>
              <ol class="layout-tree" :class="{ 'receipt-sheet': !isLabel }" :aria-label="t('Layout tree')">
                <PrinterTemplateNode v-for="(node, index) in definition.nodes" :key="index" :node="node" :path="[index]" :selected="selectedKey" @select="select" @move="reorder" />
              </ol>
              <p v-if="!definition.nodes.length">{{ t('Add a component to begin') }}</p>
            </div>
            <fieldset class="designer-properties" :disabled="blocked">
              <legend>{{ t('Properties') }}</legend>
              <template v-if="selectedNode">
                <strong>{{ selectedNode.type }} · {{ selectedKey }}</strong>
                <p v-if="!knownTypes.includes(selectedNode.type)" class="designer-help">{{ t('Unsupported node preserved') }}</p>
                <div class="node-actions">
                  <button type="button" @click="shiftNode(-1)">{{ t('Move up') }}</button>
                  <button type="button" @click="shiftNode(1)">{{ t('Move down') }}</button>
                  <button type="button" @click="duplicateNode">{{ t('Duplicate') }}</button>
                  <button type="button" @click="removeNode">{{ t('Remove') }}</button>
                </div>
                <template v-if="contentTypes.includes(selectedNode.type)">
                  <label v-if="['labelText', 'qr', 'barcode', 'text', 'string', 'field'].includes(selectedNode.type)">{{ t('Content source') }}
                    <select :value="contentMode" @change="setContentMode($event.target.value)">
                      <option v-for="mode in contentModes" :key="mode" :value="mode">{{ t(mode === 'path' ? 'Data field' : mode === 'template' ? 'Interpolated text' : 'Literal text') }}</option>
                    </select>
                  </label>
                  <label>{{ t(contentMode === 'path' ? 'Data field' : 'Content') }}
                    <textarea :value="selectedNode[contentKey] ?? ''" rows="3" @input="patchNode(contentKey, $event.target.value)" />
                  </label>
                </template>
                <label v-if="['each', 'if', 'repeat'].includes(selectedNode.type)">{{ t('Data field') }} <input :value="selectedNode[selectedNode.type === 'repeat' ? 'countPath' : 'path']" @input="patchNode(selectedNode.type === 'repeat' ? 'countPath' : 'path', $event.target.value)"></label>
                <p v-if="['each', 'repeat', 'if'].includes(selectedNode.type)" class="designer-help">{{ t('Conditional and loop JSON help') }}</p>
                <template v-if="isLabel && labelTypes.includes(selectedNode.type)">
                  <label v-for="key in ['x', 'y']" :key="key">{{ key.toUpperCase() }} (mm) <input type="number" min="0" max="256" step="0.125" :value="(selectedNode[key] || 0) / 8" @change="patchNumber(key, $event, 0, 2048, 8)"></label>
                  <template v-if="selectedNode.type === 'labelText'">
                    <label>{{ t('Printer font') }} <input type="number" min="1" max="9" :value="selectedNode.font ?? 9" @change="patchNumber('font', $event, 1, 9)"></label>
                    <label v-for="key in ['w', 'h']" :key="key">{{ t(key === 'w' ? 'Width multiplier' : 'Height multiplier') }} <input type="number" min="1" max="10" :value="selectedNode[key] ?? 1" @change="patchNumber(key, $event, 1, 10)"></label>
                    <label>{{ t('Rotation') }} <select :value="selectedNode.r ?? 0" @change="patchNode('r', Number($event.target.value))"><option v-for="angle in [0, 90, 180, 270]" :key="angle" :value="angle">{{ angle }}°</option></select></label>
                  </template>
                  <label v-if="selectedNode.type === 'barcode'">{{ t('Barcode height dots') }} <input type="number" min="1" max="255" :value="selectedNode.height ?? 64" @change="patchNumber('height', $event, 1, 255)"></label>
                  <label v-if="selectedNode.type === 'barcode'">{{ t('Barcode format') }} <select :value="selectedNode.format ?? 'BC128'" @change="patchNode('format', $event.target.value)"><option>BC128</option><option>BC39</option></select></label>
                  <div class="node-actions">
                    <button type="button" @click="alignLabel('left')">{{ t('Align left') }}</button>
                    <button type="button" @click="alignLabel('center')">{{ t('Center horizontally') }}</button>
                    <button type="button" @click="alignLabel('right')">{{ t('Align right') }}</button>
                    <button type="button" @click="alignLabel('middle')">{{ t('Center vertically') }}</button>
                  </div>
                </template>
                <template v-if="selectedNode.type === 'qr'">
                  <label>{{ t('QR size') }} <input type="number" :min="isLabel ? 1 : 0" :max="isLabel ? 10 : 16" :value="selectedNode.size ?? (isLabel ? 2 : 6)" @change="patchNumber('size', $event, isLabel ? 1 : 0, isLabel ? 10 : 16)"></label>
                  <label>{{ t('QR correction') }} <select :value="selectedNode.errorCorrection ?? 'L'" @change="patchNode('errorCorrection', $event.target.value)"><option v-for="level in ['L', 'M', 'Q', 'H']" :key="level">{{ level }}</option></select></label>
                </template>
                <template v-if="!isLabel && ['style', 'qr'].includes(selectedNode.type)">
                  <label>{{ t('Alignment') }} <select :value="selectedNode.align ?? (selectedNode.type === 'qr' ? 'center' : 'left')" @change="patchNode('align', $event.target.value)"><option v-for="alignment in alignments" :key="alignment" :value="alignment">{{ t(alignment) }}</option></select></label>
                </template>
                <template v-if="selectedNode.type === 'style'">
                  <label v-for="key in ['width', 'height']" :key="key">{{ t(key === 'width' ? 'Width multiplier' : 'Height multiplier') }} <input type="number" min="1" max="2" :value="selectedNode[key] ?? 1" @change="patchNumber(key, $event, 1, 2)"></label>
                  <label><input type="checkbox" :checked="selectedNode.bold || false" @change="patchNode('bold', $event.target.checked)"> {{ t('Bold') }}</label>
                </template>
                <template v-if="selectedNode.type === 'columns' && Array.isArray(selectedNode.columns)">
                  <fieldset v-for="(column, index) in selectedNode.columns" :key="index">
                    <legend>{{ t('Column') }} {{ index + 1 }}</legend>
                    <label>{{ t('Column width cells') }} <input type="number" min="1" max="96" :value="column.width" @change="patchColumn(index, 'width', Math.min(96, Math.max(1, Math.round(Number($event.target.value) || 1))))"></label>
                    <label>{{ t('Alignment') }} <select :value="column.align || 'left'" @change="patchColumn(index, 'align', $event.target.value)"><option v-for="alignment in alignments" :key="alignment" :value="alignment">{{ t(alignment) }}</option></select></label>
                    <label>{{ t('Content source') }} <select :value="columnMode(column)" @change="setColumnMode(index, $event.target.value)"><option value="value">{{ t('Literal text') }}</option><option value="path">{{ t('Data field') }}</option><option value="template">{{ t('Interpolated text') }}</option></select></label>
                    <label>{{ t('Content') }} <input :value="column[columnMode(column)]" @input="patchColumn(index, columnMode(column), $event.target.value)"></label>
                    <button type="button" :disabled="selectedNode.columns.length <= 1" @click="removeColumn(index)">{{ t('Remove') }}</button>
                  </fieldset>
                  <button type="button" :disabled="selectedNode.columns.length >= 8" @click="addColumn">{{ t('Add column') }}</button>
                </template>
              </template>
              <p v-else>{{ t('Select a component') }}</p>
            </fieldset>
          </div>
        </template>
        <p v-else>{{ t('Create template to begin') }} <button type="button" @click="initialize">{{ t('Create template') }}</button></p>
      </div>
      <div v-show="view !== 'Visual editor'" class="designer-json">
        <div class="designer-json-label">{{ t('Template JSON') }}</div>
        <PrinterTemplateJson class="printer-template-json" :model-value="state.raw" :aria-label="t('Template JSON')" :aria-invalid="!!state.error" @update:model-value="editJson" />
      </div>
    </div>
    <details class="designer-sample">
      <summary>{{ t('Sample data JSON') }}</summary>
      <textarea v-model="sampleRaw" rows="7" spellcheck="false" :aria-label="t('Sample data JSON')" />
      <button type="button" @click="sampleRaw = JSON.stringify(sampleData, null, 2)">{{ t('Restore default sample') }}</button>
      <p v-if="sampleError" role="alert" class="designer-error">{{ sampleError }}</p>
    </details>
    <p v-if="previewBusy" role="status">{{ t('Loading') }}</p>
    <p v-if="previewError" role="alert" class="designer-error">{{ t('Preview failed') }}: {{ previewError }}</p>
    <PrinterPaper v-if="previewDocument" :document="previewDocument" />
    <p class="designer-help">{{ t('Designer preview safety help') }}</p>
  </section>
</template>

<script setup>
import { computed, defineAsyncComponent, inject, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'
import PrinterPaper from './PrinterPaper.js'
import PrinterTemplateNode from './PrinterTemplateNode.vue'
import './printer-template-designer.css'
import sampleData from './printer-sample-data.json'
import { printerFontMetrics } from './printer-paper.mjs'
import { changeNode, cloneDefinition, containerTypes, createDesignerState, definitionErrorKeys, designerHistory, knownTypes, labelBox, labelEntries, labelTypes, moveNode, newTemplateNode, nodeAt, replaceNode, setDesignerDraft, transformLabel } from './printer-template-designer.mjs'

defineOptions({ name: 'PrinterTemplateDesigner' })
const PrinterTemplateJson = defineAsyncComponent(() => import('./PrinterTemplateJson.js'))
const props = defineProps({ form: { type: Object, required: true }, field: { type: Object, required: true } })
const state = shallowRef(createDesignerState(props.form[props.field.property]))
const view = ref('Split view')
const views = ['Visual editor', 'JSON', 'Split view']
const selected = ref([])
const selectedKey = computed(() => selected.value.join('.'))
const snap = ref(true), zoom = ref(1.5), addInside = ref(false)
const gesture = shallowRef(null)
const definition = computed(() => gesture.value?.definition || state.value.definition)
const isLabel = computed(() => definition.value?.kind === 'label')
const selectedNode = computed(() => nodeAt(definition.value, selected.value))
const selectedContainer = computed(() => selectedNode.value && containerTypes.includes(selectedNode.value.type))
const blocked = computed(() => !!state.value.error || !!gesture.value)
const kindMismatch = computed(() => props.form.kind && definition.value && props.form.kind !== definition.value.kind)
const translatedError = computed(() => definitionErrorKeys.includes(state.value.error) ? t(state.value.error) : state.value.error)
const sampleRaw = ref(JSON.stringify(sampleData, null, 2)), sample = shallowRef(sampleData), sampleError = ref('')
const entries = computed(() => labelEntries(definition.value, sample.value).map(entry => ({ ...entry, box: labelBox(entry.node, entry.text, definition.value) })))
const selectedEntry = computed(() => entries.value.find(entry => entry.path.join('.') === selectedKey.value))
const alignments = ['left', 'center', 'right']
const contentTypes = ['labelText', 'qr', 'barcode', 'text', 'string', 'field', 'receiptCommand']
const nodeLabels = { labelText: 'Text', style: 'Text block', field: 'Data field', string: 'Interpolated text', qr: 'QR code', barcode: 'Barcode', each: 'Item loop', repeat: 'Quantity repeat', if: 'Conditional block', line: 'Line break', separator: 'Separator', columns: 'Columns', text: 'Literal text', bold: 'Bold', page: 'Page break' }
const palette = computed(() => isLabel.value ? ['labelText', 'qr', 'barcode', 'each', 'repeat', 'if'] : ['style', 'field', 'string', 'text', 'line', 'separator', 'columns', 'qr', 'each', 'repeat', 'if', 'bold'])
const contentMode = computed(() => selectedNode.value?.type === 'string' || 'template' in (selectedNode.value || {}) ? 'template' : 'value' in (selectedNode.value || {}) ? 'value' : 'path')
const contentKey = computed(() => selectedNode.value?.type === 'string' ? 'value' : contentMode.value)
const contentModes = computed(() => ['text', 'string', 'field'].includes(selectedNode.value?.type) ? ['value', 'path', 'template'] : ['qr', 'barcode'].includes(selectedNode.value?.type) ? ['path', 'template'] : ['value', 'path', 'template'])
const pixelsPerDot = computed(() => 96 / 25.4 / 8 * zoom.value)
const sheetStyle = computed(() => ({ width: `${(definition.value?.widthMm || 40) * 8 * pixelsPerDot.value}px`, height: `${(definition.value?.heightMm || 30) * 8 * pixelsPerDot.value}px`, backgroundSize: `${8 * pixelsPerDot.value}px ${8 * pixelsPerDot.value}px` }))
function elementStyle(entry) {
  const b = entry.box, scale = pixelsPerDot.value
  return { left: `${b.x * scale}px`, top: `${b.y * scale}px`, width: `${b.width * scale}px`, height: `${b.height * scale}px`, transform: `rotate(${entry.node.r || 0}deg)`, transformOrigin: 'top left' }
}
function glyphStyle(entry) {
  const metrics = printerFontMetrics(entry.node.font || 9)
  return { fontSize: `${metrics.heightMm * 8 * pixelsPerDot.value}px`, lineHeight: 1, transform: `scale(${entry.node.w || 1}, ${entry.node.h || 1})`, transformOrigin: 'top left' }
}

function publish(next) {
  state.value = next
  if (next.definition) props.form[props.field.property] = cloneDefinition(next.definition)
}
function editJson(raw) { cancelGesture(); publish(setDesignerDraft(state.value, raw)) }
function commit(next) {
  if (state.value.error) return
  publish(setDesignerDraft(state.value, JSON.stringify(next, null, 2)))
}
function formatJson() { if (!state.value.error) commit(state.value.definition) }
function history(direction) { cancelGesture(); publish(designerHistory(state.value, direction)); selected.value = [] }
function initialize() { publish(setDesignerDraft(state.value, JSON.stringify({ kind: props.form.kind === 'label' ? 'label' : 'receipt', widthMm: props.form.kind === 'label' ? 40 : 80, ...(props.form.kind === 'label' ? { heightMm: 30 } : {}), nodes: [] }, null, 2))) }
function select(path) { if (!gesture.value) { selected.value = path; addInside.value = false } }
function patchNode(key, value) {
  if (blocked.value) return
  const box = selectedEntry.value?.box
  commit(changeNode(definition.value, selected.value, node => {
    if (isLabel.value && node.type === 'labelText' && node.align && (key === 'x' || (key === 'font' && value !== 9) || (key === 'r' && value !== 0))) {
      if (key !== 'x' && box) node.x = box.x
      delete node.align
    }
    node[key] = value
  }))
}
function patchNumber(key, event, min, max, scale = 1) {
  const value = Number(event.target.value)
  if (!Number.isFinite(value)) return
  patchNode(key, Math.max(min, Math.min(max, Math.round(value * scale))))
}
function setDimension(key, event) {
  const value = Math.max(10, Math.min(200, Math.round(Number(event.target.value) || 10)))
  commit({ ...definition.value, [key]: value })
}
function setContentMode(mode) {
  if (blocked.value) return
  commit(changeNode(definition.value, selected.value, node => {
    const previous = node[contentKey.value] ?? ''
    delete node.value; delete node.path; delete node.template
    if (['text', 'field', 'string'].includes(node.type)) node.type = mode === 'value' ? 'text' : mode === 'path' ? 'field' : 'string'
    node[node.type === 'string' ? 'value' : mode] = previous
  }))
}
const columnMode = column => 'template' in column ? 'template' : 'value' in column ? 'value' : 'path'
function patchColumn(index, key, value) { commit(changeNode(definition.value, selected.value, node => { node.columns[index][key] = value })) }
function setColumnMode(index, mode) {
  commit(changeNode(definition.value, selected.value, node => {
    const column = node.columns[index], value = column[columnMode(column)] ?? ''
    delete column.path; delete column.value; delete column.template; column[mode] = value
  }))
}
function addColumn() { commit(changeNode(definition.value, selected.value, node => { node.columns.push({ value: '', width: 8, align: 'left' }) })) }
function removeColumn(index) { commit(changeNode(definition.value, selected.value, node => { node.columns.splice(index, 1) })) }
function siblings(next, path) { return path.length === 1 ? next.nodes : nodeAt(next, path.slice(0, -1)).children }
function addNode(type) {
  if (blocked.value) return
  const next = cloneDefinition(definition.value)
  const inside = addInside.value && selectedContainer.value
  const nodes = inside ? (nodeAt(next, selected.value).children ??= []) : next.nodes
  nodes.push(newTemplateNode(type, next.kind))
  const path = inside ? [...selected.value, nodes.length - 1] : [nodes.length - 1]
  commit(next); selected.value = path
}
function removeNode() {
  const next = cloneDefinition(definition.value)
  siblings(next, selected.value).splice(selected.value.at(-1), 1)
  commit(next); selected.value = []
}
function duplicateNode() {
  const next = cloneDefinition(definition.value), path = [...selected.value]
  siblings(next, path).splice(path.at(-1) + 1, 0, cloneDefinition(selectedNode.value))
  path[path.length - 1]++; commit(next); selected.value = path
}
function shiftNode(delta) {
  const next = cloneDefinition(definition.value), path = [...selected.value], nodes = siblings(next, path), index = path.at(-1), to = index + delta
  if (to < 0 || to >= nodes.length) return
  nodes.splice(to, 0, nodes.splice(index, 1)[0]); path[path.length - 1] = to
  commit(next); selected.value = path
}
function reorder({ source, target }) {
  if (blocked.value) return
  const valid = path => path.length && path.every(index => Number.isInteger(index) && index >= 0) && nodeAt(definition.value, path)
  if (!valid(source) || !valid(target)) return
  commit(moveNode(definition.value, source, target)); selected.value = []
}
function alignLabel(alignment) {
  const entry = selectedEntry.value
  if (!entry) return
  const b = entry.box, width = (definition.value.widthMm || 40) * 8, height = (definition.value.heightMm || 30) * 8
  commit(changeNode(definition.value, selected.value, node => {
    if (alignment === 'middle') node.y = Math.max(0, Math.round((height - (b.maxY - b.minY)) / 2 - b.minY))
    else if (node.type === 'labelText' && (node.font ?? 9) === 9 && !(node.r ?? 0)) {
      node.align = alignment
      if (alignment === 'left') node.x = 0
    } else {
      delete node.align
      node.x = Math.max(0, Math.round((alignment === 'left' ? 0 : alignment === 'center' ? (width - (b.maxX - b.minX)) / 2 : width - (b.maxX - b.minX)) - b.minX))
    }
  }))
}
function beginGesture(event, entry, operation) {
  if (state.value.error || gesture.value || event.button !== 0) return
  event.preventDefault(); selected.value = entry.path
  event.currentTarget.setPointerCapture(event.pointerId)
  gesture.value = { pointerId: event.pointerId, target: event.currentTarget, path: entry.path, node: cloneDefinition(entry.node), box: entry.box, operation, x: event.clientX, y: event.clientY, scale: pixelsPerDot.value, definition: cloneDefinition(state.value.definition) }
}
function moveGesture(event) {
  const current = gesture.value
  if (!current || event.pointerId !== current.pointerId) return
  if (!current.changed && Math.hypot(event.clientX - current.x, event.clientY - current.y) < 3) return
  const node = transformLabel(current.node, current.box, (event.clientX - current.x) / current.scale, (event.clientY - current.y) / current.scale, current.operation, snap.value)
  const next = replaceNode(state.value.definition, current.path, node)
  gesture.value = { ...current, changed: true, definition: next }
}
function endGesture(event) {
  if (!gesture.value || event.pointerId !== gesture.value.pointerId) return
  moveGesture(event)
  const { definition: next, changed } = gesture.value
  cancelGesture(); if (changed) commit(next)
}
function cancelGesture() {
  const current = gesture.value
  gesture.value = null
  if (current?.target.hasPointerCapture?.(current.pointerId)) current.target.releasePointerCapture(current.pointerId)
}
function nudge(event, entry) {
  if (state.value.error || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return
  event.preventDefault(); event.stopPropagation()
  const step = event.shiftKey ? 8 : 1
  const node = transformLabel(entry.node, entry.box, event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0, event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0, 'move', false)
  commit(replaceNode(definition.value, entry.path, node))
}
function shortcut(event) {
  // Text inputs keep native text undo; toolbar history spans both editing modes.
  if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) return
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { event.preventDefault(); history(event.shiftKey ? 'redo' : 'undo') }
}

watch(() => props.form[props.field.property], value => {
  let same = false
  try { same = JSON.stringify(typeof value === 'string' ? JSON.parse(value) : value) === JSON.stringify(state.value.definition) } catch { /* External invalid drafts still need displaying. */ }
  if (!same) { cancelGesture(); state.value = createDesignerState(value); selected.value = [] }
}, { deep: true })

// Use the core's existing instance-bound validation seam: an invalid draft must
// not silently submit the last valid definition when the parent form is saved.
const registerValidator = inject('registerFieldValidator', null)
const unregister = registerValidator?.(props.field.property, (_rule, _value, callback) => callback(state.value.error || kindMismatch.value ? new Error(t(state.value.error ? 'Invalid template draft' : 'Template kind mismatch')) : undefined))
const previewDocument = shallowRef(null), previewBusy = ref(false), previewError = ref('')
let previewTimer, previewVersion = 0, disposed = false
function invalidatePreview() { clearTimeout(previewTimer); previewVersion++; previewDocument.value = null; previewBusy.value = false; previewError.value = '' }
async function refreshPreview() {
  invalidatePreview()
  if (state.value.error || !definition.value || sampleError.value || disposed) return
  const version = previewVersion
  previewBusy.value = true
  try {
    const response = await request.post('/api/v1/manage/printer/preview', { template: cloneDefinition(state.value.definition), data: cloneDefinition(sample.value) })
    if (!disposed && version === previewVersion) previewDocument.value = response.data.document
  } catch (cause) {
    if (!disposed && version === previewVersion) previewError.value = cause.response?.data?.error || cause.message || t('Preview failed')
  } finally { if (!disposed && version === previewVersion) previewBusy.value = false }
}
watch(sampleRaw, raw => {
  try {
    if (raw.length > 100000) throw new Error(t('Sample JSON is too large'))
    const value = JSON.parse(raw)
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(t('Invalid JSON'))
    sample.value = value; sampleError.value = ''
  } catch (cause) { sampleError.value = cause.message }
})
watch([() => state.value.raw, sampleRaw], () => {
  invalidatePreview()
  if (!state.value.error && !sampleError.value) previewTimer = setTimeout(refreshPreview, 450)
}, { immediate: true })
onBeforeUnmount(() => { disposed = true; invalidatePreview(); cancelGesture(); unregister?.() })
</script>

<style scoped>
.printer-designer { width: 100%; min-width: 0; color: var(--el-text-color-primary); line-height: 1.5; container-type: inline-size; }
.designer-toolbar, .paper-settings, .node-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
button, input, select, textarea { font: inherit; color: inherit; box-sizing: border-box; }
button, select, input:not([type=checkbox]), textarea { border: 1px solid var(--el-border-color); border-radius: 4px; background: var(--el-bg-color); padding: 5px 8px; }
button { cursor: pointer; } button:disabled { opacity: .45; cursor: not-allowed; }
button[aria-pressed=true] { border-color: var(--el-color-primary); color: var(--el-color-primary); }
button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible, .label-element:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: 2px; }
fieldset { margin: 0; padding: 8px; border: 1px solid var(--el-border-color); min-width: 0; }
.designer-help { color: var(--el-text-color-secondary); font-size: 12px; margin: 6px 0; }
.designer-error { color: var(--el-color-danger); font-size: 13px; overflow-wrap: anywhere; }
.designer-panes { display: grid; gap: 12px; min-width: 0; }
.designer-panes.split { grid-template-columns: minmax(0, 3fr) minmax(220px, 2fr); }
.designer-visual, .designer-json { min-width: 0; }
.paper-settings label { display: flex; align-items: center; gap: 5px; }
.paper-settings input[type=number] { width: 70px; }
.designer-workspace { display: grid; grid-template-columns: 115px minmax(160px, 1fr) 190px; gap: 8px; margin-top: 8px; }
.designer-library { display: flex; flex-direction: column; gap: 5px; font-size: 12px; }
.designer-stage { min-width: 0; overflow: auto; }
.designer-properties { display: flex; flex-direction: column; align-self: start; gap: 8px; font-size: 12px; }
.designer-properties label { display: flex; flex-direction: column; gap: 4px; }
.designer-json-label { margin-bottom: 4px; }
.designer-properties input, .designer-properties select, .designer-properties textarea { max-width: 100%; width: 100%; }
.designer-properties input[type=checkbox] { width: auto; }
.designer-properties .node-actions button { font-size: 11px; padding: 4px; }
.layout-tree { padding: 0; max-height: 460px; overflow: auto; }
.receipt-sheet { background: var(--el-fill-color-light); padding: 8px; min-height: 200px; }
.label-scroll { padding: 16px; overflow: auto; background: var(--el-fill-color-light); max-height: 450px; margin-bottom: 8px; }
.label-sheet { position: relative; background-color: white; color: black; box-shadow: 0 1px 8px #0003; flex-shrink: 0; }
.label-sheet.grid { background-image: linear-gradient(to right, #ddd7 1px, transparent 1px), linear-gradient(to bottom, #ddd7 1px, transparent 1px); }
.label-sheet::before, .label-sheet::after { content: ''; position: absolute; pointer-events: none; }
.label-sheet::before { top: 0; bottom: 0; left: 50%; border-left: 1px dashed #409eff66; }
.label-sheet::after { left: 0; right: 0; top: 50%; border-top: 1px dashed #409eff66; }
.label-element { position: absolute; outline: 1px dashed #888; cursor: move; touch-action: none; user-select: none; white-space: pre; font-family: monospace; }
.label-element.active { outline: 2px solid #409eff; z-index: 2; }
.label-element.overflowing { outline-color: #f56c6c; }
.label-glyphs { display: block; width: max-content; letter-spacing: 0; }
.code-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; overflow: hidden; background: #f5f5f5; font-size: 12px; }
.code-placeholder small { max-width: 100%; overflow: hidden; font-size: 9px; }
.resize-handle { position: absolute; right: -5px; bottom: -5px; width: 11px; height: 11px; padding: 0; background: #409eff; border: 1px solid white; cursor: nwse-resize; touch-action: none; }
.designer-sample { margin-top: 12px; }
.designer-sample summary { cursor: pointer; }
.designer-sample textarea { display: block; width: 100%; font-family: monospace; }
@container (max-width: 1000px) { .designer-panes.split { grid-template-columns: minmax(0, 1fr); } }
@container (max-width: 650px) { .designer-workspace { grid-template-columns: 90px minmax(0, 1fr); } .designer-properties { grid-column: 1 / -1; } }
</style>
