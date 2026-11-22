<template>
  <div class="product-category-select">
    <el-select
      v-model="form[field.property]"
      filterable
      clearable
      :loading="loading"
      :placeholder="$t('Please select')"
      :no-data-text="emptyText"
    >
      <el-option
        v-for="option in options"
        :key="option.value"
        :label="option.label"
        :value="option.value"
      />
    </el-select>
    <p v-if="loadError" class="product-category-select__error" role="alert">{{ loadError }}</p>
  </div>
</template>

<script>
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'
import { fetchAllPages, referenceUuid } from './catalog-copy.mjs'
import {
  categoryBelongsToProductScope,
  productCategoryOptions
} from './product-category-scope.mjs'

const categoryEndpoint = apiPath(API_PREFIX, 'manage/product-categories')

export default {
  name: 'ProductCategorySelect',
  props: {
    form: { type: Object, required: true },
    field: { type: Object, required: true }
  },
  data() {
    return {
      categories: [],
      loading: false,
      loadError: '',
      loadSequence: 0
    }
  },
  computed: {
    storeUuid() {
      return referenceUuid(this.form.store)
    },
    options() {
      return productCategoryOptions(this.categories, this.storeUuid).map(option => ({
        ...option,
        label: option.isGlobal ? `${option.name} · ${t('Global')}` : option.name
      }))
    },
    emptyText() {
      return this.storeUuid
        ? t('No categories are available for this store or global scope')
        : t('No global product categories are available')
    }
  },
  watch: {
    storeUuid() {
      this.clearOutOfScopeSelection()
      this.loadCategories()
    }
  },
  created() {
    this.loadCategories()
  },
  methods: {
    async loadCategories() {
      const sequence = ++this.loadSequence
      this.loading = true
      this.loadError = ''
      try {
        const categories = await fetchAllPages(
          request.get,
          categoryEndpoint,
          { '@expands': 'store', '@order': 'entity.sortOrder|ASC,entity.name|ASC' }
        )
        if (sequence !== this.loadSequence) return
        this.categories = categories
        this.clearOutOfScopeSelection()
      } catch (cause) {
        if (sequence !== this.loadSequence) return
        this.loadError = cause.response?.data?.message || cause.message || t('Failed to load product categories')
      } finally {
        if (sequence === this.loadSequence) this.loading = false
      }
    },
    clearOutOfScopeSelection() {
      const categoryUuid = this.form[this.field.property]
      if (!categoryUuid || !this.categories.length) return
      const selected = this.categories.find(category => category.uuid === categoryUuid)
      if (selected && !categoryBelongsToProductScope(selected, this.storeUuid)) {
        this.form[this.field.property] = null
      }
    }
  }
}
</script>

<style scoped>
.product-category-select {
  width: 100%;
}

.product-category-select :deep(.el-select) {
  width: 100%;
}

.product-category-select__error {
  margin: 6px 0 0;
  color: var(--el-color-danger);
  font-size: 12px;
}
</style>
