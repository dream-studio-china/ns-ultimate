import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import { uuidFilter } from '../../uuid-filter.mjs'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  OrderCounter: {
    entity: { name: 'OrderCounter', plural: 'order-counters' },
    form: {
      fields: [
        { property: 'tradeOrderUuid', required: true, relation: { entity: 'Order', valueKey: 'uuid' } },
        { property: 'counter', required: true, relation: { entity: 'Counter', valueKey: 'uuid' } }
      ]
    },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['edit'],
      list_filter: {
        tradeOrderUuid: uuidFilter('tradeOrderUuid', t('Trade order UUID'), () => loadRelationRecords({ name: 'Order', valueKey: 'uuid' })),
        storeUuid: uuidFilter('storeUuid', t('Store'), () => loadRelationRecords({ name: 'Store', valueKey: 'uuid' })),
        counterCodeSnapshot: t('Counter code')
      },
      list_display: [
        'id',
        { property: 'tradeOrderUuid', relation: { entity: 'Order', valueKey: 'uuid' } },
        'counter',
        'counterCodeSnapshot',
        'counterNameSnapshot',
        { property: 'storeUuid', relation: { entity: 'Store', valueKey: 'uuid' } },
        'createdAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
