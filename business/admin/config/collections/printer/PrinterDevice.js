import { t } from '@/i18n'
import PrinterDeviceAction from '../../components/PrinterDeviceAction.js'
export default {
  PrinterDevice: {
    entity: { name: 'PrinterDevice', prefix: '/api/v1/manage/printer', plural: 'devices' },
    form: { fields: [] },
    list: {
      disabled_actions: ['new', 'edit', 'delete', 'detail', 'batch_edit', 'batch_delete'],
      actions: [{ name: 'printer-device-register', position: 'top', component: PrinterDeviceAction }],
      list_filter: { name: t('Name'), serialNumber: t('Serial number'), kind: { __label: t('Kind'), receipt: t('Receipt'), label: t('Label') } },
      list_display: ['name', 'serialNumber', 'kind', 'model', 'storeUuid', 'enabled', 'providerStatus', 'statusCheckedAt']
    },
    detail: { detail_display: ['uuid', 'name', 'serialNumber', 'kind', 'model', 'storeUuid', 'widthMm', 'heightMm', 'enabled', 'providerStatus', 'statusCheckedAt'] }
  }
}
