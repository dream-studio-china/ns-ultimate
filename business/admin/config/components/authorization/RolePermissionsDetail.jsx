import { defineComponent, h } from 'vue'
import { t } from '@/i18n'
import { permissionCode, permissionLabel } from './labels.mjs'

export default defineComponent({
  name: 'RolePermissionsDetail',
  props: { data: { type: [Array, String, Object], default: () => [] } },
  setup(props) {
    return () => {
      const values = Array.isArray(props.data) ? props.data : (props.data ? [props.data] : [])
      const seen = new Set()
      const items = values
        .map(value => ({ value, code: permissionCode(value) }))
        .filter(item => item.code && !seen.has(item.code) && seen.add(item.code))

      if (!items.length) return h('span', '-')
      return h('div', { style: 'display:flex;flex-wrap:wrap;gap:4px;' }, items.map(({ value, code }) =>
        h('el-tag', { key: code, size: 'small', title: code }, () => permissionLabel(value, t))
      ))
    }
  }
})
