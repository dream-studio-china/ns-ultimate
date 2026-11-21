<template>
  <list-admin
    ref="list"
    :model-value="rows"
    :entity-conf="entity"
    :list-display="display"
    :data-processor="populate"
    :disabled-actions="disabled"
    :table-conf="table"
  >
    <template #titleText>{{ title }}</template>
    <template #filter><slot name="filter" /></template>
    <template #topButton><slot name="toolbar" /></template>
    <template #action="{ data }"><slot name="action" :record="data" /></template>
  </list-admin>
  <div v-if="page" class="finance-pager">
    <el-button :disabled="busy || page <= 1" @click="$emit('page-change', page - 1)">{{ $t('Previous page') }}</el-button>
    <el-tag effect="plain">{{ page }}</el-tag>
    <el-button :disabled="busy || !hasMore" @click="$emit('page-change', page + 1)">{{ $t('Next page') }}</el-button>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import ListAdmin from '@/easyadmin/ui/vue/ListAdmin.vue'
import { FINANCE_API } from './use-finance'

const props = defineProps({
  title: { type: String, required: true },
  entityName: { type: String, required: true },
  columns: { type: Array, required: true },
  rows: { type: Array, required: true },
  rowKey: { type: [String, Function], default: 'id' },
  actions: Boolean,
  busy: Boolean,
  page: { type: Number, default: 0 },
  hasMore: Boolean
})
defineEmits(['page-change'])
const list = ref(null)
const entity = computed(() => ({ name: props.entityName, prefix: FINANCE_API }))
const table = computed(() => ({ rowKey: props.rowKey }))
// These rows are one server page; sorting the visible rows would not sort the register.
const display = computed(() => props.columns.map(column => ({
  ...column, field_options: { ...column.field_options, sortable: false }
})))
const disabled = computed(() => [
  'new', 'detail', 'edit', 'delete', 'batch_edit', 'batch_delete', 'export', 'pager', ...(props.actions ? [] : ['lines'])
])

// These read projections already use Accounting's filter and pagination contract.
// Do not send EasyAdmin's generic query directives or enable its CRUD actions.
function populate(context) {
  context.list = props.rows
  context.loading = false
}
onMounted(() => list.value?.fetchData())
</script>

<style scoped>
.finance-pager { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 16px; }
</style>
