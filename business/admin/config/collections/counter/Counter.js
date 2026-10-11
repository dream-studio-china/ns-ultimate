import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import { uuidFilter } from '../../uuid-filter.mjs'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  Counter: {
    entity: { name: 'Counter', plural: 'counters' },
    form: {
      fields: [
        { property: 'storeUuid', required: true, relation: { entity: 'Store', valueKey: 'uuid' } },
        { property: 'code', required: true, help: t('Counter code help') },
        { property: 'name', required: true },
        { property: 'capacity', type: 'integer', required: false, help: t('Counter capacity help') },
        { property: 'enabled', type: 'boolean', default_value: true }
      ]
    },
    list: {
      query: orderByIdDesc,
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
