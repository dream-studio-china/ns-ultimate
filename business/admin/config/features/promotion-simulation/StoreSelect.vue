<template>
  <el-select :model-value="modelValue" filterable clearable default-first-option :filter-method="filter"
    :loading="loading" :placeholder="$t('Search stores by name / ID / UUID')" :aria-label="$t('Store')"
    popper-class="promotion-simulation__popper" @update:model-value="$emit('update:modelValue', $event || '')"
    @visible-change="open">
    <el-option :label="$t('Global')" value="" />
    <el-option v-if="modelValue && !options.some(store => store.code === modelValue)" :label="modelValue" :value="modelValue" />
    <el-option v-for="store in filtered" :key="store.code" :label="`${store.name} · ${store.code}`" :value="store.code">
      <div class="simulation-store-option"><strong>{{ store.name }}</strong><small>{{ store.code }} · #{{ store.id }} · {{ store.uuid }}</small></div>
    </el-option>
  </el-select>
</template>

<script>
import { filterStores } from './stores.mjs'
export default {
  name: 'SimulationStoreSelect',
  props: {
    modelValue: { type: String, default: '' },
    options: { type: Array, default: () => [] },
    loading: Boolean
  },
  emits: ['update:modelValue', 'open'],
  data: () => ({ query: '' }),
  computed: { filtered() { return filterStores(this.options, this.query) } },
  methods: {
    filter(query) { this.query = query },
    open(visible) { this.query = ''; if (visible) this.$emit('open') }
  }
}
</script>

<style scoped>
.simulation-store-option { display: flex; gap: 12px; align-items: baseline; max-width: min(640px, 80vw); }
.simulation-store-option strong { flex-shrink: 0; font-weight: 500; }
.simulation-store-option small { overflow: hidden; text-overflow: ellipsis; color: var(--el-text-color-secondary); }
</style>
