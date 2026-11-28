<template>
  <span class="order-status counter-order-tone" :data-tone="statusTone(status)">{{ $t(statusLabel(status)) }}</span>
</template>

<script setup>
import { statusLabel, statusTone } from './counter-orders.mjs'
import './order-status-tones.css'

defineProps({ status: { type: String, default: '' } })
</script>

<style scoped>
.order-status { display: inline-block; width: fit-content; padding: 2px 5px; border-radius: 5px; color: var(--order-ink); background: var(--order-tint); font-size: 11px; font-weight: 600; }
</style>
