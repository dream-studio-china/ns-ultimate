<template>
  <main class="printer-workbench">
    <h1>{{ t('Printer workbench') }}</h1>
    <div class="controls">
      <label>{{ t('Printer type') }}
        <select v-model="kind" @change="loadExample">
          <option value="receipt">{{ t('Receipt') }}</option>
          <option value="label">{{ t('Label') }}</option>
        </select>
      </label>
      <label>{{ t('Sample') }}
        <select v-model="sample" @change="loadExample">
          <option value="order">{{ t('Order with multiple items') }}</option>
          <option value="batch">{{ t('Batch product labels') }}</option>
        </select>
      </label>
      <button @click="refresh">{{ t('Update preview') }}</button>
    </div>
    <div class="panes">
      <section><h2>{{ t('Template JSON') }}</h2><textarea v-model="templateText" spellcheck="false" /></section>
      <section><h2>{{ t('Sample data JSON') }}</h2><textarea v-model="dataText" spellcheck="false" /></section>
      <section><h2>{{ t('Print simulation') }} ({{ pages.length }} {{ t('Pages') }})</h2>
        <div class="preview-list">
          <article v-for="(page, index) in pages" :key="index" :class="['paper', kind]" :style="kind === 'label' ? { width: `${labelSize.width * 3}px`, height: `${labelSize.height * 3}px` } : {}">
            <template v-if="kind === 'label'"><small class="page-number">{{ t('Label page') }} {{ index + 1 }} / {{ pages.length }}</small>
              <span v-for="(element, elementIndex) in labelElements(page)" :key="elementIndex" :class="['label-element', element.type]" :style="elementStyle(element)">
                {{ element.text }}
              </span>
            </template>
            <pre v-else>{{ readable(page) }}</pre>
          </article>
        </div>
        <p v-if="error" class="error">{{ error }}</p>
        <p class="warning">{{ t('Simulation disclaimer') }}</p>
      </section>
    </div>
    <section v-if="result"><h2>{{ t('Xpyun commands') }}</h2><pre class="commands">{{ result.document.content }}</pre>
      <h2>JavaScript {{ t('Example') }}</h2><pre class="commands">{{ result.javascript }}</pre>
      <h2>cURL {{ t('Example') }}</h2><pre class="commands">{{ result.cURL }}</pre></section>
    <section class="management">
      <h2>{{ t('Printer accounts and devices') }}</h2>
      <div class="forms">
        <form @submit.prevent="createAccount">
          <h3>{{ t('Add Xpyun account') }}</h3>
          <input v-model="accountForm.name" required :placeholder="t('Account name')">
          <input v-model="accountForm.developerId" required :placeholder="t('Developer ID')">
          <input v-model="accountForm.userKey" required type="password" :placeholder="t('Developer key')">
          <button>{{ t('Save account') }}</button>
        </form>
        <form @submit.prevent="createDevice">
          <h3>{{ t('Register printer') }}</h3>
          <select v-model="deviceForm.accountUuid" required><option value="">{{ t('Select account') }}</option><option v-for="account in accounts" :key="account.uuid" :value="account.uuid">{{ account.name }}</option></select>
          <input v-model="deviceForm.name" required :placeholder="t('Device name')">
          <input v-model="deviceForm.serialNumber" required :placeholder="t('Device serial number')">
          <select v-model="deviceForm.kind"><option value="receipt">{{ t('Receipt') }}</option><option value="label">{{ t('Label') }}</option></select>
          <template v-if="deviceForm.kind === 'label'"><input v-model.number="deviceForm.widthMm" type="number" min="10" max="200" :placeholder="t('Label width mm')"><input v-model.number="deviceForm.heightMm" type="number" min="10" max="200" :placeholder="t('Label height mm')"></template>
          <input v-model="deviceForm.model" :placeholder="t('Printer model optional')">
          <input v-model="deviceForm.storeUuid" :placeholder="t('Store UUID optional')">
          <p v-if="deviceForm.kind === 'label'" class="warning">{{ t('Label model compatibility warning') }}</p>
          <button :disabled="!accounts.length">{{ t('Register device') }}</button>
        </form>
      </div>
      <div class="lists">
        <div><h3>{{ t('Configured devices') }}</h3><p v-for="device in devices" :key="device.uuid">{{ device.name }} · {{ t(device.kind === 'label' ? 'Label' : 'Receipt') }} · {{ device.serialNumber }} · {{ statusName(device.status) }} <button @click="refreshDeviceStatus(device)">{{ t('Refresh status') }}</button></p></div>
        <div><h3>{{ t('Save template') }}</h3><form @submit.prevent="saveTemplate"><input v-model="templateName" required :placeholder="t('Template name')"><button>{{ t('Save current template') }}</button></form><select v-model="selectedTemplate"><option value="">{{ t('Select print template') }}</option><option v-for="template in templates" :key="template.uuid" :value="template.uuid">{{ template.name }} ({{ t(template.kind === 'label' ? 'Label' : 'Receipt') }})</option></select></div>
      </div>
      <h2>{{ t('Live print jobs') }}</h2>
      <div class="controls"><select v-model="selectedDevice"><option value="">{{ t('Select device') }}</option><option v-for="device in devices" :key="device.uuid" :value="device.uuid">{{ device.name }} ({{ t(device.kind === 'label' ? 'Label' : 'Receipt') }})</option></select><label>{{ t('Copies') }} <input v-model.number="copies" type="number" min="1" max="100"></label><span>{{ t('Estimated print count', selectedDeviceKind === 'label' ? pages.length * copies : copies) }}</span><button :disabled="!selectedTemplate || !selectedDevice" @click="submitPrint">{{ t('Confirm and print') }}</button><button @click="loadAdminData">{{ t('Refresh lists') }}</button></div>
      <p class="warning">{{ t('Live print warning') }}</p>
      <div><h3>{{ t('Recent jobs') }}</h3><p v-for="job in jobs" :key="job.uuid">{{ job.createdAt }} · {{ job.deviceName }} · {{ t(job.kind === 'label' ? 'Label' : 'Receipt') }} · {{ job.status }} · {{ job.providerOrderId || job.errorCode || '' }} <button v-if="job.providerOrderId" @click="refreshJobStatus(job)">{{ t('Query print status') }}</button></p></div>
      <p v-if="managementError" class="error">{{ managementError }}</p>
      <p v-if="managementMessage">{{ managementMessage }}</p>
    </section>
  </main>
