import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import { uuidFilter } from '../../uuid-filter.mjs'
import CounterSortAction from './CounterSortAction.jsx'

export default {
  Counter: {
    entity: { name: 'Counter', plural: 'counters' },
    form: {
      fields: [
        { property: 'storeUuid', required: true, relation: { entity: 'Store', valueKey: 'uuid' } },
        { property: 'code', required: true, help: t('Counter code help') },
        { property: 'name', required: true },
        { property: 'capacity', type: 'integer', required: false, help: t('Counter capacity help') },
        { property: 'sort', type: 'integer', required: false, default_value: 0, help: t('Counter sort help') },
        { property: 'metadata', type: 'json', required: false, help: t('Counter metadata help') },
        { property: 'enabled', type: 'boolean', default_value: true }
      ]
    },
    list: {
      query: { '@order': 'entity.sort|ASC, entity.id|ASC' },
      actions: [
        {
          name: 'counter-sort',
          position: 'top',
          get component() { return CounterSortAction }
        }
      ],
      list_filter: {
        storeUuid: uuidFilter('storeUuid', t('Store'), () => loadRelationRecords({ name: 'Store', valueKey: 'uuid' })),
        code: t('Counter code'),
        name: t('Name'),
        enabled: t('Enabled')
      },
      list_display: [
        'id',
        { property: 'storeUuid', relation: { entity: 'Store', valueKey: 'uuid' } },
        'code',
        'name',
        'capacity',
        { property: 'sort', editable: true },
        'enabled',
        'createdAt',
        'updatedAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
