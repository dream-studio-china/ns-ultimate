import { t } from '@/i18n'
import * as ledger from '../../../accounting/finance-config.js'

const column = (property, label, extra = {}) => ({ property, label: t(label), field_options: { sortable: false }, ...extra })
const amounts = ['opening_debit', 'opening_credit', 'movement_debit', 'movement_credit', 'closing_debit', 'closing_credit']

export default {
  AccountingTrialBalance: {
    entity: ledger.resource('AccountingTrialBalance', 'reports/trial'),
    adapters: { finance: { requiresBook: true } },
    form: { fields: [] },
    list: {
      data_processor: ledger.financeList({ filters: ['from', 'to'], report: 'trial' }),
      disabled_actions: ['new', 'detail', 'edit', 'delete', 'batch_edit', 'batch_delete', 'export', 'lines'],
      actions: [{ name: 'trial-balance-summary', position: 'top', component: ledger.reportSummary('trial') }],
      list_filter: {
        from: ledger.dateFilter(t('From date'), ledger.openingDate),
        to: ledger.dateFilter(t('To date'), ledger.bookToday)
      },
      list_display: [
        column('code', 'Account code'),
        column('name', 'Account name'),
        ...amounts.map(key => column(key, key, { component: ledger.moneyCell, field_options: { sortable: false, align: 'right' } }))
      ]
    }
  }
}
