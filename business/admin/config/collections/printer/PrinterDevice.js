import { t } from '@/i18n'
import PrinterDeviceStatusAction from '../../components/PrinterDeviceStatusAction.js'

const field = (property, label) => ({ property, label: t(label) })
export default {
  PrinterDevice: {
    entity: { name: 'PrinterDevice', prefix: '/api/v1/manage/printer', plural: 'devices' },
    form: { fields: [
      {
        property: 'account', label: t('Printer account'), type: 'RelationToOne', required: true,
        relation: { target: 'PrinterAccount', plural: 'accounts', prefix: '/api/v1/manage/printer', valueKey: 'id' }
      },
      { property: 'name', label: t('Device name'), required: true },
      { property: 'serialNumber', label: t('Device serial number'), required: true },
      { property: 'kind', label: t('Printer type'), type: 'select', required: true, type_options: { options: [
        { value: 'receipt', label: t('Receipt') }, { value: 'label', label: t('Label') }
      ] } },
      {
        property: 'storeUuid',
        label: t('Store UUID optional'),
        type: 'RelationToOne',
        required: false,
        relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' }
      },
      { property: 'model', label: t('Printer model optional'), required: false },
      { property: 'widthMm', label: t('Label width mm'), type: 'integer', required: false },
      { property: 'heightMm', label: t('Label height mm'), type: 'integer', required: false },
      { property: 'enabled', label: t('Enabled'), type: 'boolean', required: false }
    ] },
    list: {
      disabled_actions: ['batch_edit', 'batch_delete'],
      actions: [{ name: 'printer-device-refresh-status', position: 'top', component: PrinterDeviceStatusAction }],
      list_filter: { name: t('Name'), serialNumber: t('Serial number'), kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') } },
      list_display: [
        field('account', 'Printer account'), field('name', 'Device name'), field('serialNumber', 'Device serial number'),
        field('kind', 'Printer type'), field('model', 'Printer model optional'),
        { ...field('storeUuid', 'Store UUID optional'), relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' } },
        field('enabled', 'Enabled'), field('providerStatus', 'providerStatus'), field('statusCheckedAt', 'statusCheckedAt')
      ]
    },
    detail: { detail_display: [
      field('uuid', 'UUID'), field('account', 'Printer account'), field('name', 'Device name'),
      field('serialNumber', 'Device serial number'), field('kind', 'Printer type'), field('model', 'Printer model optional'),
      { ...field('storeUuid', 'Store UUID optional'), relation: { target: 'Store', plural: 'stores', prefix: '/api/v1/manage', valueKey: 'uuid' } },
      field('widthMm', 'Label width mm'), field('heightMm', 'Label height mm'), field('enabled', 'Enabled'),
      field('providerStatus', 'providerStatus'), field('statusCheckedAt', 'statusCheckedAt'), field('createdAt', 'Created at')
    ] }
  }
}
