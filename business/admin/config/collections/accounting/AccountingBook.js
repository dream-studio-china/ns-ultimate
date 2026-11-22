import { t } from '@/i18n'
import * as ledger from '../../../accounting/finance-config.js'

const column = (property, label, extra = {}) => ({ property, label: t(label), ...extra })
const input = (property, label, extra = {}) => ({ property, field_options: { label: t(label) }, ...extra })
const required = ledger.required(t('This field is required'))
const pending = record => record?.opening_state === 'pending' && Number(record.id) === Number(ledger.finance.book?.id)

const openingJournal = ledger.ledgerLink({
  label: 'New opening journal', capability: 'draft', when: pending,
  to: () => ({ name: 'JournalEntryCreate', params: { origin: 'opening' } })
})
const approveZeroOpening = ledger.ledgerCommand({
  action: 'approve-zero-opening', label: 'Approve zero opening', capability: 'post', type: 'warning', when: pending,
  fields: [input('reason', 'Reason', { type: 'textarea', rules: required, type_options: { maxlength: 1000, showWordLimit: true } })]
})

const display = [
  column('entity', 'Accounting entity'),
  column('store_uuid', 'Store'),
  column('currency', 'Base currency code'),
  column('scale', 'Currency scale'),
  column('timezone', 'Book timezone'),
  column('opening_date', 'Opening date'),
  column('opening_state', 'Opening position', { component: ledger.stateCell })
]

export default {
  AccountingBook: {
    entity: ledger.resource('AccountingBook', 'books'),
    adapters: { finance: { allow: { new: ledger.can('configure') } } },
    form: {
      fields: [
        input('store_uuid', 'Store UUID', {
          required: true,
          rules: [{ required: true, pattern: /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i, message: t('Enter a valid store UUID') }]
        }),
        input('entity', 'Accounting entity', { rules: required, type_options: { maxlength: 160 } }),
        input('currency', 'Base currency code', {
          rules: [{ required: true, pattern: /^[A-Z]{3}$/, message: t('Base currency code') }], type_options: { maxlength: 3 }
        }),
        input('scale', 'Currency scale', {
          type: 'integer', rules: [{ required: true, type: 'number', message: t('This field is required') }],
          type_options: { min: 0, max: 6, precision: 0 }
        }),
        input('timezone', 'Book timezone', { rules: required, type_options: { placeholder: 'Asia/Shanghai' } }),
        input('opening_date', 'Opening date', { component: ledger.IsoDateField, rules: required })
      ]
    },
    list: {
      data_processor: ledger.financeList({ reloadWorkspace: true }),
      disabled_actions: ['edit', 'delete', 'batch_edit', 'batch_delete', 'export'],
      actions: [
        { name: 'accounting-select-book', position: 'list', component: ledger.bookSelection },
        { name: 'accounting-opening-journal', position: 'list', component: openingJournal },
        { name: 'accounting-approve-zero-opening', position: 'list', component: approveZeroOpening }
      ],
      list_display: display.map(field => ({ ...field, field_options: { sortable: false } }))
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: [
        ...display,
        column('operations', 'Actions', { component: ledger.ledgerOperations(ledger.bookSelection, openingJournal, approveZeroOpening), full_width: true })
      ]
    }
  }
}
