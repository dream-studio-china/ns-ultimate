<template>
  <finance-page :finance="finance">
    <list-admin
      ref="list"
      :entity-conf="{ name: 'LedgerAccount', prefix: resourcePrefix }"
      :config="config"
      :list-display="columns"
      :disabled-actions="disabled"
      :query="query"
    >
      <template #titleText>{{ $t('Accounts') }}</template>
      <template #filter>
        <el-input v-model="search" clearable maxlength="160" :placeholder="$t('Search')" :aria-label="$t('Search')" @keyup.enter="fetchSearch" @clear="fetchSearch">
          <template #append><el-button icon="el-icon-search" :aria-label="$t('Search')" @click="fetchSearch" /></template>
        </el-input>
      </template>
      <template #classification="{ value }">{{ $t(value) }}</template>
      <template #normal_side="{ value }">{{ $t(value) }}</template>
    </list-admin>
  </finance-page>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import ListAdmin from '@/easyadmin/ui/vue/ListAdmin.vue'
import { t } from '@/i18n'
import FinancePage from './FinancePage.vue'
import { classes, fields, sides, useFinance } from './use-finance'

const finance = useFinance()
const { can, resourcePrefix } = finance
const list = ref()
const search = ref('')
const query = ref({})
const disabled = computed(() => ['detail', 'delete', 'batch_edit', 'batch_delete', 'export', ...(can('configure') ? [] : ['new', 'edit', 'lines'])])
watch(list, instance => instance?.fetchData())
async function fetchSearch() {
  query.value = search.value.trim() ? { q: search.value.trim() } : {}
  await nextTick()
  list.value?.fetchFilteredData()
}
const required = [{ required: true, whitespace: true, message: t('This field is required') }]
const codeRules = [
  { required: true, pattern: /^[A-Z0-9][A-Z0-9._-]{0,31}$/, message: t('invalid_account_code') }
]
const options = values => values.map(value => ({ value, label: t(value) }))
const config = { form: { fields: [
  { property: 'code', hidden: 'update', rules: codeRules, field_options: { label: t('Account code') }, type_options: { maxlength: 32 } },
  { property: 'name', rules: required, field_options: { label: t('Account name') }, type_options: { maxlength: 160 } },
  { property: 'classification', type: 'select', default_value: 'asset', rules: required, field_options: { label: t('Account class') }, type_options: { options: options(classes), clearable: false } },
  { property: 'normal_side', type: 'select', default_value: 'debit', rules: required, field_options: { label: t('Normal side') }, type_options: { options: options(sides), clearable: false } },
  { property: 'active', type: 'boolean', default_value: true, field_options: { label: t('Active') } },
  { property: 'postable', type: 'boolean', default_value: true, field_options: { label: t('Postable') } }
] }}
const columns = fields(['code', t('Account code')], ['name', t('Account name')], ['classification', t('Account class')], ['normal_side', t('Normal side')], ['active', t('Active'), { type: 'boolean' }], ['postable', t('Postable'), { type: 'boolean' }])
  .map(column => ({ ...column, field_options: { sortable: 'custom' } }))
</script>
