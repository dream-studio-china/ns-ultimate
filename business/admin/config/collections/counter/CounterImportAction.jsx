import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { loadRelationRecords } from '@/utils/relation'
import { t } from '@/i18n'
import { nextCounterCode, projectCounterRecords } from './counter-import-data.mjs'

const COUNTERS_ENDPOINT = apiPath(API_PREFIX, 'manage/counters')
const PAGE_SIZE = 100
const MAX_PAGES = 100
const MAX_FILE_SIZE = 10 * 1024 * 1024

const responseList = response => {
  const payload = response?.data ?? response
  if (Array.isArray(payload)) return payload
  if (Array.isArray(payload?.data)) return payload.data
  return []
}

const nameKey = value => String(value || '').trim().toLocaleLowerCase()

export default {
  name: 'CounterImportAction',
  props: {
    refresh: { type: Function, default: () => {} }
  },
  data() {
    return {
      visible: false,
      loading: false,
      importing: false,
      stores: [],
      storeUuid: '',
      storeLoaded: false,
      fileName: '',
      rows: [],
      selectedRows: [],
      existingNames: new Set(),
      usedCodes: new Set(),
      errorText: '',
      resultText: '',
      progressText: ''
    }
  },
  methods: {
    async openDialog() {
      this.visible = true
      this.errorText = ''
      this.resultText = ''
      this.progressText = ''
      if (!this.stores.length) {
        this.loading = true
        try {
          this.stores = await loadRelationRecords({ name: 'Store', valueKey: 'uuid' })
          if (!this.storeUuid && this.stores.length === 1) {
            this.storeUuid = this.stores[0].uuid
          }
          if (this.storeUuid) await this.loadStoreCounters()
          if (!this.stores.length) this.errorText = t('Counter import stores unavailable')
        } catch (error) {
          this.errorText = error?.message || t('Counter import stores unavailable')
        } finally {
          this.loading = false
        }
      } else if (this.storeUuid && !this.storeLoaded) {
        await this.loadStoreCounters()
      }
    },
    async loadStoreCounters() {
      this.storeLoaded = false
      this.selectedRows = []
      this.existingNames = new Set()
      this.usedCodes = new Set()
      if (!/^[0-9a-f-]{36}$/i.test(this.storeUuid)) return

      const storeUuid = this.storeUuid
      const counters = []
      this.loading = true
      this.errorText = ''
      try {
        for (let page = 1; page <= MAX_PAGES; page++) {
          const response = await request.get(COUNTERS_ENDPOINT, {
            params: {
              page,
              limit: PAGE_SIZE,
              '@order': 'entity.id|ASC',
              '@filter': `entity.getStoreUuid() == '${storeUuid}'`
            }
          })
          const pageRows = responseList(response)
          counters.push(...pageRows)
          if (storeUuid !== this.storeUuid) return
          if (pageRows.length < PAGE_SIZE) {
            this.existingNames = new Set(counters.map(counter => nameKey(counter?.name)).filter(Boolean))
            this.usedCodes = new Set(counters.map(counter => String(counter?.code || '').trim().toLocaleUpperCase()).filter(Boolean))
            this.storeLoaded = true
            return
          }
        }
        throw new Error(t('Counter import store list limit reached'))
      } catch (error) {
        if (storeUuid === this.storeUuid) {
          this.errorText = error?.message || t('Counter import store load failed')
        }
      } finally {
        if (storeUuid === this.storeUuid) this.loading = false
      }
    },
    async handleFileChange(event) {
      const input = event.target
      const file = input.files?.[0]
      input.value = ''
      if (!file) return

      this.fileName = file.name
      this.rows = []
      this.selectedRows = []
      this.errorText = ''
      this.resultText = ''

      if (!file.name.toLowerCase().endsWith('.json') || file.size > MAX_FILE_SIZE) {
        this.errorText = file.size > MAX_FILE_SIZE ? t('JSON file is too large') : t('Please choose a JSON file')
        return
      }

      this.loading = true
      try {
        const source = JSON.parse(await file.text())
        if (!Array.isArray(source) || source.length === 0) {
          throw new Error(t('Counter import JSON must be array'))
        }
        this.rows = projectCounterRecords(source)
        this.updateStatuses()
        if (!this.rows.some(row => row.valid)) this.errorText = t('Counter import no valid rows')
      } catch (error) {
        this.rows = []
        this.errorText = error?.message || t('Failed to read counter JSON')
      } finally {
        this.loading = false
      }
    },
    updateStatuses() {
      this.rows = this.rows.map(row => {
        let issue = row.issue
        if (!issue && !this.storeUuid) issue = 'select-store'
        else if (!issue && !this.storeLoaded) issue = 'store-not-loaded'
        else if (!issue && this.existingNames.has(nameKey(row.name))) issue = 'already-exists'
        return { ...row, issue, importable: row.valid && !issue }
      })
    },
    async handleStoreChange(storeUuid) {
      this.storeUuid = storeUuid || ''
      this.storeLoaded = false
      this.selectedRows = []
      this.rows = this.rows.map(row => ({ ...row, issue: row.valid ? '' : row.issue, importable: false }))
      if (this.storeUuid) await this.loadStoreCounters()
      this.updateStatuses()
    },
    handleSelectionChange(rows) {
      this.selectedRows = rows || []
    },
    selectAllAvailable() {
      const table = this.$refs.previewTable
      if (!table) return
      table.clearSelection()
      this.rows.forEach(row => {
        if (row.importable) table.toggleRowSelection(row, true)
      })
    },
    clearSelection() {
      this.$refs.previewTable?.clearSelection?.()
    },
    async importSelected() {
      if (!this.selectedRows.length || !this.storeLoaded || this.importing) return

      const selected = [...this.selectedRows]
      let imported = 0
      let failed = 0
      this.importing = true
      this.errorText = ''
      this.resultText = ''

      for (let index = 0; index < selected.length; index++) {
        const row = selected[index]
        this.progressText = `${t('Importing')}: ${index + 1} / ${selected.length}`
        const code = nextCounterCode(this.usedCodes)
        this.usedCodes.add(code)
        try {
          await request.post(COUNTERS_ENDPOINT, {
            storeUuid: this.storeUuid,
            code,
            name: row.name,
            sort: row.sort,
            enabled: true
          })
          this.existingNames.add(nameKey(row.name))
          row.issue = 'imported'
          row.importable = false
          imported++
        } catch (error) {
          row.issue = 'import-failed'
          row.error = error?.message || t('Import failed')
          row.importable = true
          failed++
        }
      }

      this.importing = false
      this.progressText = ''
      this.resultText = `${t('Import completed')}: ${imported} ${t('Imported')}, ${failed} ${t('Failed')}.`
      this.selectedRows = []
      this.$refs.previewTable?.clearSelection?.()
      if (imported) this.refresh()
    }
  },
  render() {
    const storeLabel = store => store.__toString || store.name || store.uuid
    const rowStatus = row => {
      if (row.issue === 'invalid-name') return t('Counter import invalid name')
      if (row.issue === 'invalid-sort') return t('Counter import invalid sort')
      if (row.issue === 'duplicate-source-name') return t('Counter import duplicate source name')
      if (row.issue === 'select-store') return t('Counter import select store')
      if (row.issue === 'store-not-loaded') return t('Counter import loading store')
      if (row.issue === 'already-exists') return t('Counter import already exists')
      if (row.issue === 'imported') return t('Imported')
      if (row.issue === 'import-failed') return row.error || t('Import failed')
      return t('Counter import ready')
    }

    return (
      <>
        <el-button size="default" type="primary" icon="el-icon-upload" plain onClick={this.openDialog}>
          {t('Import Counters')}
        </el-button>
        <el-dialog
          title={t('Import Counters')}
          modelValue={this.visible}
          width="900px"
          alignCenter={true}
          appendToBody={true}
          closeOnClickModal={!this.importing}
          {...{ 'onUpdate:modelValue': value => { this.visible = value } }}
          v-slots={{
            footer: () => (
              <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                <span>{this.progressText || this.resultText}</span>
                <div style="display:flex;gap:8px">
                  <el-button disabled={this.importing} onClick={() => { this.visible = false }}>{t('Cancel')}</el-button>
                  <el-button
                    type="primary"
                    icon="el-icon-upload"
                    loading={this.importing}
                    disabled={this.loading || !this.storeLoaded || !this.selectedRows.length}
                    onClick={this.importSelected}
                  >
                    {t('Import Selected')} ({this.selectedRows.length})
                  </el-button>
                </div>
              </div>
            )
          }}
        >
          <el-form-item label={t('Store')} required={true}>
            <el-select
              modelValue={this.storeUuid}
              placeholder={t('Counter import select store')}
              filterable={true}
              style="width:100%"
              disabled={this.importing}
              onChange={this.handleStoreChange}
            >
              {this.stores.map(store => (
                <el-option key={store.uuid} label={storeLabel(store)} value={store.uuid} />
              ))}
            </el-select>
          </el-form-item>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
            <input
              ref="fileInput"
              type="file"
              accept=".json,application/json"
              style="display:none"
              onChange={this.handleFileChange}
            />
            <el-button icon="el-icon-document" disabled={this.loading || this.importing} onClick={() => this.$refs.fileInput?.click()}>
              {t('Select JSON File')}
            </el-button>
            <span style="color:#606266">{this.fileName || t('No file selected')}</span>
            {this.rows.length > 0 && (
              <span style="margin-left:auto;display:flex;gap:8px">
                <el-button size="small" disabled={this.importing} onClick={this.selectAllAvailable}>{t('Select all available')}</el-button>
                <el-button size="small" disabled={this.importing} onClick={this.clearSelection}>{t('Clear selection')}</el-button>
              </span>
            )}
          </div>
          <p style="color:#606266;font-size:13px;line-height:1.6;margin:0 0 12px">
            {t('Counter import help')}
          </p>
          {this.loading && <el-alert title={t('Loading import preview')} type="info" showIcon={true} closable={false} />}
          {this.errorText && <el-alert title={this.errorText} type="error" showIcon={true} closable={false} style="margin-bottom:12px" />}
          {this.rows.length > 0 && (
            <el-table
              ref="previewTable"
              data={this.rows}
              rowKey="key"
              maxHeight={420}
              onSelectionChange={this.handleSelectionChange}
            >
              <el-table-column type="selection" width="48" selectable={row => row.importable && !this.importing} />
              <el-table-column prop="name" label={t('Name')} minWidth="180" />
              <el-table-column prop="sort" label={t('Counter sort help')} width="140" />
              <el-table-column label={t('Counter code')} width="130" v-slots={{
                default: ({ row }) => row.importable ? t('Counter code generated') : '-'
              }} />
              <el-table-column label={t('Import status')} minWidth="170" showOverflowTooltip={true} v-slots={{
                default: ({ row }) => (
                  <span style={{ color: row.importable || row.issue === 'imported' ? '#67c23a' : '#e6a23c' }}>
                    {rowStatus(row)}
                  </span>
                )
              }} />
            </el-table>
          )}
        </el-dialog>
      </>
    )
  }
}
