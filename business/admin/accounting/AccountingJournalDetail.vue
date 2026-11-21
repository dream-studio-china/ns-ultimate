<template>
  <div>
    <detail-admin
      :key="id" :id="id" :entity-conf="entity" :fields="columns"
      :title="$t('Journal detail')" :editable="false"
    >
      <template #actions="{ record, refresh }">
        <el-space wrap>
          <el-button :disabled="busy || !!pending" @click="emit('close')">{{ $t('Back') }}</el-button>
          <el-button v-if="record.state === 'draft' && can('draft')" :disabled="busy || !!pending" @click="emit('edit')">{{ $t('Edit draft') }}</el-button>
          <el-button v-if="record.state === 'draft' && can('draft')" type="danger" plain :disabled="!!pending" @click="ask('void-draft', record, refresh)">{{ $t('Void draft') }}</el-button>
          <el-button v-if="record.state === 'draft' && can('post')" type="primary" :disabled="!!pending" @click="ask('post', record, refresh)">{{ $t('Post journal') }}</el-button>
          <el-button v-if="record.state === 'posted' && record.origin === 'manual' && !record.reversed_by && can('reverse')" type="warning" :disabled="!!pending" @click="ask('reverse', record, refresh)">{{ $t('Reverse journal') }}</el-button>
        </el-space>
      </template>
      <template #state="{ value }"><el-tag v-if="value" :type="value === 'posted' ? 'success' : value === 'voided' ? 'info' : 'warning'">{{ $t(value) }}</el-tag></template>
      <template #origin="{ value }">{{ value ? $t(value) : '—' }}</template>
      <template #reversal_of="{ value }"><el-button v-if="value" link type="primary" @click="open(value)">{{ $t('Open original journal') }}</el-button><span v-else>—</span></template>
      <template #reversed_by="{ value }"><el-button v-if="value" link type="primary" @click="open(value)">{{ $t('Open reversing journal') }}</el-button><span v-else>—</span></template>
      <template #evidence="{ value }"><span class="evidence">{{ value || '—' }}</span></template>
      <template #lines="{ value }">
        <el-table :data="value || []" row-key="position" stripe border>
          <el-table-column prop="position" label="#" width="65" />
          <el-table-column prop="account_code" :label="$t('Account code')" min-width="130" />
          <el-table-column :label="$t('Debit or credit')" width="135"><template #default="{ row }">{{ $t(row.side) }}</template></el-table-column>
          <el-table-column :label="$t('Amount')" min-width="140" align="right"><template #default="{ row }">{{ money(row.amount) }}</template></el-table-column>
          <el-table-column prop="description" :label="$t('Description')" min-width="200" show-overflow-tooltip />
        </el-table>
      </template>
    </detail-admin>
    <finance-command-dialog v-if="intent" :intent="intent" :busy="busy" :blocked="!!pending" @close="intent = null" @confirm="confirm" />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { t } from '@/i18n'
import DetailAdmin from '@/easyadmin/ui/vue/DetailAdmin.vue'
import FinanceCommandDialog from './FinanceCommandDialog.vue'
import { fields, FINANCE_API } from './use-finance'

const props = defineProps({ id: { type: String, required: true }, finance: { type: Object, required: true } })
const emit = defineEmits(['close', 'edit', 'open', 'changed'])
const finance = props.finance
const { can, money, act, busy, pending } = finance
const entity = { name: 'JournalEntry', prefix: FINANCE_API, plural: 'journals' }
const columns = computed(() => fields(
  ['number', t('Journal number')], ['accounting_date', t('Accounting date')], ['summary', t('Summary'), { full_width: true }],
  ['state', t('Status')], ['origin', t('Journal origin')], ['posted_by', t('Posted by')], ['posted_at', t('Posting time')],
  ['reversal_of', t('Original journal')], ['reversed_by', t('Reversing journal')],
  ...(can('audit') ? [['evidence', t('Evidence notes'), { full_width: true }]] : []),
  ['lines', t('Journal lines'), { full_width: true }]
))
const intent = ref(null)
function open(id) { emit('open', id) }
function ask(action, record, refresh) {
  intent.value = {
    action, data: action === 'reverse' ? { id: props.id } : { id: props.id, revision: record.revision }, refresh
  }
}
function confirm(action, data) {
  const { refresh } = intent.value
  intent.value = null
  return act(action, data, result => {
    if (result.id === props.id) refresh()
    else open(result.id)
    emit('changed')
  })
}
</script>

<style scoped>
.evidence { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