</template>

<script>
import request from '@/utils/request'
import { t } from '@/i18n'

const orderTemplate = () => ({ kind: 'receipt', nodes: [
  { type: 'text', value: `${t('Order label')}: ` }, { type: 'field', path: 'order.number' }, { type: 'line' },
  { type: 'separator' }, { type: 'each', path: 'order.items', children: [
    { type: 'field', path: 'item.name' }, { type: 'text', value: ' x ' },
    { type: 'field', path: 'item.quantity' }, { type: 'text', value: '  ' },
    { type: 'field', path: 'item.unitPrice' }, { type: 'line' }
  ] }, { type: 'separator' }, { type: 'text', value: t('Thank you') }, { type: 'line' }
] })
const batchTemplate = () => ({ kind: 'label', widthMm: 40, heightMm: 30, nodes: [{ type: 'each', path: 'items', children: [
  { type: 'labelText', x: 16, y: 24, path: 'item.name' },
  { type: 'labelText', x: 16, y: 64, path: 'item.price' },
  { type: 'barcode', x: 16, y: 100, path: 'item.barcode', format: 'BC128', height: 64 }
]}] })
const orderData = () => ({ order: { number: 'A-2026-001', items: [
  { name: t('Sample item A'), quantity: 2, unitPrice: '12.50' },
  { name: t('Sample item B'), quantity: 1, unitPrice: '36.00' }
] } })
const batchData = () => ({ items: [
  { name: t('Sample item A'), price: '12.50', barcode: '690000000001' },
  { name: t('Sample item B'), price: '36.00', barcode: '690000000002' }
] })

