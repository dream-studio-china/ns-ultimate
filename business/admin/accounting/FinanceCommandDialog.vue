<template>
  <finance-modal :title="title" width="min(520px, 94vw)" :locked="busy" @close="$emit('close')">
    <el-alert v-if="notice" :title="notice" type="warning" :closable="false" show-icon class="finance-command__notice" />
    <el-alert v-if="error" :title="$t(error)" type="error" :closable="false" show-icon class="finance-command__notice" />
    <form-admin
      v-if="fields.length"
      ref="renderer"
      v-model="values"
      :entity-conf="entity"
      :fields="fields"
      :structure-override="structure"
      embedded
    />
    <template #footer>
      <el-button :disabled="busy" @click="$emit('close')">{{ $t('Cancel') }}</el-button>
      <el-button type="primary" :loading="busy" :disabled="blocked" @click="submit">{{ $t('Confirm') }}</el-button>
    </template>
  </finance-modal>
</template>

<script setup>
import { ref } from 'vue'
import FormAdmin from '@/easyadmin/ui/vue/FormAdmin.vue'
import FinanceModal from './FinanceModal.vue'
import { FINANCE_API } from './finance-context'

// Confirmation for an explicit ledger command. The command's input fields come
// from its collection config and render through embedded FormAdmin.
const props = defineProps({
  title: { type: String, required: true },
  notice: { type: String, default: '' },
  fields: { type: Array, default: () => [] },
  initial: { type: Object, default: () => ({}) },
  validate: { type: Function, default: null },
  busy: Boolean,
  blocked: Boolean
})
const emit = defineEmits(['close', 'confirm'])
const entity = { name: 'FinanceCommand', prefix: FINANCE_API }
const structure = {}
const renderer = ref()
const values = ref({ ...props.initial })
const error = ref('')

// Embedded FormAdmin has no save button; validate its form, then pass only
// the declared fields to the command.
async function submit() {
  if (props.busy || props.blocked) return
  error.value = ''
  if (props.fields.length) {
    const form = renderer.value?.$refs.form
    if (!form || !await form.validate().catch(() => false)) return
  }
  const data = Object.fromEntries(props.fields.map(({ property }) => [property, renderer.value.form[property]]))
  error.value = props.validate?.(data) ?? ''
  if (!error.value) emit('confirm', data)
}
</script>

<style scoped>
.finance-command__notice { margin-bottom: 16px; }
</style>
