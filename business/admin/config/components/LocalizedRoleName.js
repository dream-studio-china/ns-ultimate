import { defineComponent, h } from 'vue'
import { getLocale } from '@/i18n'
import { localizedAuthorizationName } from '../../i18n/authorization.js'

/**
 * Renders the role name in the current admin locale.
 * Form inputs keep the raw backend name so saving never writes a translated
 * value back to the database.
 */
export default defineComponent({
  name: 'LocalizedRoleName',
  props: {
    data: { type: [String, Object], default: '' },
    record: { type: Object, default: () => ({}) },
  },
  setup(props) {
    return () => {
      const record = props.record ?? {}
      const fallback = typeof props.data === 'string' ? props.data : (typeof record.name === 'string' ? record.name : '')
      const code = typeof record.code === 'string' ? record.code : ''
      return h('span', localizedAuthorizationName('role', code, fallback, getLocale()))
    }
  },
})
