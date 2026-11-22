<template>
  <el-dialog
    :model-value="true"
    :title="title"
    :width="width"
    class="finance-modal"
    align-center
    append-to-body
    :close-on-click-modal="false"
    :close-on-press-escape="!locked"
    :show-close="!locked"
    @close="$emit('close')"
  >
    <finance-feedback />
    <slot />
    <template v-if="$slots.footer" #footer><slot name="footer" /></template>
  </el-dialog>
</template>

<script setup>
import FinanceFeedback from './FinanceFeedback.vue'

defineProps({
  title: { type: String, required: true },
  width: { type: String, default: 'min(1080px, 94vw)' },
  locked: Boolean
})
defineEmits(['close'])
</script>

<style scoped>
:global(.el-dialog.finance-modal) {
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 32px);
}
:global(.finance-modal > .el-dialog__body) {
  min-height: 0;
  overflow: auto;
  overscroll-behavior: contain;
}
</style>
