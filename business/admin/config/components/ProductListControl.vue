<template>
  <el-switch
    v-if="mode === 'setting' || mode === 'soldout'"
    :model-value="booleanValue"
    :loading="saving"
    :disabled="saving"
    :aria-label="label"
    :before-change="beforeBooleanChange"
    @click.stop
  />
  <div
    v-else-if="mode === 'recommendation'"
    class="product-recommendation-picker"
    role="group"
    :aria-label="label"
    @click.stop
  >
    <button
      v-for="rating in 5"
      :key="rating"
      type="button"
      :class="{ 'is-active': rating <= recommendationValue }"
      :aria-label="`${label} ${rating}/5`"
      :aria-pressed="rating <= recommendationValue"
      :disabled="saving"
      :title="`${label} ${rating}/5`"
      @click="chooseRecommendation(rating)"
    >
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M2 21h4V9H2v12zm20-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L13.17 1 6.59 7.59A2 2 0 0 0 6 9v10a2 2 0 0 0 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z" />
      </svg>
    </button>
  </div>
  <div
    v-else
    class="product-sort-editor"
    :class="{ 'is-saving': saving }"
    :title="t('Double-click to edit')"
    @click.stop
    @dblclick.stop="beginSortEdit"
  >
    <input
      v-if="sortEditing"
      ref="sortInput"
      v-model="sortDraft"
      class="product-sort-input"
      type="number"
      min="0"
      max="1000000"
      step="1"
      :aria-label="label"
      :disabled="saving"
      @blur="commitSort"
      @keydown.enter.stop.prevent="commitSort"
      @keydown.esc.stop.prevent="cancelSortEdit"
    >
    <span v-else class="product-sort-value">{{ sortValue }}</span>
  </div>
</template>

<script setup>
import { computed, getCurrentInstance, nextTick, ref } from 'vue'
import { t } from '@/i18n'
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import {
  nextRecommendationLevel,
  normalizeRecommendationLevel,
  productRecommendationUpdate,
  productSettingUpdate,
  productSortOrderUpdate,
  productSoldoutUpdate
} from './product-list-updates.mjs'

const props = defineProps({
  record: { type: Object, required: true },
  mode: { type: String, required: true },
  settingKey: { type: String, default: '' },
  label: { type: String, required: true }
})

const saving = ref(false)
const sortEditing = ref(false)
const sortDraft = ref('')
const sortInput = ref(null)
const instance = getCurrentInstance()
const booleanValue = computed(() => props.mode === 'setting'
  ? props.record.settings?.[props.settingKey] === true
  : props.record.isSoldout === true)
const recommendationValue = computed(() => normalizeRecommendationLevel(props.record.recommendationLevel))
const sortValue = computed(() => {
  const value = Number(props.record.sortOrder)
  return Number.isInteger(value) && value >= 0 ? value : 0
})

async function update(payload) {
  saving.value = true
  try {
    const response = await request.put(apiPath(API_PREFIX, `manage/products/${props.record.id}`), payload)
    return response?.data || payload
  } finally {
    saving.value = false
  }
}

async function beforeBooleanChange() {
  if (saving.value) return false
  const next = !booleanValue.value
  try {
    const payload = props.mode === 'setting'
      ? productSettingUpdate(props.record, props.settingKey, next)
      : productSoldoutUpdate(next)
    const updated = await update(payload)
    if (props.mode === 'setting') {
      props.record.settings = updated.settings && typeof updated.settings === 'object'
        ? updated.settings
        : payload.settings
    } else {
      props.record.isSoldout = typeof updated.isSoldout === 'boolean' ? updated.isSoldout : next
    }
    instance?.proxy?.$message?.success(t('Property updated successfully'))
    return true
  } catch {
    return false
  }
}

async function chooseRecommendation(rating) {
  if (saving.value) return
  const next = nextRecommendationLevel(recommendationValue.value, rating)
  try {
    const updated = await update(productRecommendationUpdate(next))
    props.record.recommendationLevel = normalizeRecommendationLevel(updated.recommendationLevel ?? next)
    instance?.proxy?.$message?.success(t('Property updated successfully'))
  } catch {
    // The shared request interceptor displays the server error.
  }
}

async function beginSortEdit() {
  if (saving.value) return
  sortDraft.value = String(sortValue.value)
  sortEditing.value = true
  await nextTick()
  sortInput.value?.focus()
  sortInput.value?.select()
}

function cancelSortEdit() {
  sortEditing.value = false
  sortDraft.value = String(sortValue.value)
}

async function commitSort() {
  if (!sortEditing.value || saving.value) return
  const next = Number(sortDraft.value)
  if (!Number.isInteger(next) || next < 0 || next > 1000000) {
    instance?.proxy?.$message?.warning(t('Sort order must be an integer from 0 to 1000000'))
    await nextTick()
    sortInput.value?.focus()
    return
  }

  sortEditing.value = false
  if (next === sortValue.value) return

  try {
    const updated = await update(productSortOrderUpdate(next))
    props.record.sortOrder = Number.isInteger(updated.sortOrder) ? updated.sortOrder : next
    instance?.proxy?.$message?.success(t('Property updated successfully'))
  } catch {
    // The shared request interceptor displays the server error.
  }
}
</script>

<style scoped>
.product-recommendation-picker {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  min-height: 32px;
}

.product-recommendation-picker button {
  display: inline-grid;
  width: 28px;
  height: 32px;
  padding: 4px;
  color: #c7cbd1;
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: 6px;
  transition: color 160ms ease, background-color 160ms ease, transform 160ms ease;
}

.product-recommendation-picker button:hover:not(:disabled) {
  color: #e6a23c;
  background: #fdf6ec;
  transform: translateY(-1px);
}

.product-recommendation-picker button.is-active {
  color: #e6a23c;
}

.product-recommendation-picker button:disabled {
  cursor: wait;
  opacity: 0.6;
}

.product-recommendation-picker svg {
  width: 20px;
  height: 20px;
  fill: currentColor;
}

.product-sort-editor {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 64px;
  min-height: 32px;
}

.product-sort-value {
  padding: 4px 8px;
  font-variant-numeric: tabular-nums;
  cursor: text;
  border-radius: 5px;
}

.product-sort-value:hover {
  background: #f5f7fa;
}

.product-sort-input {
  width: 88px;
  height: 32px;
  padding: 0 8px;
  font: inherit;
  font-variant-numeric: tabular-nums;
  text-align: center;
  border: 1px solid #409eff;
  border-radius: 5px;
  outline: none;
  box-shadow: 0 0 0 2px rgb(64 158 255 / 12%);
}

.product-sort-editor.is-saving {
  cursor: wait;
  opacity: 0.65;
}
</style>
