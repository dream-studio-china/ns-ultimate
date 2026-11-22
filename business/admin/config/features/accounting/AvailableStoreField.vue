<template>
  <el-select
    v-model="form[property]"
    filterable remote
    :remote-method="search"
    :loading="loading"
    :placeholder="$t('Search stores by name or code')"
    :no-data-text="$t(failed ? 'Could not load stores' : 'No available stores')"
    @focus="loadInitial"
  >
    <el-option v-for="store in options" :key="store.uuid" :value="store.uuid" :label="`${store.name} (${store.code})`" />
  </el-select>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue'
import request from '@/utils/request'
import { FINANCE_API } from './finance-context'

defineOptions({ inheritAttrs: false })
const props = defineProps({
  form: { type: Object, required: true },
  property: { type: String, required: true }
})
const results = shallowRef([])
const known = new Map()
const loading = ref(false)
const failed = ref(false)
let generation = 0
let initialized = false

const options = computed(() => {
  const current = known.get(props.form[props.property])
  return current && !results.value.some(store => store.uuid === current.uuid) ? [current, ...results.value] : results.value
})

function loadInitial() {
  if (!initialized || failed.value) void search('')
}

async function search(query) {
  initialized = true
  const current = ++generation
  loading.value = true
  failed.value = false
  try {
    const response = await request.get(`${FINANCE_API}/available-stores`, { params: { q: query.trim() } })
    if (current !== generation) return
    const next = response.data.stores
    const selected = known.get(props.form[props.property])
    known.clear()
    if (selected) known.set(selected.uuid, selected)
    for (const store of next) known.set(store.uuid, store)
    results.value = next
  } catch {
    if (current !== generation) return
    failed.value = true
    results.value = []
  } finally {
    if (current === generation) loading.value = false
  }
}

onBeforeUnmount(() => { generation++ })
</script>
