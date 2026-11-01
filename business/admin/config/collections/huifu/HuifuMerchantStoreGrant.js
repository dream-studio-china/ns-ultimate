import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  HuifuMerchantStoreGrant: {
    entity: { name: 'HuifuMerchantStoreGrant', plural: 'huifu-merchant-store-grants' },
    form: { fields: [
      { property: 'merchantUuid', required: true, relation: { entity: 'HuifuMerchant', valueKey: 'uuid' },
        field_options: { label: t('Merchant') } },
      { property: 'storeUuid', required: true, relation: { entity: 'Store', valueKey: 'uuid' },
        field_options: { label: t('Store') } }
    ] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['edit', 'batch_edit'],
      list_filter: { storeUuid: t('Store') },
      list_display: ['id', 'merchantUuid', 'storeUuid', 'createdAt']
    },
    detail: { detail_display: ['id', 'uuid', 'merchantUuid', 'storeUuid', 'createdAt'] }
  }
}
