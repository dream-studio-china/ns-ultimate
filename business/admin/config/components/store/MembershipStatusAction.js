import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'

export default defineComponent({
  name: 'MembershipStatusAction',
  props: {
    record: { type: Object, default: () => ({}) },
    refresh: { type: Function, default: () => {} }
  },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElAlert = resolveComponent('ElAlert')
    const busy = ref(false)
    const error = ref('')

    const storeUuid = () => typeof props.record.store === 'string' ? props.record.store : props.record.store?.uuid
    const change = async (action) => {
      if (busy.value) return
      if (!props.record.uuid || !storeUuid() || !window.confirm(t('Confirm membership status change'))) return
      busy.value = true
      error.value = ''
      try {
        await request.post(`/api/v1/manage/stores/${storeUuid()}/members/${props.record.uuid}/${action}`, {})
        props.refresh()
      } catch (cause) {
        error.value = cause.response?.data?.message || cause.message || t('Membership operation failed')
      } finally {
        busy.value = false
      }
    }

    const button = (action, label, type, icon) => h(ElButton, {
      size: 'small', type, plain: true, icon, loading: busy.value, onClick: () => change(action)
    }, () => t(label))

    return () => h('span', { style: 'display:inline-flex;align-items:center;gap:8px;' }, [
      ...(props.record.status !== 'active' ? [button('activate', 'Activate', 'success', 'el-icon-refresh-left')] : []),
      ...(props.record.status === 'active' ? [button('suspend', 'Suspend', 'warning', 'el-icon-lock')] : []),
      ...(props.record.status !== 'revoked' ? [button('revoke', 'Revoke', 'danger', 'el-icon-delete')] : []),
      ...(error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : [])
    ])
  }
})
