<template>
  <section class="counter-orders">
    <template v-if="route.query.counter !== undefined && !active">
      <el-button text @click="back">{{ $t('Back') }} · {{ $t('Counter orders') }}</el-button>
      <el-skeleton v-if="activeLoading" :rows="4" animated />
      <el-empty v-else :description="activeError || $t('No matching counters')" />
    </template>
    <template v-else-if="!active">
      <h1>{{ $t('Counter orders') }}</h1>
      <p>{{ $t('Find a counter and trace its order history.') }}</p>
      <div class="panel controls">
        <div class="store-bar">
          <nav :aria-label="$t('Store')">
            <el-button v-for="store in tabs" :key="store.uuid" :type="storeUuid === store.uuid ? 'primary' : 'default'"
              :aria-pressed="storeUuid === store.uuid" @click="pickStore(store)">{{ store.name }}</el-button>
          </nav>
          <el-select v-model="storeUuid" filterable remote clearable :remote-method="searchStores" :loading="storeLoading"
            :placeholder="$t('Store name / UUID')" :aria-label="$t('Store name / UUID')" @change="chooseStore" @visible-change="refreshStoreOptions">
            <el-option v-for="store in storeOptions" :key="store.uuid" :label="store.name" :value="store.uuid" />
          </el-select>
        </div>
        <p v-if="storeError" role="alert">{{ storeError }}</p>
        <el-input v-model.trim="counterSearch" clearable :placeholder="$t('Counter name / code / UUID')"
          :aria-label="$t('Counter name / code / UUID')" @change="loadCounters(1)" />
        <order-filters :filters="filters" @apply="applyFilters" />
      </div>
      <p v-if="counterError" role="alert">{{ counterError }}</p>
      <div v-loading="counterLoading" class="counter-grid">
        <button v-for="counter in counters" :key="counter.uuid" type="button" class="panel counter-card" @click="openCounter(counter)">
          <strong>{{ counter.code }} <span aria-hidden="true">›</span></strong>
          <span>{{ counter.name }}</span>
          <small>{{ counter.storeName || counter.storeUuid }}<template v-if="!counter.enabled"> · {{ $t('Disabled') }}</template></small>
        </button>
      </div>
      <el-empty v-if="!counterLoading && !counterError && !counters.length" :description="$t('No matching counters')" />
      <div v-if="counterPage > 1 || countersMore" class="pager">
        <el-button :disabled="counterLoading || counterPage === 1" @click="loadCounters(counterPage - 1)">{{ $t('Previous') }}</el-button>
        <span>{{ counterPage }}</span>
        <el-button :disabled="counterLoading || !countersMore" @click="loadCounters(counterPage + 1)">{{ $t('Next') }}</el-button>
      </div>
    </template>

    <template v-else>
      <el-button text @click="back">{{ $t('Back') }} · {{ $t('Counter orders') }}</el-button>
      <header class="detail-heading">
        <div><h1>{{ active.code }} · {{ active.storeName || active.storeUuid }}</h1><p>{{ active.name }}</p></div>
        <el-button type="primary" plain :aria-expanded="historyOpen" aria-controls="counter-order-history"
          @click="historyOpen = !historyOpen">{{ $t('Order history') }}</el-button>
      </header>
      <div class="workspace" :class="{ 'with-history': historyOpen }">
        <main class="main">
          <section class="panel receipt-section">
            <h2>{{ $t('Order receipts') }} <small>{{ $t('Newest first') }}</small></h2>
            <p v-if="historyError && !historyOpen" role="alert">{{ historyError }}
              <el-button text @click="loadHistory(1)">{{ $t('Retry') }}</el-button>
            </p>
            <div v-loading="historyLoading && !history.length" class="receipt-grid">
              <order-receipt v-for="row in receipts" :key="row.uuid" :order="row" :selected="row.uuid === selected"
                @click="openReceipt(row)" />
            </div>
            <el-empty v-if="!historyLoading && !historyError && !history.length" :description="$t('No matching orders')" />
          </section>
          <section v-if="selected" class="panel graph-section" v-loading="graphLoading">
            <h2>{{ $t('Merge relationships') }} <small>{{ $t('Source order → Target order') }}</small></h2>
            <p v-if="graphError" role="alert">{{ graphError }} <el-button text @click="selectOrder(selected)">{{ $t('Retry') }}</el-button></p>
            <template v-else-if="graph">
              <p v-if="graph.truncated" role="status">{{ $t('This graph is too large to show all related orders.') }}</p>
              <p v-else-if="!graph.edges.length">{{ $t('No merge links to display.') }}</p>
              <div ref="graphScroll" class="graph-scroll" tabindex="0" :aria-label="$t('Merge relationships')">
                <div class="graph-canvas" :style="{ width: `${layout.canvasWidth}px`, height: `${layout.canvasHeight}px` }">
                  <svg class="edges" :width="layout.canvasWidth" :height="layout.canvasHeight" aria-hidden="true">
                    <defs><marker id="counter-merge-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0 0L8 4L0 8Z" /></marker></defs>
                    <path v-for="(path, index) in layout.paths" :key="index" :d="path" marker-end="url(#counter-merge-arrow)" />
                  </svg>
                  <order-receipt v-for="node in graph.nodes" :key="node.uuid" class="graph-receipt" compact
                    :selected="node.uuid === selected" :order="node" :style="nodeStyle(node.uuid)" @click="openReceipt(node)" />
                </div>
              </div>
              <details v-if="graph.edges.length">
                <summary>{{ $t('Merge connections as text') }}</summary>
                <ul><li v-for="edge in graph.edges" :key="edge.source">#{{ orderIds.get(edge.source) }} → #{{ orderIds.get(edge.target) }}</li></ul>
              </details>
            </template>
          </section>
        </main>
        <aside v-if="historyOpen" id="counter-order-history" class="panel history" :aria-label="$t('Order history')">
          <div class="history-heading"><h2>{{ $t('Order history') }}</h2><el-button text @click="historyOpen = false">{{ $t('Close') }}</el-button></div>
          <details>
            <summary>{{ $t('Order filters') }}</summary>
            <order-filters :filters="historyFilters" @apply="applyHistoryFilters" />
          </details>
          <div class="history-scroll" v-loading="historyLoading">
            <p v-if="historyError" role="alert">{{ historyError }} <el-button text @click="loadHistory(history.length ? historyPage + 1 : 1)">{{ $t('Retry') }}</el-button></p>
            <section v-for="group in historyGroups" :key="group.key" class="history-group">
              <h3>
                <button type="button" class="history-folder" :aria-expanded="openFolders.has(group.key)"
                  :aria-controls="`counter-history-folder-${group.key || 'unassigned'}`" @click="toggleFolder(group.key)">
                  <el-icon aria-hidden="true"><ArrowDown v-if="openFolders.has(group.key)" /><ArrowRight v-else /></el-icon>
                  <el-icon aria-hidden="true"><FolderOpened v-if="openFolders.has(group.key)" /><Folder v-else /></el-icon>
                  <span>{{ group.startedAt ? `${$t('Counter session')} · ${date(group.startedAt)}` : $t('Orders without a session') }}</span>
                </button>
              </h3>
              <div v-show="openFolders.has(group.key)" :id="`counter-history-folder-${group.key || 'unassigned'}`" class="history-folder-items">
                <button v-for="row in group.orders" :key="row.uuid" type="button" class="history-row" :class="{ cancelled: row.status === 'cancelled' }"
                  :aria-pressed="selected === row.uuid" @click="selectOrder(row.uuid)">
                  <time :datetime="row.createdAt">{{ date(row.createdAt) }}</time>
                  <span>{{ $t(statusLabel(row.status)) }}</span>
                  <strong>{{ money(row) }}</strong>
                </button>
              </div>
            </section>
            <el-empty v-if="!history.length && !historyLoading && !historyError" :description="$t('No matching orders')" />
            <el-button v-if="historyMore && !historyError" text :disabled="historyLoading" @click="loadHistory(historyPage + 1)">{{ $t('Load more') }}</el-button>
          </div>
        </aside>
      </div>
    </template>

    <record-dialog v-model="dialogOpen" :title="$t('Order details')">
      <detail-admin v-if="dialogOrder && dialogOpen" :id="dialogOrder" :key="dialogOrder" :entity-conf="orderConfig.entity || 'Order'"
        :fields="detailFields" :title="$t('Order details')" :editable="false">
        <template #actions><router-link :to="{ name: 'OrderDetail', params: { id: dialogOrder } }">{{ $t('Open full order page') }}</router-link></template>
      </detail-admin>
    </record-dialog>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowDown, ArrowRight, Folder, FolderOpened } from '@element-plus/icons-vue'
