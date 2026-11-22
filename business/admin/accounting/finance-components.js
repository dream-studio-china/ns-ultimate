import { defineComponent, h, inject, markRaw, ref, resolveComponent, Teleport } from 'vue'
import { useRouter } from 'vue-router'
import { t } from '@/i18n'
import request from '@/utils/request'
import { BOOK_API, finance, selectedBookId, selectBook } from './finance-context'
import FinanceCommandDialog from './FinanceCommandDialog.vue'
import FinanceRecordDialog from './FinanceRecordDialog.vue'
import { reportFilters } from './ledger-ui.mjs'

/*
 * Small renderers referenced from the Accounting collection configs. They
 * replace EasyAdmin defaults only where Finance needs exact minor-unit money,
 * translated enum values, or explicit ledger commands.
 */

const cellProps = { data: { type: null, default: null } }

/** Exact money from integer minor units at the book scale (no floating point). */
export const moneyCell = markRaw(defineComponent({
  name: 'FinanceMoneyCell',
  inheritAttrs: false,
  props: cellProps,
  setup: props => () => h('span', { class: 'finance-money' }, finance.money(props.data) || '—')
}))

/** Translated enum value (state, origin, side, classification, audit action). */
export const labelCell = markRaw(defineComponent({
  name: 'FinanceLabelCell',
  inheritAttrs: false,
  props: cellProps,
  setup: props => () => h('span', props.data === null || props.data === '' ? '—' : t(String(props.data)))
}))

const TAG_TYPES = { posted: 'success', open: 'success', voided: 'info', locked: 'info', draft: 'warning', pending: 'warning', approved: 'success' }

export const stateCell = markRaw(defineComponent({
  name: 'FinanceStateCell',
  inheritAttrs: false,
  props: cellProps,
  setup(props) {
    const ElTag = resolveComponent('ElTag')
    return () => props.data ? h(ElTag, { type: TAG_TYPES[props.data] || 'info', effect: 'plain' }, () => t(String(props.data))) : h('span', '—')
  }
}))

/** Link to another journal (original or reversing entry) by its ID. */
export const journalLinkCell = markRaw(defineComponent({
  name: 'FinanceJournalLinkCell',
  inheritAttrs: false,
  props: cellProps,
  setup(props) {
    const RouterLink = resolveComponent('RouterLink')
    return () => props.data
      ? h(RouterLink, { to: { name: 'JournalEntryDetail', params: { id: props.data } } }, () => props.data)
      : h('span', '—')
  }
}))

const actionProps = {
  record: { type: Object, default: null },
  refresh: { type: Function, default: () => {} }
}

const visible = (spec, record) => (!spec.capability || finance.can(spec.capability)) && (!spec.when || Boolean(spec.when(record, finance)))
const blocked = () => finance.state.busy || Boolean(finance.state.pending)

export const bookSelection = markRaw(defineComponent({
  name: 'FinanceBookSelection',
  props: actionProps,
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    return () => props.record ? h(ElButton, {
      size: 'small', disabled: blocked() || Number(props.record.id) === selectedBookId,
      onClick: () => selectBook(Number(props.record.id))
    }, () => t(Number(props.record.id) === selectedBookId ? 'Selected book' : 'Select book')) : null
  }
}))

/**
 * An explicit ledger command registered as a list/detail action.
 *
 * @param {object} spec
 * @param {string} spec.action Command name posted to `/commands/{action}`.
 * @param {string} spec.label Button label (translation key).
 * @param {string} spec.capability Finance capability required to show it.
 * @param {(record, finance) => boolean} [spec.when] Record-state predicate.
 * @param {(record, finance) => object} [spec.input] Identity and revision sent with the command.
 * @param {Array} [spec.fields] FormAdmin fields collected in the confirmation.
 * @param {string} [spec.notice] Warning shown in the confirmation (translation key).
 * @param {(result, record) => object|null} [spec.navigate] Route to open after success instead of refreshing.
 */
export function ledgerCommand(spec) {
  return markRaw(defineComponent({
    name: `LedgerCommand_${spec.action}`,
    props: actionProps,
    setup(props) {
      const ElButton = resolveComponent('ElButton')
      const router = useRouter()
      const open = ref(false)
      const confirm = values => {
        open.value = false
        return finance.act(spec.action, { ...(spec.input?.(props.record, finance) ?? {}), ...values }, async result => {
          const target = spec.navigate?.(result, props.record)
          if (target) await router.push(target)
          else props.refresh()
        })
      }
      return () => visible(spec, props.record) ? h('span', { class: 'finance-action' }, [
        h(ElButton, {
          size: 'small', type: spec.type || 'primary', plain: true, disabled: blocked(), onClick: () => { open.value = true }
        }, () => t(spec.label)),
        open.value ? h(FinanceCommandDialog, {
          title: t(spec.label), notice: spec.notice ? t(spec.notice) : '', fields: spec.fields ?? [],
          busy: finance.state.busy, blocked: blocked(),
          onClose: () => { open.value = false }, onConfirm: confirm
        }) : null
      ]) : null
    }
  }))
}

