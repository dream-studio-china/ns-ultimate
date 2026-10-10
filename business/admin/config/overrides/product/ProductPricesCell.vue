<template>
  <div v-if="prices.length" class="product-prices">
    <div v-for="(price, index) in prices" :key="price.id || `${price.name}-${index}`" class="product-prices__item">
      <span class="product-prices__name">{{ price.name }}</span>
      <span class="product-prices__value">{{ formatPrice(price.value) }}</span>
    </div>
  </div>
  <span v-else-if="loading" class="product-prices__empty">{{ $t('Loading product prices') }}</span>
  <span v-else-if="loadFailed" class="product-prices__empty">
    {{ $t('Product prices failed to load') }}
    <el-button link type="primary" size="small" @click="loadSpecifications">
      {{ $t('Retry product prices') }}
    </el-button>
  </span>
  <span v-else class="product-prices__empty">{{ $t('No price configured') }}</span>
</template>

<script>
import { formatCurrency } from '@/utils/currency'
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'

function normalizePrices(specifications) {
  if (!Array.isArray(specifications)) return []

  return specifications.map(specification => {
    const details = specification?.__metadata || specification || {}
    return {
      id: specification?.id,
      name: details.name || specification?.name || specification?.__toString || '',
      value: details.price ?? specification?.price,
      isDeleted: details.isDeleted ?? specification?.isDeleted
    }
  }).filter(price => price.name && price.value !== null && price.value !== undefined && !price.isDeleted)
}

export default {
  name: 'ProductPricesCell',
  props: {
    data: { type: [Array, Object], default: () => [] },
    record: { type: Object, default: () => ({}) }
  },
  computed: {
    payloadPrices() {
      return normalizePrices(this.data)
    },
    prices() {
      return this.payloadPrices.length ? this.payloadPrices : this.loadedPrices
    }
  },
  data() {
    return {
      loadedPrices: [],
      loading: false,
      loadFailed: false,
      requestSequence: 0
    }
  },
  watch: {
    'record.id': {
      immediate: true,
      handler() {
        this.loadedPrices = []
        this.loadFailed = false
        this.loadSpecifications()
      }
    }
  },
  methods: {
    formatPrice(value) {
      return formatCurrency(value, { type_options: { multiplier: 100, currency: 'CNY' } })
    },
    async loadSpecifications() {
      if (this.payloadPrices.length) return

      const productId = this.record?.id
      if (!productId) return

      const sequence = ++this.requestSequence
      this.loading = true
      this.loadFailed = false

      try {
        const response = await request.get(
          apiPath(API_PREFIX, `manage/products/${productId}/specifications`),
          { params: { page: 1, limit: 100 } }
        )
        const rows = Array.isArray(response?.data)
          ? response.data
          : Array.isArray(response?.data?.data)
            ? response.data.data
            : []

        if (sequence === this.requestSequence && productId === this.record?.id) {
          this.loadedPrices = normalizePrices(rows)
        }
      } catch {
        if (sequence === this.requestSequence && productId === this.record?.id) {
          this.loadFailed = true
        }
      } finally {
        if (sequence === this.requestSequence) this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.product-prices {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 150px;
}

.product-prices__item {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  white-space: nowrap;
}

.product-prices__name {
  color: var(--text-secondary, #606266);
}

.product-prices__value {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.product-prices__empty {
  color: var(--text-secondary, #909399);
}
</style>
