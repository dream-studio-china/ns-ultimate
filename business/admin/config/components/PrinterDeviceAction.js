import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'

export default defineComponent({
  name: 'PrinterDeviceAction',
  props: { refresh: { type: Function, default: () => {} } },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElDialog = resolveComponent('ElDialog')
    const ElInput = resolveComponent('ElInput')
    const ElSelect = resolveComponent('ElSelect')
    const ElOption = resolveComponent('ElOption')
    const ElForm = resolveComponent('ElForm')
    const ElFormItem = resolveComponent('ElFormItem')
    const ElAlert = resolveComponent('ElAlert')
    const ElInputNumber = resolveComponent('ElInputNumber')
    const visible = ref(false)
    const busy = ref(false)
    const loading = ref(false)
    const error = ref('')
    const accounts = ref([])
    const form = ref({ accountUuid: '', name: '', serialNumber: '', kind: 'receipt', model: '', storeUuid: '', widthMm: 40, heightMm: 30 })
    const load = async() => {
      const response = await request.get('/api/v1/manage/printer/accounts')
      accounts.value = (Array.isArray(response?.items) ? response.items : Array.isArray(response?.data) ? response.data : []).filter(item => item.enabled)
      if (!accounts.value.some(item => item.uuid === form.value.accountUuid)) form.value.accountUuid = ''
      if (!form.value.accountUuid && accounts.value.length) form.value.accountUuid = accounts.value[0].uuid
    }
    const refreshStatuses = async() => {
      if (busy.value || loading.value) return
      busy.value = true
      error.value = ''
      try {
        const response = await request.get('/api/v1/manage/printer/devices')
        const devices = Array.isArray(response?.items) ? response.items : Array.isArray(response?.data) ? response.data : []
        for (const device of devices.filter(item => item.enabled)) await request.post(`/api/v1/manage/printer/devices/${device.uuid}/status`)
        props.refresh()
      } catch (cause) {
        error.value = cause.response?.data?.error || cause.message || t('Printer operation failed')
      } finally { busy.value = false }
    }
    const open = async() => {
      if (busy.value || loading.value) return
      visible.value = true
      error.value = ''
      loading.value = true
      accounts.value = []
      try { await load() } catch (cause) { error.value = cause.message || t('Failed to load printer settings') }
      finally { loading.value = false }
    }
    const field = (key, label, type = 'text') => h(type === 'number' ? ElInputNumber : ElInput, {
      modelValue: form.value[key], 'onUpdate:modelValue': value => { form.value[key] = type === 'number' ? Number(value) : value },
      ...(type === 'number' ? { min: 10, max: 200, precision: 0 } : { type, clearable: true }),
      disabled: busy.value || loading.value, placeholder: t(label), style: 'width:100%'
    })
    const submit = async() => {
      if (busy.value || loading.value) return
      busy.value = true
      error.value = ''
      try {
        if (!form.value.accountUuid || !form.value.name.trim() || !form.value.serialNumber.trim()) throw new Error(t('Printer name, serial number and account are required.'))
        await request.post('/api/v1/manage/printer/devices', {
          ...form.value,
          widthMm: form.value.kind === 'label' ? Number(form.value.widthMm) : null,
          heightMm: form.value.kind === 'label' ? Number(form.value.heightMm) : null,
          storeUuid: form.value.storeUuid || null,
          model: form.value.model || null
        })
        visible.value = false
        form.value = { accountUuid: '', name: '', serialNumber: '', kind: 'receipt', model: '', storeUuid: '', widthMm: 40, heightMm: 30 }
        props.refresh()
      } catch (cause) {
        error.value = cause.response?.data?.error || cause.message || t('Printer operation failed')
      } finally { busy.value = false }
    }
    const formItem = (label, child) => h(ElFormItem, { label: t(label) }, () => child)
    return () => h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;' }, [
      h(ElButton, { loading: busy.value, disabled: loading.value, onClick: refreshStatuses }, () => t('Refresh device status')),
      h(ElButton, { type: 'primary', disabled: busy.value || loading.value, onClick: open }, () => t('Register printer')),
      ...(!visible.value && error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : []),
      h(ElDialog, { modelValue: visible.value, 'onUpdate:modelValue': value => { visible.value = value }, title: t('Register printer'), width: 'min(560px, 94vw)', alignCenter: true, closeOnClickModal: false, closeOnPressEscape: !busy.value && !loading.value, showClose: !busy.value && !loading.value, appendToBody: true }, {
        default: () => [
          ...(error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : []),
          ...(loading.value ? [h('p', { role: 'status' }, t('Loading'))] : []),
          ...(!loading.value && !accounts.value.length ? [h(ElAlert, { title: t('Create an enabled printer account first.'), type: 'info', closable: false })] : []),
          h(ElForm, { labelPosition: 'top', style: 'max-height:65vh;overflow:auto;padding:4px;' }, () => [
            formItem('Select account', h(ElSelect, { disabled: busy.value || loading.value, filterable: true, modelValue: form.value.accountUuid, 'onUpdate:modelValue': value => { form.value.accountUuid = value }, placeholder: t('Select account'), style: 'width:100%' }, () => accounts.value.map(item => h(ElOption, { key: item.uuid, value: item.uuid, label: item.name })))),
            formItem('Device name', field('name', 'Device name')),
            formItem('Device serial number', field('serialNumber', 'Device serial number')),
            formItem('Printer type', h(ElSelect, { disabled: busy.value || loading.value, modelValue: form.value.kind, 'onUpdate:modelValue': value => { form.value.kind = value }, style: 'width:100%' }, () => [
              h(ElOption, { value: 'receipt', label: t('Receipt') }), h(ElOption, { value: 'label', label: t('Label') })
            ])),
            formItem('Printer model optional', field('model', 'Printer model optional')),
            formItem('Store UUID optional', field('storeUuid', 'Store UUID optional')),
            ...(form.value.kind === 'label' ? [formItem('Label width mm', field('widthMm', 'Label width mm', 'number')), formItem('Label height mm', field('heightMm', 'Label height mm', 'number'))] : [])
          ])
        ],
        footer: () => h('div', { style: 'display:flex;justify-content:flex-end;gap:8px;' }, [
          h(ElButton, { disabled: busy.value || loading.value, onClick: () => { visible.value = false } }, () => t('Close')),
          h(ElButton, { type: 'primary', loading: busy.value, disabled: loading.value || !form.value.accountUuid || !form.value.name.trim() || !form.value.serialNumber.trim(), onClick: submit }, () => t('Register printer'))
        ])
      })
    ])
  }
})
