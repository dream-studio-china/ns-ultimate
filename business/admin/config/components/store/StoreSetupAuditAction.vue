<template>
  <span class="store-setup-audit-action">
    <el-button
      size="default"
      type="primary"
      plain
      icon="el-icon-search"
      @click="open"
    >
      {{ $t('Check store setup') }}
    </el-button>

    <el-dialog
      v-model="visible"
      class="store-setup-audit-dialog"
      :title="$t('Store setup audit')"
      width="min(1080px, 96vw)"
      append-to-body
      align-center
      destroy-on-close
      @closed="reset"
    >
      <div class="store-setup-audit">
        <el-alert
          :title="$t('Store setup audit help')"
          type="info"
          :closable="false"
          show-icon
        />

        <div class="store-setup-audit__toolbar">
          <el-select
            v-model="selectedStoreUuid"
            class="store-setup-audit__store"
            filterable
            :loading="loadingStores"
            :disabled="loadingStores || loadingAudit || !stores.length"
            :placeholder="$t('Select store')"
            @change="runAudit"
          >
            <el-option
              v-for="store in stores"
              :key="store.uuid"
              :value="store.uuid"
              :label="storeLabel(store)"
            />
          </el-select>
          <el-button
            icon="el-icon-refresh"
            :loading="loadingAudit"
            :disabled="loadingStores || !selectedStoreUuid"
            @click="runAudit"
          >
            {{ $t('Refresh audit') }}
          </el-button>
        </div>

        <el-alert
          v-if="error"
          :title="error"
          type="error"
          :closable="false"
          show-icon
        />

        <div v-if="audit" class="store-setup-audit__result">
          <el-alert
            :title="overallTitle"
            :type="overallAlertType"
            :closable="false"
            show-icon
          />

          <div class="store-setup-audit__summary" :aria-label="$t('Audit summary')">
            <div class="store-setup-audit__summary-item store-setup-audit__summary-item--complete">
              <strong>{{ audit.summary.complete }}</strong>
              <span>{{ $t('Configured') }}</span>
            </div>
            <div class="store-setup-audit__summary-item store-setup-audit__summary-item--action">
              <strong>{{ audit.summary.action }}</strong>
              <span>{{ $t('Needs attention') }}</span>
            </div>
            <div class="store-setup-audit__summary-item store-setup-audit__summary-item--manual">
              <strong>{{ audit.summary.manual }}</strong>
              <span>{{ $t('Manual confirmation') }}</span>
            </div>
            <div class="store-setup-audit__summary-item store-setup-audit__summary-item--failed">
              <strong>{{ audit.summary.failed }}</strong>
              <span>{{ $t('Query failed') }}</span>
            </div>
          </div>

          <ol class="store-setup-audit__steps">
            <li v-for="(step, index) in audit.steps" :key="step.key" class="store-setup-audit__step">
              <div class="store-setup-audit__step-number" aria-hidden="true">{{ index + 1 }}</div>
              <div class="store-setup-audit__step-body">
                <header class="store-setup-audit__step-header">
                  <h3>{{ $t(stepTitle(step.key)) }}</h3>
                  <el-tag :type="statusTagType(step.status)" effect="plain">{{ statusLabel(step.status) }}</el-tag>
                </header>

                <div class="store-setup-audit__evidence">
                  <span v-for="item in step.evidence" :key="item.labelKey" class="store-setup-audit__evidence-item">
                    {{ $t(item.labelKey) }}: <strong>{{ item.valueKey ? $t(item.valueKey) : item.value }}</strong>
                  </span>
                </div>

                <ul v-if="step.issues.length" class="store-setup-audit__issues">
                  <li
                    v-for="item in step.issues"
                    :key="`${item.messageKey}-${item.args.join('-')}`"
                    :class="`store-setup-audit__issue--${item.level}`"
                  >
                    <span class="store-setup-audit__issue-dot" aria-hidden="true" />
                    <span>{{ translateIssue(item) }}</span>
                  </li>
                </ul>

                <p v-if="step.failures.length" class="store-setup-audit__failure">
                  {{ $t('Some audit data could not be queried: {0}', failedSourceLabels(step.failures)) }}
                </p>
              </div>
            </li>
          </ol>
        </div>

        <el-empty
          v-else-if="!loadingStores && !loadingAudit && !error"
          :description="$t('No audit result')"
        />

        <div v-if="loadingStores || loadingAudit" class="store-setup-audit__loading" aria-live="polite">
          <el-icon class="is-loading"><Loading /></el-icon>
          <span>{{ loadingStores ? $t('Loading stores') : $t('Checking store configuration') }}</span>
        </div>
      </div>

      <template #footer>
        <el-button type="primary" :disabled="loadingAudit" @click="visible = false">{{ $t('Close') }}</el-button>
      </template>
    </el-dialog>
  </span>
