<template>
  <finance-page :finance="finance" :needs-book="false" :show-feedback="!opening">
    <el-card shadow="never">
      <template #header><strong>{{ $t('Book setup') }}</strong></template>
      <template v-if="book">
        <el-descriptions border :column="1">
          <el-descriptions-item :label="$t('Accounting entity')">{{ book.entity }}</el-descriptions-item>
          <el-descriptions-item :label="$t('Base currency code')">{{ book.currency }}</el-descriptions-item>
          <el-descriptions-item :label="$t('Currency scale')">{{ book.scale }}</el-descriptions-item>
          <el-descriptions-item :label="$t('Book timezone')">{{ book.timezone }}</el-descriptions-item>
          <el-descriptions-item :label="$t('Opening date')">{{ book.opening_date }}</el-descriptions-item>
          <el-descriptions-item :label="$t('Opening position')">
            <el-tag :type="book.opening_state === 'pending' ? 'warning' : 'success'">{{ $t(book.opening_state) }}</el-tag>
          </el-descriptions-item>
        </el-descriptions>
        <el-space v-if="book.opening_state === 'pending'" wrap class="opening-actions">
          <el-button v-if="can('draft')" :disabled="busy || !!pending" @click="opening = true">{{ $t('opening') }}</el-button>
          <el-button v-if="can('post')" type="warning" plain :disabled="!!pending" @click="zero = true">{{ $t('Approve zero opening') }}</el-button>
        </el-space>
      </template>
      <template v-else-if="can('configure')">
        <finance-command-form ref="form" v-model="setup" entity-name="AccountingBook" :fields="formFields" :dates="{ opening_date: 'date' }" />
        <el-button type="primary" :loading="busy" @click="initialize">{{ $t('Initialize book') }}</el-button>
      </template>
      <el-empty v-else :description="$t('No accounting book configured')" />
    </el-card>
    <finance-modal v-if="opening" :title="$t('New journal draft')" :finance="finance" :locked="busy || !!pending" @close="opening = false">
      <accounting-journal-editor origin="opening" :finance="finance" @cancel="opening = false" @saved="opening = false" />
    </finance-modal>
    <finance-command-dialog v-if="zero" :intent="{ action: 'approve-zero-opening' }" :busy="busy" :blocked="!!pending" @close="zero = false" @confirm="confirm" />
  </finance-page>
</template>

<script setup>
import { ref } from 'vue'
import { t } from '@/i18n'
import FinancePage from './FinancePage.vue'
import FinanceCommandDialog from './FinanceCommandDialog.vue'
import FinanceCommandForm from './FinanceCommandForm.vue'
import FinanceModal from './FinanceModal.vue'
import AccountingJournalEditor from './AccountingJournalEditor.vue'
import { useFinance } from './use-finance'

const finance = useFinance()
const { book, can, act, busy, pending } = finance
const zero = ref(false)
const opening = ref(false)
const form = ref()
const setup = ref({ entity: '', currency: '', scale: null, timezone: '', opening_date: '' })
const required = [{ required: true, whitespace: true, message: t('This field is required') }]
const requiredNumber = [{ required: true, type: 'number', message: t('This field is required') }]
const currencyRules = [{ required: true, pattern: /^[A-Z]{3}$/, message: t('Base currency code') }]
const formFields = [
  { property: 'entity', rules: required, field_options: { label: t('Accounting entity') }, type_options: { maxlength: 160 } },
  { property: 'currency', rules: currencyRules, field_options: { label: t('Base currency code') }, type_options: { maxlength: 3 } },
  { property: 'scale', type: 'integer', rules: requiredNumber, field_options: { label: t('Currency scale') }, type_options: { min: 0, max: 6, precision: 0 } },
  { property: 'timezone', rules: required, field_options: { label: t('Book timezone') }, type_options: { placeholder: 'Asia/Shanghai' } },
  { property: 'opening_date', rules: required, field_options: { label: t('Opening date') } }
]
async function initialize() {
  const values = await form.value?.validatedValues()
  if (values) await act('initialize', values)
}
function confirm(action, data) {
  zero.value = false
  return act(action, data)
}
</script>

<style scoped>
.opening-actions { margin-top: 16px; }
</style>
