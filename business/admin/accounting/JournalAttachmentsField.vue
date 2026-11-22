<template>
  <el-upload
    v-if="editing"
    :action="action" :headers="headers" :data="uploadData" name="file" :accept="ACCEPT" multiple :limit="MAX_FILES"
    :file-list="files" :before-upload="beforeUpload"
    :on-progress="started" :on-success="uploaded" :on-error="failed" :on-remove="removed" :on-exceed="exceeded"
  >
    <el-button :disabled="urls.length >= MAX_FILES">{{ $t('Select attachment files') }}</el-button>
    <template #tip><div class="el-upload__tip">{{ $t('Attachment upload limit') }}</div></template>
  </el-upload>
  <el-space v-else direction="vertical" alignment="start">
    <a v-for="url in data || []" :key="url" :href="url" target="_blank" rel="noopener noreferrer">{{ fileName(url) }}</a>
    <span v-if="!data?.length">—</span>
  </el-space>
</template>

<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '@/i18n'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { getUploadData, getUploadHeaders, resolveUploadHost, resolveUploadPath } from '@/utils/upload'
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'

/**
 * Journal attachment references for FormAdmin (`form` given) and DetailAdmin.
 *
 * EasyAdmin's `images`/`file` plugins always upload to the administrator-only
 * media endpoint and cannot cap the file count. Delegated Finance drafters
 * upload through the signed-in user's media endpoint instead. The form value
 * is the list of absolute file URLs the ledger stores as references.
 */
defineOptions({ inheritAttrs: false })
const props = defineProps({
  form: { type: Object, default: null },
  property: { type: String, default: 'attachments' },
  data: { type: Array, default: null }
})
const MAX_FILES = 20
const MAX_BYTES = 10 * 1024 * 1024
const ACCEPT = 'image/jpeg,image/png,image/gif,image/webp,application/pdf'
const registerFieldValidator = inject('registerFieldValidator', null)
const editing = computed(() => props.form !== null)
const action = `${resolveUploadHost()}${apiPath(API_PREFIX, 'app/media/upload')}`
const headers = getUploadHeaders()
const uploadData = getUploadData()
const uploading = ref(new Set())
const urls = computed(() => Array.isArray(props.form?.[props.property]) ? props.form[props.property] : [])
const fileName = url => {
  try { return decodeURIComponent(new URL(url).pathname.split('/').pop() || url) } catch { return url }
}
const files = ref([])
if (editing.value) {
  watch(urls, value => {
    const known = new Set(files.value.map(file => file.url))
    if (value.every(url => known.has(url)) && files.value.every(file => !file.url || value.includes(file.url))) return
    files.value = value.map(url => ({ name: fileName(url), url, status: 'success' }))
  }, { immediate: true })
}

function write(next) {
  props.form[props.property] = next
}
function beforeUpload(file) {
  if (file.size > MAX_BYTES || urls.value.length + uploading.value.size >= MAX_FILES) {
    createUiFeedback().error(t('attachment_upload_limit'))
    return false
  }
  return true
}
function started(_event, file) {
  uploading.value = new Set(uploading.value).add(file.uid)
}
function finished(file) {
  const next = new Set(uploading.value)
  next.delete(file.uid)
  uploading.value = next
}
function uploaded(response, file) {
  finished(file)
  const url = resolveUploadPath(response)
  if (!url) return failed(new Error('attachment_upload_failed'), file)
  file.url = url
  if (!urls.value.includes(url)) write([...urls.value, url])
}
function failed(_error, file) {
  if (file) finished(file)
  createUiFeedback().error(t('attachment_upload_failed'))
}
function removed(file) {
  finished(file)
  if (file.url) write(urls.value.filter(url => url !== file.url))
}
function exceeded() {
  createUiFeedback().error(t('attachment_upload_limit'))
}

let release
onMounted(() => {
  if (!editing.value) return
  release = registerFieldValidator?.(props.property, (_rule, _value, callback) => {
    callback(uploading.value.size ? new Error(t('Wait for attachment uploads to finish')) : undefined)
  }, 'change')
})
onBeforeUnmount(() => release?.())
</script>
