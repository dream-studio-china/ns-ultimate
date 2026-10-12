import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'
import { uuidFilter } from '../../uuid-filter.mjs'
import { loadRelationRecords } from '@/utils/relation'

export default {
  WechatProfileGrant: {
    entity: { name: 'WechatProfileGrant', plural: 'wechat-profile-grants' },
    form: {
      fields: [
        { property: 'profileType', type: 'select', required: true, type_options: {
          options: [
            { value: 'application', label: t('WeChat application') },
            { value: 'merchant', label: t('WeChat merchant') }
          ]
        }},
        { property: 'profileUuid', required: true, help: t('WeChat grant profile help') },
        { property: 'storeUuid', required: true, relation: { entity: 'Store', valueKey: 'uuid' } }
      ]
    },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['edit', 'batch_edit', 'batch_delete'],
      list_filter: {
        profileType: {
          __label: t('Type'),
          application: t('WeChat application'),
          merchant: t('WeChat merchant')
        },
        storeUuid: uuidFilter('storeUuid', t('Store'), () => loadRelationRecords({ name: 'Store', valueKey: 'uuid' }))
      },
      list_display: [
        'id',
        'profileType',
        'profileUuid',
        { property: 'storeUuid', relation: { entity: 'Store', valueKey: 'uuid' } },
        'createdAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
