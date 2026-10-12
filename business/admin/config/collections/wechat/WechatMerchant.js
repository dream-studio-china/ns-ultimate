import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatMerchant: {
    entity: { name: 'WechatMerchant', plural: 'wechat-merchants' },
    form: {
      fields: [
        { property: 'name', required: true },
        { property: 'merchantId', required: true, help: t('WeChat merchant ID help') },
        { property: 'mode', type: 'select', required: false, default_value: 'direct', type_options: {
          options: [
            { value: 'direct', label: t('Direct merchant') },
            { value: 'partner', label: t('Service provider') }
          ]
        }},
        { property: 'enabled', type: 'boolean', default_value: true }
      ]
    },
    list: {
      query: orderByIdDesc,
      list_filter: {
        name: t('Name'),
        merchantId: t('Merchant ID'),
        enabled: t('Enabled')
      },
      list_display: [
        'id',
        'name',
        'merchantId',
        'mode',
        'enabled',
        'createdAt',
        'updatedAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
