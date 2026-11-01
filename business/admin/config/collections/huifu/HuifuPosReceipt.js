import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  HuifuPosReceipt: {
    entity: { name: 'HuifuPosReceipt', plural: 'huifu-pos-receipts' },
    form: { fields: [] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['new', 'edit', 'batch_edit', 'batch_delete'],
      list_filter: { storeUuid: t('Store'), terminalId: t('Terminal ID'), status: t('Status') },
      list_display: ['id', 'storeUuid', 'terminalId', 'providerTransactionId', 'amount', 'status', 'invoiceUuid', 'paidAt', 'createdAt']
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: ['id', 'uuid', 'storeUuid', 'merchantUuid', 'terminalId', 'providerTransactionId', 'invoiceUuid', 'orderUuid', 'amount', 'currency', 'paidAt', 'status', 'error', 'createdAt', 'updatedAt']
    }
  }
}
