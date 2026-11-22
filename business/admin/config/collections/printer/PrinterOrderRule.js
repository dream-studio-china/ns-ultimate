import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import { uuidFilter } from '../../uuid-filter.mjs'
import OrderPrintConditions from '../../components/printer/OrderPrintConditions.vue'
import OrderPrintEvents from '../../components/printer/OrderPrintEvents.vue'
import OrderPrintOutputSelect from '../../components/printer/OrderPrintOutputSelect.vue'
import PrinterRuleEnabledControl from '../../components/printer/PrinterRuleEnabledControl.vue'
import PrinterRuleStoreField from '../../components/printer/PrinterRuleStoreField.vue'

const field = (property, label) => ({ property, label: t(label) })
const storeField = {
  ...field('storeUuid', 'Store'),
  relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' }
}
const options = values => values.map(([value, label]) => ({ value, label: t(label) }))
const enabledField = {
  property: 'enabled',
  label: t('Enabled'),
  component: PrinterRuleEnabledControl,
  field_options: { width: 100, align: 'center', sortable: false }
}

export default {
  PrinterOrderRule: {
    entity: { name: 'PrinterOrderRule', prefix: '/api/v1/manage/printer', plural: 'order-rules' },
    form: { fields: [
      { property: 'storeUuid', component: PrinterRuleStoreField, required: true, relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' } },
      { property: 'name', required: true, type_options: { maxlength: 120 } },
      { property: 'enabled', type: 'boolean', default_value: false, help: t('Print rule enable help') },
      { property: 'events', component: OrderPrintEvents, required: true, default_value: [] },
      { property: 'match', type: 'select', required: true, default_value: 'all', type_options: { options: options([
        ['all', 'All conditions'], ['any', 'Any condition']
      ]) } },
      { property: 'conditions', component: OrderPrintConditions, required: false, default_value: [] },
      { property: 'deviceUuid', component: OrderPrintOutputSelect, required: true, help: t('Print rule output help'),
        relation: { target: 'PrinterDevice', plural: 'devices', prefix: '/api/v1/manage/printer', valueKey: 'uuid' } },
      { property: 'templateUuid', component: OrderPrintOutputSelect, required: true,
        relation: { target: 'PrinterTemplate', plural: 'templates', prefix: '/api/v1/manage/printer', valueKey: 'uuid' } },
      { property: 'copies', type: 'integer', required: true, default_value: 1, type_options: { min: 1, max: 100 } }
    ] },
    list: {
      disabled_actions: ['batch_edit', 'batch_delete'],
      list_filter: { storeUuid: uuidFilter('storeUuid', t('Store'), () => loadRelationRecords({ name: 'Store', valueKey: 'uuid' })), name: t('Name'), enabled: t('Enabled') },
      list_display: [field('uuid', 'UUID'), storeField, field('name', 'Name'), enabledField, field('templateUuid', 'Printer template UUID')]
    },
    detail: { detail_display: [storeField, '__all__'] }
  }
}
