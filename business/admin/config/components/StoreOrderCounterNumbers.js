import { defineComponent, h, watch, ref } from 'vue'
import { t } from '@/i18n'
import CrudSkeletonAdapter from '@/easyadmin/adapters/crudskeleton/CrudSkeletonAdapter'
import RelationToOne from '../../../../core/crud-admin/src/easyadmin/ui/vue/plugins/list/RelationToOne.vue'

const counterNumbersByOrder = new Map()
const tradeOrdersByOrder = new Map()

export function loadOrderCounterBindings(orderUuid) {
  if (typeof orderUuid !== 'string' || !/^[0-9a-f-]{36}$/i.test(orderUuid)) return Promise.resolve([])
  if (!counterNumbersByOrder.has(orderUuid)) {
    const adapter = new CrudSkeletonAdapter({ name: 'OrderCounter', plural: 'order-counters' })
    const expression = `entity.getTradeOrderUuid() == '${orderUuid}'`
    counterNumbersByOrder.set(orderUuid, adapter.list({ '@filter': expression, '@expands': 'counter,counterSession', limit: 100 })
      .then(response => Array.isArray(response?.data) ? response.data : [])
      .catch(error => {
        // Failed loads must remain retryable.
        counterNumbersByOrder.delete(orderUuid)
        throw error
      }))
  }

  return counterNumbersByOrder.get(orderUuid)
}

export function loadCounterNumbers(orderUuid) {
  return loadOrderCounterBindings(orderUuid).then(bindings => bindings
    .filter(binding => binding.counterCodeSnapshot)
    .map(binding => ({
      relation: binding.counter
    })))
}

export function loadTradeOrderRecord(orderUuid) {
  if (typeof orderUuid !== 'string' || !/^[0-9a-f-]{36}$/i.test(orderUuid)) return Promise.resolve(null)
  if (!tradeOrdersByOrder.has(orderUuid)) {
    const adapter = new CrudSkeletonAdapter({ name: 'Order', plural: 'orders' })
    const expression = `entity.getUuid() == '${orderUuid}'`
    tradeOrdersByOrder.set(orderUuid, adapter.list({ '@filter': expression, limit: 1 })
      .then(response => {
        const records = Array.isArray(response?.data) ? response.data : []
        return records.find(record => record?.uuid === orderUuid) || null
      })
      .catch(error => {
        tradeOrdersByOrder.delete(orderUuid)
        throw error
      }))
  }

  return tradeOrdersByOrder.get(orderUuid)
}

export default defineComponent({
  name: 'StoreOrderCounterNumbers',
  props: {
    data: { type: [String, Number], default: '' },
    record: { type: Object, required: true }
  },
  setup(props) {
    const numbers = ref([])
    const loading = ref(false)
    const failed = ref(false)
    watch(() => props.data, async(orderUuid, _previous, onCleanup) => {
      let active = true
      onCleanup(() => { active = false })
      numbers.value = []
      failed.value = false
      loading.value = false
      if (typeof orderUuid !== 'string' || !/^[0-9a-f-]{36}$/i.test(orderUuid)) return
      loading.value = true
      try {
        const result = await loadCounterNumbers(orderUuid)
        if (active) numbers.value = result
      } catch {
        if (active) failed.value = true
      } finally {
        if (active) loading.value = false
      }
    }, { immediate: true })

    return () => {
      if (failed.value) return h('span', t('Counter numbers failed to load'))
      if (loading.value) return h('span', '…')
      if (!numbers.value.length) return h('span', '—')

      return h('span', numbers.value.flatMap(({ relation }, index) => [
        ...(index ? [', '] : []),
        h(RelationToOne, {
          value: relation,
          field: { property: 'counter', relation: { entity: 'Counter', valueKey: 'uuid' } }
        })
      ]))
    }
  }
})
