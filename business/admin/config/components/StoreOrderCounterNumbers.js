import { defineComponent, h, watch, ref } from 'vue'
import { t } from '@/i18n'
import CrudSkeletonAdapter from '@/easyadmin/adapters/crudskeleton/CrudSkeletonAdapter'
import RelationToOne from '../../../../core/crud-admin/src/easyadmin/ui/vue/plugins/list/RelationToOne.vue'

const counterNumbersByOrder = new Map()

function loadCounterNumbers(orderUuid) {
  if (!counterNumbersByOrder.has(orderUuid)) {
    const adapter = new CrudSkeletonAdapter({ name: 'OrderCounter', plural: 'order-counters' })
    const expression = `entity.getTradeOrderUuid() == '${orderUuid}'`
    counterNumbersByOrder.set(orderUuid, adapter.list({ '@filter': expression, '@expands': 'counter', limit: 100 }).then(response => {
      const bindings = Array.isArray(response?.data) ? response.data : []
      return bindings
        .filter(binding => binding.counterCodeSnapshot)
        .map(binding => ({
          relation: binding.counter
        }))
    }).finally(() => {
      // Deduplicate concurrent loads, but do not keep stale bindings or failed requests.
      counterNumbersByOrder.delete(orderUuid)
    }))
  }

  return counterNumbersByOrder.get(orderUuid)
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

export { loadCounterNumbers }