</template>

<script>
import { Loading } from '@element-plus/icons-vue'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'
import request from '@/utils/request'
import { fetchAllPages } from '../catalog/catalog-copy.mjs'
import { auditStoreSetup, STORE_SETUP_AUDIT_STATUSES } from './store-setup-audit.mjs'
import { storeMembersPath } from './store-members.mjs'
import { STORE_ONBOARDING_STEPS } from './store-onboarding-guide.mjs'

const storesEndpoint = apiPath(API_PREFIX, 'manage/stores')
const assignmentsEndpoint = apiPath(API_PREFIX, 'manage/assignments')
const categoriesEndpoint = apiPath(API_PREFIX, 'manage/product-categories')
const productsEndpoint = apiPath(API_PREFIX, 'manage/products')
const printerAccountsEndpoint = apiPath(API_PREFIX, 'manage/printer/accounts')
const printerDevicesEndpoint = apiPath(API_PREFIX, 'manage/printer/devices')
const printerTemplatesEndpoint = apiPath(API_PREFIX, 'manage/printer/templates')
const printerRulesEndpoint = apiPath(API_PREFIX, 'manage/printer/order-rules')
const huifuSettingsEndpoint = apiPath(API_PREFIX, 'manage/store-huifu-settings')
const huifuGrantsEndpoint = apiPath(API_PREFIX, 'manage/huifu-merchant-store-grants')
const huifuMerchantsEndpoint = apiPath(API_PREFIX, 'manage/huifu-merchants')
const huifuProfilesEndpoint = apiPath(API_PREFIX, 'manage/huifu-provider-profiles')
const huifuPosDevicesEndpoint = apiPath(API_PREFIX, 'manage/huifu-pos-devices')
const wechatSettingsEndpoint = apiPath(API_PREFIX, 'manage/store-wechat-settings')
const wechatApplicationsEndpoint = apiPath(API_PREFIX, 'manage/wechat-applications')
const wechatMerchantsEndpoint = apiPath(API_PREFIX, 'manage/wechat-merchants')

const SOURCE_LABELS = Object.freeze({
  members: 'Audit source members',
  assignments: 'Audit source assignments',
  categories: 'Audit source product categories',
  products: 'Audit source products',
  printerAccounts: 'Audit source printer accounts',
  printerDevices: 'Audit source printer devices',
  printerTemplates: 'Audit source printer templates',
  printerRules: 'Audit source print rules',
  huifuSettings: 'Audit source Huifu settings',
  huifuGrants: 'Audit source Huifu grants',
  huifuMerchants: 'Audit source Huifu merchants',
  huifuProfiles: 'Audit source Huifu provider profiles',
  huifuPosDevices: 'Audit source Huifu POS devices',
  wechatSettings: 'Audit source WeChat settings',
  wechatApplications: 'Audit source WeChat applications',
  wechatMerchants: 'Audit source WeChat merchants'
})

