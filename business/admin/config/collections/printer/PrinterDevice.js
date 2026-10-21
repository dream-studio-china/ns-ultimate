import { t } from '@/i18n'
import PrinterDeviceAction from '../../components/PrinterDeviceAction.js'
export default {
  PrinterDevice: {
    entity: { name: 'PrinterDevice', prefix: '/api/v1/manage/printer', plural: 'devices' },
    form: { fields: [
      { property: 'name', required: true },
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
      disabled_actions: ['new', 'batch_edit', 'batch_delete'],
      actions: [{ name: 'printer-device-register', position: 'top', component: PrinterDeviceAction }],
      list_filter: { name: t('Name'), serialNumber: t('Serial number'), kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') } },
      list_display: [
        'name', 'serialNumber', 'kind', 'model',
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
