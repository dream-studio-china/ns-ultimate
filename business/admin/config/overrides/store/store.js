import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'
import StoreContactSchema from '../../../../../core/crud-admin/src/configs/collections/store/StoreContact.json'
import StoreAddressSchema from './StoreAddress.json'
import StoreSettingsSchema from './StoreSettings.json'
import OptionalStoreSchemaField from './OptionalStoreSchemaField.vue'

// Business override of the core Store entity (declared explicitly in
// business/admin/config/index.js `replace`). Only code, name and status are
// required; timezone defaults to Asia/Shanghai; contact/address/settings stay
// optional. List and detail mirror the core configuration.
export default {
  form: {
    fields: [
      { property: 'code', required: true },
      { property: 'name', required: true },
      {
        property: 'status',
        type: 'select',
        required: true,
        default_value: 'activate',
        help: t('Store status help'),
        type_options: {
          options: [
            { value: 'activate', label: t('Activate') },
            { value: 'suspend', label: t('Suspend') },
            { value: 'close', label: t('Close') }
          ]
        }
      },
      { property: 'timezone', type: 'string', default_value: 'Asia/Shanghai', required: false },
      { property: 'contact', type: 'json_schema', required: false, component: OptionalStoreSchemaField, type_options: { schema: StoreContactSchema }, help: t('Store contact help') },
      { property: 'address', type: 'json_schema', required: false, component: OptionalStoreSchemaField, type_options: { schema: StoreAddressSchema }, help: t('Store address help') },
      { property: 'settings', type: 'json_schema', required: false, component: OptionalStoreSchemaField, type_options: { schema: StoreSettingsSchema }, help: t('Store settings help') },
      { property: 'paymentSetting', required: false, help: t('Payment setting help') }
    ]
  },
  list: {
    query: orderByIdDesc,
    disabled_actions: ['delete'],
    list_filter: {
      code: t('Code'),
      name: t('Name'),
      timezone: t('Timezone'),
      status: {
        __label: t('Status'),
        activate: t('Activate'),
        suspend: t('Suspend'),
        close: t('Close')
      }
    },
    list_display: [
      'id',
      'code',
      'name',
      'status',
      'timezone',
      'createdAt',
      'updatedAt'
    ]
  },
  detail: {
    detail_display: [
      { property: 'contact', type: 'json_schema', type_options: { schema: StoreContactSchema }, full_width: true },
      { property: 'address', type: 'json_schema', type_options: { schema: StoreAddressSchema }, full_width: true },
      { property: 'settings', type: 'json_schema', type_options: { schema: StoreSettingsSchema }, full_width: true },
      '__all__'
    ]
  }
}
