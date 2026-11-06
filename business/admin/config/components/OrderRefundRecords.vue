<template>
  <section class="refund-records" aria-labelledby="refund-records-title">
    <header class="refund-records__header">
      <div>
        <span class="refund-records__eyebrow">{{ $t('Order Management') }}</span>
        <h1 id="refund-records-title">{{ $t('Order refund records') }}</h1>
        <p>{{ $t('Unified order refund records help') }}</p>
      </div>
      <el-button :loading="loading" icon="el-icon-refresh" @click="reload">{{ $t('Refresh') }}</el-button>
    </header>

    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="refund-records__alert" />

    <div class="refund-records__toolbar">
      <el-select v-model="status" class="refund-records__status" :aria-label="$t('Status')" @change="changeStatus">
        <el-option :label="$t('All refund statuses')" value="" />
        <el-option
          v-for="option in statusOptions"
          :key="option"
          :label="$t(refundStatusLabel(option))"
          :value="option"
        />
      </el-select>
      <span class="refund-records__count">{{ $t('Order refund records') }} · {{ total }}</span>
    </div>

    <div class="refund-records__table-wrap" v-loading="loading">
      <el-table :data="records" :empty-text="$t('No order refund records')" row-key="uuid">
        <el-table-column :label="$t('Created at')" min-width="170">
          <template #default="{ row }">{{ formatRefundRecordDate(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column :label="$t('Order')" min-width="190">
          <template #default="{ row }">
            <router-link v-if="orderDetailPath(row.tradeOrderUuid)" :to="orderDetailPath(row.tradeOrderUuid)" class="refund-records__order">
              {{ row.orderId ? `#${row.orderId}` : row.tradeOrderUuid }}
            </router-link>
            <span v-else>{{ row.tradeOrderUuid }}</span>
          </template>
        </el-table-column>
        <el-table-column :label="$t('Store UUID')" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.storeUuid || $t('Unknown') }}</template>
        </el-table-column>
        <el-table-column :label="$t('Payment Method')" min-width="120">
          <template #default="{ row }">{{ row.payment || $t('Unknown') }}</template>
        </el-table-column>
        <el-table-column :label="$t('Refund amount')" min-width="120">
          <template #default="{ row }"><strong>{{ formatMoney(row.amount, row.currency) }}</strong></template>
        </el-table-column>
        <el-table-column :label="$t('Status')" min-width="180">
          <template #default="{ row }">
            <el-tag :type="refundStatusTagType(row.status)" effect="plain">{{ $t(refundStatusLabel(row.status)) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="reason" :label="$t('Refund reason')" min-width="220" show-overflow-tooltip />
      </el-table>
    </div>

    <el-pagination
      v-if="total > 0"
      class="refund-records__pagination"
      background
      layout="total, prev, pager, next"
      :current-page="page"
      :page-size="limit"
      :total="total"
      @current-change="changePage"
    />
  </section>
</template>

<script>
import request from '@/utils/request'
import { formatMoney, refundStatusLabel } from './order-refund.mjs'
import {
  ORDER_REFUND_HISTORY_CONCURRENCY,
  ORDER_REFUND_ORDERS_ENDPOINT,
  ORDER_REFUND_ORDERS_PAGE_SIZE,
  formatRefundRecordDate,
  normalizeOrderRefundOrdersResponse,
  normalizeStoreOrderRefundHistoryResponse,
  orderRefundContexts,
  orderRefundOrdersParams,
  orderDetailPath,
  pageOrderRefundRecords,
  storeOrderRefundHistoryPath,
  refundStatusTagType
} from './order-refund-records.mjs'

export default {
  name: 'OrderRefundRecords',
  data() {
    return {
      records: [],
      allRecords: [],
      total: 0,
      page: 1,
      limit: 20,
      status: '',
      loading: false,
      error: '',
      statusOptions: ['succeeded', 'processing', 'unknown', 'failed']
    }
  },
  mounted() {
    this.load()
  },
  methods: {
    formatMoney,
    refundStatusLabel,
    formatRefundRecordDate,
    orderDetailPath,
    refundStatusTagType,
    reload() {
      return this.load(1)
    },
    changePage(page) {
      return this.applyPage(page)
    },
    changeStatus() {
      return this.applyPage(1)
    },
    async load(page = this.page) {
      if (this.loading) return
      this.loading = true
      this.error = ''
      try {
        this.allRecords = await this.loadStoreRefundRecords()
        this.applyPage(page)
      } catch (cause) {
        this.error = cause?.response?.data?.message || cause?.message || this.$t('Failed to load order refund records')
      } finally {
        this.loading = false
      }
    },
    applyPage(page = this.page) {
      const normalized = pageOrderRefundRecords(this.allRecords, page, this.limit, this.status)
      this.records = normalized.items
      this.total = normalized.total
      this.page = normalized.page
      this.limit = normalized.limit
    },
    async loadStoreRefundRecords() {
      const orders = await this.loadAllOrders()
      const contexts = orderRefundContexts(orders)
      const records = []

      for (let offset = 0; offset < contexts.length; offset += ORDER_REFUND_HISTORY_CONCURRENCY) {
        const batch = contexts.slice(offset, offset + ORDER_REFUND_HISTORY_CONCURRENCY)
        const histories = await Promise.all(batch.map(async context => {
          const response = await request.get(storeOrderRefundHistoryPath(context.storeUuid, context.tradeOrderUuid))
          return normalizeStoreOrderRefundHistoryResponse(response, context)
        }))
        records.push(...histories.flat())
      }
      return records
    },
    async loadAllOrders() {
      const orders = []
      let page = 1
      let pages = 1

      do {
        const response = await request.get(ORDER_REFUND_ORDERS_ENDPOINT, {
          params: orderRefundOrdersParams(page, ORDER_REFUND_ORDERS_PAGE_SIZE)
        })
        const normalized = normalizeOrderRefundOrdersResponse(response)
        orders.push(...normalized.items)
        pages = normalized.pages
        if (normalized.hasNext && normalized.page >= pages) throw new Error('Invalid order list pagination')
        page = normalized.page + 1
      } while (page <= pages)

      return orders
    }
  }
}
</script>

<style scoped>
.refund-records { max-width: 1540px; margin: 0 auto; padding: 32px; color: var(--text-primary); }
.refund-records__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; margin-bottom: 24px; }
.refund-records__eyebrow { color: var(--accent); font-size: 12px; font-weight: 700; letter-spacing: .08em; }
.refund-records h1 { margin: 9px 0 8px; font-size: 28px; letter-spacing: -.03em; }
.refund-records p { max-width: 760px; margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.75; }
.refund-records__alert { margin-bottom: 18px; }
.refund-records__toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
.refund-records__status { width: 220px; }
.refund-records__count { color: var(--text-secondary); font-size: 12px; }
.refund-records__table-wrap { overflow: hidden; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); }
.refund-records__order { color: var(--accent); font-weight: 600; text-decoration: none; }
.refund-records__order:hover { text-decoration: underline; }
.refund-records__pagination { justify-content: flex-end; margin-top: 18px; }
@media (max-width: 760px) {
  .refund-records { padding: 20px 14px; }
  .refund-records__header { flex-direction: column; }
  .refund-records__toolbar { align-items: stretch; flex-direction: column; }
  .refund-records__status { width: 100%; }
  .refund-records__pagination { justify-content: center; }
}
</style>
