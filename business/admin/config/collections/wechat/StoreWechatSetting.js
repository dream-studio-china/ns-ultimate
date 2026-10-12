import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  StoreWechatSetting: {
    entity: { name: 'StoreWechatSetting', plural: 'store-wechat-settings' },
    form: { fields: [
      { property: 'storeUuid', required: true, hidden: 'update', relation: { entity: 'Store', valueKey: 'uuid' } },
      { property: 'miniappUuid', required: false, relation: { entity: 'WechatApplication', valueKey: 'uuid' },
        field_options: { label: t('Store Mini Program application') },
        type_options: { placeholder: t('Store Mini Program application placeholder') },
        relation_filter: { '@filter': 'entity.getType() == "miniapp"' },
        help: t('Store Mini Program application help') },
      { property: 'officialUuid', required: false, relation: { entity: 'WechatApplication', valueKey: 'uuid' },
        field_options: { label: t('Store Official Account application') },
        type_options: { placeholder: t('Store Official Account application placeholder') },
        relation_filter: { '@filter': 'entity.getType() == "official"' },
        help: t('Store Official Account application help') },
      { property: 'merchantUuid', required: true, relation: { entity: 'WechatMerchant', valueKey: 'uuid' },
        field_options: { label: t('Store WeChat merchant') },
        type_options: { placeholder: t('Store WeChat merchant placeholder') },
        help: t('Store WeChat merchant help') }
    ] },
    list: { query: orderByIdDesc, disabled_actions: ['delete'], list_filter: { storeUuid: t('Store') }, list_display: [
      'id', 'storeUuid',
      { property: 'miniappUuid', label: t('Store Mini Program application') },
      { property: 'officialUuid', label: t('Store Official Account application') },
      { property: 'merchantUuid', label: t('Store WeChat merchant') }
    ] },
    detail: { detail_display: [
      'id', 'uuid', 'storeUuid',
      { property: 'miniappUuid', label: t('Store Mini Program application') },
      { property: 'officialUuid', label: t('Store Official Account application') },
      { property: 'merchantUuid', label: t('Store WeChat merchant') }
    ] }
  }
}