/** A navigation action (edit draft, new opening journal) gated like a command. */
export function ledgerLink(spec) {
  return markRaw(defineComponent({
    name: `LedgerLink_${spec.label.replace(/\W+/g, '_')}`,
    props: actionProps,
    setup(props) {
      const ElButton = resolveComponent('ElButton')
      const router = useRouter()
      return () => visible(spec, props.record) ? h(ElButton, {
        size: 'small', type: spec.type || 'primary', plain: true, icon: spec.icon, disabled: blocked(),
        onClick: () => router.push(spec.to(props.record, finance))
      }, () => t(spec.label)) : null
    }
  }))
}

/**
 * Opens the resource's create, edit, or detail dialog (FinanceRecordDialog).
 * Inside an open dialog of the same resource it switches that dialog instead
 * of stacking another one, so a detail's "Edit draft" edits in place.
 *
 * @param {object} spec
 * @param {string} spec.entity Collection config name.
 * @param {'create'|'update'|'detail'} spec.mode
 * @param {string} spec.label Button label (translation key).
 * @param {string} [spec.capability] Finance capability required to show it.
 * @param {(record, finance) => boolean} [spec.when] Record-state predicate.
 */
export function recordDialog(spec) {
  return markRaw(defineComponent({
    name: `RecordDialog_${spec.entity}_${spec.mode}`,
    props: actionProps,
    setup(props) {
      const ElButton = resolveComponent('ElButton')
      const host = inject('financeRecordDialog', null)
      const open = ref(false)
      const click = () => {
        if (host?.entity === spec.entity) host.open(spec.mode, props.record)
        else open.value = true
      }
      return () => visible(spec, props.record) ? h('span', { class: 'finance-action' }, [
        h(ElButton, {
          size: spec.mode === 'create' ? 'default' : 'small', type: spec.type || 'primary', plain: spec.mode !== 'create',
          icon: spec.icon, disabled: blocked(), onClick: click
        }, () => t(spec.label)),
        // Teleport the whole dialog host out of table cells and toolbars so the
        // RecordDialog layout is not clipped by the list.
        open.value ? h(Teleport, { to: 'body' }, h(FinanceRecordDialog, {
          entity: spec.entity, mode: spec.mode, recordId: props.record?.id ?? '', refresh: props.refresh,
          onClosed: () => { open.value = false }
        })) : null
      ]) : null
    }
  }))
}

/** Detail-page operations: renders the given actions for the shown record. */
export function ledgerOperations(...actions) {
  return markRaw(defineComponent({
    name: 'LedgerOperations',
    inheritAttrs: false,
    props: actionProps,
    setup(props) {
      const ElSpace = resolveComponent('ElSpace')
      return () => h(ElSpace, { wrap: true }, () => actions.map(action => h(action, { record: props.record, refresh: props.refresh })))
    }
  }))
}

/**
 * Report snapshot summary and CSV export, registered as a top list action.
 * The export reuses the filters and posting cutoff of the rows on screen.
 */
export function reportSummary(kind) {
  return markRaw(defineComponent({
    name: `ReportSummary_${kind}`,
    props: { refresh: { type: Function, default: () => {} } },
    setup() {
      const ElButton = resolveComponent('ElButton')
      const ElTag = resolveComponent('ElTag')
      const exporting = ref(false)
      async function download(snapshot) {
        if (exporting.value) return
        exporting.value = true
        try {
          const params = { ...reportFilters(kind, snapshot.filters), cutoff: snapshot.cutoff }
          const csv = (await request.get(`${BOOK_API}/reports/${kind}/csv`, { params })).data
          const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
          const link = document.createElement('a')
          link.href = url
          link.download = `accounting-${kind}.csv`
          link.click()
          setTimeout(() => URL.revokeObjectURL(url), 1000)
        } finally {
          exporting.value = false
        }
      }
      return () => {
        const snapshot = finance.report(kind)
        if (!snapshot || snapshot.cutoff === undefined) return null
        const tags = [
          h(ElTag, { effect: 'plain' }, () => `${t('Posting cutoff')}: ${snapshot.cutoff}`),
          ...(snapshot.opening !== undefined ? [h(ElTag, { effect: 'plain' }, () => `${t('Opening balance')}: ${finance.money(snapshot.opening)} ${snapshot.currency}`)] : []),
          ...Object.entries(snapshot.totals ?? {}).map(([key, value]) => h(ElTag, { effect: 'plain' }, () => `${t(key)}: ${finance.money(value)}`))
        ]
        return h('span', { class: 'finance-report-summary' }, [
          ...tags,
          h(ElButton, { size: 'default', plain: true, icon: 'el-icon-download', loading: exporting.value, onClick: () => download(snapshot) }, () => t('Export CSV'))
        ])
      }
    }
  }))
}
