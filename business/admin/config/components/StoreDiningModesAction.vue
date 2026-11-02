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
  </span>
</template>

<script>
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'
import request from '@/utils/request'
import DiningModesEditor from './DiningModesEditor.vue'
import { normalizeDiningModesConfig, validateDiningModesConfig } from './dining-modes.mjs'

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
      error: '',
      store: {},
      settings: {},
      editorKey: 0
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
    validationErrors() {
      if (!this.loaded) return []
      return validateDiningModesConfig(this.settings.diningModes)
    }
  },
  methods: {
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
    }
  }
}
</script>

<style scoped>
.store-dining-modes-action {
  display: inline-flex;
}

.store-dining-modes__error {
  margin-bottom: 16px;
}

.store-dining-modes__content {
  min-height: 180px;
}

.store-dining-modes__footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
