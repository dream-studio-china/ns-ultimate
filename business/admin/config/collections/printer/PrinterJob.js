import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import { uuidFilter } from '../../uuid-filter.mjs'
import PrinterJobAction from '../../components/PrinterJobAction.js'
import PrinterPaperAction from '../../components/PrinterPaperAction.js'
import PrinterJobReprintAction from '../../components/PrinterJobReprintAction.js'

const field = (property, label) => ({ property, label: t(label) })
const storeField = {
  ...field('storeUuid', 'Store'),
  relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' }
}
const ruleField = {
  ...field('ruleUuid', 'Print rule'),
  relation: { target: 'PrinterOrderRule', plural: 'order-rules', prefix: '/api/v1/manage/printer', valueKey: 'uuid' }
}

export default {
  PrinterJob: {
    entity: { name: 'PrinterJob', prefix: '/api/v1/manage/printer', plural: 'jobs' },
    form: { fields: [] },
    list: {
      query: { '@order': 'entity.createdAt|DESC, entity.id|DESC' },
      disabled_actions: ['new', 'edit', 'batch_edit', 'batch_delete'],
      actions: [
        { name: 'printer-job-create', position: 'top', component: PrinterJobAction },
        { name: 'printer-job-preview', position: 'list', component: PrinterPaperAction },
        { name: 'printer-job-reprint', position: 'list', component: PrinterJobReprintAction }
      ],
      list_filter: {
        storeUuid: uuidFilter('storeUuid', t('Store'), () => loadRelationRecords({ name: 'Store', valueKey: 'uuid' })),
        ruleUuid: uuidFilter('ruleUuid', t('Print rule'), () => loadRelationRecords({
          name: 'PrinterOrderRule', plural: 'order-rules', prefix: '/api/v1/manage/printer', valueKey: 'uuid'
        })),
        serialNumber: {
          label: t('Device serial number'),
          type: 'input',
          default: null,
          expression: "entity.getDevice().getSerialNumber() matches ':value'"
        },
        kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') },
        status: { __label: t('Status'), submitting: t('Submitting'), submitted: t('Submitted'), printed: t('Printed'), failed: t('Failed'), unknown: t('Unknown') }
      },
      list_display: [
        field('uuid', 'UUID'), storeField, ruleField, field('device', 'Printer device'), field('kind', 'Printer type'), field('copies', 'Copies'),
        field('status', 'Status'), field('providerOrderId', 'providerOrderId'), field('errorCode', 'errorCode'), field('createdAt', 'Created at')
      ]
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: [
        field('uuid', 'UUID'), field('device', 'Printer device'),
        storeField, ruleField,
        field('kind', 'Printer type'), field('copies', 'Copies'), field('status', 'Status'),
        field('providerOrderId', 'providerOrderId'), field('errorCode', 'errorCode'), field('createdAt', 'Created at'),
        { ...field('templateSnapshot', 'templateSnapshot'), type: 'json', full_width: true },
        { ...field('dataSnapshot', 'dataSnapshot'), type: 'json', full_width: true },
        { ...field('renderedContent', 'renderedContent'), type: 'code', full_width: true }
      ]
    }
  }
}
