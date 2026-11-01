import Order from '../../../../../core/crud-admin/src/configs/collections/trade/Order.js'
import OrderCancelAction from '../../components/OrderCancelAction.js'
import { createOrderDiningModeField } from '../../components/OrderDiningMode.js'

const config = Order.Order
const diningModeField = createOrderDiningModeField('uuid')

export default {
  ...config,
  list: {
    ...config.list,
    list_display: [
      ...config.list.list_display.slice(0, 5),
      diningModeField,
      ...config.list.list_display.slice(5)
    ],
    actions: [
      ...(config.list.actions || []),
      { name: 'order-cancel', position: 'list', component: OrderCancelAction }
    ]
  },
  detail: {
    ...config.detail,
    detail_display: [
      ...config.detail.detail_display.slice(0, 5),
      diningModeField,
      ...config.detail.detail_display.slice(5)
    ]
  }
}
