import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  StoreWechatSetting: {
    entity: { name: 'StoreWechatSetting', plural: 'store-wechat-settings' },
    form: { fields: [
      { property: 'storeUuid', required: true, relation: { entity: 'Store', valueKey: 'uuid' } },
      { property: 'miniappUuid', required: false, relation: { entity: 'WechatApplication', valueKey: 'uuid' }, help: t('Mini Program') },
      { property: 'officialUuid', required: false, relation: { entity: 'WechatApplication', valueKey: 'uuid' }, help: t('Official Account') },
      { property: 'merchantUuid', required: true, relation: { entity: 'WechatMerchant', valueKey: 'uuid' } }
    ] },
    list: { query: orderByIdDesc, disabled_actions: ['delete'], list_filter: { storeUuid: t('Store') }, list_display: ['id', 'storeUuid', 'miniappUuid', 'officialUuid', 'merchantUuid'] },
    detail: { detail_display: ['id', 'uuid', 'storeUuid', 'miniappUuid', 'officialUuid', 'merchantUuid'] }
  }
}
