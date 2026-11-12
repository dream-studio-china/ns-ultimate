<template>
  <RelationToOne
    v-bind="$attrs"
    :form="form"
    :field="displayField"
    :struct="struct"
  />
</template>

<script>
import RelationToOne from '@/easyadmin/ui/vue/plugins/form/RelationToOne.vue'

/**
 * Store selection for a print rule. The store is immutable after creation, so
 * edit forms keep it visible but disabled instead of dropping it from the form
 * model. Dropping it left the output selectors without their store context and
 * marked existing devices and templates as unavailable.
 */
export default {
  name: 'PrinterRuleStoreField',
  components: { RelationToOne },
  props: {
    form: { type: Object, required: true },
    field: { type: Object, required: true },
    struct: { type: Object, default: () => ({}) }
  },
  computed: {
    isEdit() {
      let parent = this.$parent
      while (parent) {
        if (parent.$options?.name === 'FormAdmin') return Boolean(parent.id)
        parent = parent.$parent
      }
      return false
    },
    displayField() {
      if (!this.isEdit) return this.field
      return {
        ...this.field,
        type_options: { ...(this.field.type_options || {}), disabled: true }
      }
    }
  }
}
</script>
