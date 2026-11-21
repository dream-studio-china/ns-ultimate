<template>
  <el-form class="finance-filters" label-position="top" @submit.prevent="$emit('submit')">
    <slot />
    <div class="finance-filters__actions"><slot name="actions" /></div>
  </el-form>
</template>

<script setup>
defineEmits(['submit'])
</script>

<style scoped>
.finance-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 8px 12px;
  width: 100%;
}
.finance-filters :deep(.el-form-item) { margin: 0; }
.finance-filters :deep(.el-select),
.finance-filters :deep(.el-date-editor) { width: min(160px, 65vw); }
.finance-filters__actions { display: flex; flex-wrap: wrap; gap: 8px; }
.finance-filters__actions :deep(.el-button + .el-button) { margin-left: 0; }
</style>
