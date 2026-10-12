import coreUser from '../../../../../core/crud-admin/src/configs/collections/identity/User.js'
import UserRolesValue from '../../components/authorization/UserRolesValue.jsx'

const base = coreUser.User

function displayFields(fields) {
  return fields.map(field => {
    const normalized = typeof field === 'string' ? { property: field } : field
    return normalized.property === 'roles' ? { ...normalized, component: UserRolesValue } : field
  })
}

export default {
  ...base,
  list: {
    ...base.list,
    list_display: displayFields(base.list.list_display)
  },
  detail: {
    ...base.detail,
    detail_display: displayFields(base.detail.detail_display)
  }
}
