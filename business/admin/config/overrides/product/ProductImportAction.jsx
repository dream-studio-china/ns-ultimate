import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'

const PRODUCTS_ENDPOINT = apiPath(API_PREFIX, 'manage/products')
const CATEGORIES_ENDPOINT = apiPath(API_PREFIX, 'manage/product-categories')
const PAGE_SIZE = 100
const MAX_FILE_SIZE = 10 * 1024 * 1024

const responseList = response => {
  const data = response?.data ?? response
  if (Array.isArray(data)) return data
  if (Array.isArray(data?.data)) return data.data
  return []
}

const responseEntity = response => {
  let data = response
  for (let depth = 0; depth < 3 && data && !Array.isArray(data) && data.data !== undefined; depth++) {
    data = data.data
  }
  return Array.isArray(data) ? data[0] : data
}

const nameOf = value => {
  if (typeof value === 'string') return value.trim()
  return String(value?.name ?? value?.__toString ?? '').trim()
}

const nameKey = value => String(value || '').trim().toLocaleLowerCase()

const priceInMinorUnits = value => {
  if (typeof value === 'number') {
    return Number.isFinite(value) && value >= 0 ? Math.round(value * 100) : null
  }

  const match = String(value ?? '').trim().match(/^(\d+)(?:\.(\d{1,2}))?$/)
  if (!match) return null
  return (Number(match[1]) * 100) + Number((match[2] || '').padEnd(2, '0'))
}

const categoryForName = (categories, targetName) => {
  const matches = categories.filter(category => category.enabled !== false && nameOf(category) === targetName)
  const globalMatches = matches.filter(category => category.store == null)
  const preferred = globalMatches.length ? globalMatches : matches
  return preferred.length === 1 ? preferred[0] : null
}

const mapSourceProduct = (source, index, categories, existingNames) => {
  const name = String(source?.name ?? '').trim()
  const categoryName = nameOf(source?.category)
  const category = categoryForName(categories, categoryName)
  const rawSpecs = Array.isArray(source?.specifications) ? source.specifications : []
  const specifications = rawSpecs.map(specification => {
    const details = specification?.__metadata ?? specification
    return {
      name: nameOf(details) || nameOf(specification),
      price: priceInMinorUnits(details?.price ?? specification?.price)
    }
  })
  const tags = Array.isArray(source?.tags) ? source.tags : [source?.tags]
  const rawRecommendation = source?.recommendLevel ?? tags.find(value => value !== null && value !== undefined && value !== '')
  const parsedRecommendation = Number(rawRecommendation)
  const recommendLevel = Number.isInteger(parsedRecommendation) && parsedRecommendation >= 1 && parsedRecommendation <= 10
    ? parsedRecommendation
    : null
  const cover = typeof source?.cover === 'string' ? source.cover.trim() : ''
  const errors = []

  if (!name) errors.push(t('Missing product name'))
  if (!category) errors.push(`${t('No matching category')}: ${categoryName || '-'}`)
  if (!rawSpecs.length || specifications.some(specification => !specification.name || specification.price === null)) {
    errors.push(t('Invalid specifications'))
  }

  const existing = existingNames.has(nameKey(name))
  return {
    key: `${index}-${source?.id ?? name}`,
    source,
    name,
    categoryName,
    categoryId: category?.id,
    cover,
    recommendLevel,
    metadata: {
      ...(cover ? { cover } : {}),
      ...(recommendLevel !== null ? { recommendLevel } : {})
    },
    specifications,
    valid: errors.length === 0,
    existing,
    createdProductId: null,
    status: existing ? 'Already exists' : (errors.length ? errors.join(' / ') : 'Ready')
  }
}

