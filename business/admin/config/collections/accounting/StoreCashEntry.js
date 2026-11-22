import { t } from '@/i18n'
import * as ledger from '../../../accounting/finance-config.js'
import { decimalToMinor, minorToDecimal } from '../../../accounting/ledger-ui.mjs'

const categories = ledger.cashCategories
const required = [{ required: true, message: t('This field is required') }]
const field = (property, label, extra = {}) => ({ property, field_options: { label: t(label) }, type: 'input', ...extra })
const column = (property, label, extra = {}) => ({ property, label: t(label), field_options: { sortable: false }, ...extra })
const cashStores = () => ledger.finance.workspace?.cash_stores ?? []
const notPurchase = form => form.category !== 'purchase'
const amountRule = (_rule, value, done) => {
  try { decimalToMinor(value, 2) } catch { return done(new Error(t('invalid_amount'))) }
  done()
}
const storeField = {
  ...field('store_uuid', 'Store', {
    type: 'select', rules: required,
    type_options: {
      filterable: true,
      get options() { return cashStores().map(store => ({ value: store.uuid, label: `${store.name} (${store.code}) · ${store.currency}` })) }
    }
  }),
  get default_value() { return cashStores().length === 1 ? cashStores()[0].uuid : '' }
}
const entryFields = [
  field('date', 'Date', { component: ledger.IsoDateField, rules: required }),
  field('category', 'Cash category', { type: 'select', rules: required, type_options: { options: ledger.options(categories, t) } }),
  field('amount', 'Amount', { rules: [{ validator: amountRule }], type_options: { inputmode: 'decimal' } }),
  field('item', 'Cash purchase item', { hidden: notPurchase, rules: ledger.required(t('This field is required')), type_options: { maxlength: 160 } }),
  field('quantity', 'Quantity', {
    hidden: notPurchase, type_options: { inputmode: 'decimal' },
    rules: [{ validator: (_rule, value, done, form) => {
      const valid = typeof value === 'string' && /^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?$/.test(value)
        && /[1-9]/.test(value) && (form.unit !== 'piece' || !value.includes('.'))
      done(valid ? undefined : new Error(t('invalid_purchase_quantity')))
    } }]
  }),
  field('unit', 'Cash purchase unit', {
    type: 'select', hidden: notPurchase, rules: required, type_options: { options: ledger.options(['g', 'kg', 'ml', 'l', 'piece'], t) }
  }),
  field('note', 'Note', { type: 'textarea', type_options: { maxlength: 500 } })
]
const record = ledger.ledgerCommand({
  action: 'record-cash-entry', label: 'Record cash entry', capability: 'cash_record',
  when: () => cashStores().length > 0,
  notice: 'Recording does not create a journal',
  fields: [storeField, ...entryFields],
  payload: ({ store_uuid, date, category, amount, note, item, quantity, unit }) => ({
    store_uuid, date, category, amount: decimalToMinor(amount, 2), note: note || '',
    direction: category === 'other_income' ? 'income' : 'expense',
    ...(category === 'purchase' ? { metadata: { item, quantity, unit } } : {})
  })
})
const correct = ledger.ledgerCommand({
  action: 'correct-cash-entry', label: 'Correct cash entry', capability: 'cash_record',
  when: row => Boolean(row?.cash_version) && row.state === 'active',
  input: row => ({ id: row.id, cash_version: row.cash_version }),
  initial: row => ({ date: row.entry_date, category: row.category, amount: minorToDecimal(row.amount, 2), note: row.note, ...row.purchase }),
  fields: [...entryFields, field('reason', 'Reason', {
    type: 'textarea', rules: ledger.required(t('This field is required')), type_options: { maxlength: 1000 }
  })],
  payload: ({ date, category, amount, note, item, quantity, unit, reason }) => ({
    date, category, amount: decimalToMinor(amount, 2), note: note || '', reason,
    ...(category === 'purchase' ? { item, quantity, unit } : {})
  })
})
const voidEntry = ledger.ledgerCommand({
  action: 'void-cash-entry', label: 'Void cash entry', capability: 'cash_record',
  when: row => row?.state === 'active' && (!row.journal_id || row.journal_state === 'voided'),
  input: row => ({ id: row.id }),
  notice: 'Voiding hides the entry from the store register but keeps an audit record',
  fields: [field('reason', 'Reason', {
    type: 'textarea', rules: ledger.required(t('This field is required')), type_options: { maxlength: 1000 }
  })]
})
const openJournal = id => ({ name: 'JournalEntryDetail', params: { id } })
const mappingReady = row => [row.debit_code, row.credit_code].every(code =>
  ledger.finance.accounts.some(account => account.code === code && Number(account.active) && Number(account.postable)))
const generate = ledger.ledgerCommand({
  action: 'draft-from-cash', label: 'Generate journal draft', capability: 'draft',
  when: row => row?.state === 'active' && !row.journal_id && mappingReady(row),
  input: row => ({ source_uuid: row.id }),
  notice: 'This creates a draft without posting it',
  navigate: result => openJournal(result.id)
})
const settings = ledger.ledgerLink({
  label: 'Check cash journal mapping', capability: 'configure',
  when: row => row?.state === 'active' && !row.journal_id && !mappingReady(row),
  to: () => ({ name: 'CashJournalMappingList' })
})
const view = ledger.ledgerLink({
  label: 'Open journal', when: row => Boolean(row?.journal_id), to: row => openJournal(row.journal_id)
})

export default {
  StoreCashEntry: {
    entity: ledger.resource('StoreCashEntry', 'cash-entries'),
    adapters: { finance: { requiresBook: true, listHelp: () => t('Cash entries journal help') } },
    form: { fields: [] },
    list: {
      data_processor: ledger.financeList({ filters: ['category'] }),
      disabled_actions: ['new', 'detail', 'edit', 'delete', 'batch_edit', 'batch_delete', 'export'],
      actions: [
        { name: 'cash-record', position: 'top', component: record },
        { name: 'cash-correct', position: 'list', component: correct },
        { name: 'cash-void', position: 'list', component: voidEntry },
        { name: 'cash-generate', position: 'list', component: generate },
        { name: 'cash-mapping-help', position: 'list', component: settings },
        { name: 'cash-open-journal', position: 'list', component: view }
      ],
      list_filter: { category: ledger.choiceFilter(t('Cash category'), categories) },
      list_display: [
        column('entry_date', 'Date'), column('store_name', 'Store'),
        column('category', 'Cash category', { component: ledger.labelCell }), column('amount', 'Amount', { component: ledger.cashAmountCell }),
        column('note', 'Note'), column('debit_code', 'Debit account'), column('credit_code', 'Credit account'),
        column('journal_state', 'Journal status', { component: ledger.stateCell }),
        column('state', 'Status', { component: ledger.stateCell })
      ]
    }
  }
}
