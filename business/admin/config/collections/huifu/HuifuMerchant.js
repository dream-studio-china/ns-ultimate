import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  HuifuMerchant: {
    entity: { name: 'HuifuMerchant', plural: 'huifu-merchants' },
    form: { fields: [
      { property: 'name', required: true },
      { property: 'huifuId', required: true, hidden: 'update', field_options: { label: t('Huifu ID') } },
      { property: 'providerUuid', required: true, hidden: 'update', relation: { entity: 'HuifuProviderProfile', valueKey: 'uuid' },
        field_options: { label: t('Provider profile') } },
      { property: 'capabilities', type: 'json', required: false, default_value: ['jspay'],
        field_options: { label: t('Payment capabilities') }, help: t('Huifu capabilities help') },
      { property: 'enabled', type: 'boolean', required: false, default_value: true }
    ] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete'],
      list_filter: { name: t('Name'), huifuId: t('Huifu ID') },
      list_display: ['id', 'name', 'huifuId', 'providerUuid', 'capabilities', 'enabled']
    },
    detail: { detail_display: ['id', 'uuid', 'name', 'huifuId', 'providerUuid', 'capabilities', 'enabled', 'createdAt', 'updatedAt'] }
  }
}
