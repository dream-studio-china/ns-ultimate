<template>
  <finance-modal :title="$t(intent.action)" width="min(520px, 94vw)" :locked="busy" @close="$emit('close')">
    <el-alert v-if="intent.action === 'reverse'" :title="$t('Accounting reversal does not refund or move money')" type="warning" :closable="false" show-icon />
    <el-alert v-if="intent.action === 'post'" :title="$t('Posted journals cannot be edited')" type="info" :closable="false" show-icon />
    <finance-command-form v-if="fields.length" ref="form" v-model="data" entity-name="JournalEntry" :fields="fields" :dates="dates" />
    <template #footer>
      <el-button @click="$emit('close')">{{ $t('Cancel') }}</el-button>
      <el-button type="primary" :loading="busy" :disabled="blocked" @click="submit">{{ $t('Confirm') }}</el-button>
    </template>
  </finance-modal>
</template>

<script setup>
import { ref } from 'vue'
import { t } from '@/i18n'
import FinanceCommandForm from './FinanceCommandForm.vue'
import FinanceModal from './FinanceModal.vue'

const props = defineProps({ intent: { type: Object, required: true }, busy: Boolean, blocked: Boolean })
const emit = defineEmits(['close', 'confirm'])
const form = ref()
const data = ref({
  ...(props.intent.action === 'reverse' ? { accounting_date: '' } : {}),
  ...(props.intent.action === 'post' ? {} : { reason: '' }),
  ...props.intent.data
})
const required = [{ required: true, whitespace: true, message: t('This field is required') }]
const dates = props.intent.action === 'reverse' ? { accounting_date: 'date' } : {}
const fields = [
  ...(props.intent.action === 'reverse' ? [{ property: 'accounting_date', rules: required, field_options: { label: t('Accounting date') } }] : []),
  ...(props.intent.action !== 'post' ? [{ property: 'reason', type: 'textarea', rules: required, field_options: { label: t('Reason') }, type_options: { maxlength: 1000, showWordLimit: true } }] : [])
]
async function submit() {
  if (props.busy || props.blocked) return
  const values = fields.length ? await form.value?.validatedValues() : {}
  if (values) emit('confirm', props.intent.action, { ...props.intent.data, ...values })
}
</script>
