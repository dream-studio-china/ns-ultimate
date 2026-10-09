import { t } from '@/i18n'
import { orderByIdDesc } from '../../../../../core/crud-admin/src/configs/collections/helpers'
import PaymentChannelsSchema from '../../overrides/PaymentChannels.json'

// Business-owned payment setting profiles. A Store binds one profile.
// Channel secrets are encrypted by the backend before storage (see backend issues).
export default {
  PaymentSetting: {
    form: {
      fields: [
        { property: 'code', required: true },
        { property: 'name', required: true },
        {
          property: 'settlementCycle',
          type: 'select',
          required: true,
          default_value: 'D+1',
          help: t('Settlement cycle help'),
          type_options: {
            options: [
              { value: 'T+1', label: 'T+1' },
              { value: 'D+1', label: 'D+1' },
              { value: 'D+0', label: 'D+0' }
            ]
          }
        },
        { property: 'paymentRate', type: 'integer', required: false, default_value: 0, help: t('Payment rate help'), type_options: { min: 0, max: 1, step: 0.01 } },
        { property: 'channels', type: 'json_schema', required: false, type_options: { schema: PaymentChannelsSchema }, help: t('Payment channels help') }
      ]
    },
    list: {
      query: orderByIdDesc,
      disabled_actions: ['delete'],
      list_filter: {
        code: t('Code'),
        name: t('Name')
      },
      list_display: ['id', 'code', 'name', 'settlementCycle', 'paymentRate', 'createdAt', 'updatedAt']
    },
    detail: {
      detail_display: [
        { property: 'channels', type: 'json_schema', type_options: { schema: PaymentChannelsSchema }, full_width: true },
        '__all__'
      ]
    }
  }
}
