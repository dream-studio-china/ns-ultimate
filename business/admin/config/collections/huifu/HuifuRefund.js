import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  HuifuRefund: {
    entity: { name: 'HuifuRefund', plural: 'huifu-refunds' },
    form: { fields: [] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['new', 'edit', 'batch_edit', 'batch_delete'],
      list_filter: { refundNo: t('Refund number'), status: t('Status') },
      list_display: ['id', 'refundNo', 'amount', 'providerAmount', 'confirmedAmount', 'status', 'coreApplied', 'providerRefundId', 'createdAt']
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: ['id', 'uuid', 'refundNo', 'invoiceUuid', 'attemptUuid', 'reqDate', 'amount', 'providerAmount', 'confirmedAmount', 'status', 'providerRefundId', 'responseCode', 'responseMessage', 'coreApplied', 'queryCount', 'createdAt', 'updatedAt']
    }
  }
}
