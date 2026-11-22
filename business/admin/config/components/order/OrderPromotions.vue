<template>
  <span v-if="snapshot" class="order-promotions" :title="tooltip || undefined">
    <template v-if="snapshot.applied.length">
      <el-tag
        v-for="(promotion, index) in visible"
        :key="promotion.id ?? index"
        size="small"
        effect="plain"
        type="warning"
      >
        {{ promotion.name }}
      </el-tag>
      <el-tag v-if="hiddenCount" size="small" effect="plain" type="info">+{{ hiddenCount }}</el-tag>
    </template>
    <span v-else class="order-promotions__empty">{{ $t('No promotion used') }}</span>
  </span>
  <span v-else class="order-promotions__empty">{{ $t('Not recorded') }}</span>
</template>

<script>
import {
  PROMOTION_LIST_LIMIT,
  orderPromotionsFromMetadata,
  promotionPhaseLabel
} from './order-promotions.mjs'

export default {
  name: 'OrderPromotions',
  props: {
    data: { type: [Object, String, Array], default: null },
    record: { type: Object, default: () => ({}) }
  },
  computed: {
    snapshot() {
      return orderPromotionsFromMetadata(this.record)
    },
    visible() {
      return this.snapshot?.applied.slice(0, PROMOTION_LIST_LIMIT) ?? []
    },
    hiddenCount() {
      return Math.max(0, (this.snapshot?.applied.length ?? 0) - PROMOTION_LIST_LIMIT)
    },
    tooltip() {
      const applied = this.snapshot?.applied ?? []
      if (!applied.length) return ''
      return applied
        .map(promotion => `${this.$t(promotionPhaseLabel(promotion.phase))}: ${promotion.name}`)
        .join('\n')
    }
  }
}
</script>

<style scoped>
.order-promotions {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
}

.order-promotions__empty {
  color: var(--el-text-color-secondary);
}
</style>
