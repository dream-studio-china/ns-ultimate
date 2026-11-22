<template>
  <RelationToOne
    v-bind="$attrs"
    :form="form"
    :field="field"
    :struct="struct"
  />
</template>

<script>
import RelationToOne from '@/easyadmin/ui/vue/plugins/form/RelationToOne.vue'

/** Keep the selected store in the form model for dependent output selectors. */
export default {
  name: 'PrinterRuleStoreField',
  components: { RelationToOne },
  props: {
    form: { type: Object, required: true },
    field: { type: Object, required: true },
    struct: { type: Object, default: () => ({}) }
  }
}
</script>
