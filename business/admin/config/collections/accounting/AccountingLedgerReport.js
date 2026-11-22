import { t } from '@/i18n'
import * as ledger from '../../features/accounting/finance-config.js'

const column = (property, label, extra = {}) => ({ property, label: t(label), field_options: { sortable: false }, ...extra })

export default {
  AccountingLedgerReport: {
    entity: ledger.resource('AccountingLedgerReport', 'reports/ledger'),
    adapters: { finance: { requiresBook: true } },
    form: { fields: [] },
    list: {
      // The ledger report needs an account; rows load once one is selected.
      data_processor: ledger.financeList({ filters: ['from', 'to', 'account'], report: 'ledger' }),
      disabled_actions: ['new', 'detail', 'edit', 'delete', 'batch_edit', 'batch_delete', 'export', 'lines'],
      actions: [{ name: 'ledger-report-summary', position: 'top', component: ledger.reportSummary('ledger') }],
      list_filter: {
        from: ledger.dateFilter(t('From date'), ledger.openingDate),
        to: ledger.dateFilter(t('To date'), ledger.bookToday),
        account: ledger.accountFilter(t('Account code'))
      },
      list_display: [
        column('accounting_date', 'Accounting date'),
        column('number', 'Journal number'),
        column('summary', 'Summary'),
        column('side', 'Debit or credit', { component: ledger.labelCell }),
        column('amount', 'Amount', { component: ledger.moneyCell, field_options: { sortable: false, align: 'right' } }),
        column('description', 'Description'),
        column('balance', 'Balance', { component: ledger.moneyCell, field_options: { sortable: false, align: 'right' } })
      ]
    }
  }
}
