import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatMerchant: {
    entity: { name: 'WechatMerchant', plural: 'wechat-merchants' },
    form: { fields: [
      { property: 'name', required: true },
      { property: 'merchantId', required: true, hidden: 'update' },
      { property: 'paymentMode', label: t('Payment mode'), type: 'select', required: true, default_value: 'direct', hidden: 'update', type_options: { options: [
        { value: 'direct', label: t('Direct merchant') },
        { value: 'service_provider', label: t('Service provider') }
      ] } },
      { property: 'serviceProviderAppId', label: t('Service provider AppID'), required: false,
        hidden: (form, id) => Boolean(id) || form.paymentMode !== 'service_provider',
        help: t('Service provider AppID help') },
      { property: 'subMerchantId', label: t('Sub-merchant ID'), required: false,
        hidden: (form, id) => Boolean(id) || form.paymentMode !== 'service_provider',
        help: t('Sub-merchant ID help') },
      { property: 'apiV3Key', type: 'input', required: false,
        field_options: { label: t('WeChat Pay API v3 key') },
        type_options: { placeholder: t('WeChat Pay API v3 key placeholder'), type: 'password', showPassword: true },
        help: t('Merchant API v3 key help') },
      { property: 'privateKey', type: 'textarea', required: false,
        field_options: { label: t('WeChat Pay merchant private key') },
        type_options: { placeholder: t('WeChat Pay merchant private key placeholder'), autosize: { minRows: 4 } },
        help: t('Merchant private key help') },
      { property: 'certificate', type: 'textarea', required: false,
        field_options: { label: t('WeChat Pay merchant API certificate') },
        type_options: { placeholder: t('WeChat Pay merchant API certificate placeholder'), autosize: { minRows: 4 } },
        help: t('Merchant certificate help') },
      { property: 'platformCertificate', type: 'textarea', required: false,
        field_options: { label: t('WeChat Pay platform certificate') },
        type_options: { placeholder: t('WeChat Pay platform certificate placeholder'), autosize: { minRows: 4 } },
        help: t('Platform certificate create/edit help') }
    ] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete'],
      list_filter: { name: t('Name'), merchantId: t('Merchant ID') },
      list_display: ['id', 'name', 'merchantId', 'paymentMode']
    },
    detail: { detail_display: ['id', 'uuid', 'name', 'merchantId', 'paymentMode', 'serviceProviderAppId', 'subMerchantId'] }
  }
}
