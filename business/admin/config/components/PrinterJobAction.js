import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'
import PrinterPaper from './PrinterPaper.js'

const sampleData = JSON.stringify({ demoTitle: 'OFFLINE DEMO', order: { number: 'DEMO-001', items: [{ name: 'Demo tea', quantity: 2 }, { name: 'Demo cake', quantity: 1 }] } }, null, 2)

export default defineComponent({
  name: 'PrinterJobAction',
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
    const notice = ref('')
    const devices = ref([])
    const templates = ref([])
    const jobs = ref([])
    const values = ref({ deviceUuid: '', templateUuid: '', data: sampleData, copies: 1 })
    const preview = ref('')
    const error = ref('')
    const pending = ref({ fingerprint: '', idempotencyKey: '' })

    const itemsOf = response => Array.isArray(response?.items) ? response.items : Array.isArray(response?.data) ? response.data : []
    const load = async() => {
      const [deviceResponse, templateResponse, jobResponse] = await Promise.all([
        request.get('/api/v1/manage/printer/devices'),
        request.get('/api/v1/manage/printer/templates'),
        request.get('/api/v1/manage/printer/jobs')
      ])
      devices.value = itemsOf(deviceResponse).filter(item => item.enabled)
      templates.value = itemsOf(templateResponse).filter(item => item.enabled)
      jobs.value = itemsOf(jobResponse)
      if (!devices.value.some(item => item.uuid === values.value.deviceUuid)) values.value.deviceUuid = ''
      if (!values.value.deviceUuid && devices.value.length) values.value.deviceUuid = devices.value[0].uuid
      matchTemplate()
    }
    const compatibleTemplates = () => {
      const device = devices.value.find(item => item.uuid === values.value.deviceUuid)
      return templates.value.filter(item => !device || item.kind === device.kind)
    }
    const matchTemplate = () => {
      const options = compatibleTemplates()
      if (!options.some(item => item.uuid === values.value.templateUuid)) values.value.templateUuid = options[0]?.uuid || ''
    }
    const open = async() => {
      if (busy.value || loading.value) return
      visible.value = true
      error.value = ''
      notice.value = ''
      preview.value = ''
      devices.value = []
      templates.value = []
      loading.value = true
      try { await load() } catch (cause) { error.value = cause.message || t('Failed to load printer settings') }
      finally { loading.value = false }
    }
    const run = async(operation) => {
      if (busy.value || loading.value) return
      busy.value = true
      error.value = ''
      notice.value = ''
      try { await operation() }
      catch (cause) { error.value = cause.response?.data?.error || cause.message || t('Printer operation failed') }
      finally { busy.value = false }
    }
    const parseData = () => {
      try {
        const data = JSON.parse(values.value.data)
        if (!data || Array.isArray(data) || typeof data !== 'object') throw new Error()
        return data
      }
      catch { throw new Error(`${t('Sample data JSON')}: ${t('Invalid JSON')}`) }
    }
    const select = (key, options, label) => h(ElSelect, {
      modelValue: values.value[key], 'onUpdate:modelValue': value => { values.value[key] = value; preview.value = ''; if (key === 'deviceUuid') matchTemplate() },
      placeholder: t(label), style: 'width:100%', filterable: true, disabled: busy.value || loading.value
    }, () => options.map(item => h(ElOption, { key: item.uuid, value: item.uuid, label: item.name })))
    const textarea = () => h(ElInput, {
      modelValue: values.value.data,
      'onUpdate:modelValue': value => { values.value.data = value; preview.value = '' },
      type: 'textarea', rows: 8, disabled: busy.value || loading.value, placeholder: t('Sample data JSON')
    })

    const updatePreview = () => run(async() => {
      preview.value = ''
      const template = templates.value.find(item => item.uuid === values.value.templateUuid)
      if (!template) throw new Error(t('Select print template'))
      const response = await request.post('/api/v1/manage/printer/preview', { template: template.definition, data: parseData() })
      preview.value = response.data?.document || null
    })
    const submit = () => run(async() => {
      if (!devices.value.some(item => item.uuid === values.value.deviceUuid)
        || !compatibleTemplates().some(item => item.uuid === values.value.templateUuid)) throw new Error(t('Select device'))
      if (!window.confirm(t('Confirm live print'))) return
      const data = parseData()
      const fingerprint = JSON.stringify({ deviceUuid: values.value.deviceUuid, templateUuid: values.value.templateUuid, data, copies: Number(values.value.copies) })
      const unresolved = jobs.value.find(job => (job.deviceUuid || job.device?.uuid) === values.value.deviceUuid && job.status === 'unknown')
      if (unresolved && pending.value.fingerprint !== fingerprint && !window.confirm(t('Unknown previous job warning'))) return
      if (pending.value.fingerprint !== fingerprint || !pending.value.idempotencyKey) {
        pending.value = { fingerprint, idempotencyKey: globalThis.crypto.randomUUID() }
      }
      const response = await request.post('/api/v1/manage/printer/jobs', {
        deviceUuid: values.value.deviceUuid,
        templateUuid: values.value.templateUuid,
        data,
        copies: Number(values.value.copies),
        idempotencyKey: pending.value.idempotencyKey
      })
      notice.value = `${t('Job status')}: ${response.data?.status}`
      if (response.data?.status !== 'unknown') pending.value = { fingerprint: '', idempotencyKey: '' }
      props.refresh()
      await load()
    })
    const refreshStatuses = async() => {
      if (busy.value || loading.value) return
      busy.value = true
      error.value = ''
      try {
        const response = await request.get('/api/v1/manage/printer/jobs')
        const jobs = itemsOf(response)
        for (const job of jobs.filter(item => item.providerOrderId && !['printed', 'failed'].includes(item.status))) await request.post(`/api/v1/manage/printer/jobs/${job.uuid}/status`)
        props.refresh()
      } catch (cause) {
        error.value = cause.response?.data?.error || cause.message || t('Printer operation failed')
      } finally { busy.value = false }
    }

    const formItem = (label, child) => h(ElFormItem, { label: t(label) }, () => child)
    return () => h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;' }, [
      h(ElButton, { loading: busy.value, disabled: loading.value, onClick: refreshStatuses }, () => t('Query print status')),
      h(ElButton, { type: 'primary', disabled: busy.value || loading.value, onClick: open }, () => t('Create print job')),
      ...(!visible.value && error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false })] : []),
      h(ElDialog, {
        modelValue: visible.value,
        'onUpdate:modelValue': value => { visible.value = value },
        title: t('Create print job'),
        width: 'min(720px, 94vw)',
        alignCenter: true,
        closeOnClickModal: false,
        closeOnPressEscape: !busy.value && !loading.value,
        showClose: !busy.value && !loading.value,
        appendToBody: true
      }, {
        default: () => [
          ...(error.value ? [h(ElAlert, { title: error.value, type: 'error', closable: false, style: 'margin-bottom:16px' })] : []),
          ...(notice.value ? [h(ElAlert, { title: notice.value, type: 'info', closable: false, style: 'margin-bottom:16px' })] : []),
          ...(loading.value ? [h('p', { role: 'status' }, t('Loading'))] : []),
          ...(!loading.value && !devices.value.length ? [h(ElAlert, { title: t('No enabled printers. Preview is still available.'), type: 'info', closable: false })] : []),
          h(ElForm, { labelPosition: 'top', style: 'max-height:65vh;overflow:auto;padding:4px;' }, () => [
            formItem('Select device', select('deviceUuid', devices.value, 'Select device')),
            formItem('Select print template', select('templateUuid', compatibleTemplates(), 'Select print template')),
            formItem('Sample data JSON', textarea()),
            formItem('Copies', h(ElInputNumber, {
              modelValue: values.value.copies,
              'onUpdate:modelValue': value => { values.value.copies = Number(value) },
              min: 1, max: 100, precision: 0, disabled: busy.value || loading.value
            })),
            h(ElAlert, { title: t('Live print warning'), type: 'warning', closable: false }),
            ...(preview.value ? [h(PrinterPaper, { document: preview.value })] : [])
          ])
        ],
        footer: () => h('div', { style: 'display:flex;justify-content:flex-end;gap:8px;' }, [
          h(ElButton, { disabled: busy.value || loading.value, onClick: () => { visible.value = false } }, () => t('Close')),
          h(ElButton, { loading: busy.value, disabled: loading.value || !values.value.templateUuid, onClick: updatePreview }, () => t('Update preview')),
          h(ElButton, { type: 'primary', loading: busy.value, disabled: loading.value || !values.value.deviceUuid || !values.value.templateUuid, onClick: submit }, () => t('Submit print job'))
        ])
      })
    ])
  }
})
