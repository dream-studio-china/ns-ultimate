<template>
  <div class="order-promotions-detail">
    <template v-if="snapshot">
      <el-table v-if="snapshot.applied.length" :data="snapshot.applied" size="small" border>
        <el-table-column prop="name" :label="$t('Promotion')" min-width="180" />
        <el-table-column :label="$t('Promotion calculation phase')" min-width="150">
          <template #default="{ row }">{{ $t(promotionPhaseLabel(row.phase)) }}</template>
        </el-table-column>
        <el-table-column :label="$t('Type')" min-width="150">
          <template #default="{ row }">{{ typeLabel(row.type) }}</template>
        </el-table-column>
      </el-table>
      <p v-else class="order-promotions-detail__empty">{{ $t('No promotion used') }}</p>
      <p class="order-promotions-detail__hint">
        {{ $t('Promotion snapshot hint') }}
        <template v-if="recordedAt"> · {{ $t('Recorded at') }} {{ recordedAt }}</template>
      </p>
    </template>
    <p v-else class="order-promotions-detail__empty">{{ $t('Not recorded') }}</p>
  </div>
</template>

<script>
import {
  formatPromotionRecordedAt,
  orderPromotionsFromMetadata,
  promotionPhaseLabel,
  promotionTypeLabel
} from './order-promotions.mjs'

export default {
  name: 'OrderPromotionsDetail',
  props: {
    data: { type: [Object, String, Array], default: null },
    record: { type: Object, default: () => ({}) }
  },
  computed: {
    snapshot() {
      return orderPromotionsFromMetadata(this.record)
    },
    recordedAt() {
      return formatPromotionRecordedAt(this.snapshot?.recordedAt)
    }
  },
  methods: {
    promotionPhaseLabel,
    typeLabel(type) {
      const key = promotionTypeLabel(type)
      return key ? this.$t(key) : '—'
    }
  }
}
</script>

<style scoped>
.order-promotions-detail__empty {
  margin: 0;
  color: var(--el-text-color-secondary);
}

.order-promotions-detail__hint {
  margin: 8px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
