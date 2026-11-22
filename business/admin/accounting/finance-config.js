/*
 * Building blocks for business/admin/config/collections/accounting/.
 *
 * The collection configs declare each Finance resource's columns, fields,
 * filters, detail, and actions; EasyAdmin renders them. These helpers only
 * bind those declarations to the Finance API, capabilities, and exact money.
 */
import { BOOK_API, FINANCE_API, finance } from './finance-context'

export { FINANCE_API, finance }
export { accountFilter, bookToday, choiceFilter, dateFilter, financeList, openingDate, textFilter } from './finance-list'
export { bookSelection, cashAmountCell, cashDirectionCell, journalLinkCell, labelCell, ledgerCommand, ledgerLink, ledgerOperations, moneyCell, recordDialog, reportSummary, stateCell } from './finance-components'
export { default as IsoDateField } from './IsoDateField.vue'
export { default as AvailableStoreField } from './AvailableStoreField.vue'
export { default as JournalAttachmentsField } from './JournalAttachmentsField.vue'
export { default as JournalLinesField } from './JournalLinesField.vue'

export const sides = ['debit', 'credit']
export const cashCategories = () => (finance.workspace?.cash_categories ?? []).map(category => category.id)
export const classes = ['asset', 'liability', 'equity', 'revenue', 'expense']

/** EasyAdmin entity identity for a Finance resource path. */
export const resource = (name, plural) => ({ name, prefix: plural === 'books' ? FINANCE_API : BOOK_API, plural })

export const can = capability => () => finance.can(capability)

/** Hide evidence-level fields on existing records from users without `audit`. */
export const auditOnlyOnUpdate = (_form, id) => Boolean(id) && !finance.can('audit')

export const required = message => [{ required: true, whitespace: true, message }]

export const options = (values, translate) => values.map(value => ({ value, label: translate(value) }))
