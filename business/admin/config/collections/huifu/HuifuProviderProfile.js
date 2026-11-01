import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  HuifuProviderProfile: {
    entity: { name: 'HuifuProviderProfile', plural: 'huifu-provider-profiles' },
    form: { fields: [
      { property: 'name', required: true },
      { property: 'environment', type: 'select', required: true, default_value: 'production', type_options: { options: [
        { value: 'production', label: 'production' },
        { value: 'sandbox', label: 'sandbox' }
      ] } },
      { property: 'sysId', required: true },
      { property: 'productId', required: true },
      { property: 'merchantPrivateKey', type: 'textarea', required: false,
        field_options: { label: t('Merchant private key') },
        type_options: { placeholder: '-----BEGIN PRIVATE KEY-----', autosize: { minRows: 4 } },
        help: t('Huifu merchant private key help') },
      { property: 'huifuPublicKey', type: 'textarea', required: false,
        field_options: { label: t('Huifu response public key') },
        type_options: { placeholder: '-----BEGIN PUBLIC KEY-----', autosize: { minRows: 4 } },
        help: t('Huifu response public key help') },
      { property: 'asyncPublicKey', type: 'textarea', required: false,
        field_options: { label: t('Async notification public key') },
        type_options: { placeholder: '-----BEGIN PUBLIC KEY-----', autosize: { minRows: 4 } },
        help: t('Huifu async public key help') },
      { property: 'enabled', type: 'boolean', required: false, default_value: true }
    ] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete'],
      list_filter: { name: t('Name'), environment: t('Huifu environment'), sysId: t('System ID') },
      list_display: ['id', 'name', 'environment', 'sysId', 'productId', 'credentialVersion', 'enabled']
    },
    detail: { detail_display: ['id', 'uuid', 'name', 'environment', 'sysId', 'productId', 'credentialVersion', 'enabled', 'createdAt', 'updatedAt'] }
  }
}
