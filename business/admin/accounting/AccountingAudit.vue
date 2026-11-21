<template>
  <finance-page :finance="finance">
    <el-empty v-if="!can('audit')" :description="$t('finance_access_required')" />
    <finance-list v-else :title="$t('Accounting audit')" entity-name="AccountingAuditRecord" :rows="rows" :columns="columns" :page="result.page" :has-more="result.has_more" :busy="busy" actions @page-change="load">
      <template #action="{ record }"><el-button link type="primary" @click="detail = record">{{ $t('Detail') }}</el-button></template>
    </finance-list>
    <finance-modal v-if="detail" :title="$t('Accounting audit')" width="min(680px, 94vw)" @close="detail = null">
      <el-descriptions border :column="1">
        <el-descriptions-item v-for="field in columns" :key="field.property" :label="field.label">{{ detail[field.property] }}</el-descriptions-item>
        <el-descriptions-item :label="$t('Detail')"><pre class="audit-detail">{{ detail.detail }}</pre></el-descriptions-item>
      </el-descriptions>
    </finance-modal>
  </finance-page>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { t } from '@/i18n'
import FinancePage from './FinancePage.vue'
import FinanceList from './FinanceList.vue'
import FinanceModal from './FinanceModal.vue'
import { fields, financeGet, useFinance } from './use-finance'

const finance = useFinance()
const { book, can, run, busy } = finance
const result = ref({ rows: [] })
const detail = ref(null)
const columns = fields(['occurred_at', t('Occurred at')], ['actor', t('Actor')], ['action_label', t('Actions')], ['target', t('Target')])
const rows = computed(() => result.value.rows.map(row => ({ ...row, action_label: t(row.action) })))
const load = page => run(async() => { result.value = await financeGet('/audit', { page: String(page) }) })
watch(() => book.value?.id, async id => {
  await nextTick()
  if (id && can('audit')) await load(1)
})
</script>

<style scoped>
.audit-detail { white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; }
</style>
