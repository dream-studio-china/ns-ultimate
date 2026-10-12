import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'
import { uuidFilter } from '../../uuid-filter.mjs'
import { loadRelationRecords } from '@/utils/relation'

export default {
  StoreWechatSetting: {
    entity: { name: 'StoreWechatSetting', plural: 'store-wechat-settings' },
    form: {
      fields: [
        { property: 'storeUuid', required: true, relation: { entity: 'Store', valueKey: 'uuid' } },
        { property: 'miniappRevision', required: false, relation: { entity: 'WechatApplicationRevision', valueKey: 'uuid' } },
        { property: 'officialRevision', required: false, relation: { entity: 'WechatApplicationRevision', valueKey: 'uuid' } },
        { property: 'merchantRevision', required: false, relation: { entity: 'WechatMerchantRevision', valueKey: 'uuid' } },
        { property: 'subMerchantId', required: false, help: t('WeChat sub merchant help') },
        { property: 'subAppId', required: false, help: t('WeChat sub app help') },
        { property: 'capabilities', type: 'json', required: false, help: t('WeChat capabilities help') },
        { property: 'enabled', type: 'boolean', default_value: true }
      ]
    },
    list: {
      query: orderByIdDesc,
      list_filter: {
        storeUuid: uuidFilter('storeUuid', t('Store'), () => loadRelationRecords({ name: 'Store', valueKey: 'uuid' })),
        enabled: t('Enabled')
      },
      list_display: [
        'id',
        { property: 'storeUuid', relation: { entity: 'Store', valueKey: 'uuid' } },
        { property: 'miniappRevision', relation: { entity: 'WechatApplicationRevision', valueKey: 'uuid' } },
        { property: 'merchantRevision', relation: { entity: 'WechatMerchantRevision', valueKey: 'uuid' } },
        'capabilities',
        'enabled',
        'updatedAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
