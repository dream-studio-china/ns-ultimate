<template>
  <span class="order-status" :data-tone="statusTone(status)">{{ $t(statusLabel(status)) }}</span>
</template>

<script setup>
import { statusLabel, statusTone } from './counter-orders.mjs'

defineProps({ status: { type: String, default: '' } })
</script>

<style scoped>
.order-status { display: inline-block; width: fit-content; padding: 2px 5px; border-radius: 5px; color: #334155; background: #e2e8f0; font-size: 11px; font-weight: 600; }
.order-status[data-tone='amber'] { color: #78350f; background: #fef3c7; }
.order-status[data-tone='blue'] { color: #1e40af; background: #dbeafe; }
.order-status[data-tone='green'] { color: #14532d; background: #dcfce7; }
.order-status[data-tone='cyan'] { color: #164e63; background: #cffafe; }
.order-status[data-tone='emerald'] { color: #064e3b; background: #a7f3d0; }
.order-status[data-tone='gray'] { color: #374151; background: #e5e7eb; }
.order-status[data-tone='rose'] { color: #9f1239; background: #ffe4e6; }
.order-status[data-tone='violet'] { color: #5b21b6; background: #ede9fe; }
</style>
