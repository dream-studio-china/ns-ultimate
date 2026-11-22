import { t } from '@/i18n'
import { finance } from './finance-context'
import { listParams, localToday, reportParams, reportReady } from './ledger-ui.mjs'

/**
 * EasyAdmin `list.data_processor` for Finance resources.
 *
 * ListAdmin still renders the toolbar, filters, table, and pager. This
 * processor only translates the rendered filter values and pager into the
 * resource's whitelisted query parameters, because Finance endpoints reject
 * EasyAdmin's generic `@filter` expressions.
 *
 * @param {object} options
 * @param {string[]} [options.filters] Filter keys forwarded as plain parameters.
 * @param {boolean} [options.sortable] Forward column sorting as `@order`.
 * @param {'ledger'|'balances'|'trial'} [options.report] Report kind; keeps one posting cutoff across pages.
 * @param {boolean} [options.reloadWorkspace] Refresh shared book/chart state after loading.
 */
export function financeList({ filters = [], sortable = false, report = null, reloadWorkspace = false } = {}) {
  return async context => {
    context.loading = false
    context.refreshing = true
    try {
      await finance.ensure()
      let params = listParams({
        filterData: context.listFilterData, pager: context.pager, sort: context.sort,
        query: context.query, filters, sortable
      })
      let snapshot = null
      if (report) {
        if (!reportReady(report, params)) {
          context.list = []
          context.paginator = context.normalizePaginator({})
          return
        }
        snapshot = reportParams(report, params, finance.report(report))
        params = snapshot.request
      }
      const response = await context.em.list(params)
      context.list = response.data ?? []
      context.paginator = context.normalizePaginator(response.paginator ?? {})
      if (snapshot) finance.rememberReport(report, { key: snapshot.key, filters: snapshot.filters, ...response.report })
      if (reloadWorkspace) await finance.reload()
    } catch {
      // The request helper already reported the server message.
      context.list = []
    } finally {
      context.refreshing = false
    }
  }
}

// SearchFilter requires an expression; Finance processors ignore it and send
// the raw value as a whitelisted parameter instead.
const plain = (type, label, extra = {}) => ({ expression: '', type, label, default: null, ...extra })

export const textFilter = label => plain('input', label)

export const choiceFilter = (label, values) => plain('select', label, {
  data: values.map(option => typeof option === 'string'
    ? { value: option, label: t(option) }
    : { value: option.value, label: option.label })
})

export const accountFilter = label => () => finance.ensure().then(() => plain('select', label, {
  data: finance.accounts.map(account => ({ value: account.code, label: `${account.code} - ${account.name}` }))
}))

// EasyAdmin's date filter emits an unusable value format, so dates are typed
// as ISO text and validated by the backend.
export const dateFilter = (label, initial = () => null) => () => finance.ensure().then(() => plain('input', `${label} (YYYY-MM-DD)`, {
  default: initial(finance.book)
}))

export const openingDate = book => book?.opening_date ?? null
export const bookToday = book => {
  if (!book) return null
  const today = localToday(book.timezone)
  return today >= book.opening_date ? today : book.opening_date
}
