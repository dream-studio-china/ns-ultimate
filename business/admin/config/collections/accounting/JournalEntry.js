import { t } from '@/i18n'
import * as ledger from '../../../accounting/finance-config.js'

const { finance } = ledger
const column = (property, label, extra = {}) => ({ property, label: t(label), ...extra })
const input = (property, label, extra = {}) => ({ property, field_options: { label: t(label) }, ...extra })
const required = ledger.required(t('This field is required'))
const reason = input('reason', 'Reason', { type: 'textarea', rules: required, type_options: { maxlength: 1000, showWordLimit: true } })
const draft = record => record?.state === 'draft'
const revision = record => ({ id: record.id, revision: record.revision })
const detail = result => ({ name: 'JournalEntryDetail', params: { id: result.id } })

// Create, edit, and detail open as dialogs over the register.
const newDraft = ledger.recordDialog({ entity: 'JournalEntry', mode: 'create', label: 'New journal draft', capability: 'draft', icon: 'el-icon-plus' })
const details = ledger.recordDialog({ entity: 'JournalEntry', mode: 'detail', label: 'Details', type: 'default' })
const editDraft = ledger.recordDialog({ entity: 'JournalEntry', mode: 'update', label: 'Edit draft', capability: 'draft', when: draft })
const post = ledger.ledgerCommand({
  action: 'post', label: 'Post journal', capability: 'post', when: draft, input: revision,
  notice: 'Posted journals cannot be edited'
})
const voidDraft = ledger.ledgerCommand({
  action: 'void-draft', label: 'Void draft', capability: 'draft', type: 'danger', when: draft, input: revision, fields: [reason]
})
const reverse = ledger.ledgerCommand({
  action: 'reverse', label: 'Reverse journal', capability: 'reverse', type: 'warning',
  when: record => record?.state === 'posted' && record.origin === 'manual' && !record.reversed_by,
  input: record => ({ id: record.id }),
  fields: [input('accounting_date', 'Accounting date', { component: ledger.IsoDateField, rules: required }), reason],
  notice: 'Accounting reversal does not refund or move money',
  navigate: detail
})

export default {
  JournalEntry: {
    entity: ledger.resource('JournalEntry', 'journals'),
    adapters: {
      finance: {
        requiresBook: true,
        // Create dialogs carry a command key, so an exact retry returns the same draft.
        commandId: true,
        fields: { evidence: 'audit', attachments: 'audit', metadata: 'audit' }
      }
    },
    form: {
      fields: [
        input('origin', 'Journal origin', {
          type: 'select', hidden: 'update', default_value: 'manual', rules: required,
          type_options: {
            clearable: false,
            get options() {
              return ['manual', ...(finance.book?.opening_state === 'pending' ? ['opening'] : [])].map(value => ({ value, label: t(value) }))
            }
          }
        }),
        {
          property: 'accounting_date', field_options: { label: t('Accounting date') }, component: ledger.IsoDateField, rules: required,
          // Evaluated when each form opens, after the workspace has loaded.
          get default_value() { return finance.book?.opening_date ?? null },
          type_options: { fixed: form => form.origin === 'opening' ? finance.book?.opening_date ?? null : null }
        },
        input('summary', 'Summary', { rules: required, type_options: { maxlength: 240, showWordLimit: true } }),
        input('evidence', 'Evidence notes', {
          type: 'textarea', hidden: ledger.auditOnlyOnUpdate, type_options: { maxlength: 2000, showWordLimit: true }
        }),
        input('attachments', 'Attachments', { component: ledger.JournalAttachmentsField, hidden: ledger.auditOnlyOnUpdate }),
        input('metadata', 'Journal metadata', {
          type: 'json', hidden: ledger.auditOnlyOnUpdate,
          help: t('Journal metadata help')
        }),
        input('lines', 'Journal lines', { component: ledger.JournalLinesField }),
        input('revision', 'Revision', { hidden: 'create', type_options: { disabled: true } })
      ]
    },
    list: {
      data_processor: ledger.financeList({ filters: ['from', 'to', 'state', 'account'] }),
      disabled_actions: ['new', 'detail', 'edit', 'delete', 'batch_edit', 'batch_delete', 'export'],
      actions: [
        { name: 'journal-new-draft', position: 'top', component: newDraft },
        { name: 'journal-details', position: 'list', component: details },
        { name: 'journal-edit-draft', position: 'list', component: editDraft },
        { name: 'journal-post', position: 'list', component: post },
        { name: 'journal-void', position: 'list', component: voidDraft }
      ],
      list_filter: {
        from: ledger.dateFilter(t('From date')),
        to: ledger.dateFilter(t('To date')),
        state: ledger.choiceFilter(t('Status'), ['draft', 'posted', 'voided']),
        account: ledger.accountFilter(t('Account code'))
      },
      list_display: [
        column('number', 'Journal number'),
        column('accounting_date', 'Accounting date'),
        column('summary', 'Summary'),
        column('origin', 'Journal origin', { component: ledger.labelCell }),
        column('state', 'Status', { component: ledger.stateCell })
        // The register is a server page; sorting visible rows would not sort it.
      ].map(field => ({ ...field, field_options: { sortable: false } }))
    },
    detail: {
      disabled_actions: ['edit'],
      detail_display: [
        column('number', 'Journal number'),
        column('accounting_date', 'Accounting date'),
        column('summary', 'Summary', { full_width: true }),
        column('state', 'Status', { component: ledger.stateCell }),
        column('origin', 'Journal origin', { component: ledger.labelCell }),
        column('revision', 'Revision'),
        column('posted_by', 'Posted by'),
        column('posted_at', 'Posting time'),
        column('reversal_of', 'Original journal', { component: ledger.journalLinkCell }),
        column('reversed_by', 'Reversing journal', { component: ledger.journalLinkCell }),
        column('evidence', 'Evidence notes', { full_width: true }),
        column('attachments', 'Attachments', { component: ledger.JournalAttachmentsField, full_width: true }),
        column('metadata', 'Journal metadata', { type: 'json', full_width: true }),
        column('lines', 'Journal lines', { component: ledger.JournalLinesField, full_width: true }),
        column('operations', 'Actions', { component: ledger.ledgerOperations(editDraft, post, voidDraft, reverse), full_width: true })
      ]
    }
  }
}