export default {
  name: 'StoreSetupAuditAction',
  components: { Loading },
  props: {
    record: { type: Object, default: () => ({}) }
  },
  data() {
    return {
      visible: false,
      stores: [],
      selectedStoreUuid: '',
      loadingStores: false,
      loadingAudit: false,
      error: '',
      audit: null,
      requestId: 0
    }
  },
  computed: {
    selectedStore() {
      return this.stores.find(store => store.uuid === this.selectedStoreUuid) || null
    },
    overallAlertType() {
      return {
        [STORE_SETUP_AUDIT_STATUSES.complete]: 'success',
        [STORE_SETUP_AUDIT_STATUSES.action]: 'error',
        [STORE_SETUP_AUDIT_STATUSES.manual]: 'warning',
        [STORE_SETUP_AUDIT_STATUSES.failed]: 'error'
      }[this.audit?.overallStatus] || 'info'
    },
    overallTitle() {
      return {
        [STORE_SETUP_AUDIT_STATUSES.complete]: t('All store setup checks passed'),
        [STORE_SETUP_AUDIT_STATUSES.action]: t('Store setup has items that need attention'),
        [STORE_SETUP_AUDIT_STATUSES.manual]: t('Store setup is configured with items to confirm manually'),
        [STORE_SETUP_AUDIT_STATUSES.failed]: t('Some store setup checks could not be completed')
      }[this.audit?.overallStatus] || ''
    }
  },
  methods: {
    async open() {
      this.visible = true
      this.error = ''
      if (this.loadingStores || this.loadingAudit) return
      if (!this.stores.length) await this.loadStores()
      if (this.stores.length && !this.selectedStoreUuid) this.selectedStoreUuid = this.stores[0].uuid
      if (this.selectedStoreUuid) await this.runAudit()
    },
    async loadStores() {
      this.loadingStores = true
      this.error = ''
      try {
        this.stores = (await fetchAllPages(request.get, storesEndpoint, {
          '@order': 'entity.name|ASC'
        })).filter(store => store?.uuid)
        const preferred = this.record?.uuid
        this.selectedStoreUuid = this.stores.some(store => store.uuid === preferred)
          ? preferred
          : this.stores[0]?.uuid || ''
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Failed to load stores')
      } finally {
        this.loadingStores = false
      }
    },
    async runAudit() {
      const store = this.selectedStore
      if (!store || this.loadingAudit) return

      const requestId = ++this.requestId
      this.loadingAudit = true
      this.error = ''
      this.audit = null
      try {
        const storeUuid = store.uuid
        const storeFilter = `entity.getStore().getUuid() == "${storeUuid}"`
        const sources = [
          ['members', storeMembersPath(API_PREFIX, storeUuid), {}],
          ['assignments', assignmentsEndpoint, { scopeType: 'store', scopeUuid: storeUuid }],
          ['categories', categoriesEndpoint, { '@order': 'entity.sortOrder|ASC,entity.name|ASC' }],
          ['products', productsEndpoint, {
            '@filter': storeFilter,
            '@expands': 'specifications',
            '@order': 'entity.sortOrder|ASC,entity.name|ASC'
          }],
          ['printerAccounts', printerAccountsEndpoint, { '@order': 'entity.name|ASC' }],
          ['printerDevices', printerDevicesEndpoint, { '@order': 'entity.name|ASC' }],
          ['printerTemplates', printerTemplatesEndpoint, { '@order': 'entity.name|ASC' }],
          ['printerRules', printerRulesEndpoint, { '@order': 'entity.name|ASC' }],
          ['huifuSettings', huifuSettingsEndpoint, {}],
          ['huifuGrants', huifuGrantsEndpoint, {}],
          ['huifuMerchants', huifuMerchantsEndpoint, { '@order': 'entity.name|ASC' }],
          ['huifuProfiles', huifuProfilesEndpoint, { '@order': 'entity.name|ASC' }],
          ['huifuPosDevices', huifuPosDevicesEndpoint, {}],
          ['wechatSettings', wechatSettingsEndpoint, {}],
          ['wechatApplications', wechatApplicationsEndpoint, { '@order': 'entity.name|ASC' }],
          ['wechatMerchants', wechatMerchantsEndpoint, { '@order': 'entity.name|ASC' }]
        ]
        const results = await Promise.all(sources.map(async ([key, path, params]) => {
          try {
            return { key, data: await fetchAllPages(request.get, path, params), failed: false }
          } catch (cause) {
            return { key, data: [], failed: true, message: this.failureMessage(cause, 'Query failed') }
          }
        }))
        if (requestId !== this.requestId) return

        const data = {}
        const failures = {}
        for (const result of results) {
          data[result.key] = result.data
          if (result.failed) failures[result.key] = result.message
        }
        this.audit = auditStoreSetup({ store, data, failures })
      } catch (cause) {
        if (requestId === this.requestId) this.error = this.failureMessage(cause, 'Failed to check store setup')
      } finally {
        if (requestId === this.requestId) this.loadingAudit = false
      }
    },
    stepTitle(key) {
      return STORE_ONBOARDING_STEPS.find(step => step.key === key)?.titleKey || key
    },
    statusLabel(status) {
      return {
        [STORE_SETUP_AUDIT_STATUSES.complete]: t('Configured'),
        [STORE_SETUP_AUDIT_STATUSES.action]: t('Needs attention'),
        [STORE_SETUP_AUDIT_STATUSES.manual]: t('Manual confirmation'),
        [STORE_SETUP_AUDIT_STATUSES.failed]: t('Query failed')
      }[status] || status
    },
    statusTagType(status) {
      return {
        [STORE_SETUP_AUDIT_STATUSES.complete]: 'success',
        [STORE_SETUP_AUDIT_STATUSES.action]: 'danger',
        [STORE_SETUP_AUDIT_STATUSES.manual]: 'warning',
        [STORE_SETUP_AUDIT_STATUSES.failed]: 'info'
      }[status] || 'info'
    },
    translateIssue(item) {
      return t(item.messageKey, ...item.args)
    },
    failedSourceLabels(keys) {
      return keys.map(key => t(SOURCE_LABELS[key] || 'Query failed')).join(', ')
    },
    storeLabel(store) {
      const code = String(store?.code || '').trim()
      const name = String(store?.name || store?.__toString || store?.uuid || '').trim()
      return code ? `${name} · ${code}` : name
    },
    failureMessage(cause, fallback) {
      return cause?.response?.data?.message ||
        cause?.response?.data?.error ||
        cause?.message ||
        t(fallback)
    },
    reset() {
      this.requestId += 1
      this.audit = null
      this.error = ''
      this.loadingAudit = false
    }
  }
}
</script>

