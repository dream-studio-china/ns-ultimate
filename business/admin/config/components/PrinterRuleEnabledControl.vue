<template>
  <el-switch
    :model-value="enabled"
    :loading="saving"
    :disabled="saving || !recordId"
    :aria-label="t('Enabled')"
    :before-change="beforeChange"
    @click.stop
  />
</template>

<script setup>
import { computed, getCurrentInstance, ref } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'

const props = defineProps({
  record: { type: Object, required: true }
})

const saving = ref(false)
const instance = getCurrentInstance()
const enabled = computed(() => props.record.enabled === true)
const recordId = computed(() => props.record.uuid || props.record.id || '')

async function beforeChange() {
  if (saving.value || !recordId.value) return false
  const next = !enabled.value
  saving.value = true
  try {
    const response = await request.put(
      apiPath(API_PREFIX, `manage/printer/order-rules/${recordId.value}`),
      { enabled: next }
    )
    props.record.enabled = typeof response?.data?.enabled === 'boolean' ? response.data.enabled : next
    instance?.proxy?.$message?.success(t('Property updated successfully'))
    return true
  } catch {
    return false
  } finally {
    saving.value = false
  }
}
</script>
