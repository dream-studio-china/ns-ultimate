<template>
  <article class="receipt" :class="{ cancelled: order.status === 'cancelled', selected }">
    <button type="button" class="receipt-open" :class="{ expanded }"
      :aria-label="`#${order.id} · ${$t(statusLabel(order.status))} · ${$t('Order details')}`" @click="$emit('click')">
      <span class="receipt-head"><strong>#{{ order.id }}</strong><order-status-badge :status="order.status" /></span>
      <span v-if="!expanded" class="receipt-middle">
        <span class="receipt-items">{{ preview || $t('No item summary') }}</span>
        <span v-if="remaining" class="receipt-more" :title="$t('View all items in order details')">+{{ remaining }}</span>
      </span>
      <span class="receipt-foot"><strong>{{ formatAmount(order.totalAmount, order.currency, getLocale()) }}</strong><time v-if="!compact" :datetime="order.createdAt">{{ new Date(order.createdAt).toLocaleString(getLocale()) }}</time></span>
    </button>
    <slot />
  </article>
</template>

<script setup>
import { computed } from 'vue'
import { getLocale } from '@/i18n'
import OrderStatusBadge from './OrderStatusBadge.vue'
import { formatAmount, statusLabel } from './counter-orders.mjs'

const props = defineProps({ order: { type: Object, required: true }, compact: Boolean, selected: Boolean, expanded: Boolean })
defineEmits(['click'])
const previewItems = computed(() => (props.order.items || []).slice(0, props.compact ? 1 : 2))
const preview = computed(() => previewItems.value.map(item => `${item.name || '—'} × ${item.quantity}`).join(' · '))
const remaining = computed(() => Math.max(0, (props.order.itemCount || 0) - previewItems.value.length))
</script>

<style scoped>
.receipt { display: grid; width: 100%; min-width: 0; color: var(--text-primary); background: var(--surface); border: 1px solid var(--border); border-radius: 8px 8px 3px 3px; position: relative; }
.receipt-open { display: grid; gap: 7px; min-width: 0; min-height: 91px; width: 100%; padding: 9px 11px 13px; color: inherit; text-align: left; background: transparent; border: 0; border-radius: inherit; cursor: pointer; }
.receipt-open.expanded { min-height: 0; align-content: start; }
.receipt-open:focus-visible { outline-offset: -3px; }
.receipt::after { content: ''; position: absolute; inset: auto 0 0; height: 5px; background: linear-gradient(135deg, transparent 50%, var(--border) 50%) 0 0 / 10px 5px, linear-gradient(225deg, transparent 50%, var(--border) 50%) 0 0 / 10px 5px; pointer-events: none; }
.receipt.selected { border-color: var(--el-color-primary); box-shadow: inset 0 0 0 1px var(--el-color-primary); }
.receipt.cancelled { color: var(--text-secondary); background: var(--el-fill-color-dark); }
.receipt-head, .receipt-foot { display: flex; gap: 6px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.receipt-middle { display: flex; min-width: 0; gap: 5px; color: var(--text-secondary); border-top: 1px dashed var(--border); padding-top: 6px; }
.receipt-items { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.receipt-more { flex: none; font-weight: 600; }
time { color: var(--text-secondary); font-size: 11px; }
</style>
