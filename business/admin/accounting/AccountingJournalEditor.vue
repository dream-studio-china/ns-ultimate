<template>
  <div>
    <el-empty v-if="!can('draft') || (record && record.state !== 'draft')" :description="$t('Journal is not editable')" />
    <el-alert v-else-if="loadError" :title="$t(loadError)" type="error" :closable="false" />
    <el-skeleton v-else-if="!editor" :rows="4" animated />
    <el-form v-else ref="form" :model="editor" label-position="top" @submit.prevent="save">
        <el-row :gutter="16">
          <el-col v-if="!id" :xs="24" :sm="8">
            <el-form-item :label="$t('Journal origin')">
              <el-select v-model="editor.origin" @change="originChanged">
                <el-option value="manual" :label="$t('manual')" />
                <el-option v-if="book.opening_state === 'pending'" value="opening" :label="$t('opening')" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :xs="24" :sm="8">
            <el-form-item :label="$t('Accounting date')" prop="accounting_date" :rules="required">
              <el-date-picker v-model="editor.accounting_date" type="date" value-format="YYYY-MM-DD" :disabled="editor.origin === 'opening'" />
            </el-form-item>
          </el-col>
          <el-col :span="24"><el-form-item :label="$t('Summary')" prop="summary" :rules="required"><el-input v-model="editor.summary" maxlength="240" show-word-limit /></el-form-item></el-col>
          <el-col v-if="!id || can('audit')" :span="24"><el-form-item :label="$t('Evidence notes')"><el-input v-model="editor.evidence" type="textarea" maxlength="2000" show-word-limit /></el-form-item></el-col>
        </el-row>
        <el-table :data="editor.lines" row-key="key" stripe border>
          <el-table-column type="index" width="50" />
          <el-table-column :label="$t('Account code')" min-width="210">
            <template #default="{ row, $index }">
              <el-form-item :prop="`lines.${$index}.account_code`" :rules="required" :show-message="false">
                <el-select v-model="row.account_code" filterable :aria-label="$t('Account code')">
                  <el-option v-for="account in accounts" :key="account.code" :value="account.code" :label="`${account.code} - ${account.name}`" :disabled="!available(account)" />
                </el-select>
              </el-form-item>
            </template>
          </el-table-column>
          <el-table-column :label="$t('Debit or credit')" min-width="125">
            <template #default="{ row }"><el-form-item><el-select v-model="row.side" :aria-label="$t('Debit or credit')"><el-option v-for="side in sides" :key="side" :value="side" :label="$t(side)" /></el-select></el-form-item></template>
          </el-table-column>
          <el-table-column :label="$t('Amount')" min-width="145" align="right">
            <template #default="{ row, $index }"><el-form-item :prop="`lines.${$index}.amount`" :rules="amountRules" :show-message="false"><el-input v-model.trim="row.amount" inputmode="decimal" autocomplete="off" :aria-label="$t('Amount')" /></el-form-item></template>
          </el-table-column>
          <el-table-column :label="$t('Description')" min-width="180">
            <template #default="{ row }"><el-form-item><el-input v-model="row.description" maxlength="240" :aria-label="$t('Description')" /></el-form-item></template>
          </el-table-column>
          <el-table-column :label="$t('Actions')" width="105">
            <template #default="{ $index }"><el-button link type="danger" @click="editor.lines.splice($index, 1)">{{ $t('Remove line') }}</el-button></template>
          </el-table-column>
        </el-table>
        <el-space wrap class="editor-toolbar">
          <el-button :disabled="editor.lines.length >= 100" @click="editor.lines.push(line('debit'))">{{ $t('Add line') }}</el-button>
          <template v-if="totals">
            <el-tag effect="plain">{{ $t('debit') }}: {{ totals.debit }}</el-tag>
            <el-tag effect="plain">{{ $t('credit') }}: {{ totals.credit }}</el-tag>
            <el-tag v-if="totals.debit !== totals.credit" type="warning">{{ $t('Unbalanced draft') }}</el-tag>
          </template>
        </el-space>
        <el-alert v-if="invalid" :title="$t(invalid)" type="warning" :closable="false" />
        <el-space wrap class="editor-toolbar">
          <el-button :disabled="busy || !!pending" @click="emit('cancel')">{{ $t('Cancel') }}</el-button>
          <el-button type="primary" native-type="submit" :loading="busy" :disabled="!!pending">{{ $t('Save draft') }}</el-button>
        </el-space>
      </el-form>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { t } from '@/i18n'
import { available, financeGet, sides } from './use-finance'
import { decimalToMinor, draftLines, draftTotals } from './ledger-ui.mjs'

const props = defineProps({ id: { type: String, default: '' }, origin: { type: String, default: '' }, finance: { type: Object, required: true } })
const emit = defineEmits(['cancel', 'saved'])
const finance = props.finance
const { book, accounts, can, scale, money, busy, pending, act } = finance
const form = ref()
const record = ref(null)
const editor = ref(null)
const invalid = ref('')
const loadError = ref('')
let nextLine = 0
const line = (side, fields = {}) => ({ key: ++nextLine, account_code: '', side, amount: '', description: '', ...fields })
const totals = computed(() => editor.value ? draftTotals(editor.value.lines, scale.value) : null)
const required = [{ required: true, whitespace: true, message: t('This field is required') }]
const amountRules = [{ validator: (_rule, value) => {
  try { decimalToMinor(value, scale.value); return true } catch { return new Error(t('invalid_amount')) }
} }]

onMounted(async() => {
  if (!can('draft')) return
  if (props.id) {
    try {
      record.value = await financeGet(`/journals/${encodeURIComponent(props.id)}`)
      const { origin, revision, accounting_date, summary, evidence, lines } = record.value
      if (record.value.state === 'draft') {
        editor.value = { origin, revision, accounting_date, summary, evidence, lines: lines.map(entry => line(entry.side, { ...entry, amount: money(entry.amount) })) }
      }
    } catch (error) {
      loadError.value = error.response?.data?.message || error.message
    }
  } else {
    record.value = null
    editor.value = {
      origin: book.value.opening_state === 'pending' && props.origin === 'opening' ? 'opening' : 'manual',
      accounting_date: book.value.opening_date, summary: '', evidence: '', lines: [line('debit'), line('credit')]
    }
  }
})
function originChanged() {
  if (editor.value.origin === 'opening') editor.value.accounting_date = book.value.opening_date
}
async function save() {
  if (busy.value || pending.value) return
  invalid.value = ''
  if (!await form.value.validate().catch(() => false)) {
    invalid.value = 'Check the highlighted fields'
    return
  }
  const { revision, origin, accounting_date, summary, evidence, lines } = editor.value
  const payload = { accounting_date, summary, lines: draftLines(lines, scale.value) }
  if (evidence !== undefined) payload.evidence = evidence
  return act(props.id ? 'update-draft' : 'create-draft', props.id ? { ...payload, id: props.id, revision } : { ...payload, origin },
    result => emit('saved', result.id))
}
</script>

<style scoped>
.editor-toolbar { margin-top: 16px; }
.el-table :deep(.el-form-item) { margin-bottom: 0; }
</style>
