// Dedicated Qiniu Kodo configuration page.
//
// This is an explicit business page referenced from `business/admin/router.js`;
// the core view auto-discovery only scans its own `views/` directory. The page
// reuses the core manage Settings resource and persists every value under
// `groupName = "qiniu"`, matching the keys the backend media driver reads at
// runtime (`qiniu.access_key`, `qiniu.secret_key`, `qiniu.bucket`,
// `qiniu.domain`). `qiniu.region`, `qiniu.upload_path` and
// `qiniu.default_storage` are kept alongside them for operators.
import { defineComponent, h, reactive, ref, resolveComponent } from 'vue'
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'

const SETTINGS_URL = apiPath(API_PREFIX, 'manage/settings')
const GROUP = 'qiniu'

export default defineComponent({
  name: 'QiniuSetting',
  setup() {
    // Element Plus is registered globally by the core bootstrap (`app.use`),
    // so business pages resolve its components instead of importing the bare
    // `element-plus` package, which is not resolvable from outside the core tree.
    const ElCard = resolveComponent('ElCard')
    const ElAlert = resolveComponent('ElAlert')
    const ElForm = resolveComponent('ElForm')
    const ElFormItem = resolveComponent('ElFormItem')
    const ElInput = resolveComponent('ElInput')
    const ElSelect = resolveComponent('ElSelect')
    const ElOption = resolveComponent('ElOption')
    const ElButton = resolveComponent('ElButton')

    const fields = [
      { key: 'qiniu.access_key', label: t('AccessKey'), help: t('Qiniu access key help'), type: 'input' },
      { key: 'qiniu.secret_key', label: t('SecretKey'), help: t('Qiniu secret key help'), type: 'password' },
      { key: 'qiniu.bucket', label: t('Bucket'), help: t('Qiniu bucket help'), type: 'input' },
      { key: 'qiniu.domain', label: t('Domain'), help: t('Qiniu domain help'), type: 'input' },
      { key: 'qiniu.region', label: t('Region'), help: t('Qiniu region help'), type: 'input' },
      { key: 'qiniu.upload_path', label: t('Upload Path'), help: t('Qiniu upload path help'), type: 'input' },
      {
        key: 'qiniu.default_storage',
        label: t('Default Storage'),
        help: t('Qiniu default storage help'),
        type: 'select',
        options: [
          { value: 'local', label: 'local' },
          { value: 'qiniu', label: 'qiniu' }
        ]
      }
    ]

    const form = reactive({})
    const loading = ref(false)
    const saving = ref(false)
    const managedKeys = new Set(fields.map(field => field.key))

    // The shared request client returns either the raw payload or the
    // `{ code, data, message }` envelope; list responses may nest the rows one
    // more level. Normalize both shapes here instead of leaking them into the UI.
    const unwrap = payload =>
      payload && typeof payload === 'object' && 'data' in payload ? payload.data : payload

    // `common_setting.key` is globally unique, so a setting is identified by its
    // key alone, never by `groupName`. Resolving rows by key lets this page adopt
    // settings created elsewhere, for example by `app:storage:qiniu:settings:init`,
    // which stores them under the `storage` group. Creating a row whose key already
    // exists would otherwise be rejected with the backend "Duplication entries"
    // error ("数据重复").
    const fetchRowsByKey = async () => {
      const response = await request({
        url: SETTINGS_URL,
        method: 'GET',
        params: { '@order': 'entity.key|ASC', limit: 1000 }
      })
      const data = unwrap(response)
      const list = Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : [])

      const rows = {}
      list.forEach(item => {
        if (item && managedKeys.has(item.key)) {
          rows[item.key] = item
        }
      })
      return rows
    }

    const load = async () => {
      loading.value = true
      try {
        const rows = await fetchRowsByKey()
        fields.forEach(field => {
          const row = rows[field.key]
          form[field.key] = row && row.value != null ? row.value : ''
        })
      } catch {
        // The shared request client already surfaces the failure to the user.
      } finally {
        loading.value = false
      }
    }

    const save = async () => {
      if (saving.value) {
        return
      }
      saving.value = true
      try {
        // Re-resolve the stored rows right before writing so a stale or empty id
        // map cannot turn an existing setting into a duplicate insert.
        const rows = await fetchRowsByKey()
        for (const field of fields) {
          const value = form[field.key] == null ? '' : String(form[field.key])
          const row = rows[field.key]
          if (row && row.id) {
            await request({ url: `${SETTINGS_URL}/${row.id}`, method: 'PUT', data: { value } })
          } else {
            const created = unwrap(await request({
              url: SETTINGS_URL,
              method: 'POST',
              data: { key: field.key, value, type: 'string', groupName: GROUP, label: field.label }
            }))
            if (created && created.id) {
              rows[field.key] = created
            }
          }
        }
      } catch {
        // The shared request client already surfaces the failure to the user.
      } finally {
        saving.value = false
      }
    }

    const renderField = field => {
      const commonProps = {
        modelValue: form[field.key],
        'onUpdate:modelValue': value => { form[field.key] = value },
        placeholder: field.help,
        clearable: field.type !== 'select'
      }
      const control = field.type === 'select'
        ? h(ElSelect, commonProps, () => field.options.map(option => h(ElOption, { value: option.value, label: option.label })))
        : h(ElInput, { ...commonProps, type: field.type, showPassword: field.type === 'password' })

      return h(ElFormItem, { label: field.label, labelWidth: '160px' }, () => control)
    }

    load()

    return () => h('div', { style: 'padding:20px 24px' }, [
      h(ElCard, null, [
        h('h3', { style: 'margin:0 0 12px' }, t('Qiniu Storage Config')),
        h(ElAlert, {
          title: t('Qiniu storage config help'),
          type: 'info',
          'show-icon': true,
          closable: false,
          style: 'margin-bottom:16px'
        }),
        h(ElForm, { labelWidth: '160px', disabled: loading.value }, () => fields.map(renderField)),
        h('div', { style: 'text-align:right;margin-top:16px' }, [
          h(ElButton, { type: 'primary', loading: saving.value, onClick: save }, () => t('Save'))
        ])
      ])
    ])
  }
})