export default {
  name: 'ProductImportAction',
  props: {
    refresh: { type: Function, default: () => {} }
  },
  data() {
    return {
      visible: false,
      loading: false,
      importing: false,
      fileName: '',
      rows: [],
      selectedRows: [],
      errorText: '',
      resultText: '',
      progressText: ''
    }
  },
  methods: {
    async loadAll(endpoint) {
      const all = []
      for (let page = 1; page <= 100; page++) {
        const response = await request.get(endpoint, {
          params: { page, limit: PAGE_SIZE, '@order': 'entity.id|ASC' }
        })
        const current = responseList(response)
        all.push(...current)

        const total = Number(
          response?.paginator?.totalCount
          ?? response?.paginator?.total
          ?? response?.data?.paginator?.totalCount
          ?? response?.data?.paginator?.total
          ?? 0
        )
        if (!current.length || current.length < PAGE_SIZE || (total > 0 && all.length >= total)) break
      }
      return all
    },
    openDialog() {
      this.visible = true
      this.errorText = ''
      this.resultText = ''
      this.progressText = ''
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
        if (!Array.isArray(source) || !source.length) throw new Error(t('JSON must contain a product array'))

        const [categories, existingProducts] = await Promise.all([
          this.loadAll(CATEGORIES_ENDPOINT),
          this.loadAll(PRODUCTS_ENDPOINT)
        ])
        const existingNames = new Set(existingProducts.map(product => nameKey(product?.name)).filter(Boolean))
        this.rows = source.map((product, index) => mapSourceProduct(product, index, categories, existingNames))

        if (!this.rows.some(row => row.valid && !row.existing)) {
          this.errorText = t('No importable products found')
          return
        }

        this.$nextTick(() => {
          const previewTable = this.$refs.previewTable
          const sample = this.rows.find(row => row.name === '牛花梅' && row.valid && !row.existing)
            || this.rows.find(row => row.valid && !row.existing)
          if (sample && previewTable?.toggleRowSelection) previewTable.toggleRowSelection(sample, true)
        })
      } catch (error) {
        this.errorText = error?.message || t('Failed to read product JSON')
      } finally {
        this.loading = false
      }
    },
    handleSelectionChange(rows) {
      this.selectedRows = rows || []
    },
    selectAllAvailable() {
      const table = this.$refs.previewTable
      if (!table) return
      table.clearSelection()
      this.rows.forEach(row => {
        if (row.valid && (!row.existing || row.createdProductId)) table.toggleRowSelection(row, true)
      })
    },
    clearSelection() {
      this.$refs.previewTable?.clearSelection?.()
    },
    async importSelected() {
      if (!this.selectedRows.length || this.importing) return

      const selected = [...this.selectedRows]
      let imported = 0
      let failed = 0
      this.importing = true
      this.errorText = ''
      this.resultText = ''

      for (let index = 0; index < selected.length; index++) {
        const row = selected[index]
        this.progressText = `${t('Importing')}: ${index + 1} / ${selected.length}`
        try {
          let productId = row.createdProductId
          if (!productId) {
            const productResponse = await request.post(PRODUCTS_ENDPOINT, {
              name: row.name,
              category: row.categoryId,
              metadata: row.metadata
            })
            const product = responseEntity(productResponse)
            productId = Number(product?.id)
            if (!Number.isInteger(productId) || productId < 1) throw new Error(t('Product create response has no ID'))
            row.createdProductId = productId
          }

          await request.post(
            apiPath(API_PREFIX, `manage/products/${productId}/specifications`),
            row.specifications.map(specification => ({ name: specification.name, price: specification.price }))
          )
          row.existing = true
          row.status = 'Imported'
          row.error = ''
          imported++
        } catch (error) {
          row.status = row.createdProductId ? 'Product created, specifications failed' : 'Import failed'
          row.error = error?.message || t('Import failed')
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
    return (
      <>
        <el-button size="default" type="primary" icon="el-icon-upload" plain onClick={this.openDialog}>
          {t('Import Products')}
        </el-button>
        <el-dialog
          title={t('Import Products')}
          modelValue={this.visible}
          width="1100px"
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
                    disabled={this.loading || !this.selectedRows.length}
                    onClick={this.importSelected}
                  >
                    {t('Import Selected')} ({this.selectedRows.length})
                  </el-button>
                </div>
              </div>
            )
          }}
        >
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
            {t('Product import help')}
          </p>
          {this.loading && <el-alert title={t('Loading import preview')} type="info" showIcon={true} closable={false} />}
          {this.errorText && <el-alert title={this.errorText} type="error" showIcon={true} closable={false} style="margin-bottom:12px" />}
          {this.rows.length > 0 && (
            <el-table
              ref="previewTable"
              data={this.rows}
              rowKey="key"
              maxHeight={460}
              onSelectionChange={this.handleSelectionChange}
            >
              <el-table-column
                type="selection"
                width="48"
                selectable={row => row.valid && (!row.existing || row.createdProductId)}
              />
              <el-table-column prop="name" label={t('Product Name')} minWidth="150" />
              <el-table-column prop="categoryName" label={t('Category')} width="110" />
              <el-table-column label={t('Cover')} minWidth="150" showOverflowTooltip={true} v-slots={{
                default: ({ row }) => row.cover
                  ? <a href={row.cover} target="_blank" rel="noopener noreferrer">{row.cover}</a>
                  : '-'
              }} />
              <el-table-column label={t('Recommendation level')} width="110" v-slots={{
                default: ({ row }) => row.recommendLevel ?? '-'
              }} />
              <el-table-column label={t('Specifications')} minWidth="210" showOverflowTooltip={true} v-slots={{
                default: ({ row }) => row.specifications
                  .map(specification => `${specification.name} ¥${specification.price === null ? '-' : (specification.price / 100).toFixed(2)}`)
                  .join(' / ')
              }} />
              <el-table-column label={t('Import status')} minWidth="180" showOverflowTooltip={true} v-slots={{
                default: ({ row }) => (
                  <span title={row.error || row.status} style={{ color: row.valid && !row.existing ? '#67c23a' : '#e6a23c' }}>
                    {t(row.status)}{row.error ? `: ${row.error}` : ''}
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
