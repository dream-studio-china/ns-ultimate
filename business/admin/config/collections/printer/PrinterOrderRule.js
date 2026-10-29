import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import { uuidFilter } from '../../uuid-filter.mjs'
import OrderPrintConditions from '../../components/OrderPrintConditions.vue'
import OrderPrintEvents from '../../components/OrderPrintEvents.vue'
import OrderPrintOutputSelect from '../../components/OrderPrintOutputSelect.vue'

const field = (property, label) => ({ property, label: t(label) })
const options = values => values.map(([value, label]) => ({ value, label: t(label) }))

export default {
  PrinterOrderRule: {
    entity: { name: 'PrinterOrderRule', prefix: '/api/v1/manage/printer', plural: 'order-rules' },
    form: { fields: [
      { property: 'storeUuid', type: 'RelationToOne', required: true, hidden: (_form, id) => !!id, relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' } },
      { property: 'name', required: true, type_options: { maxlength: 120 } },
      { property: 'enabled', type: 'boolean', default_value: false, help: t('Print rule enable help') },
      { property: 'events', component: OrderPrintEvents, required: true, default_value: [] },
      { property: 'match', type: 'select', required: true, default_value: 'all', type_options: { options: options([
        ['all', 'All conditions'], ['any', 'Any condition']
      ]) } },
      { property: 'conditions', component: OrderPrintConditions, default_value: [] },
      { property: 'deviceUuid', component: OrderPrintOutputSelect, required: true, help: t('Print rule output help'),
        relation: { target: 'PrinterDevice', plural: 'devices', prefix: '/api/v1/manage/printer', valueKey: 'uuid' } },
      { property: 'templateUuid', component: OrderPrintOutputSelect, required: true,
        relation: { target: 'PrinterTemplate', plural: 'templates', prefix: '/api/v1/manage/printer', valueKey: 'uuid' } },
      { property: 'copies', type: 'integer', required: true, default_value: 1, type_options: { min: 1, max: 100 } }
    ] },
    list: {
      disabled_actions: ['delete', 'batch_edit', 'batch_delete'],
      list_filter: { storeUuid: uuidFilter('storeUuid', t('Store'), () => loadRelationRecords({ name: 'Store', valueKey: 'uuid' })), name: t('Name'), enabled: t('Enabled') },
      list_display: [field('uuid', 'UUID'), field('storeUuid', 'Store'), field('name', 'Name'), field('enabled', 'Enabled'), field('templateUuid', 'Printer template UUID')]
    },
    detail: { detail_display: '__all__' }
  }
}
