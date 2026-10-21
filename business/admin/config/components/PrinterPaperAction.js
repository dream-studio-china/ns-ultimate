import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'
import PrinterPaper from './PrinterPaper.js'

const sampleData = JSON.stringify({ demoTitle: 'OFFLINE DEMO', order: { number: 'DEMO-001', items: [{ name: 'Demo tea', quantity: 2 }, { name: 'Demo cake', quantity: 1 }] } }, null, 2)

export default defineComponent({
  name: 'PrinterPaperAction',
  props: { record: { type: Object, default: () => ({}) } },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElDialog = resolveComponent('ElDialog')
    const ElInput = resolveComponent('ElInput')
    const ElAlert = resolveComponent('ElAlert')
    const visible = ref(false)
    const busy = ref(false)
    const document = ref(null)
    const data = ref(sampleData)
    const error = ref('')
    const isJob = () => !props.record.definition
    const preview = async() => {
      if (busy.value) return
      busy.value = true
      error.value = ''
      document.value = null
      try {
        let response
        if (isJob()) {
          response = await request.get(`/api/v1/manage/printer/jobs/${props.record.id || props.record.uuid}/preview`)
        } else {
          const parsed = JSON.parse(data.value)
          if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error(t('Invalid JSON'))
          response = await request.post('/api/v1/manage/printer/preview', { template: props.record.definition, data: parsed })
        }
        document.value = response.data.document
      } catch (cause) { error.value = cause.response?.data?.error || cause.message || t('Preview failed') }
      finally { busy.value = false }
    }
    const open = () => { visible.value = true; return preview() }
    return () => h('span', [
      h(ElButton, { link: true, type: 'primary', onClick: open }, () => t('Paper preview')),
      h(ElDialog, { modelValue: visible.value, 'onUpdate:modelValue': value => { visible.value = value }, title: t('Paper preview'), width: 'min(820px, 94vw)', alignCenter: true, appendToBody: true, closeOnClickModal: false }, {
        default: () => h('div', { style: 'max-height:75vh;overflow:auto;' }, [
          ...(!isJob() ? [h('label', t('Sample data JSON')), h(ElInput, { type: 'textarea', rows: 5, modelValue: data.value, disabled: busy.value, 'onUpdate:modelValue': value => { data.value = value; document.value = null } })] : []),
          ...(error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : []),
          ...(busy.value ? [h('p', { role: 'status' }, t('Loading'))] : []),
          ...(document.value ? [h(PrinterPaper, { document: document.value })] : [])
        ]),
        footer: () => [
          h(ElButton, { onClick: () => { visible.value = false } }, () => t('Close')),
          ...(!isJob() ? [h(ElButton, { type: 'primary', loading: busy.value, onClick: preview }, () => t('Update preview'))] : [])
        ]
      })
    ])
  }
})
