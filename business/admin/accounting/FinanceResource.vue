<template>
  <div class="app-container">
    <finance-feedback />
    <p v-if="ready && finance.book" class="finance-current-book">{{ $t('Selected book') }}: {{ finance.book.entity }} ({{ finance.book.currency }})</p>
    <el-skeleton v-if="!ready" :rows="6" animated />
    <el-empty v-else-if="denied" :description="$t('finance_access_required')" />
    <el-empty v-else-if="needsBook" :description="$t('No accounting book configured')">
      <el-button type="primary" @click="router.push({ name: 'AccountingBookList' })">{{ $t('Book setup') }}</el-button>
    </el-empty>
    <template v-else-if="view === 'list'">
      <el-alert v-if="listHelp" class="finance-list-help" :title="listHelp" type="info" :closable="false" show-icon />
      <list-admin
        ref="list"
        :key="entity"
        :entity-conf="config.entity"
        :config="config"
        :list-display="config.list.list_display"
        :list-filter="config.list.list_filter"
        :query="config.list.query"
        :data-processor="config.list.data_processor"
        :actions="config.list.actions || []"
        :disabled-actions="disabled"
      >
        <template #titleText>{{ title }}</template>
      </list-admin>
      <finance-record-dialog
        v-if="open"
        :entity="entity"
        :mode="open"
        :record-id="route.params.id || ''"
        :prefill="dialogValues"
        :refresh="refreshList"
        @closed="router.replace({ name: `${config.entity.name}List` })"
      />
    </template>
    <detail-admin
      v-else-if="view === 'detail'"
      :id="id"
      :key="id"
      :entity-conf="config.entity"
      :fields="fields"
      :title="title"
      :editable="editable"
    />
    <form-admin
      v-else
      :id="id"
      :key="id || 'new'"
      :entity-conf="config.entity"
      :fields="config.form.fields"
      :config="config"
      :model-value="model"
      @input="applyLinkValues"
    >
      <template #formTitle><strong class="finance-resource__title">{{ title }}</strong></template>
      <template #action="{ submit }">
        <el-button @click="router.back()">{{ $t('Cancel') }}</el-button>
        <el-button type="primary" icon="el-icon-edit-outline" @click="submit(saved)">{{ $t('Save') }}</el-button>
      </template>
    </form-admin>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import DetailAdmin from '@/easyadmin/ui/vue/DetailAdmin.vue'
import FormAdmin from '@/easyadmin/ui/vue/FormAdmin.vue'
import ListAdmin from '@/easyadmin/ui/vue/ListAdmin.vue'
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { t } from '@/i18n'
import FinanceFeedback from './FinanceFeedback.vue'
import FinanceRecordDialog from './FinanceRecordDialog.vue'
import { finance } from './finance-context'
import { allowed, createModel, detailFields, disabledActions, entityConfig, linkValues, policyOf, prefillOnce } from './finance-policy'

/**
 * Thin route adapter for Accounting resources.
 *
 * It renders ListAdmin, DetailAdmin, or FormAdmin from the entity's collection
 * config and only applies its `adapters.finance` policy (see finance-policy).
 * With `open`, a list route also opens that record dialog, so direct create,
 * edit, and detail links show the dialog over the list. It defines no fields,
 * columns, filters, or actions of its own.
 */
const props = defineProps({
  entity: { type: String, required: true },
  view: { type: String, default: 'list', validator: value => ['list', 'detail', 'form'].includes(value) },
  open: { type: String, default: '', validator: value => ['', 'create', 'update', 'detail'].includes(value) },
  // Declared form values carried by a dialog link (route params).
  prefill: { type: Object, default: () => ({}) }
})
const route = useRoute()
const router = useRouter()
const config = entityConfig(props.entity)
const policy = policyOf(config)
const list = ref()
const ready = computed(() => Boolean(finance.state.workspace))
const id = computed(() => route.params.id || 0)
const title = computed(() => route.meta.title || props.entity)
const denied = computed(() => Boolean(policy.requires) && !finance.can(policy.requires))
const needsBook = computed(() => Boolean(policy.requiresBook) && !finance.book)
const disabled = computed(() => disabledActions(config))
const listHelp = computed(() => typeof policy.listHelp === 'function' ? policy.listHelp() : '')
const fields = computed(() => detailFields(config))
const editable = computed(() => !(config.detail?.disabled_actions ?? []).includes('edit') && allowed(config, 'edit'))
const dialogValues = linkValues(config, props.prefill)
// Full-page forms (other resources) take link values from the query.
const model = route.params.id ? {} : createModel(config)
const applyLinkValues = prefillOnce(linkValues(config, route.query))
const refreshList = () => list.value?.fetchData()

function saved(response) {
  createUiFeedback().success(t('Data saved successfully'))
  const detail = `${config.entity.name}Detail`
  const recordId = response?.data?.id
  if (recordId && router.hasRoute(detail)) router.replace({ name: detail, params: { id: recordId } })
  else router.back()
}

onBeforeRouteLeave(() => !finance.state.pending)
onMounted(() => finance.ensure().catch(() => {}))
</script>

<style scoped>
.finance-list-help { margin-bottom: 12px; }
.finance-resource__title { font-size: 20px; }
</style>
