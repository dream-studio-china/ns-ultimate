import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatMerchantRevision: {
    entity: { name: 'WechatMerchantRevision', plural: 'wechat-merchant-revisions' },
    form: {
      fields: [
        { property: 'merchant', required: true, hidden: ['update'], relation: { entity: 'WechatMerchant', valueKey: 'id' } },
        { property: 'mode', type: 'select', required: false, default_value: 'direct', hidden: ['update'], type_options: {
          options: [
            { value: 'direct', label: t('Direct merchant') },
            { value: 'partner', label: t('Service provider') }
          ]
        }},
        { property: 'apiV3Key', type: 'input', hidden: ['update'], help: t('WeChat API v3 key help') },
        { property: 'privateKey', type: 'textarea', hidden: ['update'], help: t('WeChat merchant private key help') },
        { property: 'certificate', type: 'textarea', hidden: ['update'], help: t('WeChat merchant certificate help') },
        { property: 'platformPublicKeyId', type: 'input', hidden: ['update'], help: t('WeChat platform public key id help') },
        { property: 'platformPublicKey', type: 'textarea', hidden: ['update'], help: t('WeChat platform public key help') },
        { property: 'state', type: 'select', required: false, type_options: {
          options: [
            { value: 'draft', label: t('Draft') },
            { value: 'published', label: t('Published') }
          ]
        }, help: t('WeChat publish help') }
      ]
    },
    list: {
      query: orderByIdDesc,
      list_filter: {
        merchant: t('WeChat merchant'),
        state: {
          __label: t('State'),
          draft: t('Draft'),
          published: t('Published'),
          superseded: t('Superseded')
        }
      },
      list_display: [
        'id',
        { property: 'merchant', relation: { entity: 'WechatMerchant', valueKey: 'id' } },
        'revision',
        'mode',
        'state',
        'apiV3KeyConfigured',
        'privateKeyConfigured',
        'certificateConfigured',
        'publishedAt',
        'createdAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
