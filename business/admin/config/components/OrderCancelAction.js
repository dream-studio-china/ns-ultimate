import { defineComponent, h, ref, resolveComponent } from 'vue'
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { t } from '@/i18n'
import request from '@/utils/request'

const CANCELLABLE_STATUSES = new Set(['draft', 'pending', 'confirmed'])

export default defineComponent({
  name: 'OrderCancelAction',
  props: {
    record: { type: Object, default: () => ({}) },
    refresh: { type: Function, default: () => {} }
  },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElAlert = resolveComponent('ElAlert')
    const busy = ref(false)
    const error = ref('')

    const cancelOrder = async() => {
      if (busy.value || !props.record.id || !CANCELLABLE_STATUSES.has(props.record.status)) return
      if (!window.confirm(t('Confirm order cancellation'))) return

      busy.value = true
      error.value = ''
      try {
        await request.post(`/api/v1/manage/orders/${props.record.id}/do/cancel`)
        createUiFeedback().success(t('Order cancelled'))
        props.refresh()
      } catch (cause) {
        error.value = cause.response?.data?.error || cause.response?.data?.message || cause.message || t('Order cancellation failed')
      } finally {
        busy.value = false
      }
    }

    return () => {
      if (!CANCELLABLE_STATUSES.has(props.record?.status)) return null

      return h('span', { style: 'display:inline-flex;align-items:center;gap:8px;' }, [
        h(ElButton, {
          size: 'small',
          type: 'danger',
          plain: true,
          loading: busy.value,
          disabled: !props.record.id,
          onClick: cancelOrder
        }, () => t('Cancel order')),
        ...(error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : [])
      ])
    }
  }
})
