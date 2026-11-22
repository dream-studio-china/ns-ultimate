<template>
  <el-date-picker
    v-model="form[property]"
    :type="picker"
    :value-format="picker === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD'"
    :placeholder="$t(picker === 'month' ? 'Accounting month' : 'Select date')"
    :disabled="fixed !== null"
  />
</template>

<script setup>
import { computed, watch } from 'vue'

/**
 * FormAdmin `component` field for ISO dates and months. EasyAdmin's built-in
 * date plugin hard-codes a value format that dayjs cannot produce, so ledger
 * dates use this instead.
 *
 * type_options.picker: 'date' (default) or 'month'.
 * type_options.fixed: (form) => value|null; a non-null value is applied and locks the field.
 */
defineOptions({ inheritAttrs: false })
const props = defineProps({
  form: { type: Object, required: true },
  property: { type: String, required: true },
  field: { type: Object, default: () => ({}) }
})
const picker = computed(() => props.field.type_options?.picker === 'month' ? 'month' : 'date')
const fixed = computed(() => props.field.type_options?.fixed?.(props.form) ?? null)
watch(fixed, value => {
  if (value !== null && props.form[props.property] !== value) props.form[props.property] = value
}, { immediate: true })
</script>