export default {
  name: 'PrinterWorkbench',
  data: () => ({
    kind: 'receipt', sample: 'order', templateText: '', dataText: '', result: null, error: '', timer: null, requestId: 0,
    accounts: [], devices: [], templates: [], jobs: [], templateName: '', selectedTemplate: '', selectedDevice: '', copies: 1,
    managementError: '', managementMessage: '', pendingIdempotencyKey: '', pendingFingerprint: '',
    accountForm: { name: '', developerId: '', userKey: '' },
    deviceForm: { accountUuid: '', name: '', serialNumber: '', kind: 'receipt', model: '', storeUuid: '', widthMm: 40, heightMm: 30 }
  }),
  computed: {
    pages() { return this.result?.document?.pages || [] },
    selectedDeviceKind() { return this.devices.find(device => device.uuid === this.selectedDevice)?.kind || this.kind },
    labelSize() {
      try { const definition = JSON.parse(this.templateText); return { width: definition.widthMm || 40, height: definition.heightMm || 30 } }
      catch (_) { return { width: 40, height: 30 } }
    }
  },
  created() { this.loadExample(); this.loadAdminData() },
  beforeUnmount() { clearTimeout(this.timer) },
  watch: {
    templateText() { this.schedulePreview() },
    dataText() { this.schedulePreview() }
  },
  methods: {
    t,
    loadExample() {
      const order = this.sample === 'order'
      this.kind = order ? 'receipt' : 'label'
      this.templateText = JSON.stringify(order ? orderTemplate() : batchTemplate(), null, 2)
      this.dataText = JSON.stringify(order ? orderData() : batchData(), null, 2)
      this.refresh()
    },
    schedulePreview() { clearTimeout(this.timer); this.timer = setTimeout(this.refresh, 250) },
    async refresh() {
      const requestId = ++this.requestId
      this.error = ''
      try {
        const template = JSON.parse(this.templateText)
        const data = JSON.parse(this.dataText)
        const result = await request({ url: '/api/v1/manage/printer/preview', method: 'post', data: { template, data } })
        if (requestId !== this.requestId) return
        this.result = result
      } catch (error) {
        if (requestId !== this.requestId) return
        this.error = error.response?.data?.error || error.message || t('Preview failed')
        this.result = null
      }
    },
    async loadAdminData() {
      try {
        const [accounts, devices, templates, jobs] = await Promise.all([
          request({ url: '/api/v1/manage/printer/accounts', method: 'get' }),
          request({ url: '/api/v1/manage/printer/devices', method: 'get' }),
          request({ url: '/api/v1/manage/printer/templates', method: 'get' }),
          request({ url: '/api/v1/manage/printer/jobs', method: 'get' })
        ])
        this.accounts = accounts.items || []; this.devices = devices.items || []
        this.templates = templates.items || []; this.jobs = jobs.items || []
      } catch (error) { this.managementError = error.message || t('Failed to load printer settings') }
    },
    async createAccount() {
      this.managementError = ''; this.managementMessage = ''
      try {
        await request({ url: '/api/v1/manage/printer/accounts', method: 'post', data: this.accountForm })
        this.accountForm = { name: '', developerId: '', userKey: '' }; this.managementMessage = t('Account saved'); await this.loadAdminData()
      } catch (error) { this.managementError = error.message || t('Failed to save account') }
    },
    async createDevice() {
      this.managementError = ''; this.managementMessage = ''
      try {
        const device = await request({ url: '/api/v1/manage/printer/devices', method: 'post', data: this.deviceForm })
        this.selectedDevice = device.uuid; this.managementMessage = t('Device registered'); await this.loadAdminData()
      } catch (error) { this.managementError = error.message || t('Failed to register device') }
    },
    async saveTemplate() {
      this.managementError = ''; this.managementMessage = ''
      try {
        const definition = JSON.parse(this.templateText)
        const sampleData = JSON.parse(this.dataText)
        const template = await request({ url: '/api/v1/manage/printer/templates', method: 'post', data: { name: this.templateName, kind: definition.kind, definition, sampleData } })
        this.selectedTemplate = template.uuid; this.managementMessage = t('Template saved'); await this.loadAdminData()
      } catch (error) { this.managementError = error.response?.data?.error || error.message || t('Failed to save template') }
    },
    async submitPrint() {
      if (!window.confirm(t('Confirm live print'))) return
      this.managementError = ''; this.managementMessage = ''
      try {
        const data = JSON.parse(this.dataText)
        const fingerprint = JSON.stringify({ deviceUuid: this.selectedDevice, templateUuid: this.selectedTemplate, data, copies: this.copies })
        const unresolvedJob = this.jobs.find(job => job.deviceUuid === this.selectedDevice && job.status === 'unknown')
        if (unresolvedJob && this.pendingFingerprint !== fingerprint && !window.confirm(t('Unknown previous job warning'))) return
        if (fingerprint !== this.pendingFingerprint || !this.pendingIdempotencyKey) {
          this.pendingFingerprint = fingerprint
          this.pendingIdempotencyKey = this.createIdempotencyKey()
        }
        const job = await request({ url: '/api/v1/manage/printer/jobs', method: 'post', data: {
          deviceUuid: this.selectedDevice, templateUuid: this.selectedTemplate, data, copies: this.copies,
          idempotencyKey: this.pendingIdempotencyKey
        } })
        this.managementMessage = `${t('Job status')}: ${job.status}${job.providerOrderId ? `, ${t('Provider order ID')}: ${job.providerOrderId}` : ''}`
        if (job.status !== 'unknown') { this.pendingIdempotencyKey = ''; this.pendingFingerprint = '' }
        await this.loadAdminData()
      } catch (error) {
        if (error.response?.data?.status === 'failed') { this.pendingIdempotencyKey = ''; this.pendingFingerprint = '' }
        this.managementError = error.response?.data?.error || error.message || t('Failed to submit print job')
        await this.loadAdminData()
      }
    },
    async refreshDeviceStatus(device) {
      try { await request({ url: `/api/v1/manage/printer/devices/${device.uuid}/status`, method: 'post' }); await this.loadAdminData() }
      catch (error) { this.managementError = error.message || t('Failed to refresh device status') }
    },
    async refreshJobStatus(job) {
      try { await request({ url: `/api/v1/manage/printer/jobs/${job.uuid}/status`, method: 'post' }); await this.loadAdminData() }
      catch (error) { this.managementError = error.message || t('Failed to refresh job status') }
    },
    statusName(status) { return ({ 0: t('Offline'), 1: t('Online'), 2: t('Online with error') })[status] || t('Not checked') },
    createIdempotencyKey() {
      if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
      const bytes = new Uint8Array(16)
      globalThis.crypto.getRandomValues(bytes)
      return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
    },
    labelElements(page) {
      const elements = []
      const pattern = /<(TEXT|BC128|BC39|QRC)\b([^>]*)>(.*?)<\/\1>/g
      let match
      while ((match = pattern.exec(page)) !== null) {
        const attribute = name => Number((match[2].match(new RegExp(`${name}="(\\d+)"`)) || [])[1] || 0)
        elements.push({ type: match[1] === 'TEXT' ? 'label-text' : match[1] === 'QRC' ? 'qr-preview' : 'barcode-preview', x: attribute('x'), y: attribute('y'), height: attribute('h'), text: match[3].replace(/&lt/g, '<').replace(/&gt/g, '>') })
      }
      return elements
    },
    elementStyle(element) {
      const scale = 3 / 8
      return { left: `${element.x * scale}px`, top: `${element.y * scale}px`, minHeight: element.height ? `${element.height * scale}px` : undefined }
    },
    readable(value) { return String(value).replace(/<BR>/g, '\n').replace(/<\/?(?:BOLD|C|L|R|N)>/g, '') }
  }
}
</script>

