import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'
import { apiPath, API_PREFIX } from '@/api/prefix'

const normalizeList = (response) => {
  const raw = response?.data ?? response
  if (Array.isArray(raw)) return raw
  if (Array.isArray(raw?.data)) return raw.data
  if (Array.isArray(raw?.data?.data)) return raw.data.data
  return []
}

export default defineComponent({
  name: 'StoreUserBindingAction',
  props: {
    record: { type: Object, default: () => ({}) },
    refresh: { type: Function, default: () => {} }
  },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElDialog = resolveComponent('ElDialog')
    const ElDivider = resolveComponent('ElDivider')
    const ElInput = resolveComponent('ElInput')
    const ElTable = resolveComponent('ElTable')
    const ElTableColumn = resolveComponent('ElTableColumn')
    const ElTag = resolveComponent('ElTag')
    const visible = ref(false)
    const loadingMembers = ref(false)
    const searching = ref(false)
    const savingUserUuid = ref('')
    const keyword = ref('')
    const members = ref([])
    const users = ref([])
    const hasSearched = ref(false)
    const feedback = ref(null)

    const storeUuid = () => props.record?.uuid || ''
    const storeLabel = () => props.record?.name || props.record?.code || storeUuid()
    const membershipFor = (userUuid) => members.value.find((member) => member?.userUuid === userUuid)
    const isActiveMember = (member) => member && (!member.status || member.status === 'active')

    const loadMembers = async() => {
      if (!storeUuid()) return
      loadingMembers.value = true
      try {
        const response = await request.get(apiPath(API_PREFIX, `manage/stores/${storeUuid()}/members`), {
          params: { limit: 200, page: 1 }
        })
        members.value = normalizeList(response)
      } finally {
        loadingMembers.value = false
      }
    }

    const open = async() => {
      if (!storeUuid()) return
      visible.value = true
      keyword.value = ''
      users.value = []
      hasSearched.value = false
      feedback.value = null
      try {
        await loadMembers()
      } catch (error) {
        // The request helper presents the API error; the search action remains available.
      }
    }

    const search = async() => {
      const query = keyword.value.trim()
      if (!query) {
        users.value = []
        hasSearched.value = false
        return
      }

      searching.value = true
      hasSearched.value = true
      try {
        const literal = JSON.stringify(query)
        const response = await request.get(apiPath(API_PREFIX, 'manage/users'), {
          params: {
            page: 1,
            limit: 30,
            '@order': 'entity.id|DESC',
            '@filter': `entity.getUsername() matches ${literal} || entity.getUuid() == ${literal}`
          }
        })
        users.value = normalizeList(response).filter((user) => user?.uuid)
      } catch (error) {
        users.value = []
      } finally {
        searching.value = false
      }
    }

    const bind = async(user) => {
      const uuid = storeUuid()
      if (!uuid || !user?.uuid || savingUserUuid.value) return
      savingUserUuid.value = user.uuid
      let membershipGranted = false
      let completed = false

      try {
        const existingMembership = membershipFor(user.uuid)
        if (!isActiveMember(existingMembership)) {
          await request.post(apiPath(API_PREFIX, `manage/stores/${uuid}/members`), {
            userUuid: user.uuid,
            role: 'manager'
          })
          membershipGranted = true
        }

        await request.post(apiPath(API_PREFIX, 'manage/assignments'), {
          userUuid: user.uuid,
          roleId: 'store_catalog_manager',
          scopeType: 'store',
          scopeUuid: uuid
        })

        feedback.value = { type: 'success', text: t('Store account binding succeeded') }
        completed = true
      } catch (error) {
        if (membershipGranted) {
          feedback.value = { type: 'warning', text: t('Store member was added, but catalog access was not granted. Click again to retry.') }
        }
      } finally {
        savingUserUuid.value = ''
      }

      if (completed) {
        try {
          await loadMembers()
        } catch (error) {
          // Keep the successful grant result visible if the roster refresh fails.
        }
        props.refresh()
      }
    }

    const renderMembers = () => h('div', { class: 'store-user-binding__members' }, [
      h('div', { style: 'font-weight:600;margin:8px 0 12px;' }, t('Current store members')),
      loadingMembers.value
        ? h('div', { style: 'padding:16px;text-align:center;color:#909399;' }, t('Loading'))
        : members.value.length
          ? h(ElTable, { data: members.value, size: 'small', maxHeight: '180', stripe: true }, {
            default: () => [
              h(ElTableColumn, { prop: 'userUuid', label: t('User UUID'), minWidth: 280, showOverflowTooltip: true }),
              h(ElTableColumn, { prop: 'role', label: t('Membership role'), width: 140, formatter: (_row, _column, value) => t(`Membership ${value || 'manager'}`) }),
              h(ElTableColumn, { prop: 'status', label: t('Member status'), width: 120 }, {
                default: ({ row }) => h(ElTag, {
                  type: row.status === 'active' || !row.status ? 'success' : 'info',
                  size: 'small'
                }, () => t(`Membership status ${row.status || 'active'}`))
              })
            ]
          })
          : h('div', { style: 'padding:16px;text-align:center;color:#909399;' }, t('No store members yet'))
    ])

    const renderUsers = () => {
      if (searching.value) return h('div', { style: 'padding:24px;text-align:center;color:#909399;' }, t('Loading'))
      if (!hasSearched.value) return h('div', { style: 'padding:24px;text-align:center;color:#909399;' }, t('Search for an account by username or UUID'))
      if (!users.value.length) return h('div', { style: 'padding:24px;text-align:center;color:#909399;' }, t('No matching accounts'))

      return h(ElTable, { data: users.value, size: 'small', maxHeight: '300', stripe: true }, {
        default: () => [
          h(ElTableColumn, { prop: 'username', label: t('Account name'), minWidth: 150 }),
          h(ElTableColumn, { prop: 'uuid', label: t('User UUID'), minWidth: 280, showOverflowTooltip: true }),
          h(ElTableColumn, { prop: 'email', label: t('Email'), minWidth: 180, showOverflowTooltip: true }),
          h(ElTableColumn, { label: t('Actions'), width: 190, fixed: 'right' }, {
            default: ({ row }) => {
              const activeMember = isActiveMember(membershipFor(row.uuid))
              return h(ElButton, {
                size: 'small',
                type: 'primary',
                plain: activeMember,
                loading: savingUserUuid.value === row.uuid,
                disabled: !!savingUserUuid.value,
                onClick: (event) => {
                  event?.stopPropagation?.()
                  bind(row)
                }
              }, () => activeMember ? t('Grant catalog access') : t('Bind and grant catalog access'))
            }
          })
        ]
      })
    }

    return () => h('span', [
      h(ElButton, {
        size: 'small',
        plain: true,
        icon: 'el-icon-user',
        disabled: !storeUuid(),
        onClick: (event) => {
          event?.stopPropagation?.()
          open()
        }
      }, () => t('Bind account')),
      h(ElDialog, {
        modelValue: visible.value,
        'onUpdate:modelValue': (value) => { visible.value = value },
        title: `${t('Bind store account')} - ${storeLabel()}`,
        width: '920px',
        alignCenter: true,
        appendToBody: true,
        closeOnClickModal: false
      }, {
        default: () => [
          h('p', { style: 'margin:0 0 16px;padding:10px 12px;background:#f6f8fa;border:1px solid #ebeef5;border-radius:6px;color:#606266;line-height:1.7;' }, t('Store account binding note')),
          feedback.value && h('p', {
            role: 'status',
            style: `margin:0 0 12px;padding:10px 12px;border-radius:6px;line-height:1.6;color:${feedback.value.type === 'success' ? '#529b2e' : '#b88230'};background:${feedback.value.type === 'success' ? '#f0f9eb' : '#fdf6ec'};`
          }, feedback.value.text),
          h('div', { style: 'display:flex;gap:8px;margin-bottom:12px;' }, [
            h(ElInput, {
              modelValue: keyword.value,
              'onUpdate:modelValue': (value) => { keyword.value = value },
              clearable: true,
              placeholder: t('Search by username or UUID'),
              onKeyup: (event) => { if (event.key === 'Enter') search() }
            }),
            h(ElButton, { type: 'primary', icon: 'el-icon-search', loading: searching.value, onClick: search }, () => t('Search'))
          ]),
          renderUsers(),
          h(ElDivider),
          renderMembers()
        ],
        footer: () => h(ElButton, { onClick: () => { visible.value = false } }, () => t('Close'))
      })
    ])
  }
})
