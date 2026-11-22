<script>
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'
import { fetchAllPages } from './catalog-copy.mjs'
import {
  normalizeStoreUuid,
  productCategoryFilterOptions,
  productFilterState
} from './product-filter-scope.mjs'

const categoryEndpoint = apiPath(API_PREFIX, 'manage/product-categories')

export default {
  name: 'ProductCategoryFilterScope',
  props: {
    modelValue: { type: [String, Number], default: null }
  },
  data() {
    return {
      loadSequence: 0
    }
  },
  computed: {
    state() {
      return productFilterState()
    },
    storeUuid() {
      return this.state.storeUuid
    }
  },
  watch: {
    storeUuid: {
      immediate: true,
      handler(value, previousValue) {
        const currentStoreUuid = normalizeStoreUuid(value)
        const previousStoreUuid = normalizeStoreUuid(previousValue)
        if ((!currentStoreUuid || (previousValue !== undefined && previousStoreUuid !== currentStoreUuid)) && this.modelValue) {
          this.$emit('update:modelValue', null)
        }
        this.loadCategories(currentStoreUuid)
      }
    }
  },
  methods: {
    async loadCategories(storeUuid) {
      const sequence = ++this.loadSequence
      this.state.data.splice(0)
      if (!storeUuid) return

      try {
        const categories = await fetchAllPages(
          request.get,
          categoryEndpoint,
          { '@expands': 'store', '@order': 'entity.sortOrder|ASC,entity.name|ASC' }
        )
        if (sequence !== this.loadSequence || storeUuid !== this.storeUuid) return

        const globalLabel = t('Global')
        const options = productCategoryFilterOptions(categories, storeUuid).map(option => ({
          ...option,
          label: option.isGlobal ? `${option.label} · ${globalLabel}` : option.label
        }))
        this.state.data.push(...options)

        const selected = this.modelValue === null || this.modelValue === undefined ? '' : String(this.modelValue)
        if (selected && !options.some(option => option.value === selected)) {
          this.$emit('update:modelValue', null)
        }
      } catch {
        if (sequence === this.loadSequence) this.state.data.splice(0)
      }
    }
  },
  render() {
    return null
  }
}
</script>