<style scoped>
.printer-workbench{padding:24px;color:#303133}.controls{display:flex;gap:20px;align-items:flex-end;margin-bottom:20px}.controls label{display:grid;gap:6px}.panes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.panes section{min-width:0}textarea{box-sizing:border-box;width:100%;height:300px;font:13px/1.5 monospace}.preview-list{display:flex;flex-wrap:wrap;gap:12px;max-height:560px;overflow:auto}.paper{box-sizing:border-box;position:relative;background:#fff;color:#111;padding:12px;box-shadow:0 1px 6px #bbb;white-space:pre-wrap;overflow-wrap:anywhere}.paper.receipt{width:280px;min-height:180px}.paper.label{flex:none;padding:0;border:1px dashed #888;overflow:hidden}.paper pre,.commands{white-space:pre-wrap;overflow-wrap:anywhere}.page-number{position:absolute;right:2px;top:2px;color:#999;font:9px sans-serif;background:#fff}.label-element{position:absolute;display:block;white-space:nowrap;font:10px/1.15 sans-serif}.barcode-preview{width:120px;border-bottom:1px solid #222;background:repeating-linear-gradient(90deg,#111 0,#111 1px,#fff 1px,#fff 3px)}.qr-preview{width:24px;height:24px;outline:1px solid #111;background:repeating-conic-gradient(#111 0 25%,#fff 0 50%) 0/6px 6px}.warning{color:#a66b00}.error{color:#c00}.commands{padding:12px;background:#f4f4f4}.management{margin-top:36px}.forms,.lists{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}.forms form{display:grid;gap:8px;align-content:start}.management input,.management select{padding:8px}.management p{padding:5px 0}.management button{margin-left:6px}@media(max-width:900px){.panes,.forms,.lists{grid-template-columns:1fr}}
</style>
