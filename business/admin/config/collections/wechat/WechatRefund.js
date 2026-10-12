import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatRefundAttempt: {
    entity: { name: 'WechatRefundAttempt', plural: 'wechat-refunds' },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['new', 'edit', 'delete', 'batch_edit', 'batch_delete'],
      list_filter: {
        status: {
          __label: t('State'),
          requested: t('Requested'),
          processing: t('Processing'),
          succeeded: t('Succeeded'),
          failed: t('Failed'),
          unknown: t('Unknown'),
          closed: t('Closed')
        }
      },
      list_display: [
        'id',
        'providerRefundNo',
        'amount',
        'status',
        'providerRefundId',
        'createdAt',
        'updatedAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
