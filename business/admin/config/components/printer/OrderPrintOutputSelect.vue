<template>
  <div class="order-print-output">
    <el-select :model-value="form[property]" filterable :loading="loading" :disabled="loading || failed"
      @update:model-value="form[property] = $event">
      <el-option v-if="unavailable" :value="form[property]" :label="unavailableLabel" disabled />
      <el-option v-for="item in options" :key="item.uuid" :value="item.uuid" :label="item.name || item.uuid" />
    </el-select>
    <p v-if="!form.storeUuid">{{ $t('Print rule output store help') }}</p>
    <p v-else-if="failed">{{ $t('Print rule outputs failed to load') }} <el-button link @click="load">{{ $t('Retry') }}</el-button></p>
    <p v-else-if="unavailable">{{ $t('Print rule output unavailable help') }}</p>
  </div>
</template>

<script>
import CrudSkeletonAdapter from '@/easyadmin/adapters/crudskeleton/CrudSkeletonAdapter'
import { eligiblePrintOutputs, loadAllPrintOutputs } from './order-print-output.mjs'

const adapter = (name, plural) => new CrudSkeletonAdapter({ name, plural, prefix: '/api/v1/manage/printer' })

export default {
  props: { form: { type: Object, required: true }, property: { type: String, required: true } },
  data() { return { devices: [], templates: [], loading: false, failed: false } },
  computed: {
    options() { return eligiblePrintOutputs(this.property, this.form, this.devices, this.templates) },
    unavailable() { return !this.loading && !this.failed && !!this.form[this.property] && !this.options.some(item => item.uuid === this.form[this.property]) },
    unavailableLabel() {
      const value = this.form[this.property]
      const record = [...this.devices, ...this.templates].find(item => item.uuid === value)
      return `${record?.name || value} (${this.$t('Print rule output unavailable')})`
    }
  },
  created() { this.load() },
  methods: {
    async load() {
      if (this.loading) return
      this.loading = true
      this.failed = false
      try {
        const requests = [loadAllPrintOutputs(params => adapter('PrinterDevice', 'devices').list(params))]
        if (this.property === 'templateUuid') {
          requests.push(loadAllPrintOutputs(params => adapter('PrinterTemplate', 'templates').list(params)))
        }
        const [devices, templates = []] = await Promise.all(requests)
        this.devices = devices
        this.templates = templates
      } catch {
        this.failed = true
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.order-print-output, .order-print-output .el-select { width: 100%; }
</style>
