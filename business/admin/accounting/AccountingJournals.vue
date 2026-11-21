<template>
  <finance-page :finance="finance" :show-feedback="!modal">
    <finance-list :title="$t('Journals')" entity-name="JournalEntry" :columns="columns" :rows="rows" actions :page="result.page" :has-more="result.has_more" :busy="busy" @page-change="load">
      <template #filter>
        <finance-filters @submit="search">
          <el-form-item :label="$t('From date')"><el-date-picker v-model="filters.from" type="date" value-format="YYYY-MM-DD" /></el-form-item>
          <el-form-item :label="$t('To date')"><el-date-picker v-model="filters.to" type="date" value-format="YYYY-MM-DD" /></el-form-item>
          <el-form-item :label="$t('Status')">
            <el-select v-model="filters.state" clearable><el-option v-for="state in states" :key="state" :value="state" :label="$t(state)" /></el-select>
          </el-form-item>
          <el-form-item :label="$t('Account code')">
            <el-select v-model="filters.account" clearable filterable><el-option v-for="account in accounts" :key="account.code" :value="account.code" :label="`${account.code} - ${account.name}`" /></el-select>
          </el-form-item>
          <template #actions>
            <el-button native-type="submit" type="primary" :loading="busy">{{ $t('Search') }}</el-button>
            <el-button :disabled="busy" @click="reset">{{ $t('Reset') }}</el-button>
          </template>
        </finance-filters>
      </template>
      <template #toolbar>
        <el-button v-if="can('draft')" type="primary" icon="el-icon-plus" @click="open('new')">{{ $t('New journal draft') }}</el-button>
      </template>
      <template #action="{ record }">
        <el-button link type="primary" @click="open('detail', record.id)">{{ $t('Detail') }}</el-button>
      </template>
    </finance-list>
    <finance-modal v-if="modal" :key="`${modal.action}:${modal.id}`" :title="$t(modal.title)" :finance="finance" :locked="busy || !!pending" @close="closeModal">
      <accounting-journal-detail v-if="modal.action === 'detail'" :id="modal.id" :finance="finance" @edit="open('edit', modal.id)" @open="showDetail" @changed="refreshList" @close="closeModal" />
      <accounting-journal-editor v-else :id="modal.id" :origin="modal.origin" :finance="finance" @cancel="closeModal" @saved="afterSave" />
    </finance-modal>
  </finance-page>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { onBeforeRouteUpdate, useRoute } from 'vue-router'
import { t } from '@/i18n'
import FinanceList from './FinanceList.vue'
import FinanceFilters from './FinanceFilters.vue'
import FinancePage from './FinancePage.vue'
import FinanceModal from './FinanceModal.vue'
import AccountingJournalDetail from './AccountingJournalDetail.vue'
import AccountingJournalEditor from './AccountingJournalEditor.vue'
import { fields, financeGet, useFinance } from './use-finance'

const route = useRoute()
const finance = useFinance()
const { accounts, book, busy, can, pending, run } = finance
const states = ['draft', 'posted', 'voided']
const emptyFilters = () => ({ from: '', to: '', state: '', account: '' })
const filters = reactive(emptyFilters())
const applied = ref({})
const result = ref({ rows: [] })
const rows = computed(() => result.value.rows.map(row => ({ ...row, state_label: t(row.state), origin_label: t(row.origin) })))
const columns = fields(['number', t('Journal number')], ['accounting_date', t('Accounting date')], ['summary', t('Summary')], ['origin_label', t('Journal origin')], ['state_label', t('Status')])
const fetchPage = async(page, query) => { result.value = await financeGet('/journals', { ...query, page: String(page) }) }
const load = page => run(() => fetchPage(page, applied.value))
const modal = ref(null)
function open(action, id, origin) {
  if (action === 'new') {
    modal.value = { action, id: '', origin: origin === 'opening' ? origin : '', title: 'New journal draft' }
  } else if (typeof id === 'string' && id && (action === 'edit' || action === 'detail')) {
    modal.value = { action, id, title: action === 'edit' ? 'Edit draft' : 'Journal detail' }
  } else {
    modal.value = null
  }
}
// Old external links can initialize the dialog; interactions in the list never navigate.
const linkKeys = ['journalAction', 'journal', 'origin']
watch(linkKeys.map(key => () => route.query[key]), ([action, id, origin]) => open(action, id, origin), { immediate: true })
function showDetail(id) { open('detail', id) }
function closeModal() {
  if (busy.value || pending.value) return
  if (modal.value?.action === 'edit') showDetail(modal.value.id)
  else modal.value = null
}
const refreshList = () => fetchPage(result.value.page || 1, applied.value).catch(error => {
  finance.error.value = error.response?.data?.message || error.message
})
function afterSave(id) {
  showDetail(id)
  return refreshList()
}
onBeforeRouteUpdate((to, from) => !pending.value ||
  linkKeys.every(key => to.query[key] === from.query[key]))
function search() {
  return run(async() => {
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value))
    await fetchPage(1, query)
    applied.value = query
  })
}
function reset() {
  Object.assign(filters, emptyFilters())
  return search()
}
watch(() => book.value?.id, async id => {
  await nextTick()
  if (id) await load(1)
})
</script>
