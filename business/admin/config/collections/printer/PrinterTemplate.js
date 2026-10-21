import { t } from '@/i18n'
import PrinterPaperAction from '../../components/PrinterPaperAction.js'
export default {
  PrinterTemplate: {
    entity: { name: 'PrinterTemplate', prefix: '/api/v1/manage/printer', plural: 'templates' },
    form: { fields: [
      { property: 'name', required: true },
      { property: 'kind', type: 'select', required: true, type_options: { options: [
        { value: 'receipt', label: t('Receipt') }, { value: 'label', label: t('Label') }
      ] } },
      { property: 'definition', type: 'json', required: true },
      { property: 'sampleData', type: 'json', required: true, help: t('Template validation data help') }
    ] },
    list: {
      actions: [{ name: 'printer-template-preview', position: 'list', component: PrinterPaperAction }],
      disabled_actions: ['delete', 'detail', 'batch_edit', 'batch_delete'],
      list_filter: { name: t('Name'), kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') } },
      list_display: ['uuid', 'name', 'kind', 'enabled', 'createdAt']
    },
    detail: { detail_display: ['uuid', 'name', 'kind', 'definition', 'enabled', 'createdAt'] }
  }
}
