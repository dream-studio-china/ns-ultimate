import corePermissionConfig from '../../../../../core/crud-admin/src/configs/collections/authorization/Permission.js'
import LocalizedPermissionName from '../../components/LocalizedPermissionName.js'

const permission = corePermissionConfig.Permission
const nameField = { property: 'name', component: LocalizedPermissionName }
const localizeNames = fields => fields.map(field => field === 'name' ? nameField : field)

/**
 * Localizes the read-only permission dictionary. Codes, values and the
 * server-side name stay untouched; only list/detail display is translated.
 */
export default {
  ...permission,
  list: {
    ...permission.list,
    list_display: localizeNames(permission.list.list_display),
  },
  detail: {
    ...permission.detail,
    detail_display: localizeNames(permission.detail.detail_display),
  },
}
