import { t } from '@/i18n'
import PrinterPaperAction from '../../components/PrinterPaperAction.js'
import PrinterTemplateDesigner from '../../components/PrinterTemplateDesigner.vue'

const field = (property, label) => ({ property, label: t(label) })
export default {
  PrinterTemplate: {
    entity: { name: 'PrinterTemplate', prefix: '/api/v1/manage/printer', plural: 'templates' },
    form: { fields: [
      { property: 'name', label: t('Template name'), required: true },
      { property: 'kind', label: t('Printer type'), type: 'select', required: true, type_options: { options: [
        { value: 'receipt', label: t('Receipt') }, { value: 'label', label: t('Label') }
      ] } },
      { property: 'ticketMode', label: t('Ticket mode'), type: 'select', required: true, default_value: 'single', help: t('Ticket mode help'), type_options: { options: [
        { value: 'single', label: t('One ticket per order') }, { value: 'per_unit', label: t('One ticket per item') }
      ] } },
      { property: 'itemFilter', label: t('Item filter'), type: 'code', required: false, default_value: '', help: t('Item filter help'), type_options: { language: 'javascript', height: 120 } },
      { property: 'definition', label: t('Template JSON'), type: 'json', component: PrinterTemplateDesigner, field_options: { class: 'printer-json-editor' }, required: true }
    ] },
    list: {
      actions: [{ name: 'printer-template-preview', position: 'list', component: PrinterPaperAction }],
      disabled_actions: ['delete', 'detail', 'batch_edit', 'batch_delete'],
      list_filter: { name: t('Name'), kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') } },
      list_display: [field('uuid', 'UUID'), field('name', 'Template name'), field('kind', 'Printer type'), field('ticketMode', 'Ticket mode'), field('enabled', 'Enabled'), field('createdAt', 'Created at')]
    },
    detail: { detail_display: [field('uuid', 'UUID'), field('name', 'Template name'), field('kind', 'Printer type'), field('ticketMode', 'Ticket mode'), field('itemFilter', 'Item filter'), field('definition', 'Template JSON'), field('enabled', 'Enabled'), field('createdAt', 'Created at')] }
  }
}