<style scoped>
.store-setup-audit-action {
  order: 3;
  display: inline-flex;
}

.store-setup-audit {
  display: grid;
  gap: 16px;
  color: var(--el-text-color-primary);
}

.store-setup-audit__toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
}

.store-setup-audit__store {
  width: min(420px, 100%);
}

.store-setup-audit__result {
  display: grid;
  gap: 16px;
}

.store-setup-audit__summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}

.store-setup-audit__summary-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  background: var(--el-fill-color-blank);
}

.store-setup-audit__summary-item strong {
  font-size: 22px;
  line-height: 1;
}

.store-setup-audit__summary-item span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
  text-align: right;
}

.store-setup-audit__summary-item--complete strong { color: var(--el-color-success); }
.store-setup-audit__summary-item--action strong { color: var(--el-color-danger); }
.store-setup-audit__summary-item--manual strong { color: var(--el-color-warning); }
.store-setup-audit__summary-item--failed strong { color: var(--el-text-color-secondary); }

.store-setup-audit__steps {
  display: grid;
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.store-setup-audit__step {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  background: var(--el-fill-color-blank);
}

.store-setup-audit__step-number {
  display: inline-grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  color: #fff;
  background: var(--el-color-primary);
  font-weight: 700;
}

.store-setup-audit__step-body {
  min-width: 0;
}

.store-setup-audit__step-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.store-setup-audit__step-header h3 {
  margin: 2px 0 0;
  font-size: 15px;
}

.store-setup-audit__evidence {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  margin-top: 10px;
  color: var(--el-text-color-regular);
  font-size: 12px;
}

.store-setup-audit__evidence-item strong {
  color: var(--el-text-color-primary);
}

.store-setup-audit__issues {
  display: grid;
  gap: 6px;
  margin: 10px 0 0;
  padding: 0;
  list-style: none;
}

.store-setup-audit__issues li {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  line-height: 1.55;
}

.store-setup-audit__issue-dot {
  flex: 0 0 auto;
  width: 7px;
  height: 7px;
  margin-top: 7px;
  border-radius: 50%;
  background: var(--el-color-warning);
}

.store-setup-audit__issue--action {
  color: var(--el-color-danger-dark-2);
}

.store-setup-audit__issue--action .store-setup-audit__issue-dot {
  background: var(--el-color-danger);
}

.store-setup-audit__issue--manual {
  color: var(--el-color-warning-dark-2);
}

.store-setup-audit__failure {
  margin: 10px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.store-setup-audit__loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 180px;
  color: var(--el-text-color-secondary);
}

:global(.store-setup-audit-dialog .el-dialog__body) {
  max-height: min(72vh, 760px);
  overflow-y: auto;
}

@media (max-width: 760px) {
  .store-setup-audit__toolbar {
    align-items: stretch;
    flex-direction: column;
  }

  .store-setup-audit__store {
    width: 100%;
  }

  .store-setup-audit__summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .store-setup-audit__step {
    grid-template-columns: 30px minmax(0, 1fr);
    padding: 12px;
  }

  .store-setup-audit__step-number {
    width: 28px;
    height: 28px;
  }
}
</style>
