import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'

export default defineComponent({
  name: 'PrinterJobReprintAction',
  props: {
    record: { type: Object, default: () => ({}) },
    refresh: { type: Function, default: () => {} }
  },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElAlert = resolveComponent('ElAlert')
    const busy = ref(false)
    const error = ref('')
    const submit = async() => {
      if (busy.value) return
      if (!props.record.uuid || !window.confirm(t('Confirm reprint job'))) return
      busy.value = true
      error.value = ''
      try {
        const response = await request.post(`/api/v1/manage/printer/jobs/${props.record.uuid}/reprint`, {})
        props.refresh()
        return response.data
      } catch (cause) {
        error.value = cause.response?.data?.error || cause.message || t('Printer operation failed')
      } finally { busy.value = false }
    }
    return () => h('span', { style: 'display:inline-flex;align-items:center;gap:8px;' }, [
      h(ElButton, { link: true, type: 'warning', loading: busy.value, disabled: !props.record.uuid, onClick: submit }, () => t('Reprint')),
      ...(error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : [])
    ])
  }
})
