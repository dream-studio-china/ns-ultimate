import { t } from '@/i18n'
import PrinterJobAction from '../../components/PrinterJobAction.js'
import PrinterPaperAction from '../../components/PrinterPaperAction.js'
import PrinterJobReprintAction from '../../components/PrinterJobReprintAction.js'

export default {
  PrinterJob: {
    entity: { name: 'PrinterJob', prefix: '/api/v1/manage/printer', plural: 'jobs' },
    form: { fields: [] },
    list: {
      disabled_actions: ['new', 'edit', 'batch_edit', 'batch_delete'],
      actions: [
        { name: 'printer-job-create', position: 'top', component: PrinterJobAction },
        { name: 'printer-job-preview', position: 'list', component: PrinterPaperAction },
        { name: 'printer-job-reprint', position: 'list', component: PrinterJobReprintAction }
      ],
      list_filter: { kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') }, status: t('Status') },
      list_display: ['uuid', 'device', 'kind', 'copies', 'status', 'providerOrderId', 'errorCode', 'createdAt']
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: [
        'uuid', 'device', 'storeUuid', 'kind', 'copies', 'status', 'providerOrderId', 'errorCode', 'createdAt',
        { property: 'templateSnapshot', type: 'json', full_width: true },
        { property: 'dataSnapshot', type: 'json', full_width: true },
        { property: 'renderedContent', type: 'code', full_width: true }
      ]
    }
  }
}
