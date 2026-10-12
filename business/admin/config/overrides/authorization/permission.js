import corePermission from '../../../../../core/crud-admin/src/configs/collections/authorization/Permission.js'
import { authorizationValue } from '../../components/authorization/AuthorizationValue.jsx'

const base = corePermission.Permission
const valueComponents = {
  permission: authorizationValue('permission'),
  module: authorizationValue('module'),
  resource: authorizationValue('resource'),
  action: authorizationValue('action'),
  'permission-name': authorizationValue('permission-name'),
  'permission-description': authorizationValue('permission-description')
}

function displayFields(fields) {
  return fields.map(field => {
    const normalized = typeof field === 'string' ? { property: field } : field
    const kindByProperty = {
      code: 'permission',
      module: 'module',
      resource: 'resource',
      action: 'action',
      name: 'permission-name',
      description: 'permission-description'
    }
    const kind = kindByProperty[normalized.property]
    return kind ? { ...normalized, component: valueComponents[kind] } : field
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