import admin from '@/config'
import { getLocale, t } from '@/i18n'
import request from '@/utils/request'
import DetailAdmin from '@/easyadmin/ui/vue/DetailAdmin.vue'
import RecordDialog from '@/easyadmin/ui/vue/RecordDialog.vue'
import OrderFilters from './OrderFilters.vue'
import OrderReceipt from './OrderReceipt.vue'
import { counterListQuery, counterRouteUuid, createLatestRequest, endpoint, formatAmount, groupHistory, mergeLayout, orderParams, statusLabel } from './counter-orders.mjs'

const route = useRoute()
const router = useRouter()
const routeName = 'CounterOrders'
const orderConfig = admin.entities.Order
// This explorer is read-only; the existing full page retains refund actions.
const detailFields = orderConfig.detail.detail_display.filter(field => field?.property !== 'refundDetails')
const storeUuid = ref('')
const stores = ref([])
const recentStores = ref([])
const storeLoading = ref(false)
const storeError = ref('')
const storeOptions = computed(() => [...new Map([...recentStores.value, ...stores.value].map(row => [row.uuid, row])).values()])
const tabs = computed(() => [{ uuid: '', name: t('All stores') }, ...recentStores.value])
const filters = ref({})
const counterSearch = ref('')
const counters = ref([])
const counterPage = ref(1)
const countersMore = ref(false)
const counterLoading = ref(false)
const counterError = ref('')
const active = ref(null)
const activeLoading = ref(false)
const activeError = ref('')
const historyOpen = ref(false)
const history = ref([])
const historyGroups = computed(() => groupHistory(history.value))
const openFolders = ref(new Set())
const historyFilters = ref({})
const historyLoading = ref(false)
const historyError = ref('')
const historyPage = ref(1)
const historyMore = ref(false)
const selected = ref('')
const graph = ref(null)
const graphScroll = ref(null)
const graphLoading = ref(false)
const graphError = ref('')
const dialogOpen = ref(false)
const dialogOrder = ref('')
const summaries = computed(() => new Map((graph.value?.nodes || []).map(node => [node.uuid, node])))
const receipts = computed(() => {
  const rows = history.value.slice(0, 3)
  const current = history.value.find(row => row.uuid === selected.value)
  if (current && !rows.includes(current)) rows[2] = current
  return rows.map(row => summaries.value.get(row.uuid) || row)
})
const layout = computed(() => mergeLayout(graph.value?.nodes || [], graph.value?.edges || []))
const orderIds = computed(() => new Map((graph.value?.nodes || []).map(node => [node.uuid, node.id])))
const channels = Object.fromEntries(['stores', 'counters', 'active', 'history', 'graph'].map(key => [key, createLatestRequest()]))

