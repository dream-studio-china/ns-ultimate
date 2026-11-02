import { defineComponent, h, ref, watch } from 'vue'
import { t } from '@/i18n'
import CrudSkeletonAdapter from '@/easyadmin/adapters/crudskeleton/CrudSkeletonAdapter'
import OrderDiningMode from './OrderDiningMode.js'

const ordersByUuid = new Map()

function loadStoreOrderDiningMode(orderUuid) {
  if (!ordersByUuid.has(orderUuid)) {
    const adapter = new CrudSkeletonAdapter({ name: 'Order', plural: 'orders' })
    ordersByUuid.set(orderUuid, adapter.retrieve(orderUuid).then(response => (
      response?.data && typeof response.data === 'object' ? response.data : null
    )).finally(() => {
      // Deduplicate concurrent list renders without retaining failed lookups.
      ordersByUuid.delete(orderUuid)
    }))
  }

  return ordersByUuid.get(orderUuid)
}

const StoreOrderDiningMode = defineComponent({
  name: 'StoreOrderDiningMode',
  props: {
    data: { type: [String, Number], default: '' },
    record: { type: Object, required: true }
  },
  setup(props) {
    const order = ref(null)
    const loading = ref(false)
    const failed = ref(false)

    watch(() => props.data, async(tradeOrderUuid, _previous, onCleanup) => {
      let active = true
      onCleanup(() => { active = false })
      order.value = null
      failed.value = false
      loading.value = false
      if (typeof tradeOrderUuid !== 'string' || !/^[0-9a-f-]{36}$/i.test(tradeOrderUuid)) return
      loading.value = true
      try {
        const result = await loadStoreOrderDiningMode(tradeOrderUuid)
        if (active) order.value = result
      } catch {
        if (active) failed.value = true
      } finally {
        if (active) loading.value = false
      }
    }, { immediate: true })

    return () => {
      if (failed.value) return h('span', t('Dining mode failed to load'))
      if (loading.value) return h('span', '…')
      return h(OrderDiningMode, { record: order.value || {} })
    }
  }
})

export function createStoreOrderDiningModeField() {
  return {
    property: 'tradeOrderUuid',
    label: t('Dining mode'),
    component: StoreOrderDiningMode,
    field_options: { sortable: false, minWidth: 130 }
  }
}

export default StoreOrderDiningMode
export { loadStoreOrderDiningMode }
