import { t } from '@/i18n'
import PrinterDeviceStatusAction from '../../components/PrinterDeviceStatusAction.js'
export default {
  PrinterDevice: {
    entity: { name: 'PrinterDevice', prefix: '/api/v1/manage/printer', plural: 'devices' },
    form: { fields: [
      {
        property: 'account', type: 'RelationToOne', required: true,
        relation: { target: 'PrinterAccount', plural: 'accounts', prefix: '/api/v1/manage/printer', valueKey: 'id' }
      },
      { property: 'name', required: true },
      { property: 'serialNumber', required: true },
      { property: 'kind', type: 'select', required: true, type_options: { options: [
        { value: 'receipt', label: t('Receipt') }, { value: 'label', label: t('Label') }
      ] } },
      {
        property: 'storeUuid',
        type: 'RelationToOne',
        required: false,
        relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' }
      },
      { property: 'model', required: false },
      { property: 'widthMm', type: 'integer', required: false },
      { property: 'heightMm', type: 'integer', required: false },
      { property: 'enabled', type: 'boolean', required: false }
    ] },
    list: {
      disabled_actions: ['batch_edit', 'batch_delete'],
      actions: [{ name: 'printer-device-refresh-status', position: 'top', component: PrinterDeviceStatusAction }],
      list_filter: { name: t('Name'), serialNumber: t('Serial number'), kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') } },
      list_display: [
        'account', 'name', 'serialNumber', 'kind', 'model',
        { property: 'storeUuid', relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' } },
        'enabled', 'providerStatus', 'statusCheckedAt'
      ]
    },
    detail: { detail_display: [
      'uuid', 'account', 'name', 'serialNumber', 'kind', 'model',
      { property: 'storeUuid', relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' } },
      'widthMm', 'heightMm', 'enabled', 'providerStatus', 'statusCheckedAt', 'createdAt'
    ] }
  }
}
