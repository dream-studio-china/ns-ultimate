<template>
  <finance-page :finance="finance">
    <el-alert v-if="selection" :title="`${$t(selection.kind)} · ${selection.from} → ${selection.to}${selection.account && selection.kind !== 'trial' ? ` · ${selection.account}` : ''}`" type="info" :closable="false" class="report-snapshot" />
    <finance-list :key="layout" :title="$t('Reports')" entity-name="JournalEntry" :rows="rows" :columns="columns" :row-key="rowKey" :page="report?.page" :has-more="report?.has_more" :busy="busy" @page-change="load">
      <template #filter>
        <finance-filters @submit="load(1, true)">
          <el-form-item :label="$t('Report')">
            <el-select v-model="query.kind"><el-option v-for="kind in kinds" :key="kind" :value="kind" :label="$t(kind)" /></el-select>
          </el-form-item>
          <el-form-item :label="$t('From date')"><el-date-picker v-model="query.from" type="date" value-format="YYYY-MM-DD" /></el-form-item>
          <el-form-item :label="$t('To date')"><el-date-picker v-model="query.to" type="date" value-format="YYYY-MM-DD" /></el-form-item>
          <el-form-item v-if="query.kind !== 'trial'" :label="$t('Account code')">
            <el-select v-model="query.account" clearable filterable><el-option v-for="account in accounts" :key="account.code" :value="account.code" :label="`${account.code} - ${account.name}`" /></el-select>
          </el-form-item>
          <template #actions>
            <el-button native-type="submit" type="primary" :loading="busy" :disabled="!canRun">{{ $t('Run report') }}</el-button>
          </template>
        </finance-filters>
      </template>
      <template #toolbar><el-button v-if="report" :disabled="busy" @click="download">{{ $t('Export CSV') }}</el-button></template>
    </finance-list>
    <el-descriptions v-if="report?.opening !== undefined" border :column="1" class="summary">
      <el-descriptions-item :label="$t('Opening balance')">{{ money(report.opening) }} {{ book.currency }}</el-descriptions-item>
    </el-descriptions>
    <el-descriptions v-if="report?.totals" :title="$t('Total')" border :column="2" class="summary">
      <el-descriptions-item v-for="(value, key) in report.totals" :key="key" :label="$t(key)">{{ money(value) }} {{ book.currency }}</el-descriptions-item>
    </el-descriptions>
  </finance-page>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { t } from '@/i18n'
import FinanceList from './FinanceList.vue'
import FinanceFilters from './FinanceFilters.vue'
import FinancePage from './FinancePage.vue'
import { fields, financeGet, useFinance } from './use-finance'

const finance = useFinance()
const { book, accounts, busy, money, run } = finance
const kinds = ['ledger', 'balances', 'trial']
const query = reactive({ kind: 'trial', from: '', to: '', account: '' })
const canRun = computed(() => Boolean(query.from && query.to && (query.kind !== 'ledger' || query.account)))
const selection = ref(null)
const report = ref(null)
const reportColumns = {
  ledger: fields(['accounting_date', t('Accounting date')], ['number', t('Journal number')], ['summary', t('Summary')], ['side', t('Debit or credit')],
    ['amount', t('Amount')], ['description', t('Description')], ['balance', t('Balance')]),
  balances: fields(['code', t('Account code')], ['name', t('Account name')], ...['opening_debit', 'opening_credit', 'movement_debit', 'movement_credit', 'closing_debit', 'closing_credit'].map(key => [key, t(key)]))
}
const layout = computed(() => (selection.value?.kind ?? query.kind) === 'ledger' ? 'ledger' : 'balances')
const columns = computed(() => reportColumns[layout.value])
const rowKey = row => layout.value === 'ledger' ? `${row.id}:${row.position}` : row.code
const isMoney = key => /^(opening|movement|closing)_(debit|credit)$/.test(key) || ['amount', 'balance'].includes(key)
const rows = computed(() => (report.value?.rows ?? []).map(row => Object.fromEntries(Object.entries(row).map(([key, value]) =>
  [key, isMoney(key) ? money(value) : key === 'side' ? t(value) : value]
))))

watch(book, value => {
  if (!value || query.from) return
  query.from = value.opening_date
  const today = new Intl.DateTimeFormat('sv-SE', { timeZone: value.timezone }).format(new Date())
  query.to = today >= query.from ? today : query.from
})
function filter({ kind, from, to, account }) {
  return { from, to, ...(kind !== 'trial' && account ? { account } : {}) }
}
function load(page, fresh = false) {
  if (fresh && !canRun.value) return
  return run(async() => {
    const picked = fresh ? { ...query } : selection.value
    const args = { ...filter(picked), page: String(page), ...(fresh ? {} : { cutoff: report.value.cutoff }) }
    const next = await financeGet(`/reports/${picked.kind}`, args)
    selection.value = picked
    report.value = next
  })
}
function download() {
  return run(async() => {
    const csv = await financeGet(`/reports/${selection.value.kind}/csv`, { ...filter(selection.value), cutoff: report.value.cutoff })
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = `accounting-${selection.value.kind}.csv`
    link.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  })
}
</script>

<style scoped>
.summary { margin-top: 20px; }
.report-snapshot { margin-bottom: 16px; }
</style>