function load(channel, path, params, loading, error, apply) {
  loading.value = true
  error.value = ''
  return channels[channel].run(async () => (await request.get(path, { params })).data, apply,
    () => { error.value = t('Unable to load counter order data.') }, () => { loading.value = false })
}

function searchStores(q = '') {
  return load('stores', `${endpoint}/stores`, { q }, storeLoading, storeError, data => {
    stores.value = data.items
    if (!q && !recentStores.value.length) recentStores.value = data.items.slice(0, 2)
  })
}
function refreshStoreOptions(open) { if (open) searchStores() }
function chooseStore(uuid) { pickStore(storeOptions.value.find(row => row.uuid === uuid)) }
function pickStore(store) {
  storeUuid.value = store?.uuid || ''
  if (store?.uuid && !recentStores.value.some(row => row.uuid === store.uuid)) recentStores.value = [...recentStores.value.slice(-2), store]
  loadCounters(1)
}
function loadCounters(page) {
  return load('counters', endpoint, { ...orderParams(filters.value), store: storeUuid.value, counter: counterSearch.value, page },
    counterLoading, counterError, data => {
      counters.value = data.items
      counterPage.value = page
      countersMore.value = data.hasNext
    })
}
function applyFilters(next) {
  filters.value = next
  loadCounters(1)
}
function openCounter(counter) {
  router.push({ name: routeName, query: { ...route.query, counter: counter.uuid } })
}
function back() {
  const target = { name: routeName, query: counterListQuery(route.query) }
  if (router.options.history.state.back === router.resolve(target).fullPath) router.back()
  else router.replace(target)
}
function resetActive() {
  channels.history.cancel()
  channels.graph.cancel()
  active.value = null
  historyOpen.value = false
  history.value = []
  openFolders.value.clear()
  selected.value = ''
  graph.value = null
  dialogOpen.value = false
}
watch(() => route.name === routeName ? route.query.counter : undefined, value => {
  channels.active.cancel()
  resetActive()
  activeLoading.value = false
  activeError.value = ''
  const uuid = counterRouteUuid(value)
  if (!uuid) return
  const show = counter => {
    active.value = counter
    historyFilters.value = { ...filters.value }
    loadHistory(1)
  }
  const cached = counters.value.find(row => row.uuid === uuid)
  if (cached) show(cached)
  else load('active', endpoint, { counter: uuid }, activeLoading, activeError, data => {
    const counter = data.items.find(row => row.uuid === uuid)
    if (counter) show(counter)
  })
}, { immediate: true })
function applyHistoryFilters(next) {
  historyFilters.value = next
  loadHistory(1)
}
function toggleFolder(key) {
  if (openFolders.value.has(key)) openFolders.value.delete(key)
  else openFolders.value.add(key)
}
function loadHistory(page) {
  if (page === 1) {
    channels.graph.cancel()
    history.value = []
    selected.value = ''
    openFolders.value.clear()
    graph.value = null
    historyMore.value = false
  }
  return load('history', `${endpoint}/${active.value.uuid}/orders`, { ...orderParams(historyFilters.value), page },
    historyLoading, historyError, data => {
      history.value = [...new Map([...history.value, ...data.items].map(row => [row.uuid, row])).values()]
      historyPage.value = page
      historyMore.value = data.hasNext
      if (page === 1 && history.value.length) selectOrder(history.value[0].uuid)
    })
}
function selectOrder(uuid) {
  selected.value = uuid
  const row = history.value.find(order => order.uuid === uuid)
  if (row) openFolders.value.add(row.sessionUuid || '')
  graph.value = null
  return load('graph', `${endpoint}/${active.value.uuid}/orders/${uuid}/graph`, {}, graphLoading, graphError, data => {
    graph.value = data
    nextTick(() => {
      const pane = graphScroll.value
      const node = layout.value.positions.get(uuid)
      if (selected.value !== uuid || !pane || !node) return
      pane.scrollLeft = Math.max(0, node.x + layout.value.width + 20 - pane.clientWidth)
      pane.scrollTop = Math.max(0, node.y + 110 - pane.clientHeight)
    })
  })
}
function openReceipt(row) {
  dialogOrder.value = row.uuid
  dialogOpen.value = true
}
function nodeStyle(uuid) {
  const { x, y } = layout.value.positions.get(uuid)
  return { left: `${x}px`, top: `${y}px`, width: `${layout.value.width}px` }
}
const date = value => new Date(value).toLocaleString(getLocale())
const money = row => formatAmount(row.totalAmount, row.currency, getLocale())
onMounted(() => { searchStores(); loadCounters(1) })
onBeforeUnmount(() => Object.values(channels).forEach(channel => channel.cancel()))
</script>

