<template>
  <span class="store-dining-modes-action">
    <el-button
      v-if="canConfigure"
      size="small"
      type="primary"
      icon="el-icon-s-order"
      plain
      @click="open"
    >
      {{ $t('Dining modes') }}
    </el-button>
    <el-tooltip
      v-if="canConfigure"
      :content="$t('Copy dining modes to another store')"
      placement="top"
    >
      <el-button
        size="small"
        type="primary"
        icon="el-icon-document-copy"
        plain
        :aria-label="$t('Copy dining modes to another store')"
        :disabled="loadingStores || copying"
        @click="openCopy"
      />
    </el-tooltip>

    <el-dialog
      v-model="visible"
      :title="dialogTitle"
      width="min(1180px, 96vw)"
      append-to-body
      :close-on-click-modal="false"
      destroy-on-close
      @closed="reset"
    >
      <el-alert
        v-if="error"
        :title="error"
        type="error"
        :closable="false"
        show-icon
        class="store-dining-modes__error"
      />

      <div v-loading="loading" class="store-dining-modes__content">
        <DiningModesEditor
          v-if="loaded"
          :key="editorKey"
          :form="settings"
          property="diningModes"
          :store-scope-uuid="storeUuid"
        />
      </div>

      <template #footer>
        <div class="store-dining-modes__footer">
          <el-button :disabled="saving" @click="visible = false">{{ $t('Close') }}</el-button>
          <el-button
            type="primary"
            :loading="saving"
            :disabled="loading || !loaded || validationErrors.length > 0"
            @click="save"
          >
            {{ $t('Save') }}
          </el-button>
        </div>
      </template>
    </el-dialog>

    <el-dialog
      v-model="copyVisible"
      :title="$t('Copy dining modes')"
      width="min(620px, 94vw)"
      append-to-body
      align-center
      :close-on-click-modal="!copying"
      :close-on-press-escape="!copying"
      :show-close="!copying"
      destroy-on-close
      @closed="resetCopy"
    >
      <div v-loading="loadingStores" class="store-dining-modes-copy">
        <el-alert
          :title="$t('Copy dining modes help')"
          type="warning"
          :closable="false"
          show-icon
        />
        <el-alert
          v-if="copyError"
          :title="copyError"
          type="error"
          :closable="false"
          show-icon
          class="store-dining-modes__error"
        />

        <el-form label-position="top" @submit.prevent>
          <el-form-item :label="$t('Source store')">
            <el-input :model-value="sourceStoreLabel" disabled />
          </el-form-item>
          <el-form-item :label="$t('Target store')" required>
            <el-select
              v-model="targetStoreUuid"
              filterable
              :disabled="loadingStores || copying"
              :placeholder="$t('Select target store')"
              @change="copyError = ''"
            >
              <el-option
                v-for="store in targetStores"
                :key="store.uuid"
                :value="store.uuid"
                :label="storeLabel(store)"
              />
            </el-select>
            <p v-if="!loadingStores && !targetStores.length" class="store-dining-modes-copy__empty">
              {{ $t('No other stores available') }}
            </p>
          </el-form-item>
        </el-form>
      </div>

      <template #footer>
        <el-button :disabled="copying" @click="copyVisible = false">{{ $t('Close') }}</el-button>
        <el-button
          type="primary"
          :loading="copying"
          :disabled="loadingStores || !targetStoreUuid || !targetStores.length"
          @click="copyToTarget"
        >
          {{ $t('Copy') }}
        </el-button>
      </template>
    </el-dialog>
  </span>
</template>

<script>
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'
import request from '@/utils/request'
import DiningModesEditor from './DiningModesEditor.vue'
import { fetchAllPages } from './catalog-copy.mjs'
import { copyDiningModesSettings, normalizeDiningModesConfig, validateDiningModesConfig } from './dining-modes.mjs'

const storesEndpoint = apiPath(API_PREFIX, 'manage/stores')

