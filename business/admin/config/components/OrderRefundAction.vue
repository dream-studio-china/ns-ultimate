<template>
  <span class="order-refund-action">
    <el-button v-if="record.status === 'paid'" link type="warning" :loading="loading" :disabled="!record.id" @click="open">
      {{ $t('Refund') }}
    </el-button>

    <el-dialog
      v-model="visible"
      :title="$t('Refund order')"
      width="min(520px, 94vw)"
      append-to-body
      :close-on-click-modal="false"
      destroy-on-close
      @closed="reset"
    >
      <div v-loading="loading" class="order-refund__body">
        <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
        <el-alert v-if="notice" :title="notice" type="warning" :closable="false" show-icon />

        <template v-if="loaded && !error">
          <el-descriptions :column="2" border>
            <el-descriptions-item :label="$t('Order')">#{{ order.id }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Payment Method')">{{ context.payment || $t('Unknown') }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Paid amount')">{{ formatMoney(context.amount, context.currency) }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Already refunded')">{{ formatMoney(context.refundedAmount, context.currency) }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Refundable amount')" :span="2">
              {{ formatMoney(context.refundableAmount, context.currency) }}
            </el-descriptions-item>
          </el-descriptions>

          <el-form label-position="top" class="order-refund__form" @submit.prevent>
            <el-form-item :label="$t('Refund amount')" :error="amountError">
              <el-input
                v-model="amount"
                inputmode="decimal"
                :disabled="!context.canRefund || submitting"
                :placeholder="$t('Enter refund amount')"
                @blur="amountTouched = true"
                @input="refundKey = ''"
              >
                <template #append>{{ context.currency }}</template>
              </el-input>
              <div class="order-refund__hint">
                {{ $t('Enter an amount up to {0}', [formatMoney(context.refundableAmount, context.currency)]) }}
              </div>
            </el-form-item>
            <el-form-item :label="$t('Refund reason')" :error="reasonError">
              <el-input
                v-model="reason"
                type="textarea"
                :rows="3"
                maxlength="500"
                show-word-limit
                :disabled="!context.canRefund || submitting"
                :placeholder="$t('Enter refund reason')"
                @blur="reasonTouched = true"
                @input="refundKey = ''"
              />
            </el-form-item>
          </el-form>
        </template>
      </div>

      <template #footer>
        <el-button :disabled="submitting" @click="visible = false">{{ $t('Close') }}</el-button>
        <el-button
          type="warning"
          :loading="submitting"
          :disabled="!loaded || loading || !context.canRefund"
          @click="submit"
        >
          {{ $t('Submit refund') }}
        </el-button>
      </template>
    </el-dialog>
  </span>
</template>

<script>
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { t } from '@/i18n'
import request from '@/utils/request'
import {
  formatMoney,
  minorUnitsToInput,
  orderRefundPath,
  orderStoreUuid,
  refundContext,
  refundReasonBytes,
  validateRefundAmount
} from './order-refund.mjs'

export default {
  name: 'OrderRefundAction',
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
      error: '',
      notice: '',
      order: {},
      invoice: {},
      storeUuid: '',
      context: {},
      amount: '',
      reason: '',
      amountTouched: false,
      reasonTouched: false,
      refundKey: ''
    }
  },
  computed: {
    amountValidation() {
      return validateRefundAmount(this.amount, this.context.refundableAmount)
    },
    amountError() {
      return this.amountTouched && this.amountValidation.error ? t(this.amountValidation.error) : ''
    },
    reasonError() {
      if (!this.reasonTouched) return ''
      if (!this.reason.trim()) return t('refundReasonRequired')
      return refundReasonBytes(this.reason.trim()) > 1000 ? t('refundReasonTooLong') : ''
    }
  },
  methods: {
    formatMoney,
    async open() {
      if (!this.record.id || this.loading) return
      this.visible = true
      this.loading = true
      this.loaded = false
      this.error = ''
      this.notice = ''
      this.order = {}
      this.invoice = {}
      this.storeUuid = ''
      this.context = {}
      try {
        const orderResponse = await request.get(`/api/v1/manage/orders/${this.record.id || this.record.uuid}`)
        this.order = orderResponse.data || {}
        if (!this.order.invoiceId) throw new Error(t('orderHasNoInvoice'))
        this.storeUuid = orderStoreUuid(this.order, this.record)
        if (!this.storeUuid) throw new Error(t('storeContextMissing'))

        const invoiceResponse = await request.get(`/api/v1/manage/invoices/${this.order.invoiceId}`)
        this.invoice = invoiceResponse.data || {}
        this.context = refundContext(this.order, this.invoice)
        if (this.context.error) throw new Error(t(this.context.error))
        if (!this.context.statusRefundable) throw new Error(t('invoiceNotRefundable'))

        this.amount = this.context.canRefund ? minorUnitsToInput(this.context.refundableAmount) : ''
        this.loaded = true
        if (!this.context.canRefund) this.notice = t('noRefundableAmount')
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Failed to load refund details')
      } finally {
        this.loading = false
      }
    },
    async submit() {
      if (this.submitting || !this.loaded || !this.context.canRefund) return
      this.amountTouched = true
      this.reasonTouched = true
      this.error = ''
      const validation = this.amountValidation
      if (validation.error || !this.reason.trim() || refundReasonBytes(this.reason.trim()) > 1000) return

      const amount = validation.cents
      const formattedAmount = formatMoney(validation.cents, this.context.currency)
      if (!window.confirm(`${t('Confirm refund')}: ${formattedAmount} · ${this.context.payment || t('Unknown')}`)) return

      this.submitting = true
      this.refundKey ||= `admin-order-refund:${Date.now()}:${globalThis.crypto?.randomUUID?.() || Math.random().toString(36).slice(2)}`
      try {
        const tradeOrderUuid = this.order.uuid || this.record.uuid
        if (!tradeOrderUuid) throw new Error(t('orderNotFound'))
        const body = {
          amount,
          reason: this.reason.trim(),
          idempotencyKey: this.refundKey
        }
        const response = await request.post(orderRefundPath(this.storeUuid, tradeOrderUuid), body)

        const refundStatus = response.data?.status || response.data?.invoice?.status || ''
        if (refundStatus === 'failed') {
          this.error = t('Refund failed')
          this.refresh()
          return
        }
        if (refundStatus === 'unknown') {
          this.notice = t('Refund result is unknown')
          this.refresh()
          return
        }
        createUiFeedback().success(t(refundStatus === 'processing' ? 'Refund is processing' : 'Refund completed'))
        this.visible = false
        this.refresh()
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Refund failed')
      } finally {
        this.submitting = false
      }
    },
    failureMessage(cause, fallbackKey) {
      return cause.response?.data?.message || cause.response?.data?.error || cause.message || t(fallbackKey)
    },
    reset() {
      if (this.submitting) return
      this.loading = false
      this.loaded = false
      this.error = ''
      this.notice = ''
      this.order = {}
      this.invoice = {}
      this.storeUuid = ''
      this.context = {}
      this.amount = ''
      this.reason = ''
      this.amountTouched = false
      this.reasonTouched = false
      this.refundKey = ''
    }
  }
}
</script>

<style scoped>
.order-refund-action {
  display: inline-flex;
}

.order-refund__body {
  min-height: 180px;
}

.order-refund__body :deep(.el-alert) {
  margin-bottom: 14px;
}

.order-refund__form {
  margin-top: 18px;
}

.order-refund__hint {
  margin-top: 6px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
