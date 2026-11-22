import { defineComponent, h, ref, resolveComponent, watch } from 'vue'
import { t } from '@/i18n'
import QRCode from 'qrcode'
import { labelElementBox, paperElements, paperSize, printerFontMetrics, qrGeometry, receiptMetrics, receiptRows, textCells } from './printer-paper.mjs'

// Explicit native cells prevent the browser's CJK fallback font from changing pitch.
const glyphs = (value, cellMm) => Array.from(value.replace(/\r\n?/g, '\n'), character => character === '\n' ? h('br') : h('span', { style: { display: 'inline-block', width: `${textCells(character) * cellMm}mm` } }, character))
const position = element => ({ position: 'absolute', left: `${element.x / 8}mm`, top: `${element.y / 8}mm` })

export default defineComponent({
  name: 'PrinterPaper',
  props: {
    document: { type: Object, required: true }, editable: Boolean, disabled: Boolean,
    selectedPath: { type: String, default: '' }, zoom: { type: Number, default: 1 }, grid: Boolean,
    dragScope: { type: String, default: '' }, override: { type: Object, default: null }
  },
  emits: ['select', 'label-start', 'label-move', 'label-end', 'label-cancel', 'label-key', 'move-node', 'geometry'],
  setup(props, context) {
    const emit = context?.emit || (() => {})
    const ElPagination = resolveComponent('ElPagination')
    const page = ref(1)
    const qrCache = new Map()
    const geometryFor = element => {
      const key = JSON.stringify([element.value, element.e, element.s])
      if (!qrCache.has(key)) {
        if (qrCache.size >= 128) qrCache.clear()
        qrCache.set(key, qrGeometry(element, QRCode.create))
      }
      return qrCache.get(key)
    }
    watch([() => props.selectedPath, () => props.document, () => props.disabled, page], () => {
      if (!props.editable || props.disabled || props.document.kind !== 'label' || !props.selectedPath) return
      const pages = props.document.sourcePages
      if (!Array.isArray(pages) || pages.length !== props.document.pages?.length) return
      const index = Math.min(Math.max(page.value - 1, 0), pages.length - 1)
      const element = paperElements(pages[index] || '', { sources: true }).find(item => item.sourcePath === props.selectedPath && ['labelText', 'qr', 'code'].includes(item.type))
      if (element) emit('geometry', { element, box: labelElementBox(element, element.type === 'qr' ? geometryFor(element)?.widthMm : null) })
    }, { flush: 'post', immediate: true })
    const renderQr = (element, relative = false) => {
      const geometry = geometryFor(element)
      const style = element.format === 'QRC' ? { ...position(element), ...(relative ? { left: 0, top: 0 } : {}) } : { display: 'block', textAlign: element.align, lineHeight: 0 }
      if (!geometry) return h('span', { style, title: element.value }, t('QR preview unavailable'))
      return h('div', { style }, [h('svg', {
        viewBox: `0 0 ${geometry.size} ${geometry.size}`, role: 'img', 'aria-label': element.value,
        style: { width: `${geometry.widthMm}mm`, height: `${geometry.widthMm}mm`, background: '#fff', maxWidth: 'none' },
        shapeRendering: 'crispEdges', xmlns: 'http://www.w3.org/2000/svg'
      }, [h('path', { d: geometry.path, fill: '#000' })])])
    }
    return () => {
      const document = props.document
      const pages = document.pages || []
      const index = Math.min(Math.max(page.value - 1, 0), Math.max(pages.length - 1, 0))
      const size = paperSize(document)
      const label = document.kind === 'label'
      const mapped = props.editable && Array.isArray(document.sourcePages) && document.sourcePages.length === pages.length
      const interactive = mapped && !props.disabled
      const elements = paperElements((mapped ? document.sourcePages : pages)[index] || '', { sources: mapped })
      const { columns, cellMm, fontMm, gapMm, marginMm } = receiptMetrics(size.width)
      let overflowDetected = false
      const pathArray = path => typeof path === 'string' && /^\d+(?:\.\d+)*$/.test(path) ? path.split('.').map(Number) : []
      const select = (event, path) => { if (interactive && path) { event.stopPropagation(); emit('select', pathArray(path)) } }
      const selected = path => props.selectedPath && (props.selectedPath === path || path?.startsWith(`${props.selectedPath}.`))
      const receiptInteraction = element => !mapped || !element.sourcePath ? {} : {
        class: ['paper-receipt-hit', { 'paper-selected': selected(element.sourcePath) || selected(element.blockPath) }],
        'data-source-path': element.sourcePath, 'data-block-path': element.blockPath,
        tabindex: interactive ? 0 : -1, role: 'button', 'aria-label': t('Select printed element'),
        'aria-pressed': !!(selected(element.sourcePath) || selected(element.blockPath)), 'aria-disabled': !interactive,
        draggable: interactive,
        onClick: event => select(event, element.sourcePath),
        onKeydown: event => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); select(event, element.sourcePath) } },
        onDragstart: event => {
          if (!interactive) return
          event.stopPropagation()
          event.dataTransfer.setData('application/x-printer-node', JSON.stringify({ scope: props.dragScope, path: pathArray(element.blockPath || element.sourcePath) }))
          event.dataTransfer.effectAllowed = 'move'
        },
        onDragover: event => { if (interactive) event.preventDefault() },
        onDrop: event => {
          if (!interactive) return
          event.preventDefault(); event.stopPropagation()
          try {
            const payload = JSON.parse(event.dataTransfer.getData('application/x-printer-node'))
            if (payload.scope === props.dragScope && Array.isArray(payload.path) && payload.path.every(value => Number.isInteger(value) && value >= 0)) emit('move-node', { source: payload.path, target: pathArray(element.blockPath || element.sourcePath) })
          } catch { /* Ignore unrelated drags. */ }
        }
      }
      const renderElement = (element, relative = false) => {
        if (element.type === 'qr') return renderQr(element, relative)
        if (element.type === 'row') return h('div', { ...receiptInteraction(element), style: {
          display: 'flex', alignItems: 'flex-end', justifyContent: { left: 'flex-start', center: 'center', right: 'flex-end' }[element.align],
          textAlign: element.align, height: `${fontMm * element.h + gapMm}mm`, paddingBottom: `${gapMm}mm`, boxSizing: 'border-box', lineHeight: 0, whiteSpace: 'nowrap'
        } }, element.items.map(item => h('span', {
          ...receiptInteraction(item),
          // Row alignment positions the scaled slot. Never align the unscaled
          // glyph box inside it again, which would shift enlarged text off paper.
          style: { display: 'inline-block', flexShrink: 0, textAlign: 'left', width: `${textCells(item.value) * cellMm * item.w}mm`, height: `${fontMm * item.h}mm` }
        }, [h(item.bold ? 'strong' : 'span', { style: {
          display: 'block', width: `${textCells(item.value) * cellMm}mm`, textAlign: 'left', fontSize: `${fontMm}mm`, lineHeight: `${fontMm}mm`, whiteSpace: 'pre', fontWeight: item.bold ? 700 : 400,
          transform: `scale(${item.w}, ${item.h})`, transformOrigin: 'top left'
        } }, glyphs(item.value, cellMm))])))
        if (element.type === 'line') return h('br')
        if (element.type === 'text') return h(element.bold ? 'strong' : 'span', element.value)
        const style = { ...position(element), ...(relative ? { left: 0, top: 0 } : {}), whiteSpace: 'pre', lineHeight: '1.2' }
        if (element.type === 'labelText') {
          const metrics = printerFontMetrics(element.font)
          return h('span', { style: {
            ...style, fontSize: `${metrics.heightMm}mm`, lineHeight: `${metrics.heightMm}mm`, transform: `${relative ? '' : `rotate(${element.r}deg) `}scale(${element.w}, ${element.h})`, transformOrigin: 'top left'
          } }, glyphs(element.value, metrics.cellMm))
        }
        return h('span', { title: element.value, style: { ...style, border: '1px dashed #555', padding: '2px', fontSize: '9px', maxWidth: '90%', overflow: 'hidden', height: `${element.h / 8}mm` } }, `${element.format}: ${element.value}`)
      }
      const renderLabel = (original, elementIndex) => {
        if (!mapped || !original.sourcePath) return renderElement(original)
        let element = original
        if (props.override?.path === original.sourcePath) {
          const node = props.override.node
          element = { ...original, x: node.x ?? original.x, y: node.y ?? original.y,
            ...(original.type === 'labelText' ? { w: node.w ?? original.w, h: node.h ?? original.h, r: node.r ?? original.r } : original.type === 'qr' ? { s: node.size ?? original.s } : { h: node.height ?? original.h }) }
          if (node.align && original.type === 'labelText' && original.font === 9 && !element.r) {
            const cells = node.widthMode === 'legacy' ? Array.from(element.value.trim()).reduce((sum, character) => sum + (character.codePointAt(0) > 127 ? 2 : 1), 0) : textCells(element.value)
            const width = cells * 12 * element.w
            if (node.align === 'center') element.x = Math.max(0, Math.floor((size.width * 8 - width) / 2))
            if (node.align === 'right') element.x = Math.max(0, size.width * 8 - width)
          }
        }
        const box = labelElementBox(element, element.type === 'qr' ? geometryFor(element)?.widthMm : null)
        const overflow = box.x + box.minX < 0 || box.y + box.minY < 0 || box.x + box.maxX > size.width * 8 || box.y + box.maxY > size.height * 8
        overflowDetected ||= overflow
        const payload = (event, operation = 'move') => ({ event, element, box, operation })
        const active = selected(element.sourcePath)
        return h('div', {
          key: `${element.sourcePath}:${elementIndex}`, class: ['paper-label-hit', { 'paper-selected': active, 'paper-overflow': overflow }],
          'data-source-path': element.sourcePath, role: 'button', tabindex: interactive ? 0 : -1,
          'aria-label': `${t('Select printed element')}: ${element.value}`, 'aria-pressed': !!active,
          'aria-disabled': !interactive,
          style: { ...position(element), width: `${box.width / 8}mm`, height: `${box.height / 8}mm`, transform: `rotate(${element.r || 0}deg)`, transformOrigin: 'top left', touchAction: interactive ? 'none' : 'auto' },
          onFocus: event => { select(event, element.sourcePath); if (interactive) emit('geometry', payload(event)) },
          onClick: event => { select(event, element.sourcePath); if (interactive) emit('geometry', payload(event)) },
          onKeydown: event => { if (interactive) emit('label-key', payload(event)) },
          onPointerdown: event => { if (interactive) emit('label-start', payload(event)) },
          onPointermove: event => emit('label-move', event), onPointerup: event => emit('label-end', event),
          onPointercancel: () => emit('label-cancel'), onLostpointercapture: () => emit('label-cancel')
        }, [renderElement(element, true), ...(active && interactive && !element.r ? [h('button', {
          type: 'button', class: 'paper-resize-handle', 'aria-label': t('Resize element'),
          onPointerdown: event => { event.stopPropagation(); emit('label-start', payload(event, 'resize')) },
          onPointermove: event => { event.stopPropagation(); emit('label-move', event) },
          onPointerup: event => { event.stopPropagation(); emit('label-end', event) },
          onPointercancel: () => emit('label-cancel'), onLostpointercapture: () => emit('label-cancel')
        })] : [])])
      }
      return h('section', { 'aria-label': t('Paper preview') }, [
        h('p', { style: 'color:var(--el-text-color-secondary);font-size:13px;' }, `${t(props.editable ? 'Print edit canvas' : 'Paper preview')} · ${size.width} mm${label ? ` × ${size.height} mm` : ''} · ${index + 1} / ${pages.length}`),
        h('div', { style: 'background:var(--el-fill-color);padding:24px;overflow:auto;max-height:50vh;border-radius:8px;' }, [
          h('div', { class: props.editable ? ['paper-edit-sheet', { 'paper-label-sheet': label, 'paper-grid': label && props.grid }] : undefined, style: {
            position: 'relative', boxSizing: 'border-box', margin: '0 auto', width: `${size.width}mm`,
            ...(label ? { height: `${size.height}mm`, overflow: 'hidden', borderRadius: '4px' } : { minHeight: '100px', padding: `5mm ${marginMm}mm`, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }),
            background: '#fff', color: '#000', textAlign: 'left', fontFamily: 'monospace', fontSize: '12px', fontWeight: 400, letterSpacing: 0, fontKerning: 'none', fontVariantLigatures: 'none', lineHeight: '1.5', boxShadow: '0 2px 12px #0002',
            ...(props.editable ? { zoom: props.zoom || 1 } : {}),
            ...(props.editable && label && props.grid ? { backgroundImage: 'linear-gradient(to right, #ddd7 1px, transparent 1px), linear-gradient(to bottom, #ddd7 1px, transparent 1px)', backgroundSize: '1mm 1mm' } : {})
          } }, label ? elements.filter(element => ['labelText', 'code', 'qr'].includes(element.type)).map(renderLabel) : receiptRows(elements, columns).map(element => element.type === 'qr' && mapped ? h('div', receiptInteraction(element), [renderQr(element)]) : renderElement(element)))
        ]),
        ...(pages.length > 1 ? [h(ElPagination, { layout: 'prev, pager, next', pageSize: 1, total: pages.length, currentPage: index + 1, 'onUpdate:currentPage': value => { emit('label-cancel'); page.value = value }, style: 'justify-content:center;margin-top:12px;' })] : []),
        ...(props.editable && !mapped ? [h('p', { role: 'status' }, t('Editable mapping unavailable'))] : []),
        ...(props.editable && overflowDetected ? [h('p', { role: 'status', class: 'paper-overflow-message' }, t('Elements exceed paper bounds'))] : []),
        h('p', { style: 'font-size:12px;color:var(--el-text-color-secondary);' }, t('Simulation disclaimer')),
        ...(elements.some(item => item.type === 'code') ? [h('p', { style: 'font-size:12px;color:var(--el-text-color-secondary);' }, t('Code preview placeholder help'))] : [])
      ])
    }
  }
})
