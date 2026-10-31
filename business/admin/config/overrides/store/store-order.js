import { t } from '@/i18n'
import StoreOrder from '../../../../../core/crud-admin/src/configs/collections/store/StoreOrder.js'
import StoreOrderCounterNumbers from '../../components/StoreOrderCounterNumbers.js'
import { createOrderDiningModeField } from '../../components/OrderDiningMode.js'

const config = StoreOrder.StoreOrder
const counterNumberField = {
  property: 'tradeOrderUuid',
  label: t('Counter number'),
  component: StoreOrderCounterNumbers,
  field_options: { sortable: false, minWidth: 120 }
}
const diningModeField = createOrderDiningModeField('tradeOrderUuid')

export default {
  ...config,
  detail: {
    ...config.detail,
    detail_display: [counterNumberField, diningModeField, '__all__']
  },
  list: {
    ...config.list,
    list_display: [
      ...config.list.list_display.slice(0, 3),
      counterNumberField,
      diningModeField,
      ...config.list.list_display.slice(3)
    ]
  }
}
