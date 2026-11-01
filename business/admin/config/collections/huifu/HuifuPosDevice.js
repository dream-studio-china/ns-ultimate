import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  HuifuPosDevice: {
    entity: { name: 'HuifuPosDevice', plural: 'huifu-pos-devices' },
    form: { fields: [
      { property: 'merchantUuid', required: true, hidden: 'update', relation: { entity: 'HuifuMerchant', valueKey: 'uuid' },
        field_options: { label: t('Merchant') } },
      { property: 'storeUuid', required: true, hidden: 'update', relation: { entity: 'Store', valueKey: 'uuid' },
        field_options: { label: t('Store') } },
      { property: 'terminalId', required: true, hidden: 'update', field_options: { label: t('Terminal ID') } },
      { property: 'mode', type: 'select', required: true, default_value: 'unclaimed', type_options: { options: [
        { value: 'linked_order', label: t('Linked order') },
        { value: 'unclaimed', label: t('Unclaimed receipt') }
      ] } },
      { property: 'description', required: false },
      { property: 'enabled', type: 'boolean', required: false, default_value: true }
    ] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete'],
      list_filter: { terminalId: t('Terminal ID'), storeUuid: t('Store') },
      list_display: ['id', 'terminalId', 'merchantUuid', 'storeUuid', 'mode', 'enabled']
    },
    detail: { detail_display: ['id', 'uuid', 'terminalId', 'merchantUuid', 'storeUuid', 'mode', 'description', 'enabled', 'createdAt', 'updatedAt'] }
  }
}
