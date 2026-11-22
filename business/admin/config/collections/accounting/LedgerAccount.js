import { t } from '@/i18n'
import * as ledger from '../../features/accounting/finance-config.js'

const column = (property, label, extra = {}) => ({ property, label: t(label), ...extra })
const input = (property, label, extra = {}) => ({ property, field_options: { label: t(label) }, ...extra })
const required = ledger.required(t('This field is required'))
const choice = values => ({ options: ledger.options(values, t), clearable: false })

const display = [
  column('code', 'Account code'),
  column('name', 'Account name'),
  column('classification', 'Account class', { component: ledger.labelCell }),
  column('normal_side', 'Normal side', { component: ledger.labelCell }),
  column('active', 'Active', { type: 'boolean' }),
  column('postable', 'Postable', { type: 'boolean' })
]

export default {
  LedgerAccount: {
    entity: ledger.resource('LedgerAccount', 'ledger-accounts'),
    adapters: {
      finance: {
        requiresBook: true,
        allow: { new: ledger.can('configure'), edit: ledger.can('configure') }
      }
    },
    form: {
      fields: [
        input('code', 'Account code', {
          hidden: 'update', type_options: { maxlength: 32 },
          rules: [{ required: true, pattern: /^[A-Z0-9][A-Z0-9._-]{0,31}$/, message: t('invalid_account_code') }]
        }),
        input('name', 'Account name', { rules: required, type_options: { maxlength: 160 } }),
        input('classification', 'Account class', { type: 'select', default_value: 'asset', rules: required, type_options: choice(ledger.classes) }),
        input('normal_side', 'Normal side', { type: 'select', default_value: 'debit', rules: required, type_options: choice(ledger.sides) }),
        input('active', 'Active', { type: 'boolean', default_value: true }),
        input('postable', 'Postable', { type: 'boolean', default_value: true })
      ]
    },
    list: {
      data_processor: ledger.financeList({ filters: ['q'], sortable: true, reloadWorkspace: true }),
      disabled_actions: ['delete', 'batch_edit', 'batch_delete', 'export'],
      list_filter: { q: ledger.textFilter(t('Search')) },
      list_display: display.map(field => ({ ...field, field_options: { sortable: 'custom' } }))
    },
    detail: {
      detail_display: display
    }
  }
}
