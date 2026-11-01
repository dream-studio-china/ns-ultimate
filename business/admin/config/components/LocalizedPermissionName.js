import { defineComponent, h } from 'vue'
import { getLocale } from '@/i18n'
import { localizedAuthorizationName } from '../../i18n/authorization.js'

/**
 * Renders the permission name in the current admin locale.
 * Only the display value changes; the stored name and permission checks keep
 * using the backend record and code.
 */
export default defineComponent({
  name: 'LocalizedPermissionName',
  props: {
    data: { type: [String, Object], default: '' },
    record: { type: Object, default: () => ({}) },
  },
  setup(props) {
    return () => {
      const record = props.record ?? {}
      const fallback = typeof props.data === 'string' ? props.data : (typeof record.name === 'string' ? record.name : '')
      const code = typeof record.code === 'string' ? record.code : ''
      return h('span', localizedAuthorizationName('permission', code, fallback, getLocale()))
    }
  },
})
