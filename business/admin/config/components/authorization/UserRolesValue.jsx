import { defineComponent, h } from 'vue'
import { t } from '@/i18n'
import { roleLabel } from './labels.mjs'

export default defineComponent({
  name: 'UserRolesValue',
  props: { data: { type: [Array, String], default: () => [] } },
  setup(props) {
    return () => {
      const values = Array.isArray(props.data) ? props.data : (props.data ? [props.data] : [])
      if (!values.length) return h('span', '-')
      return h('div', { style: 'display:flex;flex-wrap:wrap;gap:4px;' }, values.map((value, index) => {
        const code = typeof value === 'string' ? value : value?.code || value?.name || ''
        const label = roleLabel(typeof value === 'object' ? value : { code }, value, t)
        return h('el-tag', { key: `${code}-${index}`, size: 'small', title: code }, () => label)
      }))
    }
  }
})
