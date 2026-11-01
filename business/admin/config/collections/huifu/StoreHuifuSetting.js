import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  StoreHuifuSetting: {
    entity: { name: 'StoreHuifuSetting', plural: 'store-huifu-settings' },
    form: { fields: [
      { property: 'storeUuid', required: true, hidden: 'update', relation: { entity: 'Store', valueKey: 'uuid' },
        field_options: { label: t('Store') } },
      { property: 'merchantUuid', required: false, relation: { entity: 'HuifuMerchant', valueKey: 'uuid' },
        field_options: { label: t('Merchant') } },
      { property: 'enabled', type: 'boolean', required: false, default_value: true },
      { property: 'allowedMethods', type: 'json', required: false, default_value: ['jspay'],
        field_options: { label: t('Allowed payment methods') }, help: t('Huifu allowed methods help') },
      { property: 'defaultMethod', type: 'select', required: true, default_value: 'jspay', type_options: { options: [
        { value: 'jspay', label: 'jspay' },
        { value: 'native', label: 'native' },
        { value: 'quickpay', label: 'quickpay' }
      ] } },
      { property: 'posEnabled', type: 'boolean', required: false, default_value: false, field_options: { label: t('POS enabled') } }
    ] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete'],
      list_filter: { storeUuid: t('Store') },
      list_display: ['id', 'storeUuid', 'merchantUuid', 'enabled', 'defaultMethod', 'posEnabled']
    },
    detail: { detail_display: ['id', 'uuid', 'storeUuid', 'merchantUuid', 'enabled', 'allowedMethods', 'defaultMethod', 'posEnabled', 'createdAt', 'updatedAt'] }
  }
}
