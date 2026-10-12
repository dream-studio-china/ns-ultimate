import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatMerchant: {
    entity: { name: 'WechatMerchant', plural: 'wechat-merchants' },
    form: { fields: [
      { property: 'name', required: true },
      { property: 'merchantId', required: true },
      { property: 'apiV3Key', type: 'input', required: false, help: t('Credential replacement help') },
      { property: 'privateKey', type: 'textarea', required: false },
      { property: 'certificate', type: 'textarea', required: false },
      { property: 'platformCertificate', type: 'textarea', required: false }
    ] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete'],
      list_filter: { name: t('Name'), merchantId: t('Merchant ID') },
      list_display: ['id', 'name', 'merchantId']
    },
    detail: { detail_display: ['id', 'uuid', 'name', 'merchantId'] }
  }
}
