import coreRole from '../../../../../core/crud-admin/src/configs/collections/authorization/Role.js'
import { authorizationValue } from '../../components/authorization/AuthorizationValue.jsx'
import RolePermissionsAction from '../../components/authorization/RolePermissionsAction.jsx'
import RolePermissionsDetail from '../../components/authorization/RolePermissionsDetail.jsx'

const base = coreRole.Role
const RoleName = authorizationValue('role')

function displayFields(fields) {
  return fields.map(field => {
    const normalized = typeof field === 'string' ? { property: field } : field
    if (normalized.property === 'name') return { ...normalized, component: RoleName }
    if (normalized.property === 'permissions') return { ...normalized, component: RolePermissionsDetail }
    return field
  })
}

export default {
  ...base,
  list: {
    ...base.list,
    actions: base.list.actions.map(action => action.name === 'role-permissions'
      ? { ...action, component: RolePermissionsAction }
      : action),
    list_display: displayFields(base.list.list_display)
  },
  detail: {
    ...base.detail,
    detail_display: displayFields(base.detail.detail_display)
  }
}
