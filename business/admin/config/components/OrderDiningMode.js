import { defineComponent, h, ref, watch } from 'vue'
import { t } from '@/i18n'
import { diningModeFromOrderCounterBindings } from './order-dining-mode.mjs'
import { loadOrderCounterBindings, loadTradeOrderRecord } from './StoreOrderCounterNumbers.js'

const OrderDiningMode = defineComponent({
  name: 'OrderDiningMode',
  props: {
    data: { type: [String, Number], default: '' },
    record: { type: Object, required: true }
  },
  setup(props) {
    const mode = ref(null)
    const loading = ref(false)
    const failed = ref(false)
    watch(() => props.data, async(orderUuid, _previous, onCleanup) => {
      let active = true
      onCleanup(() => { active = false })
      const metadataMode = diningModeFromOrderCounterBindings(null, props.record)
      mode.value = metadataMode
      loading.value = false
      failed.value = false
      if (typeof orderUuid !== 'string' || !/^[0-9a-f-]{36}$/i.test(orderUuid)) return
      loading.value = true
      let loadFailed = false
      let sessionMode = null
      try {
        const bindings = await loadOrderCounterBindings(orderUuid)
        if (active) sessionMode = diningModeFromOrderCounterBindings(bindings)
      } catch {
        loadFailed = true
      }
      if (active && !metadataMode && !sessionMode) {
        try {
          const tradeOrder = await loadTradeOrderRecord(orderUuid)
          if (active) sessionMode = diningModeFromOrderCounterBindings(null, tradeOrder)
        } catch {
          loadFailed = true
        }
      }
      if (!active) return
      mode.value = sessionMode || metadataMode
      failed.value = !mode.value && loadFailed
      loading.value = false
    }, { immediate: true })

    return () => {
      if (failed.value) return h('span', t('Dining mode failed to load'))
      if (loading.value) return h('span', '…')
      if (!mode.value) return h('span', { style: { color: 'var(--el-text-color-secondary)' } }, t('Dining mode not recorded'))
      const label = mode.value.name || mode.value.uuid?.slice(0, 8) || '-'
      return h('span', { title: mode.value.uuid || undefined }, label)
    }
  }
})

export function createOrderDiningModeField(property) {
  return {
    property,
    label: t('Dining mode'),
    component: OrderDiningMode,
    field_options: { sortable: false, minWidth: 130 }
  }
}

export default OrderDiningMode
