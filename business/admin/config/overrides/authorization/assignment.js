import coreAssignment from '../../../../../core/crud-admin/src/configs/collections/authorization/Assignment.js'
import AssignmentRoleField from '../../components/authorization/AssignmentRoleField.jsx'
import { authorizationValue } from '../../components/authorization/AuthorizationValue.jsx'

const base = coreAssignment.Assignment
const RoleValue = authorizationValue('role')

export default {
  ...base,
  form: {
    ...base.form,
    fields: base.form.fields.map(field => field.property === 'role'
      ? { ...field, component: AssignmentRoleField }
      : field)
  },
  list: {
    ...base.list,
    list_display: base.list.list_display.map(field => {
      const normalized = typeof field === 'string' ? { property: field } : field
      return normalized.property === 'role' ? { ...normalized, component: RoleValue } : field
    })
  },
  detail: {
    ...base.detail,
    detail_display: base.detail.detail_display.map(field => {
      const normalized = typeof field === 'string' ? { property: field } : field
      return normalized.property === 'role' ? { ...normalized, component: RoleValue } : field
    })
  }
}
