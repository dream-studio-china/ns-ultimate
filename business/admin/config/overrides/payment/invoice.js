import Invoice from '../../../../../core/crud-admin/src/configs/collections/payment/Invoice.js'
import InvoiceCloseAction from '../../components/InvoiceCloseAction.vue'
import { t } from '@/i18n'

const config = Invoice.Invoice
const closureField = {
  property: 'paymentClosure',
  label: t('Invoice closure'),
  component: InvoiceCloseAction,
  full_width: true
}

export default {
  ...config,
  list: {
    ...config.list,
    actions: [
      ...(config.list.actions || []),
      { name: 'invoice-close', position: 'list', component: InvoiceCloseAction }
    ]
  },
  detail: {
    ...config.detail,
    detail_display: [
      ...config.detail.detail_display.filter(field => field !== '__all__'),
      closureField,
      ...(config.detail.detail_display.includes('__all__') ? ['__all__'] : [])
    ]
  }
}
