<template>
  <button type="button" class="receipt" :class="{ cancelled: order.status === 'cancelled', selected }"
    :aria-label="`#${order.id} · ${$t(statusLabel(order.status))} · ${$t('Order details')}`">
    <span class="receipt-head"><strong>#{{ order.id }}</strong><small>{{ $t(statusLabel(order.status)) }}</small></span>
    <span class="receipt-middle">
      <template v-if="order.items?.length">{{ order.items.slice(0, compact ? 1 : 2).map(item => `${item.name || '—'} × ${item.quantity}`).join(' · ') }}<template v-if="order.itemCount > (compact ? 1 : 2)"> …</template></template>
      <template v-else>{{ $t('No item summary') }}</template>
    </span>
    <span class="receipt-foot"><strong>{{ formatAmount(order.totalAmount, order.currency, getLocale()) }}</strong><time v-if="!compact" :datetime="order.createdAt">{{ new Date(order.createdAt).toLocaleString(getLocale()) }}</time></span>
  </button>
</template>

<script setup>
import { getLocale } from '@/i18n'
import { formatAmount, statusLabel } from './counter-orders.mjs'

defineProps({ order: { type: Object, required: true }, compact: Boolean, selected: Boolean })
</script>

<style scoped>
.receipt { display: grid; gap: 7px; width: 100%; min-height: 93px; padding: 9px 11px 13px; color: var(--text-primary); text-align: left; background: var(--surface); border: 1px solid var(--border); border-radius: 8px 8px 3px 3px; cursor: pointer; position: relative; }
.receipt::after { content: ''; position: absolute; inset: auto 0 0; height: 5px; background: linear-gradient(135deg, transparent 50%, var(--border) 50%) 0 0 / 10px 5px, linear-gradient(225deg, transparent 50%, var(--border) 50%) 0 0 / 10px 5px; pointer-events: none; }
.receipt.selected { border-color: var(--el-color-primary); box-shadow: inset 0 0 0 1px var(--el-color-primary); }
.receipt.cancelled { color: var(--text-secondary); background: var(--el-fill-color-dark); }
.receipt-head, .receipt-foot { display: flex; gap: 6px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.receipt-head small { font-size: 11px; border-radius: 5px; padding: 2px 5px; background: var(--el-fill-color); }
.receipt-middle { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; color: var(--text-secondary); border-top: 1px dashed var(--border); padding-top: 6px; }
time { color: var(--text-secondary); font-size: 11px; }
</style>
