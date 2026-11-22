import { t } from '@/i18n'
import * as ledger from '../../../accounting/finance-config.js'

const column = (property, label, extra = {}) => ({ property, label: t(label), ...extra })
const reason = {
  property: 'reason', type: 'textarea', field_options: { label: t('Reason') },
  rules: ledger.required(t('This field is required')), type_options: { maxlength: 1000, showWordLimit: true }
}
const month = record => ({ month: record.month })

const lock = ledger.ledgerCommand({
  action: 'lock-period', label: 'Lock period', capability: 'lock', type: 'warning',
  when: record => record?.state === 'open', input: month, fields: [reason]
})
const reopen = ledger.ledgerCommand({
  action: 'reopen-period', label: 'Reopen period', capability: 'reopen',
  when: record => record?.state === 'locked', input: month, fields: [reason]
})

const display = [
  column('month', 'Accounting month'),
  column('state', 'Status', { component: ledger.stateCell })
]

export default {
  AccountingPeriod: {
    entity: ledger.resource('AccountingPeriod', 'accounting-periods'),
    adapters: { finance: { requiresBook: true, allow: { new: ledger.can('configure') } } },
    form: {
      fields: [{
        property: 'month', required: true, component: ledger.IsoDateField,
        field_options: { label: t('Accounting month') }, type_options: { picker: 'month' }
      }]
    },
    list: {
      data_processor: ledger.financeList({ sortable: true, reloadWorkspace: true }),
      disabled_actions: ['edit', 'delete', 'batch_edit', 'batch_delete', 'export'],
      actions: [
        { name: 'accounting-period-lock', position: 'list', component: lock },
        { name: 'accounting-period-reopen', position: 'list', component: reopen }
      ],
      list_display: display.map(field => ({ ...field, field_options: { sortable: 'custom' } }))
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: [...display, column('operations', 'Actions', { component: ledger.ledgerOperations(lock, reopen), full_width: true })]
    }
  }
}