export default {
  name: 'StoreDiningModesAction',
  components: { DiningModesEditor },
  props: {
    record: { type: Object, default: () => ({}) },
    refresh: { type: Function, default: () => {} }
  },
  data() {
    return {
      visible: false,
      loading: false,
      loaded: false,
      saving: false,
      copyVisible: false,
      loadingStores: false,
      copying: false,
      error: '',
      copyError: '',
      store: {},
      settings: {},
      editorKey: 0,
      stores: [],
      targetStoreUuid: ''
    }
  },
  computed: {
    canConfigure() {
      return Boolean(this.record.id && this.record.uuid)
    },
    storeUuid() {
      return this.store.uuid || this.record.uuid || ''
    },
    dialogTitle() {
      return `${this.store.name || this.record.name || ''} · ${t('Dining modes')}`
    },
    sourceStoreLabel() {
      return this.storeLabel(this.record)
    },
    targetStores() {
      return this.stores.filter(store => store.uuid && store.uuid !== this.record.uuid)
    },
    validationErrors() {
      if (!this.loaded) return []
      return validateDiningModesConfig(this.settings.diningModes)
    }
  },
  methods: {
    storeLabel(store) {
      const code = String(store?.code || '').trim()
      const name = store?.name || store?.__toString || store?.uuid || ''
      return code ? `${name} · ${code}` : name
    },
    async open() {
      if (!this.canConfigure || this.loading) return
      this.visible = true
      this.loading = true
      this.loaded = false
      this.error = ''
      this.store = { ...this.record }
      this.settings = {}
      try {
        const response = await request.get(`${storesEndpoint}/${this.record.uuid}`)
        const store = response.data && typeof response.data === 'object' ? response.data : {}
        const settings = this.readSettings(store.settings)
        settings.diningModes = normalizeDiningModesConfig(settings.diningModes)
        this.store = { ...this.record, ...store }
        this.settings = settings
        this.loaded = true
        this.editorKey += 1
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Failed to load store dining modes')
      } finally {
        this.loading = false
      }
    },
    async openCopy() {
      if (!this.canConfigure || this.loadingStores || this.copying) return
      this.copyVisible = true
      this.copyError = ''
      this.targetStoreUuid = ''
      this.loadingStores = true
      try {
        this.stores = await fetchAllPages(
          request.get,
          storesEndpoint,
          { '@order': 'entity.name|ASC' }
        )
      } catch (cause) {
        this.copyError = this.failureMessage(cause, 'Failed to load stores')
      } finally {
        this.loadingStores = false
      }
    },
    async copyToTarget() {
      if (this.copying || !this.targetStoreUuid || this.targetStoreUuid === this.record.uuid) return
      const target = this.targetStores.find(store => store.uuid === this.targetStoreUuid)
      if (!target) return

      const sourceLabel = this.sourceStoreLabel
      const targetLabel = this.storeLabel(target)
      if (!window.confirm(t('Confirm dining mode copy from {0} to {1}', sourceLabel, targetLabel))) return

      this.copying = true
      this.copyError = ''
      try {
        const [sourceResponse, targetResponse] = await Promise.all([
          request.get(`${storesEndpoint}/${this.record.uuid}`),
          request.get(`${storesEndpoint}/${this.targetStoreUuid}`)
        ])
        const sourceStore = sourceResponse?.data
        const targetStore = targetResponse?.data
        if (!sourceStore?.uuid || !targetStore?.uuid) throw new Error('dining_modes_store_response_invalid')

        const copied = copyDiningModesSettings(targetStore.settings, sourceStore.settings)
        if (copied.modeCount === 0) {
          this.copyError = t('The source store has no dining modes to copy')
          return
        }

        await request.put(`${storesEndpoint}/${targetStore.uuid}`, { settings: copied.settings })
        createUiFeedback().success(t('Dining modes copied to {0}', targetLabel))
        this.copyVisible = false
        this.refresh()
      } catch (cause) {
        if (cause?.message === 'dining_modes_settings_invalid') {
          this.copyError = t('Invalid store settings')
        } else {
          this.copyError = this.failureMessage(cause, 'Failed to copy dining modes')
        }
      } finally {
        this.copying = false
      }
    },
    async save() {
      if (this.saving || !this.loaded || this.validationErrors.length > 0) return
      this.saving = true
      this.error = ''
      try {
        const diningModes = normalizeDiningModesConfig(this.settings.diningModes)
        await request.put(`${storesEndpoint}/${this.storeUuid}`, {
          settings: { ...this.settings, diningModes }
        })
        createUiFeedback().success(t('Dining modes saved'))
        this.visible = false
        this.refresh()
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Failed to save dining modes')
      } finally {
        this.saving = false
      }
    },
    failureMessage(cause, fallbackKey) {
      return cause.response?.data?.message ||
        cause.response?.data?.error ||
        cause.message ||
        t(fallbackKey)
    },
    readSettings(value) {
      if (value === undefined || value === null || value === '') return {}
      const parsed = typeof value === 'string' ? JSON.parse(value) : value
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        throw new Error(t('Failed to load store dining modes'))
      }
      return { ...parsed }
    },
    reset() {
      if (this.loading || this.saving) return
      this.loaded = false
      this.error = ''
      this.store = {}
      this.settings = {}
    },
    resetCopy() {
      if (this.copying) return
      this.copyError = ''
      this.targetStoreUuid = ''
    }
  }
}
</script>

<style scoped>
.store-dining-modes-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.store-dining-modes__error {
  margin-bottom: 16px;
}

.store-dining-modes__content {
  min-height: 180px;
}

.store-dining-modes-copy {
  min-height: 180px;
}

.store-dining-modes-copy .el-form {
  margin-top: 18px;
}

.store-dining-modes-copy__empty {
  margin: 6px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.store-dining-modes__footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
