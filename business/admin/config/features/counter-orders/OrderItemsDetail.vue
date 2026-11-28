<template>
  <div v-loading="loading" class="order-items" :aria-busy="loading">
    <p v-if="error" role="alert">
      {{ $t('Unable to load order items.') }}
      <el-button text @click="load">{{ $t('Retry') }}</el-button>
    </p>
    <ul v-else-if="items.length && compact" class="compact-items">
      <li v-for="(item, index) in items" :key="item.uuid || index">
        {{ item.productSnapshot?.name || item.specificationTitle || item.specificationUuid || '—' }} × {{ item.quantity }}
      </li>
    </ul>
    <table v-else-if="items.length">
      <thead><tr><th>{{ $t('Product') }}</th><th>{{ $t('Quantity') }}</th><th>{{ $t('Unit price') }}</th><th>{{ $t('Line total') }}</th></tr></thead>
      <tbody>
        <tr v-for="(item, index) in items" :key="item.uuid || index">
          <td>
            <strong>{{ item.productSnapshot?.name || item.specificationTitle || item.specificationUuid || '—' }}</strong>
            <small v-if="item.productSnapshot?.name && item.specificationTitle && item.specificationTitle !== item.productSnapshot.name">
              {{ item.specificationTitle }}
            </small>
          </td>
          <td>{{ item.quantity }}</td>
          <td>{{ money(item.unitPrice) }}</td>
          <td>{{ money(item.price) }}</td>
        </tr>
      </tbody>
    </table>
    <p v-else-if="!loading">{{ $t('No items in this order.') }}</p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { getLocale } from '@/i18n'
import request from '@/utils/request'
import { createLatestRequest, formatAmount } from './counter-orders.mjs'

const props = defineProps({
  orderUuid: { type: String, required: true },
  currency: { type: String, required: true },
  compact: Boolean
})
const items = ref([])
const loading = ref(false)
const error = ref(false)
const channel = createLatestRequest()
const money = amount => amount == null ? '—' : formatAmount(amount, props.currency, getLocale())

function load() {
  items.value = []
  loading.value = true
  error.value = false
  return channel.run(async () => {
    const { data } = await request.get(`/api/v1/manage/orders/${encodeURIComponent(props.orderUuid)}/items`)
    if (!Array.isArray(data)) throw new Error('Invalid order items response.')
    return data
  }, result => { items.value = result }, () => { error.value = true }, () => { loading.value = false })
}

watch(() => props.orderUuid, load, { immediate: true })
onBeforeUnmount(channel.cancel)
</script>

<style scoped>
.order-items { min-height: 44px; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 7px 9px; border-bottom: 1px solid var(--border); text-align: right; white-space: nowrap; }
th:first-child, td:first-child { text-align: left; min-width: 120px; white-space: normal; }
td small { display: block; color: var(--text-secondary); }
.compact-items { margin: 0; padding: 5px 4px 8px; list-style: none; color: var(--text-secondary); font-size: inherit; }
.compact-items li { line-height: 1.45; overflow-wrap: anywhere; }
</style>
