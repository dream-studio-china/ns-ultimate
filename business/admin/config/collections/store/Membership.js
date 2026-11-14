import { t } from '@/i18n'
import { loadRelationRecords } from '@/utils/relation'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'
import MembershipStatusAction from '../../components/MembershipStatusAction.js'

const roleOptions = [
  { value: 'owner', label: t('Owner') },
  { value: 'manager', label: t('Manager') },
  { value: 'clerk', label: t('Clerk') },
  { value: 'fulfillment', label: t('Fulfillment') }
]

const statusOptions = [
  { value: 'active', label: t('Active') },
  { value: 'suspended', label: t('Suspended') },
  { value: 'revoked', label: t('Revoked') }
]

const optionFilter = (label, expression, options) => () => Promise.resolve({
  label, type: 'select', default: null, expression, data: options
})

const storeFilter = () => Promise.resolve(loadRelationRecords({ name: 'Store', valueKey: 'uuid' })).then(records => ({
  label: t('Store'),
  type: 'select',
  default: null,
  expression: "entity.getStore().getUuid() == ':value'",
  data: records
    .filter(record => typeof record.uuid === 'string' && record.uuid)
    .map(record => ({ value: record.uuid, label: record.__toString || record.name || record.code || record.uuid }))
}))

export default {
  Membership: {
    entity: { name: 'Membership', prefix: '/api/v1/manage', plural: 'memberships' },
    form: {
      fields: [
        { property: 'store', label: t('Store'), required: true, hidden: 'update', relation: { entity: 'Store', valueKey: 'uuid' } },
        { property: 'userUuid', label: t('User'), required: true, hidden: 'update', relation: { entity: 'User', valueKey: 'uuid' }, relation_filter: { '@filter': 'entity.getUsername() matches ":value"' }, type_options: { remote: true }, field_options: { placeholder: t('Search username to select user') } },
        { property: 'role', label: t('Role'), type: 'select', required: true, type_options: { options: roleOptions } }
      ]
    },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete', 'batch_delete'],
      actions: [{ name: 'membership-status', position: 'list', component: MembershipStatusAction }],
      list_filter: {
        store: storeFilter,
        userUuid: t('User UUID'),
        role: optionFilter(t('Role'), "entity.getRole() == ':value'", roleOptions),
        status: optionFilter(t('Status'), "entity.getStatus() == ':value'", statusOptions)
      },
      list_display: [
        'id',
        { property: 'store', relation: { entity: 'Store', valueKey: 'uuid' } },
        { property: 'userUuid', relation: { entity: 'User', valueKey: 'uuid' } },
        'role',
        'status',
        'createdAt',
        'updatedAt'
      ]
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
