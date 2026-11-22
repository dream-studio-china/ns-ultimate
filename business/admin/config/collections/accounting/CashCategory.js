import { t } from '@/i18n'
import * as ledger from '../../../accounting/finance-config.js'

const required = [{ required: true, whitespace: true, message: t('This field is required') }]
const column = (property, label, component) => ({
  property, label: t(label), field_options: { sortable: false }, ...(component ? { component } : {})
})

const entries = ledger.ledgerLink({
  label: 'Store cash entries', to: () => ({ name: 'StoreCashEntryList' })
})
const mappings = ledger.ledgerLink({
  label: 'Cash journal settings', to: () => ({ name: 'CashJournalMappingList' })
})

export default {
  CashCategory: {
    entity: ledger.resource('CashCategory', 'cash-categories'),
    adapters: {
      finance: {
        requiresBook: true,
        allow: { new: ledger.can('configure'), edit: ledger.can('configure'), delete: ledger.can('configure') },
        listHelp: () => t('Cash categories help')
      }
    },
    form: {
      fields: [
        { property: 'name', type: 'input', field_options: { label: t('Category name') }, rules: required, type_options: { maxlength: 120 } },
        {
          property: 'direction', type: 'select', field_options: { label: t('Cash direction') }, rules: required,
          type_options: { options: [
            { value: 'expense', label: t('cash_expense') },
            { value: 'income', label: t('cash_income') }
          ] }
        }
      ]
    },
    list: {
      data_processor: ledger.financeList({ reloadWorkspace: true }),
      disabled_actions: ['detail', 'batch_edit', 'batch_delete', 'export'],
      actions: [
        { name: 'cash-category-entries', position: 'top', component: entries },
        { name: 'cash-category-mappings', position: 'top', component: mappings }
      ],
      list_display: [column('name', 'Category name', ledger.labelCell), column('direction', 'Cash direction', ledger.cashDirectionCell)]
    }
  }
}