<style scoped>
.counter-orders { padding: clamp(12px, 2vw, 28px); color: var(--text-primary); }
h1 { margin: 4px 0 8px; font-size: 25px; }
h2 { margin: 0 0 12px; font-size: 17px; }
h2 small { margin-left: 8px; color: var(--text-secondary); font-weight: 400; }
p { color: var(--text-secondary); }
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 15px; }
.controls { display: grid; gap: 12px; margin: 16px 0; }
.store-bar, .detail-heading, .history-heading, .pager { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.store-bar nav { display: flex; flex-wrap: wrap; gap: 5px; }
.store-bar nav :deep(.el-button) { margin-left: 0; }
.store-bar :deep(.el-select) { width: min(100%, 260px); }
.counter-grid, .receipt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 185px), 1fr)); gap: 12px; }
.counter-card { display: grid; gap: 7px; text-align: left; color: inherit; cursor: pointer; }
.counter-card:hover { border-color: var(--el-color-primary); }
.counter-card strong { display: flex; justify-content: space-between; font-size: 18px; }
.counter-card small { color: var(--text-secondary); }
.pager { justify-content: center; margin-top: 14px; }
.detail-heading { margin: 10px 0 16px; }
.workspace { display: grid; gap: 14px; align-items: start; }
.with-history { grid-template-columns: minmax(0, 1fr) minmax(250px, 340px); }
.main { display: grid; gap: 14px; min-width: 0; }
.history { position: sticky; top: 8px; max-height: min(78vh, 850px); display: flex; flex-direction: column; min-height: 160px; }
.history-heading h2 { margin: 0; }
.history details { padding: 8px 0; max-height: 34vh; overflow: auto; }
summary { cursor: pointer; color: var(--text-secondary); margin: 6px 0; }
.history-scroll { overflow: auto; min-height: 65px; }
.history-group h3 { margin: 0; font-size: 12px; }
.history-folder { display: flex; align-items: center; gap: 6px; width: 100%; padding: 9px 7px; text-align: left; border: 0; border-radius: 5px; color: var(--text-secondary); background: var(--el-fill-color-light); cursor: pointer; }
.history-folder:hover, .history-folder[aria-expanded='true'] { color: var(--text-primary); background: var(--el-fill-color); }
.history-folder .el-icon { flex: none; }
.history-folder-items { margin: 3px 0 6px 15px; padding-left: 6px; border-left: 1px solid var(--border); }
.history-row { display: grid; align-items: center; grid-template-columns: minmax(0, 1fr) auto auto; gap: 8px; width: 100%; padding: 10px 7px; border: 0; border-bottom: 1px solid var(--border); background: var(--surface); color: inherit; cursor: pointer; text-align: left; font-size: 12px; }
.history-row.cancelled { color: var(--text-secondary); background: var(--el-fill-color-dark); }
.history-row[aria-pressed='true'] { box-shadow: inset 3px 0 var(--el-color-primary); outline: 1px solid var(--el-color-primary); outline-offset: -1px; }
.graph-scroll { overflow: auto; max-height: 65vh; border-radius: 8px; border: 1px solid var(--border); background: radial-gradient(var(--border) 1px, transparent 1px) 0 0 / 16px 16px; }
.graph-canvas { position: relative; }
.edges { position: absolute; pointer-events: none; color: var(--el-color-primary); }
.edges > path { fill: none; stroke: currentColor; stroke-width: 2; }
.edges marker path { fill: currentColor; }
.graph-receipt { position: absolute; }
@media (max-width: 1050px) {
  .with-history { grid-template-columns: minmax(0, 1fr); }
  .history { position: fixed; top: 64px; bottom: 10px; right: 10px; z-index: 9; width: min(340px, calc(100vw - 20px)); max-height: none; box-shadow: 0 10px 32px #0003; }
}
</style>
