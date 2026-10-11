import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatApplicationRevision: {
    entity: { name: 'WechatApplicationRevision', plural: 'wechat-application-revisions' },
    form: {
      fields: [
        { property: 'application', required: true, hidden: ['update'], relation: { entity: 'WechatApplication', valueKey: 'id' } },
        { property: 'secret', type: 'input', hidden: ['update'], help: t('WeChat secret help') },
        { property: 'officialToken', type: 'input', hidden: ['update'], help: t('WeChat official token help') },
        { property: 'officialAesKey', type: 'input', hidden: ['update'], help: t('WeChat official AES key help') },
        { property: 'redirectHosts', type: 'array', hidden: ['update'], help: t('WeChat redirect hosts help') },
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
        application: t('WeChat application'),
        state: {
          __label: t('State'),
          draft: t('Draft'),
          published: t('Published'),
          superseded: t('Superseded')
        },
        secretConfigured: t('Secret configured')
      },
      list_display: [
        'id',
        { property: 'application', relation: { entity: 'WechatApplication', valueKey: 'id' } },
        'revision',
        'state',
        'secretConfigured',
        'officialTokenConfigured',
        'redirectHosts',
        'publishedAt',
        'createdAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
