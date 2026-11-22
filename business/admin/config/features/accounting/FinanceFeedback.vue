<template>
  <el-alert v-if="state.pending" :title="$t('Finance command outcome unknown')" type="warning" :closable="false" show-icon class="finance-notice">
    <el-space wrap>
      <el-button size="small" :disabled="state.busy" @click="finance.retry">{{ $t('Retry exact command') }}</el-button>
      <el-button size="small" :disabled="state.busy" @click="finance.discard">{{ $t('Discard retry') }}</el-button>
    </el-space>
  </el-alert>
  <el-alert v-if="state.error" :title="$t(state.error)" type="error" :closable="false" show-icon class="finance-notice">
    <el-button v-if="!state.workspace" size="small" :disabled="state.busy" @click="finance.reload().catch(() => {})">{{ $t('Retry') }}</el-button>
  </el-alert>
</template>

<script setup>
import { finance } from './finance-context'

const state = finance.state
</script>

<style scoped>
.finance-notice { margin-bottom: 16px; }
</style>
