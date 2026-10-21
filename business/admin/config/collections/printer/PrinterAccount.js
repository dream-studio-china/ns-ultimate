import { t } from '@/i18n'
import PrinterOperations from '../../components/PrinterOperations.js'

export default {
  PrinterAccount: {
    entity: { name: 'PrinterAccount', prefix: '/api/v1/manage/printer', plural: 'accounts' },
    form: { fields: [] },
    list: {
      disabled_actions: ['new', 'edit', 'delete', 'detail', 'batch_edit', 'batch_delete'],
      actions: [{ name: 'printer-account-operations', position: 'top', component: PrinterOperations }],
      list_filter: { name: t('Name'), developerId: t('Developer ID') },
      list_display: ['uuid', 'name', 'developerId', 'enabled', 'createdAt']
    },
    detail: { detail_display: ['uuid', 'name', 'developerId', 'enabled', 'createdAt'] }
  }
}
