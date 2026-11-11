import { t } from '@/i18n'
import Promotion from '../../../../../core/crud-admin/src/configs/collections/promotion/Promotion.js'

const config = Promotion.Promotion

const conflictModeOptions = [
  { value: 'stackable', label: t('Stackable') },
  { value: 'exclusive', label: t('Exclusive') },
  { value: 'lock_item', label: t('Lock item') },
  { value: 'best_price', label: t('Best price') }
]

export default {
  ...config,
  form: {
    ...config.form,
    fields: [
      { property: 'name', required: true },
      { property: 'description', type: 'text', required: false },
      {
        property: 'template',
        label: t('Promotion Template'),
        required: true,
        relation: { entity: 'PromotionTemplate' },
        help: t('Promotion template help')
      },
      { property: 'storeCode', required: false, help: t('Promotion storeCode help') },
      { property: 'enabled', type: 'boolean', required: false, default_value: false, help: t('Promotion enabled help') },
      { property: 'startTime', type: 'datetime', required: false, help: t('Promotion schedule help') },
      { property: 'endTime', type: 'datetime', required: false, help: t('Promotion schedule help') },
      {
        property: 'config',
        type: 'json',
        required: false,
        help: t('Promotion config help')
      },
      {
        property: 'conflictMode',
        type: 'select',
        default_value: 'stackable',
        help: t('Promotion conflictMode help'),
        type_options: { options: conflictModeOptions }
      }
    ]
  },
  list: {
    ...config.list,
    list_filter: {
      ...config.list.list_filter,
      conflictMode: {
        __label: t('Conflict Mode'),
        stackable: t('Stackable'),
        exclusive: t('Exclusive'),
        lock_item: t('Lock item'),
        best_price: t('Best price')
      }
    }
  }
}
