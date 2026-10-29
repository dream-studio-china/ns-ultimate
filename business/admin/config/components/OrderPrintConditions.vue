<template>
  <div class="order-print-conditions">
    <p v-if="conditions.length === 0">{{ $t(form.match === 'any' ? 'Print rule any conditions help' : 'Print rule all conditions help') }}</p>
    <div v-for="(condition, index) in conditions" :key="index" class="condition-row">
      <el-input v-model="condition.field" maxlength="128" :placeholder="$t('Order print field placeholder')" />
      <el-select :model-value="condition.operator" @update:model-value="setOperator(index, $event)">
        <el-option v-for="item in operators" :key="item.value" :value="item.value" :label="$t(item.label)" />
      </el-select>
      <template v-if="condition.operator === 'equals' || condition.operator === 'not_equals'">
        <el-select :model-value="valueType(condition.value)" @update:model-value="setValueType(index, $event)">
          <el-option v-for="item in valueTypes" :key="item.value" :value="item.value" :label="$t(item.label)" />
        </el-select>
        <el-input v-if="valueType(condition.value) === 'string'" v-model="condition.value" maxlength="256" />
        <el-input-number v-else-if="valueType(condition.value) === 'integer'" v-model="condition.value" :precision="0" />
        <el-switch v-else-if="valueType(condition.value) === 'boolean'" v-model="condition.value" />
      </template>
      <el-button type="danger" @click="remove(index)">{{ $t('Remove') }}</el-button>
    </div>
    <el-button :disabled="conditions.length >= 20" @click="add">{{ $t('Add condition') }}</el-button>
  </div>
</template>

<script>
import { conditionValueType, changeConditionOperator, withConditionValueType } from './order-print-conditions.mjs'

export default {
  props: { form: { type: Object, required: true }, property: { type: String, required: true } },
  data() {
    return {
      operators: [
        { value: 'equals', label: 'Equals' }, { value: 'not_equals', label: 'Not equals' },
        { value: 'exists', label: 'Exists' }, { value: 'not_exists', label: 'Does not exist' }
      ],
      valueTypes: [
        { value: 'string', label: 'Text value' }, { value: 'integer', label: 'Integer value' },
        { value: 'boolean', label: 'Boolean value' }, { value: 'null', label: 'Null value' }
      ]
    }
  },
  computed: {
    conditions() { return Array.isArray(this.form[this.property]) ? this.form[this.property] : [] }
  },
  created() {
    // FormAdmin removes null recursively; retain an explicit marker for the API.
    this.form[this.property] = this.conditions.map(condition => condition.value === null
      ? { ...condition, valueKind: 'null' } : condition)
  },
  methods: {
    valueType: conditionValueType,
    add() { this.form[this.property] = [...this.conditions, { field: 'status', operator: 'equals', value: '' }] },
    remove(index) { this.form[this.property] = this.conditions.filter((_, i) => i !== index) },
    setOperator(index, operator) { this.conditions[index] = changeConditionOperator(this.conditions[index], operator) },
    setValueType(index, type) { this.conditions[index] = withConditionValueType(this.conditions[index], type) }
  }
}
</script>

<style scoped>
.condition-row { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.condition-row > * { flex: 1 1 130px; }
.order-print-conditions { width: 100%; }
</style>
