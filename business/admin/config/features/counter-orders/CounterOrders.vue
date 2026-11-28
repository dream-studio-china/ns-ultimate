<template>
  <section class="counter-orders">
    <template v-if="route.query.counter !== undefined && !active">
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
         <button v-for="counter in counters" :key="counter.uuid" type="button" class="panel counter-card"
           :class="{ 'has-unpaid': counter.unpaidTotals?.length }" @click="openCounter(counter)">
           <strong>{{ counter.code }} <span aria-hidden="true">›</span></strong>
           <span>{{ counter.name }}</span>
           <small>{{ counter.storeName || counter.storeUuid }}<template v-if="!counter.enabled"> · {{ $t('Disabled') }}</template></small>
           <span v-if="counter.unpaidTotals?.length" class="counter-unpaid">
             {{ $t('Unpaid amount') }} · {{ counter.unpaidTotals.map(total => formatAmount(total.amount, total.currency, getLocale())).join(' / ') }}
           </span>
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
              <el-button text @click="loadHistory(historyRequestedPage)">{{ $t('Retry') }}</el-button>
            </p>
            <div v-loading="historyLoading && !history.length" class="receipt-grid">
              <order-receipt v-for="row in receipts" :key="row.uuid" :order="row" :selected="row.uuid === selected"
                @click="openReceipt(row)" />
            </div>
            <el-empty v-if="!historyLoading && !historyError && !history.length" :description="$t('No matching orders')" />
          </section>
          <section v-if="selected" class="panel graph-section" v-loading="graphLoading">
            <h2>{{ $t('Merge relationships') }} <small>{{ $t('Source orders above, merge results below') }}</small></h2>
            <p v-if="graphError" role="alert">{{ graphError }} <el-button text @click="selectOrder(selected)">{{ $t('Retry') }}</el-button></p>
            <template v-else-if="graph">
              <p v-if="graph.truncated" role="status">{{ $t('This graph is too large to show all related orders.') }}</p>
              <p v-else-if="!graph.edges.length">{{ $t('No merge links to display.') }}</p>
              <p v-if="graph.edges.length" class="graph-hint">{{ $t('Drag the canvas background to move the graph.') }}</p>
              <div v-if="graph.edges.length" ref="graphScroll" class="graph-scroll" :class="{ dragging: graphDragging }"
                tabindex="0" :aria-label="$t('Merge relationships')" @pointerdown="graphPan.start" @pointermove="graphPan.move"
                @pointerup="graphPan.end" @pointercancel="graphPan.end" @lostpointercapture="graphPan.end">
                <div class="graph-frame" :style="graphFrameStyle">
                  <div class="graph-canvas" :style="graphCanvasStyle">
                    <svg class="edges" :width="layout.canvasWidth" :height="layout.canvasHeight" aria-hidden="true">
                      <defs>
                        <marker v-for="tone in arrowTones" :id="`counter-merge-arrow-${tone}`" :key="tone"
                          class="counter-order-tone" :data-tone="tone" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
                          <path d="M0 0L8 4L0 8Z" />
                        </marker>
                      </defs>
                      <path v-for="(path, index) in layout.paths" :key="index" :d="path" class="counter-order-tone"
                        :data-tone="layout.pathTones[index]" :marker-end="`url(#counter-merge-arrow-${layout.pathTones[index]})`" />
                    </svg>
                    <order-receipt v-for="node in linkedReceipts" :key="node.uuid" class="graph-receipt counter-order-tone" compact
                      :data-tone="layout.chainTones.get(node.uuid)"
                      :selected="node.uuid === selected" :expanded="expandedItems.has(node.uuid)" :order="node"
                      :style="nodeStyle(node.uuid)" @click="openReceipt(node)">
                      <div v-if="node.itemCount > 1" v-show="expandedItems.has(node.uuid)" :id="`graph-items-${node.uuid}`" class="graph-item-list">
                        <order-items-detail v-if="expandedItems.has(node.uuid)" compact :order-uuid="node.uuid" :currency="node.currency" />
                      </div>
                      <button v-if="node.itemCount > 1" type="button" class="graph-items-toggle"
                        :aria-expanded="expandedItems.has(node.uuid)" :aria-controls="`graph-items-${node.uuid}`"
                        @click="toggleGraphItems(node.uuid)">
                        {{ expandedItems.has(node.uuid) ? $t('Hide order items') : $t('Show all order items') }} · {{ node.itemCount }}
                      </button>
                    </order-receipt>
                  </div>
                </div>
              </div>
              <div v-if="layout.standalone.length" class="standalone-receipts">
                <h3>{{ $t('Orders outside merges') }}</h3>
                <div class="receipt-grid">
                  <order-receipt v-for="node in layout.standalone" :key="node.uuid" :order="node"
                    :selected="node.uuid === selected" @click="openReceipt(node)" />
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
          <label class="history-grouping">
            <input v-model="groupBySession" type="checkbox">
            {{ $t('Group by counter session') }}
          </label>
          <div ref="historyScroll" class="history-scroll" v-loading="historyLoading">
            <p v-if="historyError" role="alert">{{ historyError }} <el-button text @click="loadHistory(historyRequestedPage)">{{ $t('Retry') }}</el-button></p>
            <section v-for="group in historyGroups" :key="group.key" class="history-group">
              <h3 v-if="groupBySession">
                <button type="button" class="history-folder" :aria-expanded="openFolders.has(group.key)"
                  :aria-controls="`counter-history-folder-${group.key || 'unassigned'}`" @click="toggleFolder(group.key)">
                  <el-icon aria-hidden="true"><ArrowDown v-if="openFolders.has(group.key)" /><ArrowRight v-else /></el-icon>
                  <el-icon aria-hidden="true"><FolderOpened v-if="openFolders.has(group.key)" /><Folder v-else /></el-icon>
                  <span>{{ group.startedAt ? `${$t('Counter session')} · ${date(group.startedAt)}` : $t('Orders without a session') }}</span>
                </button>
              </h3>
              <div v-show="!groupBySession || openFolders.has(group.key)"
                :id="groupBySession ? `counter-history-folder-${group.key || 'unassigned'}` : undefined"
                :class="{ 'history-folder-items': groupBySession }">
                <button v-for="row in group.orders" :key="row.uuid" type="button" class="history-row" :class="{ cancelled: row.status === 'cancelled' }"
                  :aria-pressed="selected === row.uuid" @click="selectOrder(row.uuid)">
                  <time :datetime="row.createdAt">{{ date(row.createdAt) }}</time>
                  <order-status-badge :status="row.status" />
                  <strong>{{ money(row) }}</strong>
                </button>
              </div>
            </section>
            <el-empty v-if="!history.length && !historyLoading && !historyError" :description="$t('No matching orders')" />
          </div>
          <nav v-if="historyPage > 1 || historyMore" class="pager history-pager" :aria-label="$t('Order history')">
            <el-button :disabled="historyLoading || historyPage === 1" @click="loadHistory(historyPage - 1)">{{ $t('Previous') }}</el-button>
            <span aria-live="polite">{{ historyPage }}</span>
            <el-button :disabled="historyLoading || !historyMore" @click="loadHistory(historyPage + 1)">{{ $t('Next') }}</el-button>
          </nav>
        </aside>
      </div>
    </template>

    <record-dialog v-model="dialogOpen" :title="$t('Order details')">
      <detail-admin v-if="dialogOrder && dialogOpen" :id="dialogOrder" :key="dialogOrder" :entity-conf="orderConfig.entity || 'Order'"
        :fields="detailFields" :title="$t('Order details')" :editable="false">
        <template #actions><router-link :to="{ name: 'OrderDetail', params: { id: dialogOrder } }">{{ $t('Open full order page') }}</router-link></template>
        <template #items="{ record }">
          <order-items-detail v-if="record.currency" :order-uuid="dialogOrder" :currency="record.currency" />
        </template>
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
import OrderItemsDetail from './OrderItemsDetail.vue'
import OrderStatusBadge from './OrderStatusBadge.vue'
import { counterRouteUuid, createGraphPan, createLatestRequest, endpoint, formatAmount, graphFitScale, groupHistory, mergeLayout, orderParams } from './counter-orders.mjs'

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
const historyOpen = ref(true)
const history = ref([])
const groupBySession = ref(false)
const historyGroups = computed(() => groupHistory(history.value, groupBySession.value))
const openFolders = ref(new Set())
watch(groupBySession, grouped => {
  if (grouped) {
    const row = history.value.find(order => order.uuid === selected.value)
    if (row) openFolders.value.add(row.sessionUuid || '')
  }
})
const historyFilters = ref({})
const historyLoading = ref(false)
const historyError = ref('')
const historyScroll = ref(null)
const historyPage = ref(1)
const historyRequestedPage = ref(1)
const historyMore = ref(false)
const selected = ref('')
const graph = ref(null)
const expandedItems = ref(new Set())
const graphScroll = ref(null)
const graphViewportWidth = ref(0)
const graphDragging = ref(false)
const graphPan = createGraphPan(value => { graphDragging.value = value })
watch(graphScroll, (pane, _, onCleanup) => {
  graphPan.reset()
  graphViewportWidth.value = pane?.clientWidth || 0
  if (!pane || typeof ResizeObserver === 'undefined') return
  const observer = new ResizeObserver(() => { graphViewportWidth.value = pane.clientWidth })
  observer.observe(pane)
  onCleanup(() => observer.disconnect())
}, { flush: 'post' })
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
const layout = computed(() => mergeLayout(graph.value?.nodes || [], graph.value?.edges || [], expandedItems.value))
const graphScale = computed(() => graphFitScale(graphViewportWidth.value, layout.value.canvasWidth))
const graphFrameStyle = computed(() => ({
  width: `${Math.floor(layout.value.canvasWidth * graphScale.value)}px`,
  height: `${Math.ceil(layout.value.canvasHeight * graphScale.value)}px`
}))
const graphCanvasStyle = computed(() => ({
  width: `${layout.value.canvasWidth}px`,
  height: `${layout.value.canvasHeight}px`,
  transform: `scale(${graphScale.value})`
}))
const linkedReceipts = computed(() => (graph.value?.nodes || []).filter(node => layout.value.positions.has(node.uuid)))
const arrowTones = computed(() => [...new Set(layout.value.pathTones)])
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
function resetActive() {
  channels.history.cancel()
  active.value = null
  historyOpen.value = true
  resetHistory()
  dialogOpen.value = false
}
function resetHistory() {
  history.value = []
  historyPage.value = 1
  historyMore.value = false
  historyLoading.value = false
  historyError.value = ''
  openFolders.value.clear()
  resetHistorySelection()
}
function resetHistorySelection() {
  channels.graph.cancel()
  selected.value = ''
  graphLoading.value = false
  graphError.value = ''
  graph.value = null
  expandedItems.value.clear()
}
watch(() => route.name === routeName ? route.query.counter : undefined, (value, previous) => {
  channels.active.cancel()
  resetActive()
  activeLoading.value = false
  activeError.value = ''
  const uuid = counterRouteUuid(value)
  if (!uuid) {
    if (value === undefined && counterRouteUuid(previous) && route.name === routeName) loadCounters(counterPage.value)
    return
  }
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
  resetHistory()
  loadHistory(1)
}
function toggleFolder(key) {
  if (openFolders.value.has(key)) openFolders.value.delete(key)
  else openFolders.value.add(key)
}
function loadHistory(page) {
  historyRequestedPage.value = page
  return load('history', `${endpoint}/${active.value.uuid}/orders`, { ...orderParams(historyFilters.value), terminalOnly: true, page },
    historyLoading, historyError, data => {
      history.value = data.items
      historyPage.value = page
      historyMore.value = data.hasNext
      if (historyScroll.value) historyScroll.value.scrollTop = 0
      resetHistorySelection()
      if (history.value.length) selectOrder(history.value[0].uuid)
    })
}
function selectOrder(uuid) {
  selected.value = uuid
  const row = history.value.find(order => order.uuid === uuid)
  if (row) openFolders.value.add(row.sessionUuid || '')
  graph.value = null
  return load('graph', `${endpoint}/${active.value.uuid}/orders/${uuid}/graph`, { chainOnly: true }, graphLoading, graphError, data => {
    graph.value = data
    nextTick(() => {
      const pane = graphScroll.value
      const node = layout.value.positions.get(uuid)
      if (selected.value !== uuid || !pane || !node) return
      const scale = graphFitScale(pane.clientWidth, layout.value.canvasWidth)
      pane.scrollLeft = Math.max(0, (node.x + layout.value.width + 20) * scale - pane.clientWidth)
      pane.scrollTop = Math.max(0, (node.y + node.height + 14) * scale - pane.clientHeight)
    })
  })
}
function openReceipt(row) {
  dialogOrder.value = row.uuid
  dialogOpen.value = true
}
function toggleGraphItems(uuid) {
  if (expandedItems.value.has(uuid)) expandedItems.value.delete(uuid)
  else expandedItems.value.add(uuid)
}
function nodeStyle(uuid) {
  const { x, y, height } = layout.value.positions.get(uuid)
  return { left: `${x}px`, top: `${y}px`, width: `${layout.value.width}px`, height: `${height}px` }
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
.counter-card { display: grid; gap: 7px; align-content: start; text-align: left; color: var(--text-secondary); background: var(--el-fill-color-light); cursor: pointer; }
.counter-card.has-unpaid { color: var(--text-primary); background: var(--el-color-primary-light-9); border-color: var(--el-color-primary); }
.counter-card:hover { box-shadow: inset 0 0 0 1px currentColor; }
.counter-card strong { display: flex; justify-content: space-between; font-size: 18px; }
.counter-card small { color: var(--text-secondary); }
.counter-unpaid { color: var(--el-color-primary); font-weight: 600; }
.pager { justify-content: center; margin-top: 14px; }
.detail-heading { margin: 10px 0 16px; }
.workspace { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-items: start; }
.with-history { grid-template-columns: minmax(0, 1fr) minmax(250px, 340px); }
.main { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; min-width: 0; }
.history { position: sticky; top: 8px; max-height: min(78vh, 850px); display: flex; flex-direction: column; min-height: 160px; }
.history-heading h2 { margin: 0; }
.history details { padding: 8px 0; max-height: 34vh; overflow: auto; }
.history-grouping { display: flex; align-items: center; gap: 6px; padding: 0 0 8px; font-size: 13px; color: var(--text-secondary); cursor: pointer; }
summary { cursor: pointer; color: var(--text-secondary); margin: 6px 0; }
.history-scroll { overflow: auto; min-height: 65px; }
.history-pager { flex-shrink: 0; margin-top: 8px; }
.history-group h3 { margin: 0; font-size: 12px; }
.history-folder { display: flex; align-items: center; gap: 6px; width: 100%; padding: 9px 7px; text-align: left; border: 0; border-radius: 5px; color: var(--text-secondary); background: var(--el-fill-color-light); cursor: pointer; }
.history-folder:hover, .history-folder[aria-expanded='true'] { color: var(--text-primary); background: var(--el-fill-color); }
.history-folder .el-icon { flex: none; }
.history-folder-items { margin: 3px 0 6px 15px; padding-left: 6px; border-left: 1px solid var(--border); }
.history-row { display: grid; align-items: center; grid-template-columns: minmax(0, 1fr) auto auto; gap: 8px; width: 100%; padding: 10px 7px; border: 0; border-bottom: 1px solid var(--border); background: var(--surface); color: inherit; cursor: pointer; text-align: left; font-size: 12px; }
.history-row.cancelled { color: var(--text-secondary); background: var(--el-fill-color-dark); }
.history-row[aria-pressed='true'] { box-shadow: inset 3px 0 var(--el-color-primary); outline: 1px solid var(--el-color-primary); outline-offset: -1px; }
.graph-hint { margin: 0 0 8px; font-size: 12px; }
.graph-scroll { overflow: auto; scrollbar-gutter: stable; max-width: 100%; max-height: 65vh; contain: inline-size; border-radius: 8px; border: 1px solid var(--border); background: radial-gradient(var(--border) 1px, transparent 1px) 0 0 / 16px 16px; cursor: grab; user-select: none; }
.graph-scroll.dragging, .graph-scroll.dragging :deep(button) { cursor: grabbing; }
.graph-frame { position: relative; overflow: clip; margin-inline: auto; }
.graph-canvas { position: absolute; top: 0; left: 0; transform-origin: top left; }
.standalone-receipts h3 { font-size: 13px; }
.edges { position: absolute; pointer-events: none; }
.edges > path { fill: none; stroke: var(--order-line); stroke-width: 2; }
.edges marker path { fill: var(--order-line); }
.graph-receipt { position: absolute; grid-template-rows: 94px 26px minmax(0, 1fr); }
.graph-receipt:not(:has(.graph-items-toggle)) { grid-template-rows: 1fr; }
.graph-receipt:has(.graph-items-toggle[aria-expanded='true']) { grid-template-rows: 62px minmax(0, 1fr) 26px; }
.graph-items-toggle { padding: 4px; border: 0; color: var(--el-color-primary); background: transparent; cursor: pointer; font-size: 12px; }
.graph-items-toggle:focus-visible { outline-offset: -2px; }
.graph-item-list { overflow: auto; margin-inline: 7px; border-top: 1px dashed var(--border); cursor: auto; user-select: text; }
@media (max-width: 1050px) {
  .with-history { grid-template-columns: minmax(0, 1fr); }
  .history { position: static; max-height: 60vh; }
}
</style>
