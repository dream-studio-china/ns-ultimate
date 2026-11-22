<template>
  <el-button
    size="default"
    type="primary"
    icon="el-icon-download"
    plain
    :loading="exporting"
    @click="exportQrCodes"
  >
    {{ $t('Export QR codes') }}
  </el-button>
</template>

<script>
import QRCode from 'qrcode'
import { saveAs } from 'file-saver'
import CrudSkeletonAdapter from '@/easyadmin/adapters/crudskeleton/CrudSkeletonAdapter'
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { t } from '@/i18n'
import {
  DEFAULT_CUSTOMER_ORDERING_BASE_URL,
  buildStoredZip,
  counterOrderingUrl,
  counterQrArchiveName,
  counterQrFileNames
} from './counter-qr-export.mjs'

const counterAdapter = new CrudSkeletonAdapter({ name: 'Counter', plural: 'counters' })
const orderingBaseUrl = import.meta.env.VITE_CUSTOMER_ORDERING_BASE_URL || DEFAULT_CUSTOMER_ORDERING_BASE_URL

function dataUrlBytes(dataUrl) {
  const separator = dataUrl.indexOf(',')
  if (separator < 0) throw new Error('QR code image data is invalid')
  const binary = atob(dataUrl.slice(separator + 1))
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index)
  return bytes
}

function routeFilter(routeQuery = {}) {
  const expressions = []
  const value = key => String(routeQuery[key] || '').replaceAll("'", "''")
  if (routeQuery.storeUuid) expressions.push(`(entity.getStoreUuid() == '${value('storeUuid')}')`)
  if (routeQuery.code) expressions.push(`(entity.getCode() matches '${value('code')}')`)
  if (routeQuery.name) expressions.push(`(entity.getName() matches '${value('name')}')`)
  if (routeQuery.enabled) expressions.push(`(entity.getEnabled() == '${value('enabled')}')`)
  return expressions.length ? { '@filter': expressions.join(' && ') } : {}
}

export default {
  name: 'CounterQrExportAction',
  props: {
    refresh: { type: Function, default: () => {} },
    query: { type: Object, default: () => ({}) },
    filter: { type: Object, default: () => ({}) },
    sort: { type: Object, default: () => ({}) }
  },
  data() {
    return { exporting: false }
  },
  methods: {
    async exportQrCodes() {
      if (this.exporting) return
      this.exporting = true
      try {
        const counters = await this.loadFilteredCounters()
        if (!counters.length) {
          createUiFeedback().warning(t('No counters match the current filters'))
          return
        }

        const names = counterQrFileNames(counters)
        const files = []

        for (let index = 0; index < counters.length; index += 1) {
          const counter = counters[index]
          const dataUrl = await QRCode.toDataURL(
            counterOrderingUrl(counter.uuid, counter.storeUuid, orderingBaseUrl),
            {
              width: 320,
              margin: 2,
              errorCorrectionLevel: 'M',
              color: { dark: '#201813', light: '#ffffff' }
            }
          )
          files.push({ name: names[index], data: dataUrlBytes(dataUrl) })
        }

        const archive = buildStoredZip(files)
        saveAs(archive, counterQrArchiveName())
        createUiFeedback().success(t('Exported {0} counter QR codes', counters.length))
      } catch (cause) {
        createUiFeedback().error(cause.response?.data?.message || cause.message || t('Failed to export counter QR codes'))
      } finally {
        this.exporting = false
      }
    },
    async loadFilteredCounters() {
      const activeFilter = this.filter && Object.keys(this.filter).length
        ? this.filter
        : routeFilter(this.$route?.query)
      const response = await counterAdapter.list({
        ...this.query,
        ...activeFilter,
        ...this.sort,
        page: 1,
        limit: 100000
      })
      const records = Array.isArray(response?.data) ? response.data : []
      return records.filter(counter => counter?.uuid && counter?.storeUuid)
    }
  }
}
</script>
