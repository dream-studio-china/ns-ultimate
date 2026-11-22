import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'

export default defineComponent({
  name: 'PrinterDeviceStatusAction',
  props: { refresh: { type: Function, default: () => {} } },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElAlert = resolveComponent('ElAlert')
    const busy = ref(false)
    const error = ref('')

    const refreshStatuses = async() => {
      if (busy.value) return
      busy.value = true
      error.value = ''
      try {
        const response = await request.get('/api/v1/manage/printer/devices')
        const devices = Array.isArray(response?.items) ? response.items : Array.isArray(response?.data) ? response.data : []
        for (const device of devices.filter(item => item.enabled)) {
          await request.post(`/api/v1/manage/printer/devices/${device.uuid}/status`)
        }
        props.refresh()
      } catch (cause) {
        error.value = cause.response?.data?.error || cause.message || t('Printer operation failed')
      } finally {
        busy.value = false
      }
    }

    return () => h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;' }, [
      h(ElButton, { loading: busy.value, onClick: refreshStatuses }, () => t('Refresh device status')),
      ...(error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : [])
    ])
  }
})
