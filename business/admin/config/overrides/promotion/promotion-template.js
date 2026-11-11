import { t } from '@/i18n'
import PromotionTemplate from '../../../../../core/crud-admin/src/configs/collections/promotion/PromotionTemplate.js'

const config = PromotionTemplate.PromotionTemplate

const typeOptions = [
  { value: 'full_reduction', label: t('Full reduction') },
  { value: 'discount', label: t('Discount') },
  { value: 'member_discount', label: t('Member discount') },
  { value: 'nth_discount', label: t('Nth item discount') },
  { value: 'gift', label: t('Gift') },
  { value: 'free_shipping', label: t('Free shipping') }
]

const phaseOptions = [
  { value: 0, label: t('Inner promotion phase') },
  { value: 1, label: t('Outer promotion phase') }
]

export default {
  ...config,
  form: {
    ...config.form,
    fields: [
      { property: 'name', required: true },
      { property: 'description', type: 'text', required: false },
      {
        property: 'type',
        type: 'select',
        required: true,
        default_value: 'full_reduction',
        help: t('PromotionTemplate type help'),
        type_options: { options: typeOptions }
      },
      {
        property: 'phase',
        type: 'select',
        default_value: 0,
        help: t('PromotionTemplate phase help'),
        type_options: { options: phaseOptions }
      },
      { property: 'enabled', type: 'boolean', required: false, default_value: false, help: t('PromotionTemplate enabled help') },
      { property: 'dsl', type: 'code', required: true, help: t('PromotionTemplate dsl help') },
      { property: 'fields', type: 'json', required: false, help: t('PromotionTemplate fields help') }
    ]
  }
}
