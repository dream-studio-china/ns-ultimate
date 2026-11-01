import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  HuifuPaymentAttempt: {
    entity: { name: 'HuifuPaymentAttempt', plural: 'huifu-payment-attempts' },
    form: { fields: [] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['new', 'edit', 'batch_edit', 'batch_delete'],
      list_filter: { storeUuid: t('Store'), status: t('Status'), reqSeqId: t('Request sequence ID') },
      list_display: ['id', 'storeUuid', 'reqSeqId', 'channel', 'amount', 'status', 'transactionId', 'createdAt']
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: ['id', 'uuid', 'storeUuid', 'providerProfileUuid', 'merchantUuid', 'huifuId', 'reqDate', 'reqSeqId', 'channel', 'tradeType', 'amount', 'currency', 'status', 'responseCode', 'responseMessage', 'hfSeqId', 'transactionId', 'providerTransStatus', 'queryCount', 'createdAt', 'updatedAt']
    }
  }
}
