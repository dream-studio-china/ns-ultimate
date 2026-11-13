import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'
import PrinterPaper from './PrinterPaper.js'
import printerSampleData from './printer-sample-data.json'

const defaultSampleData = JSON.stringify(printerSampleData, null, 2)
const SAMPLE_STORAGE_KEY = 'ns-ultimate:printer-sample-data'

const readStoredSampleData = () => {
  try {
    const stored = globalThis.localStorage?.getItem(SAMPLE_STORAGE_KEY)
    if (!stored) return defaultSampleData
    const parsed = JSON.parse(stored)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? stored : defaultSampleData
  } catch {
    return defaultSampleData
  }
}

const storeSampleData = value => {
  try { globalThis.localStorage?.setItem(SAMPLE_STORAGE_KEY, value) } catch { /* storage may be unavailable */ }
}

const clearStoredSampleData = () => {
  try { globalThis.localStorage?.removeItem(SAMPLE_STORAGE_KEY) } catch { /* storage may be unavailable */ }
}

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
    const data = ref(readStoredSampleData())
    const error = ref('')
    const isJob = () => !props.record.definition
    const restoreDefault = () => {
      data.value = defaultSampleData
      document.value = null
      clearStoredSampleData()
    }
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
          storeSampleData(data.value)
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
          ...(!isJob() ? [
            h('label', t('Sample data JSON')),
            h(ElInput, { type: 'textarea', rows: 5, modelValue: data.value, disabled: busy.value, 'onUpdate:modelValue': value => { data.value = value; document.value = null; storeSampleData(value) } }),
            h('p', { style: 'color:#909399;font-size:12px;margin:4px 0 12px' }, t('Sample data is saved in this browser'))
          ] : []),
          ...(error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : []),
          ...(busy.value ? [h('p', { role: 'status' }, t('Loading'))] : []),
          ...(document.value ? [h(PrinterPaper, { document: document.value })] : [])
        ]),
        footer: () => [
          ...(!isJob() ? [h(ElButton, { disabled: busy.value, onClick: restoreDefault }, () => t('Restore default sample'))] : []),
          h(ElButton, { onClick: () => { visible.value = false } }, () => t('Close')),
          ...(!isJob() ? [h(ElButton, { type: 'primary', loading: busy.value, onClick: preview }, () => t('Update preview'))] : [])
        ]
      })
    ])
  }
})
