<template>
  <span class="catalog-copy-action">
    <el-button type="primary" plain icon="el-icon-copy-document" @click="open">
      {{ $t('Copy from store') }}
    </el-button>

    <el-dialog
      v-model="visible"
      :title="mode === 'products' ? $t('Copy products from another store') : $t('Copy product categories from another store')"
      width="min(620px, 94vw)"
      append-to-body
      align-center
      :close-on-click-modal="false"
      :close-on-press-escape="!copying"
      :show-close="!copying"
      destroy-on-close
      @closed="reset"
    >
      <div v-loading="loadingStores" class="catalog-copy__body">
        <el-alert
          :title="mode === 'products' ? $t('Product copy help') : $t('Product category copy help')"
          type="info"
          :closable="false"
          show-icon
        />

        <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />

        <el-form label-position="top" class="catalog-copy__form" @submit.prevent>
          <el-form-item :label="$t('Source store')" required>
            <el-select v-model="sourceStoreUuid" filterable :disabled="copying" :placeholder="$t('Select source store')" @change="resetOutcome">
              <el-option
                v-for="store in stores"
                :key="store.uuid"
                :value="store.uuid"
                :label="storeLabel(store)"
              />
            </el-select>
          </el-form-item>
          <el-form-item :label="$t('Target store')" required :error="targetStoreError">
            <el-select v-model="targetStoreUuid" filterable :disabled="copying" :placeholder="$t('Select target store')" @change="resetOutcome">
              <el-option
                v-for="store in stores"
                :key="store.uuid"
                :value="store.uuid"
                :label="storeLabel(store)"
              />
            </el-select>
          </el-form-item>
          <el-form-item v-if="mode === 'products'">
            <el-checkbox v-model="skipSameNameProducts" :disabled="copying" @change="resetOutcome">
              {{ $t('Skip products with the same name in the target store') }}
            </el-checkbox>
          </el-form-item>
        </el-form>

        <el-alert v-if="copying" :title="progress || $t('Preparing catalog copy')" type="info" :closable="false" show-icon />

        <el-descriptions v-if="result" :column="2" border class="catalog-copy__result">
          <el-descriptions-item :label="$t('Categories created')">{{ result.categoriesCreated }}</el-descriptions-item>
          <el-descriptions-item :label="$t('Categories reused')">{{ result.categoriesReused }}</el-descriptions-item>
          <el-descriptions-item v-if="mode === 'products'" :label="$t('Products created')">{{ result.productsCreated }}</el-descriptions-item>
          <el-descriptions-item v-if="mode === 'products'" :label="$t('Products skipped')">{{ result.productsSkipped }}</el-descriptions-item>
          <el-descriptions-item v-if="mode === 'products'" :label="$t('Specifications created')" :span="2">{{ result.specificationsCreated }}</el-descriptions-item>
        </el-descriptions>

        <p v-if="result && !error" class="catalog-copy__success" role="status">
          <i class="el-icon-success" />{{ mode === 'products' ? $t('Products copied') : $t('Product categories copied') }}
        </p>
      </div>

      <template #footer>
        <el-button :disabled="copying" @click="visible = false">{{ $t('Close') }}</el-button>
        <el-button
          type="primary"
          :loading="copying"
          :disabled="loadingStores || !sourceStoreUuid || !targetStoreUuid || sourceStoreUuid === targetStoreUuid"
          @click="copy"
        >
          {{ mode === 'products' ? $t('Copy products') : $t('Copy categories') }}
        </el-button>
      </template>
    </el-dialog>
  </span>
</template>

<script>
import { t } from '@/i18n'
import request from '@/utils/request'
import {
  categoryCopyPayload,
  categoryStoreUuid,
  fetchAllPages,
  findCategoryByReference,
  mappedCategory,
  orderedSourceCategories,
  productCopyPayload,
  productNameKey,
  productStoreUuid,
  referenceUuid,
  rememberCategoryMapping,
  specificationCopyPayload,
  targetCategoriesBySlug
} from './catalog-copy.mjs'

const categoryPath = '/api/v1/manage/product-categories'
const productPath = '/api/v1/manage/products'

