import coreRoleConfig from '../../../../../core/crud-admin/src/configs/collections/authorization/Role.js'
import LocalizedRoleName from '../../components/LocalizedRoleName.js'
import LocalizedRolePermissionsDetail from '../../components/LocalizedRolePermissionsDetail.jsx'
import LocalizedRolePermissionsAction from '../../components/LocalizedRolePermissionsAction.jsx'

const role = coreRoleConfig.Role
const propertyOf = field => typeof field === 'string' ? field : field?.property

const localizeField = field => {
  const property = propertyOf(field)
  if (property === 'name') return { property: 'name', component: LocalizedRoleName }
  if (property === 'permissions') return { property: 'permissions', component: LocalizedRolePermissionsDetail }
  return field
}
const localizeFields = fields => fields.map(localizeField)

/**
 * Localizes role names and permission tags for display. The edit form keeps the
 * raw backend name so saving never persists a translated value.
 */
export default {
  ...role,
  list: {
    ...role.list,
    actions: role.list.actions.map(action =>
      action?.name === 'role-permissions' ? { ...action, component: LocalizedRolePermissionsAction } : action
    ),
    list_display: localizeFields(role.list.list_display),
  },
  detail: {
    ...role.detail,
    detail_display: localizeFields(role.detail.detail_display),
  },
}
