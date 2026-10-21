import { t } from '@/i18n'
export default {
  PrinterAccount: {
    entity: { name: 'PrinterAccount', prefix: '/api/v1/manage/printer', plural: 'accounts' },
    form: { fields: [
      { property: 'name', required: true },
      { property: 'developerId', required: true },
      { property: 'userKey', type: 'password', required: true, help: t('Printer developer key is encrypted at rest.') }
    ] },
    list: {
      disabled_actions: ['edit', 'delete', 'batch_edit', 'batch_delete'],
      list_filter: {
        name: t('Name'), developerId: t('Developer ID'),
        provider: { __label: t('Printer provider'), xpyun: t('Xpyun'), delicloud: t('DeliCloud') }
      },
      list_display: ['uuid', 'provider', 'name', 'developerId', 'enabled', 'createdAt']
    },
    detail: { detail_display: ['uuid', 'provider', 'name', 'developerId', 'enabled', 'createdAt'] }
  }
}
