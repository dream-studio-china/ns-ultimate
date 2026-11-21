<template>
  <el-alert v-if="finance.pending.value" :title="$t('Finance command outcome unknown')" type="warning" :closable="false" show-icon class="finance-notice">
    <el-space wrap>
      <el-button size="small" :disabled="finance.busy.value" @click="finance.retry">{{ $t('Retry exact command') }}</el-button>
      <el-button size="small" :disabled="finance.busy.value" @click="finance.discard">{{ $t('Discard retry') }}</el-button>
    </el-space>
  </el-alert>
  <el-alert v-if="finance.error.value" :title="$t(finance.error.value)" type="error" :closable="false" show-icon class="finance-notice">
    <el-button v-if="!finance.workspace.value" size="small" :disabled="finance.busy.value" @click="finance.reload">{{ $t('Retry') }}</el-button>
  </el-alert>
</template>

<script setup>
defineProps({ finance: { type: Object, required: true } })
</script>

<style scoped>
.finance-notice { margin-bottom: 16px; }
</style>
