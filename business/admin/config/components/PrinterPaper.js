import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import QRCode from 'qrcode'
import { paperElements, paperSize, printerFontMetrics, qrGeometry, receiptMetrics, receiptRows, textCells } from './printer-paper.mjs'

// Explicit native cells prevent the browser's CJK fallback font from changing pitch.
const glyphs = (value, cellMm) => Array.from(value.replace(/\r\n?/g, '\n'), character => character === '\n' ? h('br') : h('span', { style: { display: 'inline-block', width: `${textCells(character) * cellMm}mm` } }, character))
const position = element => ({ position: 'absolute', left: `${element.x / 8}mm`, top: `${element.y / 8}mm` })

export default defineComponent({
  name: 'PrinterPaper',
  props: { document: { type: Object, required: true } },
  setup(props) {
    const ElPagination = resolveComponent('ElPagination')
    const page = ref(1)
    const qrCache = new Map()
    const renderQr = element => {
      const key = JSON.stringify([element.value, element.e, element.s])
      if (!qrCache.has(key)) {
        if (qrCache.size >= 128) qrCache.clear()
        qrCache.set(key, qrGeometry(element, QRCode.create))
      }
      const geometry = qrCache.get(key)
      const style = element.format === 'QRC' ? position(element) : { display: 'block', textAlign: element.align, lineHeight: 0 }
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
      const elements = paperElements(pages[index] || '')
      const { columns, cellMm, fontMm, gapMm, marginMm } = receiptMetrics(size.width)
      const renderElement = element => {
        if (element.type === 'qr') return renderQr(element)
        if (element.type === 'row') return h('div', { style: {
          display: 'flex', alignItems: 'flex-end', justifyContent: { left: 'flex-start', center: 'center', right: 'flex-end' }[element.align],
          textAlign: element.align, height: `${fontMm * element.h + gapMm}mm`, paddingBottom: `${gapMm}mm`, boxSizing: 'border-box', lineHeight: 0, whiteSpace: 'nowrap'
        } }, element.items.map(item => h('span', {
          // Row alignment positions the scaled slot. Never align the unscaled
          // glyph box inside it again, which would shift enlarged text off paper.
          style: { display: 'inline-block', flexShrink: 0, textAlign: 'left', width: `${textCells(item.value) * cellMm * item.w}mm`, height: `${fontMm * item.h}mm` }
        }, [h(item.bold ? 'strong' : 'span', { style: {
          display: 'block', width: `${textCells(item.value) * cellMm}mm`, textAlign: 'left', fontSize: `${fontMm}mm`, lineHeight: `${fontMm}mm`, whiteSpace: 'pre', fontWeight: item.bold ? 700 : 400,
          transform: `scale(${item.w}, ${item.h})`, transformOrigin: 'top left'
        } }, glyphs(item.value, cellMm))])))
        if (element.type === 'line') return h('br')
        if (element.type === 'text') return h(element.bold ? 'strong' : 'span', element.value)
        const style = { ...position(element), whiteSpace: 'pre', lineHeight: '1.2' }
        if (element.type === 'labelText') {
          const metrics = printerFontMetrics(element.font)
          return h('span', { style: {
            ...style, fontSize: `${metrics.heightMm}mm`, lineHeight: `${metrics.heightMm}mm`, transform: `rotate(${element.r}deg) scale(${element.w}, ${element.h})`, transformOrigin: 'top left'
          } }, glyphs(element.value, metrics.cellMm))
        }
        return h('span', { title: element.value, style: { ...style, border: '1px dashed #555', padding: '2px', fontSize: '9px', maxWidth: '90%', overflow: 'hidden', height: `${element.h / 8}mm` } }, `${element.format}: ${element.value}`)
      }
      return h('section', { 'aria-label': t('Paper preview') }, [
        h('p', { style: 'color:var(--el-text-color-secondary);font-size:13px;' }, `${t('Paper preview')} · ${size.width} mm${label ? ` × ${size.height} mm` : ''} · ${index + 1} / ${pages.length}`),
        h('div', { style: 'background:var(--el-fill-color);padding:24px;overflow:auto;max-height:50vh;border-radius:8px;' }, [
          h('div', { style: {
            position: 'relative', boxSizing: 'border-box', margin: '0 auto', width: `${size.width}mm`,
            ...(label ? { height: `${size.height}mm`, overflow: 'hidden', borderRadius: '4px' } : { minHeight: '100px', padding: `5mm ${marginMm}mm`, whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }),
            background: '#fff', color: '#000', textAlign: 'left', fontFamily: 'monospace', fontSize: '12px', fontWeight: 400, letterSpacing: 0, fontKerning: 'none', fontVariantLigatures: 'none', lineHeight: '1.5', boxShadow: '0 2px 12px #0002'
          } }, (label ? elements.filter(element => ['labelText', 'code', 'qr'].includes(element.type)) : receiptRows(elements, columns)).map(renderElement))
        ]),
        ...(pages.length > 1 ? [h(ElPagination, { layout: 'prev, pager, next', pageSize: 1, total: pages.length, currentPage: index + 1, 'onUpdate:currentPage': value => { page.value = value }, style: 'justify-content:center;margin-top:12px;' })] : []),
        h('p', { style: 'font-size:12px;color:var(--el-text-color-secondary);' }, t('Simulation disclaimer')),
        ...(elements.some(item => item.type === 'code') ? [h('p', { style: 'font-size:12px;color:var(--el-text-color-secondary);' }, t('Code preview placeholder help'))] : [])
      ])
    }
  }
})
