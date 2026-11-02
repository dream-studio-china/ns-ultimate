import { t } from '@/i18n'
import StoreOrder from '../../../../../core/crud-admin/src/configs/collections/store/StoreOrder.js'
import StoreOrderCounterNumbers from '../../components/StoreOrderCounterNumbers.js'
import { createStoreOrderDiningModeField } from '../../components/StoreOrderDiningMode.js'

const config = StoreOrder.StoreOrder
const diningModeField = createStoreOrderDiningModeField()
const counterNumberField = {
  property: 'tradeOrderUuid',
  label: t('Counter number'),
  component: StoreOrderCounterNumbers,
  field_options: { sortable: false, minWidth: 120 }
}

export default {
  ...config,
  detail: {
    ...config.detail,
    detail_display: [counterNumberField, '__all__']
  },
  list: {
    ...config.list,
    list_display: [
      ...config.list.list_display.slice(0, 3),
      diningModeField,
      counterNumberField,
      ...config.list.list_display.slice(3)
    ]
  }
}
