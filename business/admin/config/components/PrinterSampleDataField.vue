<template>
  <JsonField :form="form" :field="field" />
</template>

<script>
import JsonField from '@/easyadmin/ui/vue/plugins/form/json.vue'
import printerSampleData from './printer-sample-data.json'

export default {
  name: 'PrinterSampleDataField',
  components: { JsonField },
  props: {
    form: { type: Object, required: true },
    field: { type: Object, required: true }
  },
  computed: {
    fieldValue() {
      return this.form[this.field.property]
    }
  },
  watch: {
    fieldValue: {
      immediate: true,
      handler(value) {
        // Retrieval replaces the form without this validation-only field.
        // Clone the sample for each form; never overwrite user-entered data.
        if (value == null) {
          this.form[this.field.property] = JSON.parse(JSON.stringify(printerSampleData))
        }
      }
    }
  }
}
</script>
