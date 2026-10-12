import { defineComponent, h } from 'vue'
import { t } from '@/i18n'
import {
  authorizationTokenLabel,
  permissionCode,
  permissionDescription,
  permissionLabel,
  roleLabel
} from './labels.mjs'

export function authorizationValue(kind) {
  return defineComponent({
    name: `AuthorizationValue${kind.replace(/(^|-)([a-z])/g, (_, _separator, letter) => letter.toUpperCase())}`,
    props: {
      data: { type: [String, Number, Object], default: '' },
      record: { type: Object, default: () => ({}) }
    },
    setup(props) {
      return () => {
        let label = props.data
        let title = ''

        if (kind === 'role') {
          label = roleLabel(props.record, props.data, t)
          title = props.record?.code || ''
        } else if (kind === 'permission' || kind === 'permission-name') {
          const permission = kind === 'permission'
            ? { ...props.record, code: permissionCode(props.data) || props.data }
            : props.record
          label = permissionLabel(permission, t)
          title = permissionCode(permission)
        } else if (kind === 'permission-description') {
          label = permissionDescription(props.record, t)
          title = props.record?.code || ''
        } else {
          label = authorizationTokenLabel(kind, props.data, t)
          title = props.data === label ? '' : String(props.data ?? '')
        }

        if (kind === 'permission' && label !== '-') label = `${label} (${permissionCode(props.data)})`
        return h('span', { title }, String(label ?? '-'))
      }
    }
  })
}
