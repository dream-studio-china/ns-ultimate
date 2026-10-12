import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatApplication: {
    entity: { name: 'WechatApplication', plural: 'wechat-applications' },
    form: {
      fields: [
        { property: 'name', required: true },
        { property: 'type', type: 'select', required: true, type_options: {
          options: [
            { value: 'miniapp', label: t('Mini Program') },
            { value: 'official', label: t('Official Account') }
          ]
        }},
        { property: 'appId', required: true, help: t('WeChat AppID help') },
        { property: 'secret', type: 'input', help: t('WeChat secret help') },
        { property: 'officialToken', type: 'input', help: t('WeChat official token help') },
        { property: 'officialAesKey', type: 'input', help: t('WeChat official AES key help') },
        { property: 'redirectHosts', type: 'array', help: t('WeChat redirect hosts help') },
        { property: 'enabled', type: 'boolean', default_value: true }
      ]
    },
    list: {
      query: orderByIdDesc,
      list_filter: {
        name: t('Name'),
        type: {
          __label: t('Type'),
          miniapp: t('Mini Program'),
          official: t('Official Account')
        },
        appId: t('App ID'),
        enabled: t('Enabled')
      },
      list_display: [
        'id',
        'name',
        'type',
        'appId',
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
