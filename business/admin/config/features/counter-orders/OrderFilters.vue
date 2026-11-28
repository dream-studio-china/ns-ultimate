<template>
  <form class="order-filters" @submit.prevent="$emit('apply', { ...draft })">
    <label>{{ $t('Order search') }}<el-input v-model.trim="draft.order" clearable placeholder="#ID / Trade UUID / Store UUID" /></label>
    <label>{{ $t('Ordering user UUID') }}<el-input v-model.trim="draft.user" clearable placeholder="UUID" /></label>
    <label>{{ $t('Trade status') }}
      <el-select v-model="draft.status" multiple collapse-tags clearable :aria-label="$t('Trade status')">
        <el-option v-for="value in states.status" :key="value" :value="value" :label="$t(statusLabel(value))" />
      </el-select>
    </label>
    <label>{{ $t('Created from') }}<input v-model="draft.start" type="date" /></label>
    <label>{{ $t('Created through') }}<input v-model="draft.end" type="date" :min="draft.start || undefined" /></label>
    <template v-if="expanded">
      <label v-for="(label, key) in extra" :key="key">{{ $t(label) }}
        <el-select v-model="draft[key]" clearable :aria-label="$t(label)">
          <el-option v-for="value in states[key]" :key="value" :value="value" :label="$t(statusLabel(value))" />
        </el-select>
      </label>
    </template>
    <div class="filter-actions">
      <el-button native-type="submit" type="primary">{{ $t('Search') }}</el-button>
      <el-button native-type="button" @click="reset">{{ $t('Reset') }}</el-button>
      <el-button native-type="button" text :aria-expanded="expanded" @click="expanded = !expanded">{{ $t(expanded ? 'Fewer filters' : 'More filters') }}</el-button>
    </div>
  </form>
</template>

<script setup>
import { ref, watch } from 'vue'
import { states, statusLabel } from './counter-orders.mjs'

const props = defineProps({ filters: { type: Object, required: true } })
const emit = defineEmits(['apply'])
const draft = ref({})
const extra = { payment: 'Payment status', operational: 'Store processing status', merged: 'In a merge', anonymous: 'Anonymous order' }
const expanded = ref(false)
watch(() => props.filters, filters => {
  draft.value = { status: [], ...filters }
  if (Object.keys(extra).some(key => filters[key])) expanded.value = true
}, { immediate: true })

function reset() {
  draft.value = { status: [] }
  emit('apply', {})
}
</script>

<style scoped>
.order-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 155px), 1fr)); gap: 10px; }
label { display: grid; gap: 5px; min-width: 0; font-size: 13px; }
input { min-width: 0; height: 32px; border: 1px solid var(--border); border-radius: 4px; padding: 0 7px; color: inherit; background: var(--surface); }
.filter-actions { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 5px 8px; }
.filter-actions :deep(.el-button) { margin-left: 0; }
</style>
