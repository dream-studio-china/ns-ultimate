import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  WechatApplication: {
    entity: { name: 'WechatApplication', plural: 'wechat-applications' },
    form: { fields: [
      { property: 'name', required: true },
      { property: 'type', type: 'select', required: true, type_options: { options: [
        { value: 'miniapp', label: t('Mini Program') },
        { value: 'official', label: t('Official Account') }
      ] } },
      { property: 'appId', required: true },
      { property: 'secret', type: 'input', required: false, help: t('Credential replacement help') },
      { property: 'token', type: 'input', required: false },
      { property: 'aesKey', type: 'input', required: false }
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
