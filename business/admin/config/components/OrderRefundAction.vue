<template>
  <span v-if="record.id" class="order-refund-action">
    <el-button
      type="info"
      size="small"
      plain
      icon="el-icon-refresh-left"
      :loading="loading"
      :disabled="!record.id"
      @click="open"
    >
      <span>{{ $t('Refund history') }}</span>
    </el-button>

    <el-dialog
      v-model="visible"
      :title="$t('Refund order')"
      width="min(520px, 94vw)"
      append-to-body
      align-center
      :close-on-click-modal="false"
      destroy-on-close
      @closed="reset"
    >
      <div v-loading="loading" class="order-refund__body">
        <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
        <el-alert v-if="notice" :title="notice" type="warning" :closable="false" show-icon />

        <template v-if="loaded">
          <el-alert v-if="paymentError" :title="paymentError" type="warning" :closable="false" show-icon />
          <el-descriptions v-if="context.amount !== undefined" :column="2" border>
            <el-descriptions-item :label="$t('Order')">#{{ order.id }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Payment Method')">{{ context.payment || $t('Unknown') }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Payment status')" :span="2">{{ $t(paymentStatusLabel(invoice.status)) }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Paid amount')">{{ formatMoney(context.amount, context.currency) }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Already refunded')">{{ formatMoney(context.refundedAmount, context.currency) }}</el-descriptions-item>
            <el-descriptions-item :label="$t('Refundable amount')" :span="2">
              {{ formatMoney(context.refundableAmount, context.currency) }}
            </el-descriptions-item>
          </el-descriptions>

          <h3>{{ $t('Refund history') }}</h3>
          <el-alert v-if="historyError" :title="historyError" type="error" :closable="false" />
          <el-table v-else :data="history" :empty-text="$t('No refund requests')">
            <el-table-column prop="createdAt" :label="$t('Created at')" min-width="170" />
            <el-table-column :label="$t('Refund amount')" min-width="110"><template #default="{ row }">{{ formatMoney(row.amount, context.currency) }}</template></el-table-column>
            <el-table-column :label="$t('Status')" min-width="150"><template #default="{ row }">{{ $t(refundStatusLabel(row.status)) }}</template></el-table-column>
            <el-table-column prop="reason" :label="$t('Refund reason')" min-width="150" />
          </el-table>
          <p class="order-refund__hint">{{ $t('Refund history confirmation hint') }}</p>

          <el-form v-if="order.status === 'paid' && context.canRefund" label-position="top" class="order-refund__form" @submit.prevent>
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
        <el-button :disabled="submitting || loading" @click="open">{{ $t('Refresh refund details') }}</el-button>
        <el-button
          v-if="order.status === 'paid' && context.canRefund"
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
  orderRefundHistoryPath,
  orderRefundPath,
  orderStoreUuid,
  refundContext,
  refundReasonBytes,
  paymentStatusLabel,
  refundStatusLabel,
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
      paymentError: '',
      order: {},
      invoice: {},
      context: {},
      amount: '',
      reason: '',
      amountTouched: false,
      reasonTouched: false,
      refundKey: '',
      history: [],
      historyError: ''
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
    paymentStatusLabel,
    refundStatusLabel,
    async open() {
      if (!this.record.id || this.loading) return
      const preserveInput = this.visible && this.loaded
      this.visible = true
      this.loading = true
      this.loaded = false
      this.error = ''
      this.notice = ''
      this.paymentError = ''
      this.order = {}
      this.invoice = {}
      this.context = {}
      this.history = []
      this.historyError = ''
      try {
        const orderResponse = await request.get(`/api/v1/manage/orders/${this.record.id || this.record.uuid}`)
        this.order = orderResponse.data || {}
        this.loaded = true
        const storeUuid = orderStoreUuid(this.order, this.record)
        if (!storeUuid) {
          this.historyError = t('storeContextMissing')
        } else {
          try {
            const response = await request.get(orderRefundHistoryPath(storeUuid, this.order.uuid))
            this.history = response.data || []
          } catch (cause) {
            this.historyError = this.failureMessage(cause, 'Failed to load refund history')
          }
        }
        if (!this.order.invoiceId) {
          this.paymentError = t('orderHasNoInvoice')
          return
        }

        const invoiceResponse = await request.get(`/api/v1/manage/invoices/${this.order.invoiceId}`)
        this.invoice = invoiceResponse.data || {}
        this.context = refundContext(this.order, this.invoice)
        if (this.context.error) {
          this.paymentError = t(this.context.error)
          return
        }

        if (!preserveInput) this.amount = this.context.canRefund ? minorUnitsToInput(this.context.refundableAmount) : ''
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
        const response = await request.post(orderRefundPath(tradeOrderUuid), body)

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
      this.paymentError = ''
      this.order = {}
      this.invoice = {}
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
:global(.easy-admin-actions:has(> .order-refund-action)) {
  flex-wrap: wrap;
  min-width: 0;
  white-space: normal;
}

:global(.easy-admin-actions:has(> .order-refund-action) > .el-button--danger) {
  order: 2;
}

.order-refund-action {
  display: inline-flex;
  align-items: center;
  order: 1;
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
