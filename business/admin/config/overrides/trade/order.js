import Order from '../../../../../core/crud-admin/src/configs/collections/trade/Order.js'
import { createOrderDiningModeField } from '../../components/OrderDiningMode.js'
import OrderRefundAction from '../../components/OrderRefundAction.vue'

const config = Order.Order
const diningModeField = createOrderDiningModeField()

export default {
  ...config,
  list: {
    ...config.list,
    actions: [
      ...(config.list.actions || []),
      { name: 'order-refund', position: 'list', component: OrderRefundAction }
    ],
    list_display: [
      ...config.list.list_display.slice(0, 5),
      diningModeField,
      ...config.list.list_display.slice(5)
    ]
  },
  detail: {
    ...config.detail,
    detail_display: [
      ...config.detail.detail_display.slice(0, 6),
      diningModeField,
      ...config.detail.detail_display.slice(6)
    ]
  }
}
