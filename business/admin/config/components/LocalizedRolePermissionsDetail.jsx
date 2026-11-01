import { defineComponent, h } from 'vue'
import CoreRolePermissionsDetail from '../../../../core/crud-admin/src/configs/collections/authorization/RolePermissionsDetail.jsx'
import { getLocale } from '@/i18n'
import { translatedAuthorizationName } from '../../i18n/authorization.js'

const localizePermission = (value, locale) => {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return value
  const code = value.code ?? value.permission?.code ?? value.permissionCode ?? value.key ?? value.value
  const translated = translatedAuthorizationName('permission', code, locale)
  return translated === null ? value : { ...value, name: translated }
}

const localizePermissions = (data, locale) => {
  if (Array.isArray(data)) return data.map(item => localizePermission(item, locale))
  return localizePermission(data, locale)
}

/**
 * Localizes the permission tags shown on the Role list and detail pages while
 * delegating layout to the core component. Codes remain visible unchanged.
 */
export default defineComponent({
  name: 'LocalizedRolePermissionsDetail',
  props: {
    data: { type: [Array, String, Object], default: () => [] },
    record: { type: Object, default: () => ({}) },
  },
  setup(props) {
    return () => h(CoreRolePermissionsDetail, { data: localizePermissions(props.data, getLocale()) })
  },
})
