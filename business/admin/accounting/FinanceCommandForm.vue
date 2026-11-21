<template>
  <form-admin
    ref="renderer"
    :model-value="modelValue"
    :entity-conf="entity"
    :fields="fields"
    :structure-override="structure"
    embedded
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <template v-for="name in dateFields" #[name]="{ form }" :key="name">
      <el-date-picker v-model="form[name]" :type="dateType(name)" :value-format="dateType(name) === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD'" />
    </template>
  </form-admin>
</template>

<script setup>
import { computed, ref } from 'vue'
import FormAdmin from '@/easyadmin/ui/vue/FormAdmin.vue'
import { FINANCE_API } from './use-finance'

const props = defineProps({
  modelValue: { type: Object, required: true },
  entityName: { type: String, required: true },
  fields: { type: Array, required: true },
  dates: { type: Object, default: () => ({}) }
})
defineEmits(['update:modelValue'])
const entity = computed(() => ({ name: props.entityName, prefix: FINANCE_API }))
const structure = {}
const dateFields = computed(() => Object.keys(props.dates))
const dateType = key => props.dates[key]
const renderer = ref()

// FormAdmin intentionally has no generic save button in embedded mode.
// Validate its Element Plus form, then pass only declared fields to the command.
async function validatedValues() {
  const admin = renderer.value
  const form = admin?.$refs.form
  if (!form || !await form.validate().catch(() => false)) return null
  return Object.fromEntries(props.fields.map(({ property }) => [property, admin.form[property]]))
}
defineExpose({ validatedValues })
</script>
