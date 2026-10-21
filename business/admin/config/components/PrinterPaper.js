import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import { paperElements, paperSize } from './printer-paper.mjs'

export default defineComponent({
  name: 'PrinterPaper',
  props: { document: { type: Object, required: true } },
  setup(props) {
    const ElPagination = resolveComponent('ElPagination')
    const page = ref(1)
    return () => {
      const document = props.document
      const pages = document.pages || []
      const index = Math.min(Math.max(page.value - 1, 0), Math.max(pages.length - 1, 0))
      const size = paperSize(document)
      const label = document.kind === 'label'
      const elements = paperElements(pages[index] || '')
      return h('section', { 'aria-label': t('Paper preview') }, [
        h('p', { style: 'color:var(--el-text-color-secondary);font-size:13px;' }, `${t('Paper preview')} · ${size.width} mm${label ? ` × ${size.height} mm` : ''} · ${index + 1} / ${pages.length}`),
        h('div', { style: 'background:var(--el-fill-color);padding:24px;overflow:auto;max-height:50vh;border-radius:8px;' }, [
          h('div', { style: {
            position: 'relative', boxSizing: 'border-box', margin: '0 auto', width: `${size.width}mm`,
            ...(label ? { height: `${size.height}mm`, overflow: 'hidden', borderRadius: '4px' } : { minHeight: '100px', padding: '5mm', whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }),
            background: '#fff', color: '#111', fontFamily: 'monospace', fontSize: '12px', lineHeight: '1.5', boxShadow: '0 2px 12px #0002'
          } }, elements.map(element => {
            if (element.type === 'line') return h('br')
            if (element.type === 'text') return h(element.bold ? 'strong' : 'span', element.value)
            const style = { position: 'absolute', left: `${(element.x || 0) / 8}mm`, top: `${(element.y || 0) / 8}mm`, whiteSpace: 'pre', lineHeight: '1.2' }
            if (element.type === 'labelText') return h('span', { style }, element.value)
            return h('span', { title: element.value, style: { ...style, border: '1px dashed #555', padding: '2px', fontSize: '9px', maxWidth: '90%', overflow: 'hidden', height: element.h ? `${element.h / 8}mm` : '8mm' } }, `${element.format}: ${element.value}`)
          }))
        ]),
        ...(pages.length > 1 ? [h(ElPagination, { layout: 'prev, pager, next', pageSize: 1, total: pages.length, currentPage: index + 1, 'onUpdate:currentPage': value => { page.value = value }, style: 'justify-content:center;margin-top:12px;' })] : []),
        h('p', { style: 'font-size:12px;color:var(--el-text-color-secondary);' }, t('Simulation disclaimer')),
        ...(elements.some(item => item.type === 'code') ? [h('p', { style: 'font-size:12px;color:var(--el-text-color-secondary);' }, t('Code preview placeholder help'))] : [])
      ])
    }
  }
})
