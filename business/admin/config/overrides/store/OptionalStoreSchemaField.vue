<template>
  <div class="optional-store-schema-field">
    <el-button
      link
      type="primary"
      :aria-expanded="String(expanded)"
      @click="expanded = !expanded"
    >
      {{ expanded ? t('Collapse') : t('Fill in (optional)') }}
    </el-button>
    <div v-if="expanded" class="optional-store-schema-field__content">
      <json-schema-field :form="form" :field="field" />
    </div>
  </div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { t } from '@/i18n'

const JsonSchemaField = defineAsyncComponent(() =>
  import('../../../../../core/crud-admin/src/easyadmin/ui/vue/plugins/form/json_schema.vue')
)

function hasValue(value) {
  return Boolean(value && typeof value === 'object' && Object.keys(value).length > 0)
}

export default {
  name: 'OptionalStoreSchemaField',
  components: { JsonSchemaField },
  props: {
    form: { type: Object, required: true },
    property: { type: String, required: true },
    field: { type: Object, required: true }
  },
  data() {
    return { expanded: hasValue(this.form[this.property]) }
  },
  computed: {
    value() {
      return this.form[this.property]
    }
  },
  watch: {
    value: {
      deep: true,
      handler(value) {
        if (hasValue(value)) this.expanded = true
      }
    }
  },
  methods: { t }
}
</script>

<style scoped>
.optional-store-schema-field__content {
  margin-top: 12px;
}
</style>
