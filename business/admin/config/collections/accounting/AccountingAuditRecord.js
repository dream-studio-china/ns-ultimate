import { t } from '@/i18n'
import * as ledger from '../../../accounting/finance-config.js'

const column = (property, label, extra = {}) => ({ property, label: t(label), ...extra })

const display = [
  column('occurred_at', 'Occurred at'),
  column('actor', 'Actor'),
  column('action', 'Actions', { component: ledger.labelCell }),
  column('target', 'Target')
]

export default {
  AccountingAuditRecord: {
    entity: ledger.resource('AccountingAuditRecord', 'audit'),
    adapters: { finance: { requires: 'audit', requiresBook: true } },
    form: { fields: [] },
    list: {
      data_processor: ledger.financeList(),
      disabled_actions: ['new', 'edit', 'delete', 'batch_edit', 'batch_delete', 'export'],
      list_display: display.map(field => ({ ...field, field_options: { sortable: false } }))
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: [
        ...display,
        column('correlation_id', 'Command key'),
        column('detail', 'Audit detail', { type: 'json', full_width: true })
      ]
    }
  }
}
