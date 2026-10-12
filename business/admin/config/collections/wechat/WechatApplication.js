import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatApplication: {
    entity: { name: 'WechatApplication', plural: 'wechat-applications' },
    form: { fields: [
      { property: 'name', required: true },
      { property: 'type', type: 'select', required: true, hidden: 'update', type_options: { options: [
        { value: 'miniapp', label: t('Mini Program') },
        { value: 'official', label: t('Official Account') }
      ] } },
      { property: 'appId', required: true, hidden: 'update' },
      { property: 'secret', type: 'input', required: false,
        field_options: { label: t('WeChat AppSecret') },
        type_options: { placeholder: t('WeChat AppSecret placeholder'), type: 'password', showPassword: true },
        help: t('WeChat application secret help') },
      { property: 'token', type: 'input', required: false,
        field_options: { label: t('WeChat server Token') },
        type_options: { placeholder: t('WeChat server Token placeholder'), type: 'password', showPassword: true },
        help: t('WeChat server Token help') },
      { property: 'aesKey', type: 'input', required: false,
        field_options: { label: t('WeChat EncodingAESKey') },
        type_options: { placeholder: t('WeChat EncodingAESKey placeholder'), type: 'password', showPassword: true },
        help: t('WeChat EncodingAESKey help') }
    ] },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete'],
      list_filter: { name: t('Name'), type: t('Type'), appId: t('AppID') },
      list_display: ['id', 'name', 'type', 'appId']
    },
    detail: { detail_display: ['id', 'uuid', 'name', 'type', 'appId'] }
  }
}
