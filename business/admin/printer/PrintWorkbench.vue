<template>
  <main class="printer-workbench">
    <h1>打印调试台</h1>
    <div class="controls">
      <label>类型
        <select v-model="kind" @change="loadExample">
          <option value="receipt">小票</option>
          <option value="label">标签</option>
        </select>
      </label>
      <label>示例
        <select v-model="sample" @change="loadExample">
          <option value="order">订单含多条明细</option>
          <option value="batch">批量商品标签</option>
        </select>
      </label>
      <button @click="refresh">更新预览</button>
    </div>
    <div class="panes">
      <section><h2>模板 JSON</h2><textarea v-model="templateText" spellcheck="false" /></section>
      <section><h2>示例数据 JSON</h2><textarea v-model="dataText" spellcheck="false" /></section>
      <section><h2>模拟打印结果（{{ pages.length }} 页）</h2>
        <div class="preview-list">
          <article v-for="(page, index) in pages" :key="index" :class="['paper', kind]">
            <small v-if="kind === 'label'">标签 {{ index + 1 }} / {{ pages.length }}</small>
            <pre>{{ readable(page) }}</pre>
          </article>
        </div>
        <p v-if="error" class="error">{{ error }}</p>
        <p class="warning">模拟效果仅供参考，以实际打印机输出为准。</p>
      </section>
    </div>
    <section v-if="result"><h2>芯烨云指令</h2><pre class="commands">{{ result.document.content }}</pre>
      <h2>JavaScript 调用示例</h2><pre class="commands">{{ result.javascript }}</pre>
      <h2>cURL 示例</h2><pre class="commands">{{ result.cURL }}</pre></section>
  </main>
</template>

<script>
import request from '@/utils/request'

const orderTemplate = { kind: 'receipt', nodes: [
  { type: 'text', value: '订单：' }, { type: 'field', path: 'order.number' }, { type: 'line' },
  { type: 'separator' }, { type: 'each', path: 'order.items', children: [
    { type: 'field', path: 'item.name' }, { type: 'text', value: ' x ' },
    { type: 'field', path: 'item.quantity' }, { type: 'text', value: '  ' },
    { type: 'field', path: 'item.unitPrice' }, { type: 'line' }
  ] }, { type: 'separator' }, { type: 'text', value: '感谢光临' }, { type: 'line' }
] }
const batchTemplate = { kind: 'label', widthMm: 40, heightMm: 30, nodes: [{ type: 'each', path: 'items', children: [
  { type: 'labelText', x: 16, y: 24, path: 'item.name' },
  { type: 'labelText', x: 16, y: 64, path: 'item.price' },
  { type: 'barcode', x: 16, y: 100, path: 'item.barcode', format: 'BC128', height: 64 }
]}] }
const orderData = { order: { number: 'A-2026-001', items: [
  { name: '示例商品 A', quantity: 2, unitPrice: '12.50' },
  { name: '示例商品 B', quantity: 1, unitPrice: '36.00' }
] } }
const batchData = { items: [
  { name: '商品 A', price: '12.50', barcode: '690000000001' },
  { name: '商品 B', price: '36.00', barcode: '690000000002' }
] }

export default {
  name: 'PrinterWorkbench',
  data: () => ({ kind: 'receipt', sample: 'order', templateText: '', dataText: '', result: null, error: '', timer: null, requestId: 0 }),
  computed: {
    pages() { return this.result?.document?.pages || [] }
  },
  created() { this.loadExample() },
  beforeUnmount() { clearTimeout(this.timer) },
  watch: {
    templateText() { this.schedulePreview() },
    dataText() { this.schedulePreview() }
  },
  methods: {
    loadExample() {
      const order = this.sample === 'order'
      this.kind = order ? 'receipt' : 'label'
      this.templateText = JSON.stringify(order ? orderTemplate : batchTemplate, null, 2)
      this.dataText = JSON.stringify(order ? orderData : batchData, null, 2)
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
        this.error = error.response?.data?.error || error.message || '预览失败'
        this.result = null
      }
    },
    readable(value) { return String(value).replace(/<BR>/g, '\n').replace(/<\/?(?:BOLD|C|L|R|N)>/g, '') }
  }
}
</script>

<style scoped>
.printer-workbench{padding:24px;color:#303133}.controls{display:flex;gap:20px;align-items:end;margin-bottom:20px}.controls label{display:grid;gap:6px}.panes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.panes section{min-width:0}textarea{box-sizing:border-box;width:100%;height:300px;font:13px/1.5 monospace}.preview-list{display:flex;flex-wrap:wrap;gap:12px;max-height:560px;overflow:auto}.paper{box-sizing:border-box;background:#fff;color:#111;padding:12px;box-shadow:0 1px 6px #bbb;white-space:pre-wrap;overflow-wrap:anywhere}.paper.receipt{width:280px;min-height:180px}.paper.label{width:240px;min-height:180px;border:1px dashed #888}.paper pre,.commands{white-space:pre-wrap;overflow-wrap:anywhere}.warning{color:#a66b00}.error{color:#c00}.commands{padding:12px;background:#f4f4f4}@media(max-width:900px){.panes{grid-template-columns:1fr}}
</style>
