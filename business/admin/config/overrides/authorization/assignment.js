import coreAssignmentConfig from '../../../../../core/crud-admin/src/configs/collections/authorization/Assignment.js'
import LocalizedAssignmentRoleField from '../../components/LocalizedAssignmentRoleField.jsx'

const assignment = coreAssignmentConfig.Assignment

/**
 * Localizes the role selector while submitting the unchanged role id/uuid.
 */
export default {
  ...assignment,
  form: {
    ...assignment.form,
    fields: assignment.form.fields.map(field =>
      field?.property === 'role' ? { ...field, component: LocalizedAssignmentRoleField } : field
    ),
  },
}
