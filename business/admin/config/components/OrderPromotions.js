import { t } from '@/i18n'
import OrderPromotions from './OrderPromotions.vue'
import OrderPromotionsDetail from './OrderPromotionsDetail.vue'

export function createOrderPromotionsField() {
  return {
    property: 'promotions',
    label: t('Promotions'),
    component: OrderPromotions,
    field_options: { sortable: false, minWidth: 180 }
  }
}

export function createOrderPromotionsDetailField() {
  return {
    property: 'promotionDetails',
    label: t('Promotion details'),
    component: OrderPromotionsDetail,
    full_width: true
  }
}

export default OrderPromotions
