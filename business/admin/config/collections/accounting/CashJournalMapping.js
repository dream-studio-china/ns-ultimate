import { t } from '@/i18n'
import * as ledger from '../../../accounting/finance-config.js'

const required = [{ required: true, message: t('This field is required') }]
const configured = () => ledger.finance.workspace?.mapped_cash_categories ?? []
const availableCategories = () => ledger.cashCategories.filter(category => !configured().includes(category))
const eligibleAccounts = () => ledger.finance.accounts.filter(row => Number(row.active) && Number(row.postable))
const account = (property, label) => ({
  property, type: 'select', field_options: { label: t(label) }, rules: required,
  type_options: {
    filterable: true,
    get options() { return eligibleAccounts().map(row => ({ value: row.code, label: `${row.code} - ${row.name}` })) }
  }
})
const column = (property, label, component) => ({
  property, label: t(label), field_options: { sortable: false }, ...(component ? { component } : {})
})
const register = ledger.ledgerLink({
  label: 'Store cash entries', to: () => ({ name: 'StoreCashEntryList' })
})

export default {
  CashJournalMapping: {
    entity: ledger.resource('CashJournalMapping', 'cash-mappings'),
    adapters: {
      finance: {
        requiresBook: true,
        allow: { new: ledger.can('configure'), edit: ledger.can('configure'), delete: ledger.can('configure') },
        listHelp: () => t(ledger.finance.can('configure') && eligibleAccounts().length < 2
          ? 'Create or enable two postable accounts before setting cash journal mappings'
          : 'Cash journal settings help')
      }
    },
    form: {
      fields: [
        {
          property: 'category', type: 'select', field_options: { label: t('Cash category') }, rules: required,
          hidden: 'update', type_options: { get options() { return ledger.options(availableCategories(), t) } },
          get default_value() { return availableCategories()[0] ?? '' }
        },
        account('debit_code', 'Debit account'),
        account('credit_code', 'Credit account')
      ]
    },
    list: {
      data_processor: ledger.financeList({ reloadWorkspace: true }),
      disabled_actions: ['detail', 'batch_edit', 'batch_delete', 'export'],
      actions: [{ name: 'cash-mapping-register', position: 'top', component: register }],
      list_display: [
        column('category', 'Cash category', ledger.labelCell),
        column('direction', 'Cash direction', ledger.labelCell),
        column('debit_account', 'Debit account'),
        column('credit_account', 'Credit account'),
        column('status', 'Status', ledger.stateCell)
      ]
    }
  }
}
