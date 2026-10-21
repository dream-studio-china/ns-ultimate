import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'

const defaultTemplate = JSON.stringify({ kind: 'receipt', nodes: [
  { type: 'text', value: 'Order: ' }, { type: 'field', path: 'order.number' }, { type: 'line' },
  { type: 'each', path: 'order.items', children: [
    { type: 'field', path: 'item.name' }, { type: 'text', value: ' x ' }, { type: 'field', path: 'item.quantity' }, { type: 'line' }
  ] }
] }, null, 2)
const defaultData = JSON.stringify({ order: { number: 'A-2026-001', items: [{ name: 'Sample product', quantity: 2 }] } }, null, 2)

export default defineComponent({
  name: 'PrinterOperations',
  props: { refresh: { type: Function, default: () => {} } },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElDialog = resolveComponent('ElDialog')
    const ElInput = resolveComponent('ElInput')
    const ElSelect = resolveComponent('ElSelect')
    const ElOption = resolveComponent('ElOption')
    const visible = ref(false)
    const busy = ref(false)
    const accounts = ref([])
    const devices = ref([])
    const templates = ref([])
    const jobs = ref([])
    const pendingIdempotencyKey = ref('')
    const pendingPrintFingerprint = ref('')
    const account = ref({ name: '', developerId: '', userKey: '' })
    const device = ref({ accountUuid: '', name: '', serialNumber: '', kind: 'receipt', storeUuid: '', model: '', widthMm: 40, heightMm: 30 })
    const template = ref({ name: '', definition: defaultTemplate })
    const print = ref({ deviceUuid: '', templateUuid: '', data: defaultData, copies: 1, preview: '' })
    const error = ref('')
    const itemsOf = response => Array.isArray(response?.items) ? response.items : Array.isArray(response?.data) ? response.data : []

    const load = async() => {
      const [accountResult, deviceResult, templateResult, jobResult] = await Promise.all([
        request.get('/api/v1/manage/printer/accounts'), request.get('/api/v1/manage/printer/devices'),
        request.get('/api/v1/manage/printer/templates'), request.get('/api/v1/manage/printer/jobs')
      ])
      accounts.value = itemsOf(accountResult)
      devices.value = itemsOf(deviceResult)
      templates.value = itemsOf(templateResult)
      jobs.value = itemsOf(jobResult)
    }
    const open = async() => {
      visible.value = true
      error.value = ''
      try { await load() } catch (cause) { error.value = cause.message || t('Failed to load printer settings') }
    }
    const run = async(operation) => {
      busy.value = true
      error.value = ''
      try { await operation(); await load(); props.refresh() }
      catch (cause) { error.value = cause.response?.data?.error || cause.message || t('Printer operation failed') }
      finally { busy.value = false }
    }
    const parse = (value, label) => {
      try { return JSON.parse(value) } catch { throw new Error(`${label}: ${t('Invalid JSON')}`) }
    }
    const field = (model, key, placeholder, type = 'text') => h(ElInput, {
      modelValue: model.value[key], 'onUpdate:modelValue': value => { model.value[key] = value },
      placeholder: t(placeholder), type, clearable: true
    })
    const panel = (title, children) => h('section', { class: 'printer-operation-panel' }, [h('h3', title), ...children])
    const submit = (label, callback) => h(ElButton, { type: 'primary', loading: busy.value, onClick: () => run(callback) }, () => t(label))
    const pick = (model, key, options, label) => h(ElSelect, {
      modelValue: model.value[key], 'onUpdate:modelValue': value => { model.value[key] = value },
      placeholder: t(label), style: 'width:100%'
    }, () => options.map(item => h(ElOption, { key: item.uuid, value: item.uuid, label: item.name })))
    const textarea = (model, key, label) => h(ElInput, {
      modelValue: model.value[key], 'onUpdate:modelValue': value => { model.value[key] = value },
      type: 'textarea', rows: 8, placeholder: t(label)
    })
    const refreshDevice = async() => {
      for (const item of devices.value) await request.post(`/api/v1/manage/printer/operations/devices/${item.uuid}/status`)
    }
    const refreshJobs = async() => {
      for (const job of jobs.value.filter(item => item.providerOrderId)) await request.post(`/api/v1/manage/printer/operations/jobs/${job.uuid}/status`)
    }
    const renderPanels = () => [
      panel(t('Add Xpyun account'), [
        field(account, 'name', 'Account name'), field(account, 'developerId', 'Developer ID'), field(account, 'userKey', 'Developer key', 'password'),
        submit('Save account', async() => { await request.post('/api/v1/manage/printer/operations/accounts', account.value); account.value = { name: '', developerId: '', userKey: '' } })
      ]),
      panel(t('Register printer'), [
        pick(account, 'accountUuid', accounts.value, 'Select account'), field(device, 'name', 'Device name'), field(device, 'serialNumber', 'Device serial number'),
        h(ElSelect, { modelValue: device.value.kind, 'onUpdate:modelValue': value => { device.value.kind = value }, style: 'width:100%' }, () => [
          h(ElOption, { value: 'receipt', label: t('Receipt') }), h(ElOption, { value: 'label', label: t('Label') })
        ]),
        field(device, 'model', 'Printer model optional'), field(device, 'storeUuid', 'Store UUID optional'),
        ...(device.value.kind === 'label' ? [field(device, 'widthMm', 'Label width mm'), field(device, 'heightMm', 'Label height mm')] : []),
        submit('Register device', async() => { await request.post('/api/v1/manage/printer/operations/devices', device.value) })
      ]),
      panel(t('Save template'), [
        field(template, 'name', 'Template name'), textarea(template, 'definition', 'Template JSON'),
        submit('Save current template', async() => {
          const definition = parse(template.value.definition, t('Template JSON'))
          await request.post('/api/v1/manage/printer/templates', { name: template.value.name, kind: definition.kind, definition, sampleData: parse(print.value.data, t('Sample data JSON')) })
        })
      ]),
      panel(t('Print simulation and submission'), [
        pick(print, 'deviceUuid', devices.value, 'Select device'), pick(print, 'templateUuid', templates.value, 'Select print template'),
        textarea(print, 'data', 'Sample data JSON'),
        h(ElInput, { modelValue: print.value.copies, 'onUpdate:modelValue': value => { print.value.copies = Number(value) }, type: 'number', min: 1, max: 100, placeholder: t('Copies') }),
        h('div', { class: 'printer-operation-buttons' }, [
          h(ElButton, { loading: busy.value, onClick: () => run(async() => {
            const templateValue = templates.value.find(item => item.uuid === print.value.templateUuid)
            if (!templateValue) throw new Error(t('Select print template'))
            const response = await request.post('/api/v1/manage/printer/preview', { template: templateValue.definition, data: parse(print.value.data, t('Sample data JSON')) })
            print.value.preview = response.document?.content || ''
          }) }, () => t('Update preview')),
          h(ElButton, { type: 'danger', loading: busy.value, onClick: () => {
            if (!window.confirm(t('Confirm live print'))) return
            return run(async() => {
              const data = parse(print.value.data, t('Sample data JSON'))
              const fingerprint = JSON.stringify({ deviceUuid: print.value.deviceUuid, templateUuid: print.value.templateUuid, data, copies: Number(print.value.copies) })
              const unresolved = jobs.value.find(job => job.deviceUuid === print.value.deviceUuid && job.status === 'unknown')
              if (unresolved && pendingPrintFingerprint.value !== fingerprint && !window.confirm(t('Unknown previous job warning'))) return
              if (pendingPrintFingerprint.value !== fingerprint || !pendingIdempotencyKey.value) {
                pendingPrintFingerprint.value = fingerprint
                pendingIdempotencyKey.value = globalThis.crypto.randomUUID()
              }
              const response = await request.post('/api/v1/manage/printer/operations/jobs', {
                deviceUuid: print.value.deviceUuid, templateUuid: print.value.templateUuid,
                data, copies: Number(print.value.copies), idempotencyKey: pendingIdempotencyKey.value
              })
              error.value = `${t('Job status')}: ${response.status}`
              if (response.status !== 'unknown') {
                pendingPrintFingerprint.value = ''
                pendingIdempotencyKey.value = ''
              }
            })
          } }, () => t('Confirm and print'))
        ]),
        ...(print.value.preview ? [h('pre', { class: 'printer-operation-preview' }, print.value.preview)] : [])
      ]),
      panel(t('Refresh provider statuses'), [
        h(ElButton, { loading: busy.value, onClick: () => run(refreshDevice) }, () => t('Refresh device status')),
        h(ElButton, { loading: busy.value, onClick: () => run(refreshJobs) }, () => t('Query print status'))
      ])
    ]

    return () => h('span', [
      h(ElButton, { type: 'primary', icon: 'el-icon-printer', onClick: open }, () => t('Printer operations')),
      h(ElDialog, { modelValue: visible.value, 'onUpdate:modelValue': value => { visible.value = value }, title: t('Printer operations'), width: '760px', appendToBody: true }, {
        default: () => [
          ...(error.value ? [h('p', { class: 'printer-operation-error' }, error.value)] : []),
          h('div', { class: 'printer-operation-grid', style: 'display:grid;gap:14px;max-height:65vh;overflow:auto;padding:2px 4px;' }, renderPanels())
        ],
        footer: () => h(ElButton, { onClick: () => { visible.value = false } }, () => t('Close'))
      })
    ])
  }
})
