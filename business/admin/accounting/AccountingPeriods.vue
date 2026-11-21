<template>
  <finance-page :finance="finance">
    <list-admin ref="list" :entity-conf="{ name: 'AccountingPeriod', prefix: resourcePrefix }" :config="config" :list-display="columns" :disabled-actions="disabled">
      <template #titleText>{{ $t('Accounting periods') }}</template>
      <template #state="{ value }">{{ $t(value) }}</template>
      <template #extraAction="{ data: record }">
        <el-button v-if="record.state === 'open' && can('lock')" link type="warning" :disabled="!!pending" @click="ask('lock-period', record)">{{ $t('Lock period') }}</el-button>
        <el-button v-if="record.state === 'locked' && can('reopen')" link type="primary" :disabled="!!pending" @click="ask('reopen-period', record)">{{ $t('Reopen period') }}</el-button>
      </template>
    </list-admin>
    <finance-command-dialog v-if="intent" :intent="intent" :busy="busy" :blocked="!!pending" @close="intent = null" @confirm="confirm" />
  </finance-page>
</template>

<script setup>
import { computed, defineComponent, h, ref, resolveComponent } from 'vue'
import ListAdmin from '@/easyadmin/ui/vue/ListAdmin.vue'
import { t } from '@/i18n'
import FinancePage from './FinancePage.vue'
import FinanceCommandDialog from './FinanceCommandDialog.vue'
import { fields, useFinance } from './use-finance'

const finance = useFinance()
const { can, pending, busy, act, resourcePrefix } = finance
const list = ref()
const disabled = computed(() => ['detail', 'edit', 'delete', 'batch_edit', 'batch_delete', 'export',
  ...(can('configure') && !pending.value ? [] : ['new']), ...(can('lock') || can('reopen') ? [] : ['lines'])])
const MonthField = defineComponent({
  props: { form: { type: Object, required: true } },
  setup(props) {
    const DatePicker = resolveComponent('el-date-picker')
    return () => h(DatePicker, {
      type: 'month', valueFormat: 'YYYY-MM', modelValue: props.form.month,
      'onUpdate:modelValue': value => { props.form.month = value }
    })
  }
})
const config = { form: { fields: [{ property: 'month', component: MonthField, required: true, field_options: { label: t('Accounting month') } }] }}
const intent = ref(null)
const columns = fields(['month', t('Accounting month')], ['state', t('Status')])
  .map(column => ({ ...column, field_options: { sortable: 'custom' } }))
function ask(action, row) { intent.value = { action, data: { month: row.month } } }
function confirm(action, data) {
  intent.value = null
  return act(action, data, () => list.value?.fetchData())
}
</script>
