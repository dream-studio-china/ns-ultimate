import { defineComponent, h } from 'vue'
import { t } from '@/i18n'
import { diningModeFromOrderMetadata } from './order-dining-mode.mjs'

const OrderDiningMode = defineComponent({
  name: 'OrderDiningMode',
  props: {
    data: { type: [Object, String, Array], default: null },
    record: { type: Object, required: true }
  },
  setup(props) {
    return () => {
      const mode = diningModeFromOrderMetadata(props.record)
      if (!mode) {
        return h('span', { style: { color: 'var(--el-text-color-secondary)' } }, t('Not recorded'))
      }

      const label = mode.name || mode.uuid.slice(0, 8)
      return h('span', { title: mode.uuid || undefined }, label)
    }
  }
})

export function createOrderDiningModeField(property = 'diningModeUuid') {
  return {
    property,
    label: t('Dining mode'),
    component: OrderDiningMode,
    field_options: { sortable: false, minWidth: 130 }
  }
}

export default OrderDiningMode
