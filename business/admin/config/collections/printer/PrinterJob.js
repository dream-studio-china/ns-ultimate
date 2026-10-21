import { t } from '@/i18n'
import PrinterOperations from '../../components/PrinterOperations.js'

export default {
  PrinterJob: {
    entity: { name: 'PrinterJob', prefix: '/api/v1/manage/printer', plural: 'jobs' },
    form: { fields: [] },
    list: {
      disabled_actions: ['new', 'edit', 'delete', 'detail', 'batch_edit', 'batch_delete'],
      actions: [{ name: 'printer-job-operations', position: 'top', component: PrinterOperations }],
      list_filter: { kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') }, status: t('Status') },
      list_display: ['uuid', 'deviceUuid', 'kind', 'copies', 'status', 'providerOrderId', 'errorCode', 'createdAt']
    },
    detail: { detail_display: ['uuid', 'deviceUuid', 'storeUuid', 'kind', 'copies', 'status', 'providerOrderId', 'errorCode', 'createdAt'] }
  }
}
