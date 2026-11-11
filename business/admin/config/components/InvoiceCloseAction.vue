<template>
  <span v-if="record.id" class="invoice-close-action">
    <el-button
      type="warning"
      size="small"
      plain
      icon="el-icon-switch-button"
      :disabled="!buttonEligible"
      :title="buttonEligible ? $t('Close Invoice') : $t('Only pending, processing, or failed Trade order invoices can be closed')"
      @click="open"
    >
      {{ $t('Close Invoice') }}
    </el-button>

    <el-dialog
      v-model="visible"
      :title="$t('Invoice closure')"
      width="min(560px, 94vw)"
      append-to-body
      align-center
      :close-on-click-modal="false"
      destroy-on-close
    >
      <div v-loading="loading" class="invoice-close__body">
        <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
        <el-alert v-if="notice" :title="notice" type="warning" :closable="false" show-icon />
        <el-alert v-if="loaded" :title="$t('Invoice closure help')" type="info" :closable="false" />

        <template v-if="loaded">
          <el-descriptions :column="2" border>
            <el-descriptions-item :label="$t('Invoice Number')">{{ invoice.outTradeNo || '-' }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Payment status')">{{ $t(paymentStatusLabel(invoice.status)) }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Amount')">{{ formatMoney(invoice.amount, invoice.currency) }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Payment Method')">{{ invoice.payment || $t('Unknown') }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Trade order UUID')" :span="2">{{ invoice.sourceId || '-' }}</el-descriptions-item>
          </el-descriptions>

          <el-form v-if="context.canClose" label-position="top" class="invoice-close__form" @submit.prevent>
            <el-form-item :label="$t('Invoice closure reason')" :error="reasonError">
              <el-input
                v-model="reason"
                type="textarea"
                :rows="3"
                maxlength="1000"
                show-word-limit
                :disabled="submitting || reasonLocked"
                :placeholder="$t('Enter invoice closure reason')"
                @blur="reasonTouched = true"
              />
            </el-form-item>
          </el-form>
        </template>
      </div>

      <template #footer>
        <el-button :disabled="submitting" @click="visible = false">{{ $t('Close') }}</el-button>
        <el-button
          v-if="loaded && context.canClose"
          type="warning"
          :loading="submitting"
          :disabled="loading || !reason.trim()"
          @click="submit"
        >
          {{ commandUuid ? $t('Retry invoice closure') : $t('Close Invoice') }}
        </el-button>
      </template>
    </el-dialog>
  </span>
</template>

<script>
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { t } from '@/i18n'
import request from '@/utils/request'
import { formatMoney, paymentStatusLabel } from './order-refund.mjs'
import {
  buildInvoiceCloseRequest,
  createInvoiceCloseCommandUuid,
  invoiceCloseContext,
  invoiceCloseFailureKind,
  invoiceCloseReasonError,
  invoiceCloseSucceeded,
  invoiceDetailPath,
  orderPaymentClosePath,
  preliminaryInvoiceCloseable
} from './invoice-close.mjs'

export default {
  name: 'InvoiceCloseAction',
  props: {
    record: { type: Object, default: () => ({}) },
    refresh: { type: Function, default: () => {} }
  },
  data() {
    return {
      visible: false,
      loading: false,
      loaded: false,
      submitting: false,
      invoice: {},
      context: { canClose: false },
      reason: '',
      reasonTouched: false,
      reasonLocked: false,
      commandUuid: '',
      error: '',
      notice: ''
    }
  },
  computed: {
    buttonEligible() {
      return preliminaryInvoiceCloseable(this.record)
    },
    reasonError() {
      if (!this.reasonTouched) return ''
      const key = invoiceCloseReasonError(this.reason)
      return key ? t(key) : ''
    }
  },
  methods: {
    formatMoney,
    paymentStatusLabel,
    async open() {
      if (!this.record.id || this.loading) return
      this.visible = true
      this.loading = true
      this.loaded = false
      this.error = ''
      this.notice = ''
      try {
        const response = await request.get(invoiceDetailPath(this.record.id))
        this.invoice = response.data || {}
        this.context = invoiceCloseContext(this.invoice)
        if (this.context.error) {
          this.error = t(this.context.error)
          return
        }
        this.restoreAttempt()
        if (!this.reason) this.reason = t('Invoice closed from the admin invoice page.')
        this.loaded = true
      } catch (cause) {
        this.error = t('Failed to load invoice closure details')
      } finally {
        this.loading = false
      }
    },
    async submit() {
      if (this.submitting || !this.loaded || !this.context.canClose) return
      this.reasonTouched = true
      const reasonError = invoiceCloseReasonError(this.reason)
      if (reasonError) {
        this.error = t(reasonError)
        return
      }
      if (!window.confirm(t('Confirm invoice closure'))) return

      const normalizedReason = this.reason.trim()
      this.commandUuid ||= createInvoiceCloseCommandUuid()
      let body
      try {
        body = buildInvoiceCloseRequest(this.invoice, this.commandUuid, normalizedReason)
      } catch (cause) {
        this.error = t(cause.message)
        return
      }
      this.persistAttempt(body)

      this.submitting = true
      this.reasonLocked = true
      this.error = ''
      this.notice = ''
      try {
        const response = await request.post(orderPaymentClosePath(this.invoice.sourceId), body)
        if (!invoiceCloseSucceeded(response)) {
          this.error = t('The invoice could not be closed because its order or payment state changed. Refresh and verify before retrying.')
          this.refresh()
          return
        }
        createUiFeedback().success(t('Invoice closed and released from the order.'))
        this.visible = false
        this.clearCommand()
        this.refresh()
      } catch (cause) {
        this.showFailure(cause)
        this.refresh()
      } finally {
        this.submitting = false
      }
    },
    showFailure(cause) {
      const notices = {
        paymentAlreadyPaid: 'Invoice payment was already completed. It was not closed.',
        paymentRequiresReview: 'A payment was discovered while closing this invoice and requires review. The invoice was not released.',
        unconfirmed: 'The invoice could not be closed because its order or payment state changed. Refresh and verify before retrying.',
        channelFailed: 'Invoice closure failed. The invoice was not treated as closed.',
        unknown: 'Invoice closure result is unknown. Refresh and verify the payment status before trying again.',
        failed: 'Invoice closure failed. The invoice was not treated as closed.'
      }
      const kind = invoiceCloseFailureKind(cause)
      const message = t(notices[kind] || notices.failed)
      if (kind === 'paymentAlreadyPaid' || kind === 'paymentRequiresReview') {
        this.notice = message
        this.context = { ...this.context, canClose: false }
        this.clearStoredAttempt()
      } else {
        this.error = message
      }
    },
    attemptStorageKey() {
      return `ns-ultimate:invoice-close:${this.invoice.uuid}`
    },
    restoreAttempt() {
      try {
        const saved = JSON.parse(globalThis.sessionStorage?.getItem(this.attemptStorageKey()) || 'null')
        if (saved?.commandUuid && saved?.invoiceUuid === this.invoice.uuid && typeof saved.reason === 'string') {
          this.commandUuid = saved.commandUuid
          this.reason = saved.reason
          this.reasonLocked = true
        }
      } catch {
        // Storage is optional; the in-memory command still protects retries in this component.
      }
    },
    persistAttempt(body) {
      try {
        globalThis.sessionStorage?.setItem(this.attemptStorageKey(), JSON.stringify(body))
      } catch {
        // Storage is optional; the in-memory command still protects retries in this component.
      }
    },
    clearStoredAttempt() {
      try {
        globalThis.sessionStorage?.removeItem(this.attemptStorageKey())
      } catch {
        // Storage is optional.
      }
    },
    clearCommand() {
      this.clearStoredAttempt()
      this.commandUuid = ''
      this.reason = ''
      this.reasonTouched = false
      this.reasonLocked = false
    }
  }
}
</script>

<style scoped>
:global(.easy-admin-actions:has(> .invoice-close-action)) {
  flex-wrap: wrap;
  min-width: 0;
  white-space: normal;
}

.invoice-close-action {
  display: inline-flex;
  align-items: center;
}

.invoice-close__body {
  min-height: 180px;
}

.invoice-close__body :deep(.el-alert) {
  margin-bottom: 14px;
}

.invoice-close__form {
  margin-top: 18px;
}

@media (max-width: 560px) {
  .invoice-close__body :deep(.el-descriptions__body) {
    overflow-x: auto;
  }
}
</style>
