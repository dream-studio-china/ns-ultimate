import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import { uuidFilter } from '../../uuid-filter.mjs'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'

export default {
  Counter: {
    entity: { name: 'Counter', plural: 'counters' },
    form: {
      fields: [
        {
          property: 'storeUuid',
          required: true,
          relation: { entity: 'Store', valueKey: 'uuid' },
          field_options: { label: t('Store') },
          hidden: ['update']
        },
        {
          property: 'code',
          required: true,
          help: t('Counter code help'),
          field_options: { label: t('Counter code') }
        },
        { property: 'name', required: true, field_options: { label: t('Name') } },
        {
          property: 'capacity',
          type: 'integer',
          required: false,
          help: t('Counter capacity help'),
          field_options: { label: t('Capacity') }
        },
        {
          property: 'enabled',
          type: 'boolean',
          default_value: true,
          field_options: { label: t('Enabled') }
        }
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
        { property: 'id', label: t('ID') },
        {
          property: 'storeUuid',
          label: t('Store'),
          relation: { entity: 'Store', valueKey: 'uuid' }
        },
        { property: 'code', label: t('Counter code') },
        { property: 'name', label: t('Name') },
        { property: 'capacity', label: t('Capacity') },
        { property: 'enabled', label: t('Enabled') },
        { property: 'createdAt', label: t('Created At') },
        { property: 'updatedAt', label: t('Updated At') }
      ]
    },
    detail: {
      detail_display: [
        { property: 'id', label: t('ID') },
        { property: 'uuid', label: t('UUID') },
        { property: 'storeUuid', label: t('Store') },
        { property: 'code', label: t('Counter code') },
        { property: 'name', label: t('Name') },
        { property: 'capacity', label: t('Capacity') },
        { property: 'enabled', label: t('Enabled') },
        { property: 'createdAt', label: t('Created At') },
        { property: 'updatedAt', label: t('Updated At') }
      ]
    }
  }
}
