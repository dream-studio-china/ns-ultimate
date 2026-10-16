<template>
  <div v-if="visible" class="store-workspace">
    <div class="store-workspace__header">
      <div>
        <p class="store-workspace__eyebrow">{{ $t('Store performance dashboard') }}</p>
        <h1>{{ pageTitle }}</h1>
      </div>
      <div class="store-workspace__actions">
        <el-select
          v-if="storeOptions.length > 1"
          v-model="selectedStoreUuid"
          class="store-workspace__store-select"
          :placeholder="$t('Select store')"
        >
          <el-option
            v-for="store in storeOptions"
            :key="store.uuid"
            :label="store.name"
            :value="store.uuid"
          />
        </el-select>
        <el-button :loading="loading" icon="el-icon-refresh" @click="loadPage">
          {{ $t('Refresh') }}
        </el-button>
      </div>
    </div>

    <template v-if="isOverview">
      <div class="store-workspace__metrics">
        <article class="store-workspace__metric store-workspace__metric--blue">
          <span>{{ $t('Loaded orders') }}</span>
          <strong>{{ orders.length }}</strong>
          <small>{{ $t('Recent orders loaded for this store') }}</small>
        </article>
        <article class="store-workspace__metric store-workspace__metric--green">
          <span>{{ $t('Recent order amount') }}</span>
          <strong>{{ formatMoney(recentOrderAmount) }}</strong>
          <small>{{ $t('Calculated from the loaded order page') }}</small>
        </article>
        <article class="store-workspace__metric store-workspace__metric--amber">
          <span>{{ $t('Store products') }}</span>
          <strong>{{ products.length }}</strong>
          <small>{{ $t('Current page') }}</small>
        </article>
        <article class="store-workspace__metric store-workspace__metric--violet">
          <span>{{ $t('Enabled counters') }}</span>
          <strong>{{ enabledCounterCount }}</strong>
          <small>{{ $t('Current page') }}</small>
        </article>
      </div>

      <section class="store-workspace__panel">
        <div class="store-workspace__panel-heading">
          <div>
            <h2>{{ $t('Recent orders') }}</h2>
            <p>{{ $t('Latest orders for the selected store') }}</p>
          </div>
          <el-tag v-if="selectedStoreName" effect="plain">{{ selectedStoreName }}</el-tag>
        </div>
        <el-table v-if="orders.length" v-loading="loading" :data="orders" stripe>
          <el-table-column prop="tradeOrderUuid" :label="$t('Order number')" min-width="210" show-overflow-tooltip />
          <el-table-column :label="$t('Amount')" width="140" align="right">
            <template #default="{ row }">{{ formatMoney(orderAmount(row)) }}</template>
          </el-table-column>
          <el-table-column :label="$t('Status')" width="170">
            <template #default="{ row }">
              <el-tag size="small" :type="statusTagType(row.operationalStatus)">
                {{ statusLabel(row.operationalStatus) }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="createdAt" :label="$t('Created at')" min-width="180" />
        </el-table>
        <el-empty v-else-if="!loading" :description="$t('No recent orders')" />
      </section>
    </template>

    <section v-else-if="isProducts" class="store-workspace__panel">
      <div class="store-workspace__panel-heading">
        <div>
          <h2>{{ $t('Store products') }}</h2>
          <p>{{ $t('Store product list description') }}</p>
        </div>
        <el-tag v-if="selectedStoreName" effect="plain">{{ selectedStoreName }}</el-tag>
      </div>
      <el-table v-if="products.length" v-loading="loading" :data="products" stripe>
        <el-table-column :label="$t('Cover')" width="100" align="center">
          <template #default="{ row }">
            <img v-if="coverUrl(row)" class="store-workspace__cover" :src="coverUrl(row)" alt="">
            <span v-else class="store-workspace__no-cover">—</span>
          </template>
        </el-table-column>
        <el-table-column prop="name" :label="$t('Product')" min-width="180" />
        <el-table-column :label="$t('Product category')" min-width="150">
          <template #default="{ row }">{{ categoryName(row) }}</template>
        </el-table-column>
        <el-table-column :label="$t('Status')" width="130">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status === 'active' ? 'success' : 'info'">
              {{ row.status === 'active' ? $t('Active') : $t('Inactive') }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="createdAt" :label="$t('Created at')" min-width="180" />
      </el-table>
      <el-empty v-else-if="!loading" :description="$t('No products')" />
    </section>

    <section v-else-if="isCounters" class="store-workspace__panel">
      <div class="store-workspace__panel-heading">
        <div>
          <h2>{{ $t('Store counters') }}</h2>
          <p>{{ $t('Store counter list description') }}</p>
        </div>
        <el-tag v-if="selectedStoreName" effect="plain">{{ selectedStoreName }}</el-tag>
      </div>
      <el-table v-if="counters.length" v-loading="loading" :data="counters" stripe>
        <el-table-column prop="sort" :label="$t('Sort')" width="100" />
        <el-table-column prop="code" :label="$t('Counter code')" min-width="150" />
        <el-table-column prop="name" :label="$t('Counter number')" min-width="180" />
        <el-table-column prop="capacity" :label="$t('Capacity')" width="130" />
        <el-table-column :label="$t('Status')" width="130">
          <template #default="{ row }">
            <el-tag size="small" :type="row.enabled ? 'success' : 'info'">
              {{ row.enabled ? $t('Enabled') : $t('Disabled') }}
            </el-tag>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-else-if="!loading" :description="$t('No counters')" />
    </section>

    <section v-else-if="isOrders" class="store-workspace__panel">
      <div class="store-workspace__panel-heading">
        <div>
          <h2>{{ $t('Store orders') }}</h2>
          <p>{{ $t('Store order list description') }}</p>
        </div>
        <el-tag v-if="selectedStoreName" effect="plain">{{ selectedStoreName }}</el-tag>
      </div>
      <el-table v-if="orders.length" v-loading="loading" :data="orders" stripe>
        <el-table-column prop="tradeOrderUuid" :label="$t('Order number')" min-width="210" show-overflow-tooltip />
        <el-table-column :label="$t('Amount')" width="140" align="right">
          <template #default="{ row }">{{ formatMoney(orderAmount(row)) }}</template>
        </el-table-column>
        <el-table-column :label="$t('Status')" width="170">
          <template #default="{ row }">
            <el-tag size="small" :type="statusTagType(row.operationalStatus)">
              {{ statusLabel(row.operationalStatus) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="createdAt" :label="$t('Created at')" min-width="180" />
      </el-table>
      <el-empty v-else-if="!loading" :description="$t('No recent orders')" />
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import router from '@/router'
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'

const loading = ref(false)
const scopes = ref({})
const storeOptions = ref([])
const selectedStoreUuid = ref('')
const products = ref([])
const counters = ref([])
const orders = ref([])
const pageName = computed(() => String(router.currentRoute.value.name || ''))
const isOverview = computed(() => pageName.value === 'StorePerformanceOverview')
const isProducts = computed(() => pageName.value === 'StoreWorkspaceProducts')
const isCounters = computed(() => pageName.value === 'StoreWorkspaceCounters')
const isOrders = computed(() => pageName.value === 'StoreWorkspaceOrders')
const isEmployees = computed(() => pageName.value === 'StoreWorkspaceEmployees')
const pageTitle = computed(() => {
  if (isProducts.value) return t('Store products')
  if (isCounters.value) return t('Store counters')
  if (isOrders.value) return t('Store orders')
  if (isEmployees.value) return t('Store employees')
  return t('Performance overview')
})
const relevantPermissions = computed(() => {
  if (isProducts.value) return ['store:product:read']
  if (isCounters.value) return ['store:counter:read']
  if (isOrders.value) return ['store:order:read']
  if (isEmployees.value) return ['store:membership:read']
  return ['store:order:read', 'store:product:read', 'store:counter:read']
})
const availableStoreUuids = computed(() => [...new Set(relevantPermissions.value.flatMap(permission => scopes.value[permission] || []))])
const selectedStoreName = computed(() => storeOptions.value.find(store => store.uuid === selectedStoreUuid.value)?.name || selectedStoreUuid.value)
const visible = computed(() => {
  if (isEmployees.value) return false
  return availableStoreUuids.value.length > 0 && availableStoreUuids.value.includes(selectedStoreUuid.value)
})
const recentOrderAmount = computed(() => orders.value.reduce((sum, order) => {
  if (['rejected', 'cancelled'].includes(order?.operationalStatus)) return sum
  return sum + orderAmount(order)
}, 0))
const enabledCounterCount = computed(() => counters.value.filter(counter => counter?.enabled === true).length)

function responseData(response) {
  return response?.data ?? response
}

function responseObject(response) {
  const value = responseData(response)
  return value?.data ?? value
}

function responseList(response) {
  const value = responseData(response)
  if (Array.isArray(value)) return value
  if (Array.isArray(value?.data)) return value.data
  if (Array.isArray(value?.data?.data)) return value.data.data
  return []
}

function pageParams() {
  return { page: 1, limit: 100 }
}

function allowedFor(permission, storeUuid = selectedStoreUuid.value) {
  return (scopes.value[permission] || []).includes(storeUuid)
}

async function loadStoreOptions(storeUuids) {
  const options = await Promise.all(storeUuids.map(async uuid => {
    try {
      const response = await request.get(apiPath(API_PREFIX, `public/stores/${uuid}`))
      const store = responseObject(response)
      return { uuid, name: store?.name || uuid }
    } catch (error) {
      return { uuid, name: uuid }
    }
  }))
  storeOptions.value = options
}

async function loadAuthorization() {
  const response = await request.get(apiPath(API_PREFIX, 'app/authorization/me'))
  const data = responseObject(response)
  scopes.value = data?.storeScopes && typeof data.storeScopes === 'object' ? data.storeScopes : {}
  const union = [...new Set(Object.values(scopes.value).flatMap(value => Array.isArray(value) ? value : []))]
  await loadStoreOptions(union)

  if (!availableStoreUuids.value.includes(selectedStoreUuid.value)) {
    selectedStoreUuid.value = availableStoreUuids.value[0] || ''
  }
}

async function fetchScoped(resource, permission) {
  if (!selectedStoreUuid.value || !allowedFor(permission)) return []
  const response = await request.get(apiPath(API_PREFIX, `store/${selectedStoreUuid.value}/${resource}`), {
    params: { ...pageParams(), '@order': 'entity.createdAt|DESC' }
  })
  return responseList(response)
}

async function loadPageData() {
  products.value = []
  counters.value = []
  orders.value = []

  if (!selectedStoreUuid.value || isEmployees.value) return
  if (isProducts.value) {
    products.value = await fetchScoped('products', 'store:product:read')
    return
  }
  if (isCounters.value) {
    counters.value = await fetchScoped('counters', 'store:counter:read')
    return
  }
  if (isOrders.value) {
    orders.value = await fetchScoped('orders', 'store:order:read')
    return
  }

  const requests = []
  if (allowedFor('store:order:read')) requests.push(fetchScoped('orders', 'store:order:read').then(rows => { orders.value = rows }))
  if (allowedFor('store:product:read')) requests.push(fetchScoped('products', 'store:product:read').then(rows => { products.value = rows }))
  if (allowedFor('store:counter:read')) requests.push(fetchScoped('counters', 'store:counter:read').then(rows => { counters.value = rows }))
  await Promise.all(requests)
}

async function loadPage() {
  loading.value = true
  try {
    await loadAuthorization()
    await loadPageData()
  } catch (error) {
    products.value = []
    counters.value = []
    orders.value = []
  } finally {
    loading.value = false
  }
}

function orderAmount(order) {
  const floatAmount = Number(order?.totalAmountAsFloat)
  if (Number.isFinite(floatAmount)) return floatAmount
  const amount = Number(order?.totalAmount)
  return Number.isFinite(amount) ? amount / 100 : 0
}

function formatMoney(amount) {
  return new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'CNY' }).format(Number(amount) || 0)
}

function coverUrl(product) {
  const cover = product?.metadata?.cover
  if (typeof cover === 'string') return cover
  return cover?.url || cover?.src || ''
}

function categoryName(product) {
  const category = product?.category
  if (typeof category === 'string') return category
  return category?.name || '—'
}

function statusLabel(status) {
  const key = `Store order status ${status || 'unknown'}`
  return t(key)
}

function statusTagType(status) {
  if (['fulfilled', 'verified', 'accepted'].includes(status)) return 'success'
  if (['rejected', 'cancelled'].includes(status)) return 'danger'
  if (['fulfilling', 'fulfillment_pending'].includes(status)) return 'warning'
  return 'info'
}

watch(() => router.currentRoute.value.name, loadPage)
watch(selectedStoreUuid, async(current, previous) => {
  if (!current || current === previous || loading.value) return
  loading.value = true
  try {
    await loadPageData()
  } catch (error) {
    products.value = []
    counters.value = []
    orders.value = []
  } finally {
    loading.value = false
  }
})

onMounted(loadPage)
</script>

<style scoped>
.store-workspace { min-height: 100%; padding: 26px; background: #f5f7fb; color: #24334b; }
.store-workspace__header, .store-workspace__panel-heading { display: flex; align-items: center; justify-content: space-between; gap: 18px; }
.store-workspace__header { margin-bottom: 20px; }
.store-workspace__eyebrow { margin: 0 0 6px; color: #71839e; font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
.store-workspace h1 { margin: 0; font-size: 24px; }
.store-workspace__actions { display: flex; align-items: center; gap: 10px; }
.store-workspace__store-select { width: 220px; }
.store-workspace__metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 20px; }
.store-workspace__metric, .store-workspace__panel { border: 1px solid #e6ebf2; border-radius: 12px; background: #fff; box-shadow: 0 8px 22px rgba(37, 57, 87, .04); }
.store-workspace__metric { display: flex; min-height: 125px; flex-direction: column; justify-content: center; padding: 20px; }
.store-workspace__metric span { color: #718096; font-size: 13px; }
.store-workspace__metric strong { margin: 10px 0 6px; color: #23334b; font-size: 26px; }
.store-workspace__metric small { color: #9aa7b8; font-size: 11px; }
.store-workspace__metric--blue { border-top: 3px solid #4d7fd0; }
.store-workspace__metric--green { border-top: 3px solid #36a37b; }
.store-workspace__metric--amber { border-top: 3px solid #d99534; }
.store-workspace__metric--violet { border-top: 3px solid #8b6bd1; }
.store-workspace__panel { padding: 20px; }
.store-workspace__panel-heading { margin-bottom: 16px; }
.store-workspace__panel-heading h2 { margin: 0 0 5px; font-size: 17px; }
.store-workspace__panel-heading p { margin: 0; color: #8a98aa; font-size: 12px; }
.store-workspace__cover { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; }
.store-workspace__no-cover { color: #b6bfcb; }
@media (max-width: 1100px) { .store-workspace__metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) { .store-workspace { padding: 16px; } .store-workspace__header { align-items: flex-start; flex-direction: column; } .store-workspace__actions { width: 100%; } .store-workspace__metrics { grid-template-columns: 1fr; gap: 10px; } .store-workspace__metric { min-height: 100px; } }
</style>
