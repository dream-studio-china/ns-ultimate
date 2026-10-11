import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { loadRelationRecords } from '@/utils/relation'
import './CounterSortAction.css'

const COUNTERS_ENDPOINT = apiPath(API_PREFIX, 'manage/counters')
const COUNTER_BATCH_UPDATE_ENDPOINT = apiPath(API_PREFIX, 'manage/counters/batch-update')
const PAGE_SIZE = 100
const MAX_PAGES = 100

const responseList = response => {
  const payload = response?.data ?? response
  if (Array.isArray(payload)) return payload
  if (Array.isArray(payload?.data)) return payload.data
  return []
}

export default {
  name: 'CounterSortAction',
  props: {
    refresh: { type: Function, default: () => {} }
  },
  data() {
    return {
      visible: false,
      loading: false,
      saving: false,
      stores: [],
      storeUuid: '',
      counters: [],
      dirty: false,
      draggedCounterId: null
    }
  },
  methods: {
    async openDialog() {
      this.visible = true
      if (!this.stores.length) {
        this.stores = await loadRelationRecords({ name: 'Store', valueKey: 'uuid' })
      }

      if (!this.storeUuid && this.stores.length === 1) {
        this.storeUuid = this.stores[0].uuid
      }
      if (this.storeUuid) await this.loadCounters()
    },
    async loadCounters() {
      if (!/^[0-9a-f-]{36}$/i.test(this.storeUuid)) {
        this.counters = []
        return
      }

      const storeUuid = this.storeUuid
      this.loading = true
      this.dirty = false
      this.counters = []

      try {
        const rows = []
        for (let page = 1; page <= MAX_PAGES; page++) {
          const response = await request.get(COUNTERS_ENDPOINT, {
            params: {
              page,
              limit: PAGE_SIZE,
              '@order': 'entity.sort|ASC, entity.id|ASC',
              '@filter': `entity.getStoreUuid() == '${storeUuid}'`
            }
          })
          const pageRows = responseList(response)
          rows.push(...pageRows)
          if (storeUuid !== this.storeUuid) return
          if (pageRows.length < PAGE_SIZE) {
            this.counters = rows
            return
          }
        }

        throw new Error(this.$t('Counter sort list limit reached'))
      } catch (error) {
        if (storeUuid === this.storeUuid) {
          this.counters = []
          if (error?.message === this.$t('Counter sort list limit reached')) {
            this.$message.error(error.message)
          }
        }
      } finally {
        if (storeUuid === this.storeUuid) this.loading = false
      }
    },
    onDragStart(counter, event) {
      if (this.saving) {
        event.preventDefault()
        return
      }
      this.draggedCounterId = counter.id
      event.dataTransfer.effectAllowed = 'move'
      event.dataTransfer.setData('text/plain', String(counter.id))
    },
    onDrop(targetCounter, event) {
      event.preventDefault()
      const sourceId = this.draggedCounterId ?? event.dataTransfer.getData('text/plain')
      const sourceIndex = this.counters.findIndex(counter => String(counter.id) === String(sourceId))
      const targetIndex = this.counters.findIndex(counter => counter.id === targetCounter.id)
      this.draggedCounterId = null
      if (sourceIndex < 0 || targetIndex < 0 || sourceIndex === targetIndex) return

      const reordered = [...this.counters]
      const [moved] = reordered.splice(sourceIndex, 1)
      reordered.splice(targetIndex, 0, moved)
      this.counters = reordered
      this.dirty = true
    },
    async saveOrder() {
      if (!this.dirty || this.saving || !this.counters.length) return

      this.saving = true
      try {
        const changes = this.counters.map((counter, index) => ({ id: counter.id, sort: index + 1 }))
        await request.post(COUNTER_BATCH_UPDATE_ENDPOINT, changes, {
          params: { '@basis': 'id', '@mode': 'update' }
        })
        this.dirty = false
        this.visible = false
        this.$message.success(this.$t('Counter order saved'))
        this.refresh()
      } catch {
        // The shared request client displays API errors; leave the dialog open for retry.
      } finally {
        this.saving = false
      }
    }
  },
  render() {
    const storeLabel = store => store.__toString || store.name || store.uuid

    return (
      <>
        <el-button type={'primary'} icon={'el-icon-sort'} plain onClick={this.openDialog}>
          {this.$t('Sort counters')}
        </el-button>
        <el-dialog
          modelValue={this.visible}
          title={this.$t('Sort counters')}
          width={'640px'}
          alignCenter={true}
          appendToBody={true}
          {...{ 'onUpdate:modelValue': value => { this.visible = value } }}
          v-slots={{
            footer: () => (
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
                <el-button onClick={() => { this.visible = false }}>{this.$t('Cancel')}</el-button>
                <el-button
                  type={'primary'}
                  loading={this.saving}
                  disabled={!this.dirty || !this.counters.length}
                  onClick={this.saveOrder}
                >
                  {this.$t('Save order')}
                </el-button>
              </div>
            )
          }}
        >
          <el-form-item label={this.$t('Store')}>
            <el-select
              modelValue={this.storeUuid}
              placeholder={this.$t('Select a store to sort counters')}
              filterable={true}
              style={'width: 100%'}
              onChange={value => {
                this.storeUuid = value
                this.loadCounters()
              }}
            >
              {this.stores.map(store => (
                <el-option key={store.uuid} label={storeLabel(store)} value={store.uuid} />
              ))}
            </el-select>
          </el-form-item>

          <p class={'counter-sort-hint'}>{this.$t('Drag rows to set counter order')}</p>

          {this.loading
            ? <el-empty description={this.$t('Loading counters')} />
            : this.counters.length
              ? <div class={'counter-sort-list'}>
                  {this.counters.map((counter, index) => (
                    <div
                      key={counter.id}
                      class={{ 'counter-sort-row': true, 'counter-sort-row--dragging': String(this.draggedCounterId) === String(counter.id) }}
                      draggable={!this.saving}
                      onDragstart={event => this.onDragStart(counter, event)}
                      onDragover={event => { event.preventDefault() }}
                      onDrop={event => this.onDrop(counter, event)}
                      onDragend={() => { this.draggedCounterId = null }}
                    >
                      <span class={'counter-sort-handle'} aria-label={this.$t('Drag to reorder')}>⠿</span>
                      <span class={'counter-sort-rank'}>{index + 1}</span>
                      <span class={'counter-sort-code'}>{counter.code}</span>
                      <span class={'counter-sort-name'}>{counter.name}</span>
                    </div>
                  ))}
                </div>
              : <el-empty description={this.storeUuid ? this.$t('No counters in this store') : this.$t('Select a store to sort counters')} />}
        </el-dialog>
      </>
    )
  }
}
