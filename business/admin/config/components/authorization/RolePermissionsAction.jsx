import { defineComponent, h, ref } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'
import { apiPath, API_PREFIX } from '@/api/prefix'
import { permissionCode, permissionLabel, roleLabel } from './labels.mjs'

export default defineComponent({
  name: 'RolePermissionsAction',
  props: {
    record: { type: Object, default: () => ({}) },
    refresh: { type: Function, default: () => {} }
  },
  setup(props) {
    const visible = ref(false)
    const loading = ref(false)
    const saving = ref(false)
    const options = ref([])
    const selected = ref([])
    const isSystem = () => !!(props.record?.system ?? props.record?.isSystem)
    const uuid = () => props.record?.uuid || ''

    const normalizeList = response => {
      const raw = response?.data ?? response
      if (Array.isArray(raw)) return raw
      if (Array.isArray(raw?.data)) return raw.data
      if (Array.isArray(raw?.data?.data)) return raw.data.data
      return []
    }

    const toCodes = permissions => [...new Set((permissions || []).map(permissionCode).filter(Boolean))]

    const loadAllPermissions = async() => {
      const all = []
      const limit = 200
      for (let page = 1; page <= 10; page++) {
        const response = await request.get(apiPath(API_PREFIX, 'manage/permissions'), {
          params: { limit, page, '@order': 'entity.id|ASC' }
        })
        const list = normalizeList(response)
        all.push(...list)
        if (list.length < limit) break
      }
      return all.map(permission => {
        const code = permissionCode(permission)
        return code && { value: code, label: `${permissionLabel(permission, t)} (${code})` }
      }).filter(Boolean)
    }

    const loadCurrentCodes = async() => {
      const fromRow = toCodes(props.record?.permissions)
      const pk = props.record?.id ?? uuid()
      if (pk === null || pk === undefined || pk === '') return fromRow
      try {
        const response = await request.get(apiPath(API_PREFIX, `manage/roles/${pk}`))
        return [...new Set([...toCodes((response?.data ?? response)?.permissions), ...fromRow])]
      } catch {
        return fromRow
      }
    }

    const open = async() => {
      if (isSystem() || !uuid()) return
      visible.value = true
      loading.value = true
      try {
        const [all, codes] = await Promise.all([
          loadAllPermissions().catch(() => []),
          loadCurrentCodes()
        ])
        const known = new Set(all.map(option => option.value))
        options.value = [
          ...all,
          ...codes.filter(code => !known.has(code)).map(code => ({ value: code, label: `${permissionLabel(code, t)} (${code})` }))
        ]
        selected.value = codes
      } finally {
        loading.value = false
      }
    }

    const save = async() => {
      if (!uuid()) return
      saving.value = true
      try {
        await request.post(apiPath(API_PREFIX, `manage/roles/${uuid()}/permissions`), { permissions: [...selected.value] })
        visible.value = false
        props.refresh()
      } catch (error) {
        // Keep the dialog open so the user can review the server error and retry.
      } finally {
        saving.value = false
      }
    }

    const renderBody = () => {
      const label = roleLabel(props.record, props.record?.name, t)
      const count = loading.value
        ? t('Loading')
        : t('Selected permission summary', selected.value.length, options.value.length)
      return [
        h('div', { style: 'display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;' }, [
          h('div', { style: 'font-size:13px;color:#303133;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' }, label),
          h('div', { style: 'font-size:12px;color:#909399;white-space:nowrap;' }, count)
        ]),
        h('p', { style: 'color:#606266;font-size:13px;line-height:1.7;margin:0 0 16px;padding:10px 12px;background:#f6f8fa;border:1px solid #ebeef5;border-radius:6px;' }, t('Role permissions help')),
        h('div', { class: 'role-permissions-transfer', style: 'display:flex;justify-content:center;padding-bottom:4px;' }, [
          h('el-transfer', {
            modelValue: selected.value,
            'onUpdate:modelValue': value => { selected.value = value || [] },
            data: options.value,
            props: { key: 'value', label: 'label' },
            filterable: true,
            titles: [t('Available permissions'), t('Selected permissions')],
            filterPlaceholder: t('Please select'),
            style: '--el-transfer-panel-width:350px;--el-transfer-panel-body-height:440px;'
          })
        ])
      ]
    }

    return () => h('span', [
      h('el-button', {
        size: 'small',
        plain: true,
        icon: 'el-icon-key',
        disabled: isSystem() || !uuid(),
        title: isSystem() ? t('Role permissions help') : t('Edit Permissions'),
        onClick: event => { event?.stopPropagation?.(); open() }
      }, () => t('Edit Permissions')),
      h('el-dialog', {
        modelValue: visible.value,
        'onUpdate:modelValue': value => { visible.value = value },
        title: `${t('Edit Permissions')} - ${roleLabel(props.record, props.record?.name, t)}`,
        width: '980px',
        alignCenter: true,
        appendToBody: true,
        closeOnClickModal: false
      }, {
        default: renderBody,
        footer: () => [
          h('el-button', { onClick: () => { visible.value = false } }, () => t('Cancel')),
          h('el-button', { type: 'primary', icon: 'el-icon-key', loading: saving.value || loading.value, onClick: save }, () => t('Save'))
        ]
      })
    ])
  }
})
