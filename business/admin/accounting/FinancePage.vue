<template>
  <div class="app-container" v-loading="finance.busy.value">
    <finance-feedback v-if="showFeedback || !finance.workspace.value" :finance="finance" />
    <template v-if="finance.workspace.value">
      <el-empty v-if="needsBook && !finance.book.value" :description="$t('No accounting book configured')">
        <el-button type="primary" @click="router.push({ name: 'AccountingWorkspace' })">{{ $t('Book setup') }}</el-button>
      </el-empty>
      <slot v-else />
    </template>
  </div>
</template>

<script setup>
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRouter } from 'vue-router'
import FinanceFeedback from './FinanceFeedback.vue'

const props = defineProps({
  finance: { type: Object, required: true },
  needsBook: { type: Boolean, default: true },
  showFeedback: { type: Boolean, default: true }
})
const router = useRouter()
const mayNavigate = () => !props.finance.pending.value
onBeforeRouteLeave(mayNavigate)
onBeforeRouteUpdate((to, from) => to.path === from.path || mayNavigate())
</script>
