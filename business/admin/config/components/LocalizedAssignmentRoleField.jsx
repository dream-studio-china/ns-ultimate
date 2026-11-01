import { defineComponent, h, onMounted, ref, resolveComponent } from 'vue'
import { getLocale, t } from '@/i18n'
import request from '@/utils/request'
import { apiPath, API_PREFIX } from '@/api/prefix'
import { localizedAuthorizationName } from '../../i18n/authorization.js'

/**
 * Localized variant of the core Assignment role selector. Behavior mirrors
 * core/crud-admin configs/collections/authorization/AssignmentRoleField.jsx;
 * only the option labels are translated by role code. The submitted value
 * remains the role id/uuid, never a translated name.
 */
export default defineComponent({
  name: 'LocalizedAssignmentRoleField',
  props: { form: { type: Object, default: () => ({}) }},
  setup(props) {
    const ElOption = resolveComponent('ElOption')
    const ElSelect = resolveComponent('ElSelect')
    const roles = ref({})
    const options = ref([])
    const idOf = (value) => typeof value === 'object' && value ? value.id : value
    const current = () => {
      const uuid = props.form.roleUuid
      if (uuid && roles.value[uuid]) return uuid
      const role = Object.values(roles.value).find((item) => item.id === idOf(props.form.role) || item.id === idOf(props.form.roleId))
      return role?.uuid || ''
    }
    const set = (uuid) => {
      const role = roles.value[uuid]
      if (!role) {
        props.form.role = null
        delete props.form.roleId
        delete props.form.roleUuid
        return
      }
      props.form.role = role.id
      props.form.roleId = role.id
      props.form.roleUuid = role.uuid
      props.form.scopeType = role.scopeType
    }
    onMounted(async() => {
      try {
        const response = await request.get(apiPath(API_PREFIX, 'manage/roles'), { params: { limit: 200, '@order': 'entity.id|DESC' }})
        const payload = response?.data ?? response
        const list = Array.isArray(payload) ? payload : (Array.isArray(payload?.data) ? payload.data : [])
        roles.value = Object.fromEntries(list.filter((role) => role?.uuid).map((role) => [role.uuid, role]))
        const locale = getLocale()
        options.value = list.filter((role) => role?.uuid).map((role) => {
          const name = localizedAuthorizationName('role', role.code, role.name || role.code || role.uuid, locale)
          return {
            value: role.uuid,
            label: `${name}${role.code && role.name ? ` (${role.code})` : ''} [${role.scopeType === 'global' ? t('Global') : t('Store')}]`
          }
        })
      } catch (error) {
        // Keep the selector usable if the role dictionary is unavailable.
      }
    })
    return () => h(ElSelect, {
      modelValue: current(),
      'onUpdate:modelValue': set,
      clearable: true,
      filterable: true,
      placeholder: t('Please select a role'),
      style: 'width:100%'
    }, () => options.value.map((option) => h(ElOption, option)))
  }
})
