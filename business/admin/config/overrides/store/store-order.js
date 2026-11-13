import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import StoreOrder from '../../../../../core/crud-admin/src/configs/collections/store/StoreOrder.js'
import StoreOrderCounterNumbers from '../../components/StoreOrderCounterNumbers.js'
import { createStoreOrderDiningModeField } from '../../components/StoreOrderDiningMode.js'

const config = StoreOrder.StoreOrder
const { storeCodeSnapshot: _storeCodeSnapshot, ...baseListFilter } = config.list.list_filter || {}
const diningModeField = typeof createStoreOrderDiningModeField === 'function'
  ? createStoreOrderDiningModeField()
  : null
const counterNumberField = {
  property: 'tradeOrderUuid',
  label: t('Counter number'),
  component: StoreOrderCounterNumbers,
  field_options: { sortable: false, minWidth: 120 }
}

const storeFilter = () => loadRelationRecords({ name: 'Store', valueKey: 'uuid' }).then(stores => ({
  label: t('Store'),
  type: 'select',
  default: null,
  expression: "entity.getStore().getUuid() == ':value'",
  data: stores.filter(store => typeof store.uuid === 'string' && store.uuid).map(store => ({
    value: store.uuid,
    label: [store.name, store.code].filter(Boolean).join(' · ') || store.__toString || store.uuid
  }))
}))

export default {
  ...config,
  detail: {
    ...config.detail,
    detail_display: [counterNumberField, '__all__']
  },
  list: {
    ...config.list,
    list_filter: {
      ...baseListFilter,
      'store.uuid': storeFilter
    },
    list_display: [
      ...config.list.list_display.slice(0, 3),
      ...(diningModeField ? [diningModeField] : []),
      counterNumberField,
      ...config.list.list_display.slice(3)
    ]
  }
}
