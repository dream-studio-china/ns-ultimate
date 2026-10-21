import { t } from '@/i18n'

const field = (property, label) => ({ property, label: t(label) })
export default {
  PrinterAccount: {
    entity: { name: 'PrinterAccount', prefix: '/api/v1/manage/printer', plural: 'accounts' },
    form: { fields: [
      {
        property: 'provider', label: t('Printer provider'), type: 'select', required: true, default_value: 'xpyun',
        type_options: { options: [{ value: 'xpyun', label: t('Xpyun') }] },
        help: t('DeliCloud provider is not yet available.')
      },
      { property: 'name', label: t('Account name'), required: true },
      { property: 'developerId', label: t('Developer ID'), required: true },
      { property: 'userKey', label: t('Developer key'), type: 'password', required: true, help: t('Printer developer key is encrypted at rest.') }
    ] },
    list: {
      disabled_actions: ['edit', 'delete', 'batch_edit', 'batch_delete'],
      list_filter: {
        name: t('Name'), developerId: t('Developer ID'),
        provider: { __label: t('Printer provider'), xpyun: t('Xpyun'), delicloud: t('DeliCloud') }
      },
      list_display: [field('uuid', 'UUID'), field('provider', 'Printer provider'), field('name', 'Account name'), field('developerId', 'Developer ID'), field('enabled', 'Enabled'), field('createdAt', 'Created at')]
    },
    detail: { detail_display: [field('uuid', 'UUID'), field('provider', 'Printer provider'), field('name', 'Account name'), field('developerId', 'Developer ID'), field('enabled', 'Enabled'), field('createdAt', 'Created at')] }
  }
}
