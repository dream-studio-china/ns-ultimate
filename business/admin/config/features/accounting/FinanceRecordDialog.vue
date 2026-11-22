<template>
  <record-dialog v-model="visible" class="easy-admin-dialog" :title="title" @closed="closed">
    <finance-feedback />
    <detail-admin
      v-if="mode === 'detail'"
      :id="recordId"
      :key="`detail:${recordId}:${generation}`"
      :entity-conf="config.entity"
      :fields="fields"
      :title="title"
      :editable="false"
    >
      <template #actions><span /></template>
    </detail-admin>
    <form-admin
      v-else
      ref="form"
      :id="mode === 'update' ? recordId : 0"
      :key="`form:${mode}:${recordId}:${generation}`"
      :entity-conf="config.entity"
      :fields="config.form.fields"
      :config="config"
      :model-value="model"
      @input="prefill"
    >
      <template #formTitle><span /></template>
      <template #action><span /></template>
    </form-admin>
    <template #footer>
      <template v-if="mode === 'detail'">
        <el-button @click="visible = false">{{ $t('Close') }}</el-button>
      </template>
      <template v-else>
        <el-button @click="cancel">{{ $t('Cancel') }}</el-button>
        <el-button type="primary" icon="el-icon-edit-outline" @click="save">{{ $t('Save') }}</el-button>
      </template>
    </template>
  </record-dialog>
</template>

<script setup>
import { computed, onMounted, provide, ref } from 'vue'
import DetailAdmin from '@/easyadmin/ui/vue/DetailAdmin.vue'
import FormAdmin from '@/easyadmin/ui/vue/FormAdmin.vue'
import RecordDialog from '@/easyadmin/ui/vue/RecordDialog.vue'
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { t } from '@/i18n'
import FinanceFeedback from './FinanceFeedback.vue'
import { createModel, detailFields, entityConfig, prefillOnce } from './finance-policy'

/**
 * Create, edit, and detail dialog for one Accounting resource, rendered by the
 * EasyAdmin RecordDialog, FormAdmin, and DetailAdmin from its collection
 * config. A save shows the saved record's detail; editing from the detail
 * returns to it. Detail operations can switch this dialog to edit mode.
 */
const props = defineProps({
  entity: { type: String, required: true },
  mode: { type: String, required: true, validator: value => ['create', 'update', 'detail'].includes(value) },
  recordId: { type: [String, Number], default: '' },
  prefill: { type: Object, default: () => ({}) },
  refresh: { type: Function, default: () => {} }
})
const emit = defineEmits(['closed'])
const config = entityConfig(props.entity)
const visible = ref(false)
const mode = ref(props.mode)
const recordId = ref(props.recordId)
const generation = ref(0)
const form = ref()
const model = ref(createModel(config))
// Edit opened from the detail view returns to that detail on cancel.
const returnToDetail = ref(false)
const fields = computed(() => detailFields(config))
const title = computed(() => t({ create: 'New Record', update: 'Edit Record', detail: 'Details' }[mode.value]))
let prefill = prefillOnce(props.prefill)

function show(nextMode, id = '') {
  returnToDetail.value = mode.value === 'detail' && nextMode === 'update'
  mode.value = nextMode
  recordId.value = id
  if (nextMode === 'create') {
    model.value = createModel(config)
    prefill = prefillOnce(props.prefill)
  }
  generation.value++
  visible.value = true
}

function cancel() {
  if (returnToDetail.value) show('detail', recordId.value)
  else visible.value = false
}

// FormAdmin validates and reports failures itself; the callback runs only
// after a successful save. A repeated create reuses the same command key.
function save() {
  form.value?.onSubmit(response => {
    createUiFeedback().success(t('Data saved successfully'))
    props.refresh()
    show('detail', response?.data?.id ?? recordId.value)
  })
}

function closed() {
  props.refresh()
  emit('closed')
}

provide('financeRecordDialog', { entity: props.entity, open: (nextMode, record) => show(nextMode, record?.id ?? '') })
onMounted(() => show(props.mode, props.recordId))
</script>
