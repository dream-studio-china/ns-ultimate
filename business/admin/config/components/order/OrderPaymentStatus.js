import { defineComponent, h } from 'vue'
import { t } from '@/i18n'
import { paymentStatusLabel } from './order-refund.mjs'

const OrderPaymentStatus = defineComponent({
  name: 'OrderPaymentStatus',
  props: { record: { type: Object, required: true } },
  setup(props) {
    return () => h('span', t(paymentStatusLabel(props.record.paymentStatus)))
  }
})

export function createOrderPaymentStatusField() {
  return { property: 'paymentStatus', label: t('Payment status'), component: OrderPaymentStatus }
}
