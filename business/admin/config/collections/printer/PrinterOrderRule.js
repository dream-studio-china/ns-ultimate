import { t } from '@/i18n'
import OrderPrintConditions from '../../components/OrderPrintConditions.vue'
import OrderPrintEvents from '../../components/OrderPrintEvents.vue'

const field = (property, label) => ({ property, label: t(label) })
const options = values => values.map(([value, label]) => ({ value, label: t(label) }))

// The menu is intentionally not registered until event execution is switched over.
export default {
  PrinterOrderRule: {
    entity: { name: 'PrinterOrderRule', prefix: '/api/v1/manage/printer', plural: 'order-rules' },
    form: { fields: [
      { property: 'storeUuid', type: 'RelationToOne', required: true, hidden: ['update'], relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' } },
      { property: 'name', required: true },
      { property: 'enabled', type: 'boolean', default_value: false },
      { property: 'events', component: OrderPrintEvents, required: true, default_value: [] },
      { property: 'match', type: 'select', required: true, default_value: 'all', type_options: { options: options([
        ['all', 'All conditions'], ['any', 'Any condition']
      ]) } },
      { property: 'conditions', component: OrderPrintConditions, default_value: [] },
      { property: 'scene', type: 'select', required: true, type_options: { options: options([
        ['order', 'Standard order receipt'], ['per_item', 'Per-item ticket'],
        ['filtered_list', 'Filtered item list'], ['full_list', 'Full item list'], ['settlement', 'Settlement receipt']
      ]) } },
      { property: 'deviceUuid', type: 'RelationToOne', required: true,
        relation: { target: 'PrinterDevice', plural: 'devices', prefix: '/api/v1/manage/printer', valueKey: 'uuid' } },
      { property: 'templateUuid', type: 'RelationToOne', required: true,
        relation: { target: 'PrinterTemplate', plural: 'templates', prefix: '/api/v1/manage/printer', valueKey: 'uuid' } },
      { property: 'copies', type: 'integer', required: true, default_value: 1 }
    ] },
    list: {
      disabled_actions: ['delete', 'batch_edit', 'batch_delete'],
      list_filter: { name: t('Name'), enabled: t('Enabled') },
      list_display: [field('uuid', 'UUID'), field('storeUuid', 'Store'), field('name', 'Name'), field('enabled', 'Enabled'), field('scene', 'Print scene')]
    },
    detail: { detail_display: '__all__' }
  }
}