export default {
  name: 'CopyCatalogAction',
  props: {
    mode: {
      type: String,
      required: true,
      validator: value => ['categories', 'products'].includes(value)
    },
    refresh: { type: Function, default: () => {} }
  },
  data() {
    return {
      visible: false,
      stores: [],
      sourceStoreUuid: '',
      targetStoreUuid: '',
      skipSameNameProducts: true,
      loadingStores: false,
      copying: false,
      progress: '',
      error: '',
      result: null
    }
  },
  computed: {
    targetStoreError() {
      return this.sourceStoreUuid && this.sourceStoreUuid === this.targetStoreUuid ? t('Source and target stores must be different') : ''
    }
  },
  methods: {
    storeLabel(store) {
      const code = String(store?.code || '').trim()
      return code ? `${store.name || store.__toString || store.uuid} · ${code}` : (store.name || store.__toString || store.uuid)
    },
    async open() {
      this.visible = true
      if (this.loadingStores || this.stores.length) return
      this.loadingStores = true
      this.error = ''
      try {
        this.stores = (await fetchAllPages(request.get, '/api/v1/manage/stores', { '@order': 'entity.name|ASC' }))
          .filter(store => store?.uuid)
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Failed to load stores')
      } finally {
        this.loadingStores = false
      }
    },
    async copy() {
      if (this.copying || !this.sourceStoreUuid || !this.targetStoreUuid) return
      if (this.sourceStoreUuid === this.targetStoreUuid) {
        this.error = t('Source and target stores must be different')
        return
      }
      const source = this.stores.find(store => store.uuid === this.sourceStoreUuid)
      const target = this.stores.find(store => store.uuid === this.targetStoreUuid)
      if (!source || !target) return
      const message = this.mode === 'products'
        ? t('Confirm product copy from {0} to {1}', source.name || source.uuid, target.name || target.uuid)
        : t('Confirm category copy from {0} to {1}', source.name || source.uuid, target.name || target.uuid)
      if (!window.confirm(message)) return

      this.copying = true
      this.error = ''
      this.progress = t('Preparing catalog copy')
      this.result = {
        categoriesCreated: 0,
        categoriesReused: 0,
        productsCreated: 0,
        productsSkipped: 0,
        specificationsCreated: 0
      }
      try {
        const categoryCopy = await this.copyCategories(this.sourceStoreUuid, this.targetStoreUuid)
        this.result.categoriesCreated = categoryCopy.created
        this.result.categoriesReused = categoryCopy.reused
        if (this.mode === 'products') {
          await this.copyProducts(this.sourceStoreUuid, this.targetStoreUuid, categoryCopy)
        }
      } catch (cause) {
        this.error = this.failureMessage(cause, 'Catalog copy failed')
      } finally {
        this.copying = false
        this.progress = ''
        this.refresh()
      }
    },
    async copyCategories(sourceStoreUuid, targetStoreUuid) {
      this.progress = t('Loading product categories')
      const [sourceCategories, targetCategories] = await Promise.all([
        fetchAllPages(request.get, categoryPath, { '@expands': 'parent,store', '@order': 'entity.sortOrder|ASC,entity.name|ASC' }),
        fetchAllPages(request.get, categoryPath, { '@expands': 'parent,store', '@order': 'entity.sortOrder|ASC,entity.name|ASC' })
      ])
      const localSource = sourceCategories.filter(category => categoryStoreUuid(category) === sourceStoreUuid)
      const localTarget = targetCategories.filter(category => categoryStoreUuid(category) === targetStoreUuid)
      const bySlug = targetCategoriesBySlug(localTarget)
      const mapping = new Map()
      let created = 0
      let reused = 0

      for (const sourceCategory of orderedSourceCategories(localSource)) {
        const slug = String(sourceCategory?.slug || '').trim()
        if (!slug) throw new Error('catalog_category_slug_missing')
        let targetCategory = bySlug.get(slug)
        if (targetCategory) {
          reused += 1
        } else {
          const parentReference = sourceCategory?.parent
          let parentUuid = null
          if (parentReference) {
            const mappedParent = mappedCategory(mapping, parentReference)
            if (mappedParent) {
              parentUuid = mappedParent.uuid
            } else {
              const sourceParent = findCategoryByReference(sourceCategories, parentReference)
              if (sourceParent && !categoryStoreUuid(sourceParent)) parentUuid = referenceUuid(sourceParent)
              if (!parentUuid) throw new Error('catalog_category_parent_unresolved')
            }
          }
          this.progress = t('Creating product category {0}', sourceCategory.name || slug)
          const response = await request.post(categoryPath, categoryCopyPayload(sourceCategory, targetStoreUuid, parentUuid))
          targetCategory = response?.data
          if (!targetCategory?.uuid) throw new Error('catalog_category_response_invalid')
          bySlug.set(slug, targetCategory)
          created += 1
        }
        rememberCategoryMapping(mapping, sourceCategory, targetCategory)
      }

      return { mapping, sourceCategories, created, reused }
    },
    async copyProducts(sourceStoreUuid, targetStoreUuid, categoryCopy) {
      this.progress = t('Loading products')
      const [sourceProducts, targetProducts] = await Promise.all([
        fetchAllPages(request.get, productPath, { '@order': 'entity.sortOrder|ASC,entity.name|ASC' }),
        fetchAllPages(request.get, productPath, { '@order': 'entity.sortOrder|ASC,entity.name|ASC' })
      ])
      const localSource = sourceProducts.filter(product => productStoreUuid(product) === sourceStoreUuid && product?.isDeleted !== true)
      const localTarget = targetProducts.filter(product => productStoreUuid(product) === targetStoreUuid && product?.isDeleted !== true)
      const existingNames = new Set(localTarget.map(productNameKey))
      for (let index = 0; index < localSource.length; index += 1) {
        const sourceProduct = localSource[index]
        const nameKey = productNameKey(sourceProduct)
        if (this.skipSameNameProducts && existingNames.has(nameKey)) {
          this.result.productsSkipped += 1
          continue
        }

        const sourceCategory = findCategoryByReference(categoryCopy.sourceCategories, sourceProduct.category)
        let categoryUuid = null
        if (sourceProduct.category) {
          if (!sourceCategory) throw new Error('catalog_product_category_unresolved')
          if (!categoryStoreUuid(sourceCategory)) {
            categoryUuid = referenceUuid(sourceCategory) || referenceUuid(sourceProduct.category)
          } else {
            const targetCategory = mappedCategory(categoryCopy.mapping, sourceCategory)
            if (!targetCategory) throw new Error('catalog_product_category_unresolved')
            if (targetCategory.enabled === false) throw new Error('catalog_product_category_disabled')
            categoryUuid = targetCategory.uuid
          }
        }

        this.progress = t('Copying product {0} of {1}', index + 1, localSource.length)
        const specifications = (await fetchAllPages(
          request.get,
          `${productPath}/${sourceProduct.id}/specifications`,
          { '@order': 'entity.sort|ASC,entity.id|ASC' }
        )).filter(specification => specification?.isDeleted !== true)
        const productResponse = await request.post(productPath, productCopyPayload(sourceProduct, targetStoreUuid, categoryUuid))
        const createdProduct = productResponse?.data
        if (!createdProduct?.id) throw new Error('catalog_product_response_invalid')
        this.result.productsCreated += 1
        existingNames.add(nameKey)

        for (const specification of specifications) {
          await request.post(
            `${productPath}/${createdProduct.id}/specifications`,
            specificationCopyPayload(specification)
          )
          this.result.specificationsCreated += 1
        }
      }
    },
    resetOutcome() {
      if (this.copying) return
      this.error = ''
      this.result = null
      this.progress = ''
    },
    reset() {
      if (this.copying) return
      this.sourceStoreUuid = ''
      this.targetStoreUuid = ''
      this.skipSameNameProducts = true
      this.stores = []
      this.error = ''
      this.result = null
      this.progress = ''
    },
    failureMessage(cause, fallback) {
      const messages = {
        catalog_category_cycle: 'Product category hierarchy contains a cycle',
        catalog_category_identity_missing: 'A product category is missing its identity',
        catalog_category_slug_missing: 'A product category is missing its slug',
        catalog_category_parent_unresolved: 'A product category parent could not be mapped to the target store',
        catalog_category_response_invalid: 'The backend did not return the created product category',
        catalog_product_category_unresolved: 'A product category could not be mapped to the target store',
        catalog_product_category_disabled: 'A target-store category referenced by a product is disabled',
        catalog_product_response_invalid: 'The backend did not return the created product',
        catalog_copy_too_large: 'Too many records were found for a safe browser-side copy'
      }
      if (cause?.message && messages[cause.message]) return t(messages[cause.message])
      const serverMessage = cause?.response?.data?.message
      return serverMessage ? `${t(fallback)}: ${serverMessage}` : t(fallback)
    }
  }
}
</script>

<style scoped>
.catalog-copy-action {
  display: inline-flex;
  align-items: center;
}

.catalog-copy__body {
  display: grid;
  gap: 16px;
  min-height: 120px;
}

.catalog-copy__form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 16px;
}

.catalog-copy__form :deep(.el-select) {
  width: 100%;
}

.catalog-copy__result {
  margin-top: 2px;
}

.catalog-copy__success {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  color: var(--el-color-success);
  font-weight: 600;
}

@media screen and (max-width: 640px) {
  .catalog-copy__form {
    grid-template-columns: 1fr;
  }
}
</style>
