import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { Button, CenterPopup } from 'antd-mobile'
import { CheckCircle2, ChevronDown, Clock3, CreditCard, ExternalLink, LayoutGrid, LoaderCircle, Minus, Pencil, Plus, Printer, QrCode, ReceiptText, UsersRound } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, legacyCounterSession, StoreApi, type StoreAccess, type CounterSessionView, type SessionOrder, type OrderQuote, type OrderCart, type CounterCommand, type LegacyCatalogItem, type LiveCounter } from '../adapters/live'
import { availableDiningModes, diningModeLabel, isChoiceRuleCandidate, isChoiceSelectionBlocked, normalizeDiningModes, resolveRecordedDiningMode, resolveTableDiningModeDisplay, ruleQuantity, validateDiningRules, type ChoiceDiningRule, type DiningLine, type DiningMode, type DiningRule, type DiningRuleIssue, type FixedDiningRule, type PerGuestDiningRule } from '../dining-mode'
import { reconcileCheckoutQuantities } from '../checkout-reconciliation'
import { checkoutPaymentMethods, type CheckoutPaymentMethod } from '../checkout-payment'
import { checkoutMergeBlockers, type CheckoutMergeBlocker } from '../checkout-merge'
import { submitCheckoutReplacement } from '../checkout-replacement'
import { waitForStoreOrderProjection } from '../checkout-store-order'
import { confirmedOrderCanCollect, invoiceReferenceUuid, paymentStatusIsPaid, paymentStatusStartsAttempt, type StaffPaymentResult } from '../order-payment'
import { isOccupiedDiningTable as isDining, unsettledDiningOrders as effectiveDiningOrders } from '../dining-table-state'
import { ApiError } from '../adapters/api-client'
import { errorKey, ListFeedback, LiveSheet, MutationForm, Notice, PageControls, StatusPill, useCommand, usePage, usePageRefresh } from './ui'
import './dining.css'

const tradeLabels: Record<string, MessageKey> = { draft: 'diningDraft', pending: 'diningPending', confirmed: 'diningConfirmed', paid: 'diningPaid', fulfilled: 'stateFulfilled', completed: 'diningCompleted', cancelled: 'stateCancelled', refunded: 'diningRefunded', merged: 'diningMerged' }
const effectiveDiningStatuses = new Set(['pending', 'confirmed'])

function effectiveDiningAmount(table: CounterSessionView): number {
  return effectiveDiningOrders(table).reduce((total, order) => total + order.totalAmount, 0)
}

const staffProjectionTimeout = 10_000
const staffProjectionInterval = 250
const wait = (milliseconds: number) => new Promise((resolve) => setTimeout(resolve, milliseconds))

/** Workers project and confirm a newly created order asynchronously after the command response. */
async function retryCheckoutConflict<T>(attempt: () => Promise<T>): Promise<T> {
  const deadline = Date.now() + staffProjectionTimeout
  while (true) {
    try {
      return await attempt()
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 409 || Date.now() >= deadline) throw error
    }
    await wait(staffProjectionInterval)
  }
}

async function waitForCheckoutOrder(api: StoreApi, access: StoreAccess, counterUuid: string, matches: (order: SessionOrder) => boolean): Promise<{ table: CounterSessionView; order: SessionOrder }> {
  const deadline = Date.now() + staffProjectionTimeout
  while (true) {
    const table = await loadCounterSession(api, access, counterUuid)
    const order = effectiveDiningOrders(table).find(matches)
    if (order) return { table, order }
    if (Date.now() >= deadline) throw new ApiError('request', 409)
    await wait(staffProjectionInterval)
  }
}

async function hasStoreOrderProjection(api: StoreApi, access: StoreAccess, counterUuid: string, tradeOrderUuid: string): Promise<boolean> {
  if (can(access, 'order', 'read')) {
    const exists = await api.storeOrderExists(tradeOrderUuid)
    if (exists !== null) return exists
  }
  // Accounts without Store order read access can use the server-computed
  // projection blocker from the counter session. It is no longer
  // projection_pending as soon as the Store order has been created.
  const table = await loadCounterSession(api, access, counterUuid)
  const order = effectiveDiningOrders(table).find((entry) => entry.uuid === tradeOrderUuid)
  return Boolean(order && order.changeBlockedReason !== 'projection_pending')
}

/** Session commands and order commands use different resources, so each action is checked separately. */
function permitted(access: StoreAccess, command: CounterCommand | 'merge'): boolean {
  if (command === 'update' || command === 'end') return can(access, 'counter_session', command)
  if (command === 'confirm') return can(access, 'counter_order', 'confirm') || can(access, 'counter_order', 'create')
  return can(access, 'counter_order', command)
}

const counterPageSize = 30

const hasModernCounterRead = (access: StoreAccess) => can(access, 'counter_session', 'read')
const hasLegacyCounterRead = (access: StoreAccess) => !hasModernCounterRead(access) && can(access, 'counter', 'read')
async function loadCounterSession(api: StoreApi, _access: StoreAccess, counterUuid: string) {
  return api.counterSession(counterUuid)
}

async function readLegacyCounterSession(api: StoreApi, access: StoreAccess, counterUuid: string) {
  return legacyCounterSession(
    await api.legacyCounter(counterUuid, access.store.code),
    await api.legacyCounterOrders(counterUuid, access.store.code),
    access.store.currency,
  )
}

async function loadLegacyCounterSession(api: StoreApi, access: StoreAccess, counterUuid: string) {
  return readLegacyCounterSession(api, access, counterUuid)
}

async function loadAllCounterSessions(api: StoreApi): Promise<CounterSessionView[]> {
  return api.allCounterSessions()
}

async function loadLegacyCounterSessions(api: StoreApi, access: StoreAccess): Promise<CounterSessionView[]> {
  const counters = (await api.legacyCounters()).filter((counter) => counter.enabled)
  return Promise.all(counters.map((counter) => readLegacyCounterSession(api, access, counter.uuid)))
}

function resolveTableDiningMode(table: CounterSessionView, storeModes: unknown): DiningMode | null {
  return resolveTableDiningModeDisplay(table, storeModes)?.mode ?? null
}

function resolveTableDiningModeName(table: CounterSessionView, storeModes: unknown): string | null {
  const display = resolveTableDiningModeDisplay(table, storeModes)
  return display?.name || display?.uuid || null
}

function logAllCounterOrders(tables: CounterSessionView[]) {
  if (!import.meta.env.DEV) return
  const rows = tables.flatMap((table) => table.ordersVisible
    ? table.orders.map((order) => ({
        counterUuid: table.counter.uuid,
        counterCode: table.counter.code,
        counterName: table.counter.name,
        session: table.session,
        order,
      }))
    : [])
  console.groupCollapsed(`[store-console][counter-orders] parsed all pages (${rows.length})`)
  console.log('all orders across every page', rows)
  console.log('counter summaries', tables.map((table) => ({
    counter: table.counter,
    session: table.session,
    ordersVisible: table.ordersVisible,
    orderCount: table.ordersVisible ? table.orders.length : null,
    openOrderCount: table.ordersVisible ? table.openOrderCount : null,
  })))
  console.groupEnd()
}

function useDiningSessions(api: StoreApi, access: StoreAccess, refreshKey: number) {
  const [page, setPage] = useState(1)
  const [state, setState] = useState({ allItems: [] as CounterSessionView[], loading: true, error: null as MessageKey | null })
  const request = useRef(0)
  const legacy = hasLegacyCounterRead(access)
  const refresh = useCallback(async () => {
    const requestId = ++request.current
    setState((current) => ({ ...current, loading: true, error: null }))
    try {
      const items = await (legacy ? loadLegacyCounterSessions(api, access) : loadAllCounterSessions(api))
      if (requestId !== request.current) return
      logAllCounterOrders(items)
      const lastPage = Math.max(1, Math.ceil(items.length / counterPageSize))
      setPage((current) => Math.min(current, lastPage))
      setState({ allItems: items, loading: false, error: null })
    } catch (error) {
      if (requestId === request.current) setState({ allItems: [], loading: false, error: errorKey(error) })
    }
  }, [access, api, legacy])
  useEffect(() => { void refresh() }, [refresh, refreshKey])
  useEffect(() => () => { request.current += 1 }, [])
  usePageRefresh(refresh)
  const items = state.allItems.slice((page - 1) * counterPageSize, page * counterPageSize)
  return {
    ...state,
    items,
    page,
    hasNext: page * counterPageSize < state.allItems.length,
    setPage,
    reload: () => { void refresh() },
    legacy,
  }
}

function summarizeDining(tables: CounterSessionView[]) {
  let dining = 0
  let idle = 0
  let orderedAmount = 0
  let openAmount = 0
  for (const table of tables) {
    if (isDining(table)) dining += 1
    else if (table.counter.enabled) idle += 1
    if (table.ordersVisible) {
      orderedAmount += table.orderedAmount
      openAmount += table.openAmount
    }
  }
  return {
    dining,
    idle,
    orderedAmount,
    collectedAmount: Math.max(0, orderedAmount - openAmount),
    openAmount,
    amountsVisible: tables.every((table) => table.ordersVisible),
  }
}

export function DiningSummary({ tables, loading, currency, locale, available = true }: { tables: CounterSessionView[]; loading: boolean; currency: string; locale: string; available?: boolean }) {
  const { t } = useI18n()
  const [financeOpen, setFinanceOpen] = useState(false)
  const summary = summarizeDining(tables)
  const money = (value: number) => loading || !available || !summary.amountsVisible ? '—' : new Intl.NumberFormat(locale, { style: 'currency', currency }).format(value / 100)
  const count = (value: number) => loading || !available ? '—' : <>{value}<small>{t('tableCountUnit')}</small></>
  const countMetrics = [
    { key: 'diningSummaryDining' as MessageKey, value: count(summary.dining) },
    { key: 'diningSummaryIdle' as MessageKey, value: count(summary.idle) },
  ]
  const financeMetrics = [
    { key: 'diningSummaryCollected' as MessageKey, value: money(summary.collectedAmount) },
    { key: 'diningSummaryPending' as MessageKey, value: money(summary.openAmount) },
  ]
  return <section className="dining-summary" aria-label={t('diningSummary')}>
    <div className="dining-summary-counts">{countMetrics.map(({ key, value }) => <article className="dining-summary-item" key={key}><p>{t(key)}</p><strong>{value}</strong></article>)}</div>
    <div className={`dining-finance ${financeOpen ? 'is-open' : ''}`}>
      <button type="button" className="dining-finance-toggle" aria-label={t('diningSummaryRevenue')} aria-expanded={financeOpen} onClick={() => setFinanceOpen((open) => !open)}>
        <span>{t('diningSummaryRevenue')}</span><strong>{money(summary.orderedAmount)}</strong><ChevronDown size={17} />
      </button>
      {financeOpen && <div className="dining-finance-breakdown">{financeMetrics.map(({ key, value }) => <article className="dining-summary-item" key={key}><p>{t(key)}</p><strong>{value}</strong></article>)}</div>}
    </div>
  </section>
}

export function LiveDining({ api, access, onEdit, refreshKey = 0 }: { api: StoreApi; access: StoreAccess; onEdit?: (counter: LiveCounter) => void; refreshKey?: number }) {
  const { t, locale } = useI18n()
  const list = useDiningSessions(api, access, refreshKey)
  const [selected, setSelected] = useState<string | null>(null)
  const [ordering, setOrdering] = useState<{ table: CounterSessionView; mode: 'new' | 'add' } | null>(null)
  const [returnTo, setReturnTo] = useState<string | null>(null)
  const [settling, setSettling] = useState<CounterSessionView | null>(null)
  const [actionError, setActionError] = useState<MessageKey | null>(null)
  const amount = (value: number, currency: string) => new Intl.NumberFormat(locale, { style: 'currency', currency }).format(value / 100)
  const orderable = list.legacy ? can(access, 'counter', 'read') : permitted(access, 'create')
  const startCheckout = async (table: CounterSessionView) => {
    setSelected(null)
    setActionError(null)
    if (!navigator.onLine) { setActionError('offline'); return }
    try {
      // Checkout starts with the saved rule sequence. Every rule and item edit remains
      // local until the final confirmation; legacy reads resolve the versioned session first.
      const current = list.legacy ? await loadCounterSession(api, access, table.counter.uuid) : table
      setSettling(current)
    } catch (error) {
      setActionError(errorKey(error))
    }
  }
  const startOrder = (table: CounterSessionView) => {
    if (!orderable || !table.counter.enabled) return
    if (!navigator.onLine) { setActionError('offline'); return }
    setActionError(null)
    setOrdering({ table, mode: effectiveDiningOrders(table).length === 0 ? 'new' : 'add' })
  }
  return <><ListFeedback state={list} empty="firstTable" hint="existingTablesHint" icon={LayoutGrid} />
    {actionError && <Notice>{t(actionError)}</Notice>}
    <div className="dining-board">{list.items.map((table) => {
      const orders = effectiveDiningOrders(table)
      const hasOrders = orders.length > 0
      const hasPendingOrders = orders.some((order) => order.status === 'pending')
      const dining = isDining(table)
      const showTableDiningMode = hasOrders
      const tableDiningModeName = showTableDiningMode ? resolveTableDiningModeName(table, access.store.settings?.diningModes) : null
      return <article className={`dining-card ${hasOrders ? 'has-orders' : ''} ${table.counter.enabled ? '' : 'is-disabled'}`} key={table.counter.uuid} onClick={() => setSelected(table.counter.uuid)}>
        <div className="dining-card-heading"><h2><button type="button" className="dining-card-title">{table.counter.name}</button></h2><div className="dining-card-heading-actions"><StatusPill muted={!dining}>{t(dining ? 'dining' : table.counter.enabled ? 'idle' : 'tableDisabled')}</StatusPill>{onEdit && can(access, 'counter', 'update') && <Button size="mini" className="dining-card-edit" aria-label={t('edit')} onClick={(event) => { event.stopPropagation(); void api.legacyCounter(table.counter.uuid, access.store.code).then(onEdit).catch((error: unknown) => setActionError(errorKey(error))) }}><Pencil size={12} /></Button>}</div></div>
        {hasOrders && <div className="dining-card-meta"><span><UsersRound size={14} />{table.session?.participantCount ?? '—'} {t('guestUnit')}</span>{table.session && <span><Clock3 size={14} /><time dateTime={table.session.startedAt}>{formatDiningDate(table.session.startedAt, locale)}</time></span>}</div>}
        {showTableDiningMode && <p className="dining-card-mode"><span>{t('diningMode')}</span><strong>{tableDiningModeName ?? t('diningModeNotRecorded')}</strong></p>}
        {hasOrders ? <><div className="dining-card-total"><span>{t('diningOpenAmount')}</span><strong>{amount(effectiveDiningAmount(table), table.currency)}</strong></div><p className="dining-card-caption">{orders.length} {t('diningBatches')}{hasPendingOrders ? ` · ${t('diningPendingConfirmation').replace('{count}', String(orders.filter((order) => order.status === 'pending').length))}` : ''}{table.ordersVisible && table.paymentHold && ` · ${t('diningPaymentHold')}`}</p></> : table.ordersVisible ? <p className="dining-card-idle">{t(table.counter.enabled ? 'readyToOpen' : 'tableDisabled')}</p> : <p className="dining-card-caption">{t('sessionOrdersRestricted')}</p>}
      </article>
    })}</div><PageControls state={list} />
    {selected && <LiveSheet open title={t('viewDining')} className="dining-detail-sheet" onClose={() => setSelected(null)}><DiningDetail api={api} access={access} legacy={list.legacy} orderable={orderable} id={selected} changed={list.reload} onClose={() => setSelected(null)} onOrder={(table) => { setReturnTo(table.counter.uuid); setSelected(null); startOrder(table) }} onCheckout={(table) => void startCheckout(table)} /></LiveSheet>}
    {ordering && <LegacyDiningEditor api={api} access={access} table={ordering.table} mode={ordering.mode} onClose={() => { setOrdering(null); setReturnTo(null) }} done={() => { setOrdering(null); list.reload(); setSelected(returnTo); setReturnTo(null) }} />}
    {settling && <CheckoutFlow api={api} access={access} table={settling} onClose={() => { setSettling(null); list.reload() }} />}
  </>
}

function formatDiningDate(value: string, locale: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(date)
}

function ConfirmPendingOrder({ api, table, order, onDone }: { api: StoreApi; table: CounterSessionView; order: SessionOrder; onDone: () => void }) {
  const { t } = useI18n()
  const payload = { version: table.version, orderUuid: order.uuid }
  const commandUuid = useCommand({ command: 'confirm', counter: table.counter.uuid, ...payload })
  return <MutationForm actions={false} submit={() => api.counterCommand(table.counter.uuid, 'confirm', { ...payload, commandUuid })} onDone={onDone}>
    <Button type="submit" color="primary">{t('confirmPendingOrder')}</Button>
  </MutationForm>
}

function DiningDetail({ api, access, legacy, orderable, id, changed, onClose, onOrder, onCheckout }: { api: StoreApi; access: StoreAccess; legacy: boolean; orderable: boolean; id: string; changed: () => void; onClose: () => void; onOrder: (table: CounterSessionView) => void; onCheckout: (table: CounterSessionView) => void }) {
  const { t, locale } = useI18n()
  const load = useCallback(async () => ({ items: [legacy
    ? await loadLegacyCounterSession(api, access, id)
    : await loadCounterSession(api, access, id)], hasNext: false }), [access, api, id, legacy])
  const detail = usePage(load)
  const [replaceOrder, setReplaceOrder] = useState<{ table: CounterSessionView; order: SessionOrder } | null>(null)
  const [collectOrder, setCollectOrder] = useState<{ table: CounterSessionView; order: SessionOrder } | null>(null)
  const [cancelMode, setCancelMode] = useState(false)
  const [cancelSelection, setCancelSelection] = useState<Set<string>>(() => new Set())
  const done = () => { setReplaceOrder(null); detail.reload(); changed() }
  const closeCancelSelection = () => { setCancelMode(false); setCancelSelection(new Set()) }
  return <><ListFeedback state={detail} empty="emptyLive" hint="diningScopeHint" icon={LayoutGrid} />{detail.items.map((table) => {
    const orders = effectiveDiningOrders(table)
    const hasOrders = orders.length > 0
    const hasPendingOrders = orders.some((order) => order.status === 'pending')
    const dining = isDining(table)
    const showTableDiningMode = hasOrders
    const canCancelOrders = permitted(access, 'cancel')
    const tableDiningMode = resolveTableDiningMode(table, access.store.settings?.diningModes)
    const tableDiningModeName = resolveTableDiningModeName(table, access.store.settings?.diningModes)
    const orderDiningModeName = (order: SessionOrder) => {
      const recorded = resolveRecordedDiningMode(null, order.diningModeUuid, order.diningModeName, access.store.settings?.diningModes)
      if (recorded) return recorded.name || recorded.uuid
      if (!order.diningModeUuid && !order.diningModeName && tableDiningMode) return tableDiningMode.name || tableDiningMode.uuid
      return order.diningModeName ?? order.diningModeUuid ?? t('diningModeNotRecorded')
    }
    const cancellableOrders = table.ordersVisible ? effectiveDiningOrders(table).filter((order) => order.canCancel) : []
    const selectedCancelOrders = cancellableOrders.filter((order) => cancelSelection.has(order.uuid))
    const allCancellableSelected = cancellableOrders.length > 0 && selectedCancelOrders.length === cancellableOrders.length
    const toggleCancelOrder = (uuid: string) => setCancelSelection((current) => {
      const next = new Set(current)
      if (next.has(uuid)) next.delete(uuid)
      else next.add(uuid)
      return next
    })
    return <section className="dining-detail" key={table.counter.uuid}>
      <div className="dining-detail-fixed">
        <div className="dining-detail-hero"><div className="dining-detail-heading"><div><h3>{table.counter.name}</h3></div><StatusPill muted={!dining}>{t(dining ? 'dining' : table.counter.enabled ? 'idle' : 'tableDisabled')}</StatusPill></div>{hasOrders && table.session && <div className="dining-detail-summary"><span><UsersRound size={15} />{table.session.participantCount ?? '—'} {t('guestUnit')}</span><span><Clock3 size={15} /><time dateTime={table.session.startedAt}>{formatDiningDate(table.session.startedAt, locale)}</time></span></div>}{showTableDiningMode && <div className="dining-detail-mode"><span>{t('diningMode')}</span><strong>{tableDiningModeName ?? t('diningModeNotRecorded')}</strong></div>}{hasOrders && <div className="dining-detail-amount"><span>{t('diningOpenAmount')}</span><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: table.currency }).format(effectiveDiningAmount(table) / 100)}</strong></div>}</div>
        {hasOrders && table.session?.notes && <p className="dining-notes dining-party-notes"><strong>{t('diningPartyNotes')}</strong>{table.session.notes}</p>}
        <div className="order-operation-actions">{orderable && <Button color="primary" disabled={!table.counter.enabled || hasPendingOrders || (table.ordersVisible && table.paymentHold)} onClick={() => onOrder(table)}>{t(hasOrders ? 'addDining' : 'placeNewDining')}</Button>}{hasOrders && <Button color="danger" fill="outline" disabled={!canCancelOrders} onClick={() => cancelMode ? closeCancelSelection() : setCancelMode(true)}>{t(cancelMode ? 'cancelSelectionExit' : 'cancelOrder')}</Button>}{hasOrders && <Button disabled={hasPendingOrders} onClick={() => onCheckout(table)}>{t('checkoutDining')}</Button>}</div>
        {hasPendingOrders && <p className="helper dining-pending-confirmation" role="status">{t('confirmPendingBeforeContinue')}</p>}
        {hasOrders && !canCancelOrders && <p className="helper dining-cancel-permission" role="status">{t('cancelOrderPermissionRequired')}</p>}
        {table.ordersVisible && table.paymentHold && <Notice>{t('diningPaymentHold')}</Notice>}
      </div>
      <div className="dining-detail-scroll">
        {!table.ordersVisible ? <Notice subtle>{t('sessionOrdersRestricted')}</Notice> : hasOrders ? <>
          <div className="dining-section-heading"><h3>{t('diningBatches')}</h3><span>{orders.length}</span></div>{!orders.length && <p className="dining-empty-orders">{t('noItems')}</p>}
          {[...orders].sort((a, b) => a.createdAt.localeCompare(b.createdAt) || a.uuid.localeCompare(b.uuid)).map((order, index) => <article className={`dining-round ${cancelMode ? 'is-cancel-selectable' : ''} ${cancelSelection.has(order.uuid) ? 'is-cancel-selected' : ''}`} key={order.uuid}>
            <header><div className="dining-round-heading">{cancelMode && <input className="dining-cancel-checkbox" aria-label={`${t('selectOrder')} #${order.uuid.slice(0, 8).toUpperCase()}`} type="checkbox" checked={cancelSelection.has(order.uuid)} disabled={!order.canCancel} onChange={() => toggleCancelOrder(order.uuid)} />}<strong>{String(index + 1).padStart(2, '0')} · #{order.uuid.slice(0, 8)}</strong></div><StatusPill>{tradeLabels[order.status] ? t(tradeLabels[order.status]) : order.status} ({order.status})</StatusPill></header>
            <p><time dateTime={order.createdAt}>{formatDiningDate(order.createdAt, locale)}</time></p>
            <p>{t('diningMode')} · {orderDiningModeName(order)}</p>
            <p>{t('diningPaymentState')} · {order.paymentStatus ?? t('diningNoPayment')}{order.hasInvoice && ` · ${t('diningHasInvoice')}`}</p>
            <DiningLines items={order.items} currency={order.currency} /><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(order.totalAmount / 100)}</strong>
            {order.notes && <p className="dining-notes">{order.notes}</p>}
            {order.status === 'pending' && permitted(access, 'confirm') && <ConfirmPendingOrder api={api} table={table} order={order} onDone={() => { detail.reload(); changed() }} />}
            {confirmedOrderCanCollect(order) && <Button color="primary" fill="outline" onClick={() => setCollectOrder({ table, order })}>{t('collectPayment')}</Button>}
            {permitted(access, 'replace') && <Button onClick={() => setReplaceOrder({ table, order })}>{t('replaceDining')}</Button>}
            {cancelMode && !order.canCancel && <p className="dining-cancel-unavailable">{t('cancelOrderUnavailable')}</p>}
          </article>)}
          {cancelMode && <div className="dining-cancel-selection">
            <header><span>{selectedCancelOrders.length} / {cancellableOrders.length} · {t('selectedOrders')}</span><button type="button" disabled={!cancellableOrders.length} onClick={() => setCancelSelection(allCancellableSelected ? new Set() : new Set(cancellableOrders.map((order) => order.uuid)))}>{t(allCancellableSelected ? 'cancelClearSelection' : 'cancelSelectAll')}</button></header>
            {cancellableOrders.length > 0 ? <CancelSelectedOrders api={api} access={access} legacy={legacy} table={table} selectedOrders={selectedCancelOrders} onDone={() => { closeCancelSelection(); detail.reload(); changed(); onClose() }} /> : <Notice>{t('cancelPendingNoEligible')}</Notice>}
          </div>}
        </> : <p className="dining-empty-orders">{t('noItems')}</p>}
        <PageControls state={detail} detail />
      </div>
    </section>})}{replaceOrder && <DiningEditor api={api} access={access} mode="replace" table={replaceOrder.table} order={replaceOrder.order} onClose={() => setReplaceOrder(null)} done={done} />}
    {collectOrder && <OrderPaymentSheet api={api} access={access} table={collectOrder.table} order={collectOrder.order} onClose={() => setCollectOrder(null)} onSettled={() => { detail.reload(); changed() }} />}</>
}

function OrderPaymentSheet({ api, access, table, order, onClose, onSettled }: {
  api: StoreApi
  access: StoreAccess
  table: CounterSessionView
  order: SessionOrder
  onClose: () => void
  onSettled: () => void
}) {
  const { t, locale } = useI18n()
  const initialInvoice = useMemo(() => invoiceReferenceUuid(order), [order])
  const [invoiceUuid, setInvoiceUuid] = useState<string | null>(initialInvoice)
  const [loadingInvoice, setLoadingInvoice] = useState(!initialInvoice && order.hasInvoice)
  const [invoiceLookupFailed, setInvoiceLookupFailed] = useState(false)
  const [payment, setPayment] = useState<StaffPaymentResult | null>(null)
  const [paid, setPaid] = useState(() => paymentStatusIsPaid(order.paymentStatus))
  const [busy, setBusy] = useState(false)
  const [checking, setChecking] = useState(false)
  const [error, setError] = useState<MessageKey | null>(null)
  const canCreatePayment = can(access, 'huifu_payment', 'create')
  const paymentStarted = paymentStatusStartsAttempt(order.paymentStatus)
  const amount = new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(order.totalAmount / 100)
  const qrValue = payment?.qrCode ?? payment?.payUrl ?? null

  useEffect(() => {
    if (initialInvoice || !order.hasInvoice) return
    let active = true
    void api.orderInvoiceReference(order.tradeOrderUuid)
      .then((reference) => { if (active) setInvoiceUuid(reference) })
      .catch(() => { if (active) setInvoiceLookupFailed(true) })
      .finally(() => { if (active) setLoadingInvoice(false) })
    return () => { active = false }
  }, [api, initialInvoice, order.hasInvoice, order.tradeOrderUuid])

  const startPayment = async () => {
    if (!invoiceUuid || busy || paymentStarted || !canCreatePayment) return
    if (!navigator.onLine) { setError('offline'); return }
    setBusy(true)
    setError(null)
    try {
      const result = await api.startHuifuPayment(invoiceUuid, { channel: 'native', tradeType: 'T_NATIVE' })
      setPayment(result)
      if (paymentStatusIsPaid(result.status)) {
        setPaid(true)
        onSettled()
      }
    } catch (cause) {
      setError(errorKey(cause))
    } finally {
      setBusy(false)
    }
  }

  const refreshPayment = async () => {
    if (checking) return
    if (!navigator.onLine) { setError('offline'); return }
    setChecking(true)
    setError(null)
    try {
      const current = await loadCounterSession(api, access, table.counter.uuid)
      const latest = current.ordersVisible
        ? current.orders.find((entry) => entry.tradeOrderUuid === order.tradeOrderUuid)
        : null
      if (latest && paymentStatusIsPaid(latest.paymentStatus)) {
        setPaid(true)
        setPayment((existing) => ({ ...(existing ?? { invoiceUuid, payUrl: null, qrCode: null, payInfo: null, message: null }), status: 'paid' }))
        onSettled()
      } else {
        setError('paymentNotConfirmed')
      }
    } catch (cause) {
      setError(errorKey(cause))
    } finally {
      setChecking(false)
    }
  }

  const footer = <div className="dining-confirm-bar">
    {paid
      ? <Button block color="primary" onClick={onClose}>{t('close')}</Button>
      : payment
        ? <><Button disabled={checking} onClick={() => void refreshPayment()}>{checking ? t('checkingPayment') : t('paymentRefreshStatus')}</Button><Button block onClick={onClose}>{t('close')}</Button></>
        : <><Button onClick={onClose}>{t('close')}</Button><Button block color="primary" loading={busy} disabled={busy || !invoiceUuid || loadingInvoice || paymentStarted || !canCreatePayment} onClick={() => void startPayment()}>{t('paymentGenerateQr')}</Button></>}
  </div>

  return <LiveSheet open title={t('collectPaymentTitle')} className="dining-payment-sheet" onClose={onClose} footer={footer}>
    <section className="dining-payment-order" aria-label={t('collectPaymentTitle')}>
      <header><div><span>#{order.uuid.slice(0, 8).toUpperCase()}</span><h3>{table.counter.name}</h3><small>{order.diningModeName ?? t('diningModeNotRecorded')}</small></div><strong>{amount}</strong></header>
      <DiningLines items={order.items} currency={order.currency} />
      <div className="dining-payment-total"><span>{t('amountDue')}</span><strong>{amount}</strong></div>
    </section>

    <Notice subtle>{t('paymentCustomerHint')}</Notice>
    {paid ? <section className="dining-payment-success" role="status"><CheckCircle2 size={48} strokeWidth={1.35} /><h3>{t('paymentSuccess')}</h3><p>{t('paymentThanks')}</p><strong>{amount}</strong></section>
      : <section className="dining-payment-scan">
        <header><QrCode size={20} /><div><h3>{t('paymentScanTitle')}</h3><p>{t('paymentScanMethods')}</p></div></header>
        {loadingInvoice && <div className="dining-payment-loading" role="status"><LoaderCircle className="is-spinning" size={24} /><span>{t('paymentInvoiceChecking')}</span></div>}
        {!loadingInvoice && !invoiceUuid && <><Notice title="paymentInvoiceMissingTitle">{t('paymentInvoiceMissingHint')}</Notice>{invoiceLookupFailed && <p className="helper">{t('paymentInvoiceLookupFailed')}</p>}</>}
        {!loadingInvoice && invoiceUuid && !canCreatePayment && <Notice>{t('paymentStartPermission')}</Notice>}
        {!loadingInvoice && invoiceUuid && canCreatePayment && paymentStarted && !payment && !paid && <Notice>{t('paymentAlreadyStarted')}</Notice>}
        {qrValue && <div className="dining-payment-code"><QRCodeSVG value={qrValue} size={208} level="M" marginSize={1} bgColor="#ffffff" fgColor="#201813" /><p>{t('paymentQrHint')}</p></div>}
        {payment && !qrValue && !paid && <Notice>{t('paymentNoQr')}</Notice>}
        {payment?.payUrl && <a className="dining-payment-link" href={payment.payUrl} target="_blank" rel="noreferrer"><ExternalLink size={17} />{t('paymentOpenLink')}</a>}
      </section>}
    {error && <Notice>{t(error)}</Notice>}
  </LiveSheet>
}

function CancelSelectedOrders({ api, access, legacy, table, selectedOrders, onDone }: { api: StoreApi; access: StoreAccess; legacy: boolean; table: CounterSessionView; selectedOrders: SessionOrder[]; onDone: () => void }) {
  const { t, locale } = useI18n()
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [result, setResult] = useState<string | null>(null)
  const commandUuids = useRef(new Map<string, string>())
  const amount = selectedOrders.reduce((total, order) => total + order.totalAmount, 0)
  const formattedAmount = new Intl.NumberFormat(locale, { style: 'currency', currency: selectedOrders[0]?.currency ?? table.currency }).format(amount / 100)
  const loadTable = async () => legacy
    ? loadLegacyCounterSession(api, access, table.counter.uuid)
    : loadCounterSession(api, access, table.counter.uuid)
  const cancelSelected = async () => {
    if (busy || !selectedOrders.length) return
    if (!navigator.onLine) {
      setResult(t('networkFailed'))
      return
    }
    setBusy(true)
    setResult(null)
    let cancelled = 0
    let failed = 0
    try {
      for (const target of selectedOrders) {
        try {
          const current = await loadTable()
          if (!current.ordersVisible) { failed += 1; continue }
          const order = current.orders.find((entry) => entry.uuid === target.uuid)
          if (!order) { failed += 1; continue }
          if (order.status === 'cancelled') { cancelled += 1; continue }
          if (!effectiveDiningStatuses.has(order.status) || !order.canCancel) { failed += 1; continue }
          let commandUuid = commandUuids.current.get(order.uuid)
          if (!commandUuid) {
            commandUuid = crypto.randomUUID()
            commandUuids.current.set(order.uuid, commandUuid)
          }
          await api.counterCommand(table.counter.uuid, 'cancel', {
            version: current.version,
            orderUuid: order.uuid,
            reason: t('cancelPendingReason'),
            commandUuid,
          })
          cancelled += 1
        } catch {
          failed += 1
        }
      }
      setResult(failed
        ? t('cancelPendingPartial').replace('{cancelled}', String(cancelled)).replace('{failed}', String(failed))
        : t('cancelPendingSuccess').replace('{count}', String(cancelled)))
      if (!failed) {
        setConfirming(false)
        onDone()
      }
    } finally {
      setBusy(false)
    }
  }
  return <><div className="dining-batch-cancel"><Button block color="danger" fill="outline" disabled={busy} onClick={() => { setResult(null); setConfirming(true) }}>{t('cancelPendingOrders')}</Button></div>
    <LiveSheet open={confirming} title={t('cancelPendingTitle')} className="dining-batch-cancel-confirm" onClose={() => { if (!busy) setConfirming(false) }} footer={<div className="dining-confirm-bar"><Button disabled={busy} onClick={() => setConfirming(false)}>{t('cancel')}</Button><Button block color="danger" loading={busy} disabled={busy || !selectedOrders.length} onClick={() => void cancelSelected()}>{t('cancelPendingConfirm')}</Button></div>}>
      <Notice>{t('cancelPendingHint').replace('{count}', String(selectedOrders.length)).replace('{amount}', formattedAmount)}</Notice>
      {result && <Notice>{result}</Notice>}
    </LiveSheet>
  </>
}

function DiningLines({ items, currency }: { items: SessionOrder['items']; currency: string }) {
  const { locale } = useI18n()
  return <ul className="dining-lines">{items.map((item, index) => <li key={`${item.specificationUuid}:${index}`}><span>{item.productName}<small>{item.specificationName}</small></span><b>×{item.quantity}</b><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency }).format(item.amount / 100)}</strong></li>)}</ul>
}

interface RuleCatalogEntry { rule: FixedDiningRule | PerGuestDiningRule; item: LegacyCatalogItem }
interface ModeCartLine extends LegacyCatalogItem { specificationUuid: string; quantity: number; ruleUuids: string[]; locked: boolean }

function useDiningModeState(source: unknown, timeZone: string, boundMode: DiningMode | null = null, allowStoreFallback = true) {
  const [now, setNow] = useState(() => Date.now())
  const [selectedUuid, setSelectedUuid] = useState<string | null>(null)
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 60_000)
    return () => window.clearInterval(timer)
  }, [])
  const modes = useMemo(() => boundMode ? [boundMode] : allowStoreFallback ? normalizeDiningModes(source) : [], [allowStoreFallback, boundMode, source])
  const available = useMemo(() => boundMode ? [boundMode] : availableDiningModes(modes, new Date(now), timeZone), [boundMode, modes, now, timeZone])
  const selected = available.find((mode) => mode.uuid === selectedUuid) ?? available[0] ?? null
  return { modes, available, selected, selectedUuid, setSelectedUuid, configured: modes.length > 0, locked: boundMode !== null }
}

function useRuleCatalog(api: StoreApi, storeCode: string, mode: DiningMode | null) {
  const [state, setState] = useState<{ items: RuleCatalogEntry[]; loading: boolean; error: MessageKey | null }>({ items: [], loading: false, error: null })
  useEffect(() => {
    let active = true
    const defaults = mode?.rules.filter((rule): rule is FixedDiningRule | PerGuestDiningRule => rule.type === 'fixed_item' || rule.type === 'per_guest_item') ?? []
    if (!defaults.length) {
      setState({ items: [], loading: false, error: null })
      return
    }
    setState({ items: [], loading: true, error: null })
    const specificationUuids = [...new Set(defaults.map((rule) => rule.specificationUuid))]
    void Promise.all(specificationUuids.map(async (id) => [id, await api.legacySpecification(id, storeCode)] as const))
      .then((rows) => {
        const byUuid = new Map(rows)
        const entries = defaults.flatMap((rule) => {
          const item = byUuid.get(rule.specificationUuid)
          return item ? [{ rule, item }] : []
        })
        if (active) setState({ items: entries, loading: false, error: null })
      })
      .catch((error: unknown) => { if (active) setState({ items: [], loading: false, error: errorKey(error) }) })
    return () => { active = false }
  }, [api, mode, storeCode])
  return state
}

function defaultRuleLines(entries: RuleCatalogEntry[], participantCount: number, removed: ReadonlySet<string>): ModeCartLine[] {
  const quantities = new Map<string, { quantity: number; ruleUuids: string[]; locked: boolean }>()
  for (const { rule, item } of entries) {
    if (removed.has(rule.uuid)) continue
    const current = quantities.get(item.uuid) ?? { quantity: 0, ruleUuids: [], locked: false }
    current.quantity += ruleQuantity(rule, Math.max(1, participantCount))
    current.ruleUuids.push(rule.uuid)
    current.locked ||= rule.required || !rule.allowRemove
    quantities.set(item.uuid, current)
  }
  return [...quantities].flatMap(([specificationUuid, value]) => {
    const item = entries.find((entry) => entry.item.uuid === specificationUuid)?.item
    return item ? [{ ...item, specificationUuid: item.uuid, ...value }] : []
  })
}

function mergeModeLines(lines: ModeCartLine[]): ModeCartLine[] {
  const merged = new Map<string, ModeCartLine>()
  for (const line of lines) {
    const current = merged.get(line.uuid)
    merged.set(line.uuid, current ? {
      ...current,
      quantity: Math.min(99, current.quantity + line.quantity),
      ruleUuids: [...new Set([...current.ruleUuids, ...line.ruleUuids])],
      locked: current.locked || line.locked,
    } : { ...line })
  }
  return [...merged.values()]
}

function ModeRuleGuide({ mode, lines, issues }: { mode: DiningMode; lines: DiningLine[]; issues: DiningRuleIssue[] }) {
  const { t } = useI18n()
  if (!mode.rules.length) return null
  const invalid = new Set(issues.map((issue) => issue.ruleUuid))
  const ruleLabel = (rule: DiningRule) => rule.name.trim() || t(rule.type === 'fixed_item' ? 'diningRuleFixed' : rule.type === 'per_guest_item' ? 'diningRulePerGuest' : 'diningRuleChoice')
  const description = (rule: DiningRule) => {
    if (rule.type === 'fixed_item') return `${t('diningRuleDefaultQuantity')} ×${rule.quantity}`
    if (rule.type === 'per_guest_item') return `${t('diningRulePerGuest')} ×${rule.quantityPerGuest}`
    const actual = lines.filter((line) => line.quantity > 0 && isChoiceRuleCandidate(rule, line)).length
    return `${actual}/${rule.maxSelections} · ${t('diningRuleChooseRange').replace('{min}', String(rule.minSelections)).replace('{max}', String(rule.maxSelections))}`
  }
  return <section className="dining-rule-guide" aria-label={t('diningRules')}>
    <header><h3>{t('diningRules')}</h3><span>{mode.name}</span></header>
    <div>{mode.rules.map((rule) => <article key={rule.uuid} className={invalid.has(rule.uuid) ? 'is-invalid' : ''}><strong>{ruleLabel(rule)}</strong><small>{description(rule)}</small></article>)}</div>
  </section>
}

interface DiningCategoryOption { uuid: string | null; name: string }

function categoryOptions(items: LegacyCatalogItem[], uncategorized: string): DiningCategoryOption[] {
  const categories = new Map<string | null, string>()
  for (const item of items) {
    if (!categories.has(item.categoryUuid)) categories.set(item.categoryUuid, item.categoryName || (item.categoryUuid ? item.categoryUuid.slice(0, 8) : uncategorized))
  }
  return [...categories].map(([uuid, name]) => ({ uuid, name }))
}

function MenuCategoryFilter({ categories, selected, onChange }: { categories: DiningCategoryOption[]; selected: string | null; onChange: (value: string | null) => void }) {
  const { t } = useI18n()
  if (categories.length < 2) return null
  const key = (value: string | null) => value ?? 'uncategorized'
  return <section className="dining-category-filter" aria-label={t('diningCategoryFilter')}>
    <span>{t('diningCategoryFilter')}</span>
    <div>
      <button type="button" className={selected === null ? 'is-active' : ''} aria-pressed={selected === null} onClick={() => onChange(null)}>{t('diningAllCategories')}</button>
      {categories.map((category) => <button type="button" key={key(category.uuid)} className={selected === key(category.uuid) ? 'is-active' : ''} aria-pressed={selected === key(category.uuid)} onClick={() => onChange(key(category.uuid))}>{category.name}</button>)}
    </div>
  </section>
}

function ChoiceRulePicker({ rule, items, lines, currency, invalid, disabled, onSelect, onClear }: {
  rule: ChoiceDiningRule; items: LegacyCatalogItem[]; lines: DiningLine[]; currency: string; invalid: boolean; disabled: boolean; onSelect: (item: LegacyCatalogItem) => void; onClear: (specificationUuid: string) => void
}) {
  const { t, locale } = useI18n()
  const candidates = items.filter((item) => isChoiceRuleCandidate(rule, { ...item, specificationUuid: item.uuid, quantity: 1 }))
  if (!candidates.length) return null
  const selected = lines.filter((line) => line.quantity > 0 && isChoiceRuleCandidate(rule, line))
  const selectedIds = new Set(selected.map((line) => line.specificationUuid))
  const label = rule.name.trim() || t('diningRuleChoice')
  return <section className={`dining-choice-rule ${invalid ? 'is-invalid' : ''}`} aria-label={label}>
    <header><div><strong>{label}</strong><small>{t('diningRuleChooseRange').replace('{min}', String(rule.minSelections)).replace('{max}', String(rule.maxSelections))}</small></div><span>{selected.length}/{rule.maxSelections}</span></header>
    <div className="dining-choice-scroll">
      {candidates.map((item) => {
        const quantity = lines.find((line) => line.specificationUuid === item.uuid)?.quantity ?? 0
        const isSelected = selectedIds.has(item.uuid)
        return <button type="button" key={item.uuid} className={isSelected ? 'is-selected' : ''} aria-pressed={isSelected} aria-label={`${t('diningRuleChoice')} ${item.productName} ${item.name}`} disabled={disabled || (!isSelected && selected.length >= rule.maxSelections)} onClick={() => isSelected ? onClear(item.uuid) : onSelect(item)}>
          <span><strong>{item.productName}</strong><small>{item.name} · {new Intl.NumberFormat(locale, { style: 'currency', currency }).format(item.price / 100)}</small></span>
          {quantity > 0 && <b>×{quantity}</b>}
        </button>
      })}
    </div>
  </section>
}

function DiningModePicker({ state, timeZone, participantCount, onParticipantCount, showGuests = false, forceGuests = false, onSelect }: {
  state: ReturnType<typeof useDiningModeState>; timeZone: string; participantCount: number; onParticipantCount: (value: number) => void; showGuests?: boolean; forceGuests?: boolean; onSelect: (mode: DiningMode) => void
}) {
  const { t } = useI18n()
  if (!state.configured) return null
  if (!state.selected) return <Notice title="diningModeUnavailableTitle">{t('diningModeUnavailableHint')}</Notice>
  const guestBased = state.selected.rules.some((rule) => rule.type === 'per_guest_item')
  return <section className="dining-mode-picker" aria-label={t('diningMode')}>
    <div className="dining-mode-current"><span>{t('diningMode')}</span><strong>{state.selected.name}</strong><small>{t('diningModeStoreTimezone').replace('{timezone}', timeZone)}</small></div>
    {!state.locked && state.available.length > 1 && <div className="dining-mode-switches">{state.available.map((mode) => <button type="button" key={mode.uuid} aria-pressed={mode.uuid === state.selected?.uuid} className={mode.uuid === state.selected?.uuid ? 'is-active' : ''} onClick={() => onSelect(mode)}>{diningModeLabel(mode)}</button>)}</div>}
    {showGuests && (guestBased || forceGuests) && <div className="dining-guest-picker"><span>{t('diningGuestCount')}</span><div className="dining-guest-quick" role="group" aria-label={t('diningGuestQuick')}>{[1, 2, 3, 4, 5, 6, 7, 8, 9].map((value) => <button type="button" key={value} className={participantCount === value ? 'is-active' : ''} aria-pressed={participantCount === value} onClick={() => onParticipantCount(value)}>{value}</button>)}</div><label className="dining-guest-input"><span>{t('diningOtherGuests')}</span><input aria-label={t('diningGuestCount')} required inputMode="numeric" pattern="[0-9]*" type="number" min={1} max={100} step={1} value={participantCount} onFocus={(event) => event.currentTarget.select()} onChange={(event) => { const value = Number(event.target.value); if (Number.isInteger(value) && value >= 1) onParticipantCount(Math.min(100, value)) }} /></label></div>}
  </section>
}

type DiningEditorMode = 'new' | 'add' | 'replace'
interface ModernCartLine {
  specificationUuid: string
  quantity: number
  productUuid?: string
  categoryUuid?: string | null
  productName: string
  specificationName: string
  price?: number
  ruleUuids: string[]
  locked: boolean
}

interface CheckoutDisplayLine {
  key: string
  specificationUuid: string | null
  productName: string
  specificationName: string
  unitPrice: number
  quantity: number
}

function DiningProductQuantity({ item, quantity, disabled, canDecrease, onIncrease, onDecrease }: {
  item: { productName: string; specificationName: string }; quantity: number; disabled: boolean; canDecrease: boolean; onIncrease: () => void; onDecrease: () => void
}) {
  const { t } = useI18n()
  const label = `${item.productName} ${item.specificationName}`
  if (quantity === 0) return <Button className="dining-step-button" aria-label={`${t('addItems')} ${label}`} disabled={disabled} onClick={onIncrease}><Plus size={19} /></Button>
  return <div className="dining-product-quantity" aria-label={`${t('diningCart')} ${label} ${quantity}`}>
    <Button className="dining-step-button" aria-label={`${t('decrease')} ${label}`} disabled={!canDecrease} onClick={onDecrease}><Minus size={19} /></Button>
    <strong aria-live="polite">×{quantity}</strong>
    <Button className="dining-step-button" aria-label={`${t('increase')} ${label}`} disabled={disabled} onClick={onIncrease}><Plus size={19} /></Button>
  </div>
}

function DiningMenuRow({ item, quantity, currency, disabled, canDecrease, onIncrease, onDecrease }: {
  item: { productName: string; specificationName: string; price: number; categoryName?: string | null }; quantity: number; currency: string; disabled: boolean; canDecrease: boolean; onIncrease: () => void; onDecrease: () => void
}) {
  const { locale } = useI18n()
  return <li><span><strong>{item.productName}</strong><small>{item.specificationName} · {new Intl.NumberFormat(locale, { style: 'currency', currency }).format(item.price / 100)}{item.categoryName ? ` · ${item.categoryName}` : ''}</small></span><DiningProductQuantity item={item} quantity={quantity} disabled={disabled} canDecrease={canDecrease} onIncrease={onIncrease} onDecrease={onDecrease} /></li>
}

function mergeModernLines(lines: ModernCartLine[]): ModernCartLine[] {
  const merged = new Map<string, ModernCartLine>()
  for (const line of lines) {
    const current = merged.get(line.specificationUuid)
    merged.set(line.specificationUuid, current ? {
      ...current,
      quantity: Math.min(99, current.quantity + line.quantity),
      ruleUuids: [...new Set([...current.ruleUuids, ...line.ruleUuids])],
      locked: current.locked || line.locked,
    } : { ...line })
  }
  return [...merged.values()]
}

function checkoutCartLines(table: CounterSessionView): ModernCartLine[] {
  return mergeModernLines(effectiveDiningOrders(table).flatMap((order) => order.items.flatMap((item) => item.specificationUuid === null ? [] : [{
    specificationUuid: item.specificationUuid,
    quantity: item.quantity,
    productName: item.productName,
    specificationName: item.specificationName,
    price: item.unitPrice,
    ruleUuids: [],
    locked: false,
  }])))
}

function checkoutDisplayLines(table: CounterSessionView): CheckoutDisplayLine[] {
  const merged = new Map<string, CheckoutDisplayLine>()
  for (const item of effectiveDiningOrders(table).flatMap((order) => order.items)) {
    const key = item.specificationUuid
      ? `specification:${item.specificationUuid}`
      : `product:${item.productName.trim().toLocaleLowerCase()}:${item.specificationName.trim().toLocaleLowerCase()}`
    const current = merged.get(key)
    merged.set(key, current ? {
      ...current,
      quantity: current.quantity + item.quantity,
    } : {
      key,
      specificationUuid: item.specificationUuid,
      productName: item.productName,
      specificationName: item.specificationName,
      unitPrice: item.unitPrice,
      quantity: item.quantity,
    })
  }
  return [...merged.values()]
}

class CheckoutMergePreflightError extends Error {
  constructor(public readonly reasons: string[]) {
    super('checkout_merge_preflight')
  }
}

function mergeBlockedReason(blocker: CheckoutMergeBlocker, t: (key: MessageKey) => string): string {
  const orderLabel = `#${blocker.orderUuid.slice(0, 8).toUpperCase()}`
  return t(blocker.status === 'pending' ? 'mergeBlockedPendingOrderStatus' : 'mergeBlockedOrderStatus')
    .replace('{order}', orderLabel)
    .replace('{status}', blocker.status)
}

function assertCheckoutMergeable(table: CounterSessionView, t: (key: MessageKey) => string) {
  const blockers = checkoutMergeBlockers(table)
  if (blockers.length > 0) throw new CheckoutMergePreflightError(blockers.map((blocker) => mergeBlockedReason(blocker, t)))
}

function preflightExistingMergeOrders(table: CounterSessionView, t: (key: MessageKey) => string) {
  const blockers = checkoutMergeBlockers(table)
  if (blockers.length > 0) throw new CheckoutMergePreflightError(blockers.map((blocker) => mergeBlockedReason(blocker, t)))
}

type CheckoutProgressPhase = 'preparing' | 'creating' | 'syncing' | 'merging' | 'replacing'

function logCheckoutFlow(label: string, data: unknown) {
  if (!import.meta.env.DEV || typeof window === 'undefined') return
  console.log(`[store-console][checkout-flow] ${label} ${JSON.stringify(data, null, 2)}`)
}

async function processCheckout(api: StoreApi, access: StoreAccess, table: CounterSessionView, lines: ModernCartLine[], mode: DiningMode | null, participantCount: number | null, t: (key: MessageKey) => string, onProgress: (phase: CheckoutProgressPhase) => void): Promise<CounterSessionView> {
  const refresh = () => loadCounterSession(api, access, table.counter.uuid)
  let current = table
  logCheckoutFlow('start', {
    counterUuid: table.counter.uuid,
    version: table.version,
    participantCount,
    diningMode: mode ? { uuid: mode.uuid, name: mode.name } : null,
    requestedLines: lines,
    openOrders: effectiveDiningOrders(table).map((order) => ({
      uuid: order.uuid,
      status: order.status,
      canReplace: order.canReplace,
      changeBlockedReason: order.changeBlockedReason,
      replaceBlockedReason: order.replaceBlockedReason,
    })),
  })
  const participantManagedByMode = Boolean(mode && (
    mode.rules.some((rule) => rule.type === 'per_guest_item')
    || mode.checkoutRules.some((rule) => rule.type === 'confirm_participants')
  ))
  if (participantManagedByMode && participantCount !== null && current.session && participantCount !== current.session.participantCount) {
    if (!permitted(access, 'update')) throw new ApiError('forbidden', 403)
    await api.counterCommand(table.counter.uuid, 'update', {
      version: current.version,
      participantCount,
      notes: current.session.notes ?? '',
      commandUuid: crypto.randomUUID(),
    })
    current = await refresh()
  }

  const notes = current.session?.notes ?? ''
  const intended = lines.map(({ specificationUuid, quantity }) => ({ specificationUuid, quantity }))
  const reconciliation = reconcileCheckoutQuantities(checkoutCartLines(current), intended)
  logCheckoutFlow('reconciliation', reconciliation)
  let mergedOrderUuid: string | null = null
  let latestOrderUuid: string | null = null
  let mergedOrders = false

  if (reconciliation.additions.length > 0) {
    // Reject known-ineligible existing orders before creating another round that
    // would otherwise be left behind when the subsequent merge fails.
    preflightExistingMergeOrders(current, t)
    // Additions follow the customer ordering path: create a separate Trade Order
    // directly. The event projection binds it to this counter before the merge.
    onProgress('creating')
    const created = await api.legacyCreateOrderFromCart(
      access.store.code,
      table.counter.uuid,
      current.currency,
      reconciliation.additions,
      notes,
      mode ?? undefined,
    )
    logCheckoutFlow('addition created', created)
    latestOrderUuid = created.uuid
    const projectedAddition = await waitForCheckoutOrder(api, access, table.counter.uuid, (order) => order.uuid === created.uuid)
    current = projectedAddition.table
    logCheckoutFlow('addition projected', { counterUuid: table.counter.uuid, orderUuid: created.uuid, version: current.version })
    if (effectiveDiningOrders(current).length >= 2) {
      onProgress('syncing')
      await waitForStoreOrderProjection(() => hasStoreOrderProjection(api, access, table.counter.uuid, created.uuid))
    }
  }

  const previewMerge = async () => {
    current = await refresh()
    // The checkout sheet can outlive a concurrent cancel or merge. Re-evaluate
    // the refreshed table instead of merging from the snapshot taken on open.
    if (effectiveDiningOrders(current).length < 2) return null
    assertCheckoutMergeable(current, t)
    return api.previewCounterMerge(table.counter.uuid, current.version)
  }
  if (effectiveDiningOrders(current).length >= 2) onProgress('merging')
  const mergeQuote = reconciliation.additions.length > 0 ? await retryCheckoutConflict(previewMerge) : await previewMerge()
  logCheckoutFlow('merge preview result', mergeQuote)
  if (mergeQuote) {
    mergedOrders = true
    const merged = await api.confirmCounterMerge(table.counter.uuid, { version: current.version, commandUuid: crypto.randomUUID(), quoteToken: mergeQuote.quoteToken })
    logCheckoutFlow('merge confirm result', merged)
    mergedOrderUuid = merged.orderUuid ?? null
    latestOrderUuid = mergedOrderUuid
    if (mergedOrderUuid) {
      current = (await waitForCheckoutOrder(api, access, table.counter.uuid, (order) => order.uuid === mergedOrderUuid)).table
    } else {
      current = await refresh()
    }
  }

  if (reconciliation.reductions.length > 0) {
    onProgress('replacing')
    // The replace endpoints own order eligibility checks. The frontend only
    // resolves the order UUID from the consolidated table state.
    const candidates = effectiveDiningOrders(current)
    const targetOrderUuid = mergedOrderUuid ?? candidates[0]?.uuid ?? null
    logCheckoutFlow('replacement target', {
      targetOrderUuid,
      mergedOrderUuid,
      candidates: candidates.map((order) => ({
        uuid: order.uuid,
        status: order.status,
        canReplace: order.canReplace,
        changeBlockedReason: order.changeBlockedReason,
        replaceBlockedReason: order.replaceBlockedReason,
      })),
    })
    if (!targetOrderUuid) throw new ApiError('request', 409, 'checkout_replacement_order_missing')
    const replacement = await submitCheckoutReplacement({
      loadTable: refresh,
      quote: (cart) => api.orderQuote(table.counter.uuid, cart),
      replace: (body) => api.counterCommand(table.counter.uuid, 'replace', body),
      ensureTargetReady: mergedOrders
        ? async (targetUuid) => {
            onProgress('syncing')
            await waitForStoreOrderProjection(() => hasStoreOrderProjection(api, access, table.counter.uuid, targetUuid))
            onProgress('replacing')
          }
        : undefined,
      targetOrderUuid,
      items: intended,
      notes,
      diningMode: mode,
    })
    logCheckoutFlow('replacement result', replacement.result)
    current = replacement.table
    latestOrderUuid = replacement.result.orderUuid ?? null
    if (latestOrderUuid) {
      current = (await waitForCheckoutOrder(api, access, table.counter.uuid, (order) => order.uuid === latestOrderUuid)).table
    } else {
      current = await refresh()
    }
  } else if (!latestOrderUuid) {
    current = await refresh()
  }

  logCheckoutFlow('finished', { version: current.version, openOrders: effectiveDiningOrders(current).map((order) => order.uuid) })
  return current
}

type CheckoutStage = { kind: 'rule'; index: number } | { kind: 'review' } | { kind: 'result' }
type CheckoutError = { key: MessageKey } | { title: MessageKey; details: string[] }

function CheckoutFlow({ api, access, table, onClose }: { api: StoreApi; access: StoreAccess; table: CounterSessionView; onClose: () => void }) {
  const { t, locale } = useI18n()
  const diningMode = useMemo(() => resolveTableDiningMode(table, access.store.settings?.diningModes), [access.store.settings?.diningModes, table])
  const checkoutRules = diningMode?.checkoutRules ?? []
  const [ruleIndex, setRuleIndex] = useState(0)
  const [participantCount, setParticipantCount] = useState<number | null>(table.session?.participantCount ?? null)
  const [lines, setLines] = useState<ModernCartLine[]>(() => checkoutCartLines(table))
  const [catalog, setCatalog] = useState<{ items: LegacyCatalogItem[]; loading: boolean; error: MessageKey | null }>({ items: [], loading: Boolean(diningMode), error: null })
  const [result, setResult] = useState<CounterSessionView | null>(null)
  const [busy, setBusy] = useState(false)
  const [progress, setProgress] = useState<CheckoutProgressPhase | null>(null)
  const [error, setError] = useState<CheckoutError | null>(null)
  const [paymentMethod, setPaymentMethod] = useState<string | null>(null)
  const originalLines = useMemo(() => checkoutCartLines(table), [table])
  const originalDisplayLines = useMemo(() => checkoutDisplayLines(table), [table])
  const paymentMethods = useMemo(() => checkoutPaymentMethods(access.store), [access.store])
  const stage: CheckoutStage = result ? { kind: 'result' } : ruleIndex < checkoutRules.length ? { kind: 'rule', index: ruleIndex } : { kind: 'review' }

  useEffect(() => {
    let active = true
    if (!diningMode) { setCatalog({ items: [], loading: false, error: null }); return }
    setCatalog({ items: [], loading: true, error: null })
    const load = async () => {
      const menu = await api.legacyCatalogAll('', access.store.code, diningMode.catalog)
      const byUuid = new Map(menu.items.map((item) => [item.uuid, item]))
      const required = diningMode.rules.flatMap((rule) => rule.type === 'fixed_item' || rule.type === 'per_guest_item' ? [rule.specificationUuid] : [])
      for (const specificationUuid of [...new Set(required)]) {
        if (byUuid.has(specificationUuid)) continue
        const item = await api.legacySpecification(specificationUuid, access.store.code)
        byUuid.set(item.uuid, item)
      }
      return [...byUuid.values()]
    }
    void load()
      .then((items) => { if (active) setCatalog({ items, loading: false, error: null }) })
      .catch((cause: unknown) => { if (active) setCatalog({ items: [], loading: false, error: errorKey(cause) }) })
    return () => { active = false }
  }, [access.store.code, api, diningMode])

  const catalogBySpecification = useMemo(() => new Map(catalog.items.map((item) => [item.uuid, item])), [catalog.items])
  const originalLineBySpecification = useMemo(() => new Map(originalLines.map((line) => [line.specificationUuid, line])), [originalLines])
  const lineQuantity = (specificationUuid: string) => lines.find((line) => line.specificationUuid === specificationUuid)?.quantity ?? 0
  const adjust = (specificationUuid: string, delta: number, fallback?: ModernCartLine) => {
    setLines((current) => {
      const existing = current.find((line) => line.specificationUuid === specificationUuid)
      const nextQuantity = Math.max(0, Math.min(99, (existing?.quantity ?? 0) + delta))
      if (nextQuantity === 0) return current.filter((line) => line.specificationUuid !== specificationUuid)
      if (existing) return current.map((line) => line.specificationUuid === specificationUuid ? { ...line, quantity: nextQuantity } : line)
      if (!fallback || delta <= 0) return current
      return [...current, { ...fallback, quantity: nextQuantity }]
    })
  }
  const lineFromCatalog = (item: LegacyCatalogItem): ModernCartLine => ({
    specificationUuid: item.uuid,
    quantity: 1,
    productUuid: item.productUuid,
    categoryUuid: item.categoryUuid,
    productName: item.productName,
    specificationName: item.name,
    price: item.price,
    ruleUuids: [],
    locked: false,
  })
  const requiredModeRules = diningMode?.rules.filter((rule): rule is FixedDiningRule | PerGuestDiningRule => (
    (rule.type === 'fixed_item' || rule.type === 'per_guest_item') && rule.required
  )) ?? []
  const requiredItems = requiredModeRules.map((rule) => {
    const item = catalogBySpecification.get(rule.specificationUuid)
    const existing = originalLines.find((line) => line.specificationUuid === rule.specificationUuid)
    const productName = item?.productName ?? existing?.productName ?? rule.name
    const specificationName = item?.name ?? existing?.specificationName ?? t('checkoutRequiredItem')
    const price = item?.price ?? existing?.price ?? 0
    const expected = rule.type === 'per_guest_item' && participantCount === null
      ? null
      : ruleQuantity(rule, participantCount ?? 1)
    const priceKnown = Boolean(item || existing)
    return { rule, item, productName, specificationName, price, expected, priceKnown }
  })
  const activeRule = stage.kind === 'rule' ? checkoutRules[stage.index] : null
  const activeRuleTitle = activeRule
    ? activeRule.title.trim() || activeRule.name.trim() || t(activeRule.type === 'confirm_participants' ? 'checkoutGuestRule' : activeRule.type === 'confirm_required_items' ? 'checkoutRequiredItemsRule' : 'checkoutCategoryRule')
    : ''
  const categoryItems = activeRule?.type === 'suggest_category'
    ? catalog.items.filter((item) => item.categoryUuid === activeRule.categoryUuid)
    : []
  // Rules are review aids. Staff can move on without changing the order because
  // the current order may already be correct or no adjustment may be necessary.
  const nextDisabled = busy
  const reconciliation = reconcileCheckoutQuantities(originalLines, lines)
  const lineName = (specificationUuid: string) => {
    const line = lines.find((item) => item.specificationUuid === specificationUuid) ?? originalLines.find((item) => item.specificationUuid === specificationUuid)
    return line ? `${line.productName}${line.specificationName ? ` · ${line.specificationName}` : ''}` : specificationUuid
  }
  const originalSpecifications = new Set(originalDisplayLines.flatMap((line) => line.specificationUuid ? [line.specificationUuid] : []))
  const newAdditionLines = reconciliation.additions.filter((line) => !originalSpecifications.has(line.specificationUuid))
  const estimatedTotal = lines.reduce((total, line) => total + (line.price ?? 0) * line.quantity, 0)
  const money = (value: number, currency = table.currency) => new Intl.NumberFormat(locale, { style: 'currency', currency }).format(value / 100)
  const proceed = () => {
    setError(null)
    setRuleIndex((current) => Math.min(checkoutRules.length, current + 1))
  }
  const submit = async () => {
    if (busy) return
    if (!navigator.onLine) { setError({ key: 'offline' }); return }
    setBusy(true)
    setProgress('preparing')
    setError(null)
    try {
      setResult(await processCheckout(api, access, table, lines, diningMode, participantCount, t, setProgress))
      // TODO(checkout): End the corresponding counter session after the complete checkout and payment flow succeeds.
    } catch (cause) {
      logCheckoutFlow('failed', {
        error: cause,
        message: cause instanceof Error ? cause.message : null,
        kind: cause instanceof ApiError ? cause.kind : null,
        status: cause instanceof ApiError ? cause.status : null,
        serverMessage: cause instanceof ApiError ? cause.serverMessage : null,
        errorKey: errorKey(cause),
      })
      if (cause instanceof CheckoutMergePreflightError) {
        setError({ title: 'mergePreflightTitle', details: cause.reasons })
      } else if (cause instanceof ApiError && cause.serverMessage === 'store_order_projection_timeout') {
        setError({ key: 'storeOrderProjectionTimeout' })
      } else if (cause instanceof ApiError && cause.serverMessage === 'staff_order_locked') {
        setError({ title: 'mergePreflightTitle', details: [
          t('mergeBackendLocked'),
          t('mergeBackendLockedDetail').replace('{status}', String(cause.status)),
        ] })
      } else {
        const key = errorKey(cause)
        setError({ key })
      }
    } finally {
      setProgress(null)
      setBusy(false)
    }
  }
  const finalCurrency = result?.currency ?? table.currency
  const finalLines = result ? checkoutDisplayLines(result) : []
  const finalAmount = result ? effectiveDiningAmount(result) : 0
  const finalOrders = result ? effectiveDiningOrders(result) : []
  const checkoutError = error && <div className="checkout-footer-feedback" role="alert" aria-live="assertive">
    <Notice title={'title' in error ? error.title : undefined}>
      {'key' in error
        ? t(error.key)
        : <ul className="checkout-merge-blockers">{error.details.map((detail, index) => <li key={`${index}-${detail}`}>{detail}</li>)}</ul>}
    </Notice>
  </div>
  const progressLabels: Record<CheckoutProgressPhase, MessageKey> = {
    preparing: 'checkoutPreparing',
    creating: 'checkoutCreatingOrder',
    syncing: 'checkoutWaitingStoreOrder',
    merging: 'checkoutMergingOrders',
    replacing: 'checkoutReplacingOrder',
  }
  const footer = stage.kind === 'result'
    ? <div className="dining-confirm-bar"><Button block onClick={onClose}>{t('close')}</Button></div>
    : stage.kind === 'rule'
      ? <div className="dining-confirm-bar"><Button disabled={busy || ruleIndex === 0} onClick={() => setRuleIndex((current) => Math.max(0, current - 1))}>{t('back')}</Button><Button block color="primary" disabled={nextDisabled} onClick={proceed}>{t(stage.index === checkoutRules.length - 1 ? 'checkoutFinalReview' : 'checkoutNextRule')}</Button></div>
      : <>{checkoutError}<div className="dining-confirm-bar">{checkoutRules.length > 0 && <Button disabled={busy} onClick={() => setRuleIndex(checkoutRules.length - 1)}>{t('back')}</Button>}<Button block color="primary" loading={busy} disabled={busy} onClick={() => void submit()}>{t('checkoutConfirmAndProcess')}</Button></div></>

  return <><LiveSheet open title={t(stage.kind === 'result' ? 'checkoutResultTitle' : 'checkoutDining')} className="dining-settlement dining-checkout-flow" onClose={() => { if (!busy) onClose() }} footer={footer}>
    {stage.kind === 'rule' && <section className="checkout-rule-step" aria-label={activeRuleTitle}>
      <header><div><span>{t('checkoutRuleStep').replace('{current}', String(stage.index + 1)).replace('{total}', String(checkoutRules.length))}</span><h3>{activeRuleTitle}</h3></div>{diningMode && <small>{diningMode.name}</small>}</header>
      {activeRule?.type === 'confirm_participants' && <div className="checkout-participant-review"><p>{participantCount === null ? t('checkoutParticipantsMissing') : t('checkoutParticipantsValue').replace('{count}', String(participantCount))}</p><div className="dining-guest-quick" role="group" aria-label={t('diningGuestQuick')}>{[1, 2, 3, 4, 5, 6, 7, 8, 9].map((value) => <button type="button" key={value} className={participantCount === value ? 'is-active' : ''} aria-pressed={participantCount === value} onClick={() => setParticipantCount(value)}>{value}</button>)}</div><label className="dining-guest-input"><span>{t('diningOtherGuests')}</span><input aria-label={t('diningGuestCount')} inputMode="numeric" pattern="[0-9]*" type="number" min={1} max={100} step={1} value={participantCount ?? ''} onFocus={(event) => event.currentTarget.select()} onChange={(event) => { const value = Number(event.target.value); setParticipantCount(Number.isInteger(value) && value >= 1 ? Math.min(100, value) : null) }} /></label></div>}
      {activeRule?.type === 'confirm_required_items' && <div className="checkout-rule-products" aria-label={t('checkoutRequiredItemsRule')}>
        {catalog.loading && <p className="helper" role="status">{t('diningRulesLoading')}</p>}
        {!catalog.loading && requiredItems.length === 0 && <Notice subtle>{t('checkoutRequiredItemsNone')}</Notice>}
        {requiredItems.map(({ rule, item, productName, specificationName, price, expected, priceKnown }) => {
          const fallback: ModernCartLine | undefined = item ? lineFromCatalog(item) : price > 0 ? { specificationUuid: rule.specificationUuid, quantity: 1, productName, specificationName, price, ruleUuids: [], locked: false } : undefined
          const quantity = lineQuantity(rule.specificationUuid)
          return <article key={rule.uuid}><div><strong>{productName}</strong><small>{specificationName}{priceKnown ? ` · ${t('unitPrice')} ${money(price)}` : ''} · {t('checkoutRequiredExpected').replace('{count}', expected === null ? '—' : String(expected))}</small></div><DiningProductQuantity item={{ productName, specificationName }} quantity={quantity} disabled={false} canDecrease={quantity > 0} onIncrease={() => adjust(rule.specificationUuid, 1, fallback)} onDecrease={() => adjust(rule.specificationUuid, -1)} /></article>
        })}
      </div>}
      {activeRule?.type === 'suggest_category' && <div className="checkout-rule-products" aria-label={activeRuleTitle}>
        <p className="helper">{t('checkoutCategorySuggestion')}</p>
        {!catalog.loading && categoryItems.length === 0 && <Notice subtle>{t('checkoutNoCategoryItems')}</Notice>}
        {categoryItems.map((item) => <article key={item.uuid}><div><strong>{item.productName}</strong><small>{item.name} · {t('unitPrice')} {money(item.price)}</small></div><DiningProductQuantity item={{ productName: item.productName, specificationName: item.name }} quantity={lineQuantity(item.uuid)} disabled={!item.available} canDecrease={lineQuantity(item.uuid) > 0} onIncrease={() => adjust(item.uuid, 1, lineFromCatalog(item))} onDecrease={() => adjust(item.uuid, -1)} /></article>)}
      </div>}
      {catalog.error && <Notice>{t(catalog.error)}</Notice>}
    </section>}
    {stage.kind === 'review' && <section className="checkout-final-review">
      <header><span>{t('checkoutFinalReview')}</span><h3>{table.counter.name}</h3><small>{diningMode?.name ?? t('diningModeNotRecorded')}</small></header>
      <div className="checkout-review-amount dining-settlement-total"><span>{t('checkoutEstimatedAmount')}</span><strong>{money(estimatedTotal)}</strong><small>{t('checkoutEstimatedHint')}</small></div>
      {!diningMode && <Notice subtle>{t('checkoutNoModeHint')}</Notice>}
      <div className="checkout-review-heading"><h4>{t('checkoutOriginalItems')}</h4><small>{originalDisplayLines.length} {t('recordsUnit')}</small></div>
      <ul className="checkout-final-lines is-original">{originalDisplayLines.map((line) => {
        const specificationUuid = line.specificationUuid
        const desiredQuantity = specificationUuid ? lineQuantity(specificationUuid) : line.quantity
        const adjustment = desiredQuantity - line.quantity
        const itemLabel = `${line.productName} ${line.specificationName}`
        return <li key={line.key}>
          <span><strong>{line.productName}</strong><small>{line.specificationName} · {t('unitPrice')} {money(line.unitPrice)}</small></span>
          <b>×{line.quantity}</b>
          {specificationUuid && <div className="checkout-original-actions">
            <Button className="dining-step-button" aria-label={`${t('decrease')} ${itemLabel}`} disabled={desiredQuantity <= 0} onClick={() => adjust(specificationUuid, -1)}><Minus size={19} /></Button>
            <Button className="dining-step-button" aria-label={`${t('increase')} ${itemLabel}`} disabled={desiredQuantity >= 99} onClick={() => adjust(specificationUuid, 1, originalLineBySpecification.get(specificationUuid))}><Plus size={19} /></Button>
          </div>}
          {adjustment !== 0 && <em className={adjustment > 0 ? 'is-addition' : 'is-reduction'}>{adjustment > 0 ? `+${adjustment}` : `−${Math.abs(adjustment)}`}</em>}
        </li>
      })}</ul>
      {newAdditionLines.length > 0 && <><div className="checkout-review-heading"><h4>{t('checkoutAddedItems')}</h4><small>{newAdditionLines.length} {t('recordsUnit')}</small></div><ul className="checkout-final-lines is-addition">{newAdditionLines.map((line) => {
        const addedLine = lines.find((entry) => entry.specificationUuid === line.specificationUuid)
        return <li key={line.specificationUuid}><span><strong>{lineName(line.specificationUuid)}</strong>{typeof addedLine?.price === 'number' && <small>{t('unitPrice')} {money(addedLine.price)}</small>}</span><em>+{line.quantity}</em></li>
      })}</ul></>}
      {!reconciliation.changed && <Notice subtle>{t('checkoutNoChangesPlan')}</Notice>}
    </section>}
    {stage.kind === 'result' && result && <section className="checkout-result">
      <Notice title="checkoutResultHintTitle">{t('checkoutResultHint')}</Notice>
      <div className="checkout-receipt-print">
        <header><strong>{access.store.name}</strong><span>{table.counter.name} · {formatDiningDate(new Date().toISOString(), locale)}</span></header>
        <ul>{finalLines.map((line) => <li key={line.key}><span>{line.productName}<small>{line.specificationName}</small><small>{t('unitPrice')} {money(line.unitPrice, finalCurrency)}</small></span><b>×{line.quantity}</b></li>)}</ul>
        <div className="dining-settlement-total"><span>{t('checkoutFinalAmount')}</span><strong>{money(finalAmount, finalCurrency)}</strong><small>{finalOrders.map((order) => `#${order.uuid.slice(0, 8).toUpperCase()}`).join(' · ')}</small></div>
      </div>
      <section className="checkout-payment-methods"><header><CreditCard size={17} /><h4>{t('checkoutPaymentMethods')}</h4></header>
        {paymentMethods.length > 0 ? <div>{paymentMethods.map((method: CheckoutPaymentMethod) => <button type="button" key={method.code} className={paymentMethod === method.code ? 'is-active' : ''} aria-pressed={paymentMethod === method.code} onClick={() => setPaymentMethod(method.code)}>{method.name}</button>)}</div> : <Notice subtle>{t('checkoutNoPaymentMethods')}</Notice>}
      </section>
      <Button block className="checkout-print-button" onClick={() => window.print()}><Printer size={17} />{t('checkoutPrintReceipt')}</Button>
    </section>}
  </LiveSheet>
  <CenterPopup
    visible={progress !== null}
    style={{ zIndex: 1100 }}
    bodyClassName="checkout-progress-popup"
    maskStyle={{ backgroundColor: 'rgba(35, 27, 22, .46)', backdropFilter: 'blur(3px)' }}
    disableBodyScroll
    role="status"
    aria-live="polite"
    aria-atomic="true"
    aria-label={progress ? t(progressLabels[progress]) : undefined}
  >
    {progress && <div className="checkout-progress">
      <span className="checkout-progress-spinner" aria-hidden="true" />
      <strong>{t(progressLabels[progress])}</strong>
      <small>{t('checkoutProgressHint')}</small>
    </div>}
  </CenterPopup>
  </>
}

function DiningEditor({ api, access, table, order, mode = order ? 'replace' : 'add', onClose, done }: { api: StoreApi; access: StoreAccess; table: CounterSessionView; order?: SessionOrder; mode?: DiningEditorMode; onClose: () => void; done: () => void }) {
  const { t, locale } = useI18n()
  const formId = useId()
  const isAdd = mode === 'add'
  const initialLines = useMemo(() => order?.items.flatMap(({ specificationUuid, quantity, productName, specificationName, unitPrice }) => specificationUuid === null ? [] : [{
    specificationUuid, quantity, productName, specificationName, price: unitPrice, ruleUuids: [], locked: false,
  }]) ?? [], [order])
  const [categoryFilter, setCategoryFilter] = useState<string | null>(null)
  const [participantCount, setParticipantCount] = useState(table.session?.participantCount ?? 1)
  const [removedRules, setRemovedRules] = useState<Set<string>>(() => new Set())
  const boundDiningMode = useMemo(
    () => resolveTableDiningMode(table, access.store.settings?.diningModes),
    [access.store.settings?.diningModes, table],
  )
  const diningMode = useDiningModeState(access.store.settings?.diningModes, access.store.timezone, boundDiningMode)
  const ruleCatalog = useRuleCatalog(api, access.store.code, isAdd ? null : diningMode.selected)
  const [items, setItems] = useState<ModernCartLine[]>(initialLines)
  const [notes, setNotes] = useState(order?.notes ?? '')
  const [reason, setReason] = useState('')
  const [quote, setQuote] = useState<OrderQuote | null>(null)
  const [attempted, setAttempted] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const manualQuantities = useMemo(() => {
    const result = new Map<string, number>()
    for (const line of items) result.set(line.specificationUuid, (result.get(line.specificationUuid) ?? 0) + line.quantity)
    return result
  }, [items])
  const ruleLines = useMemo<ModernCartLine[]>(() => isAdd ? [] : defaultRuleLines(ruleCatalog.items, participantCount, removedRules).flatMap((line) => {
    const quantity = Math.max(0, line.quantity - (manualQuantities.get(line.uuid) ?? 0))
    return quantity === 0 ? [] : [{
      specificationUuid: line.uuid, quantity, productUuid: line.productUuid, categoryUuid: line.categoryUuid,
      productName: line.productName, specificationName: line.name, price: line.price, ruleUuids: line.ruleUuids, locked: line.locked,
    }]
  }), [isAdd, manualQuantities, participantCount, removedRules, ruleCatalog.items])
  const orderLines = useMemo(() => mergeModernLines([...ruleLines, ...items]), [items, ruleLines])
  const issues = isAdd ? [] : validateDiningRules(diningMode.selected, orderLines)
  const load = useCallback(async (page: number) => {
    if (diningMode.configured && diningMode.selected) {
      const result = await api.legacyCatalogAll('', access.store.code, diningMode.selected.catalog)
      return { ...result, items: result.items.map((item) => ({ ...item, specificationUuid: item.uuid, specificationName: item.name })) }
    }
    return api.orderableCatalog(page)
  }, [access.store.code, api, diningMode.configured, diningMode.selected])
  const menu = usePage(load, !diningMode.configured || Boolean(diningMode.selected))
  const categories = useMemo(() => categoryOptions(menu.items as LegacyCatalogItem[], t('uncategorized')), [menu.items, t])
  const visibleMenu = useMemo(() => {
    const items = menu.items.filter((item) => {
      const legacy = item as LegacyCatalogItem
      if (categoryFilter === null) return true
      if (categoryFilter === 'uncategorized') return !legacy.categoryUuid
      return legacy.categoryUuid === categoryFilter
    })
    return { ...menu, items }
  }, [categoryFilter, menu])
  const cart: OrderCart = {
    version: table.version,
    items: orderLines.map(({ specificationUuid, quantity }) => ({ specificationUuid, quantity })),
    notes,
    ...(diningMode.selected ? { metadata: { diningModeUuid: diningMode.selected.uuid, diningModeName: diningMode.selected.name } } : {}),
    ...(order ? { replaceOrderUuid: order.uuid } : {}),
  }
  const payload = { ...cart, quoteToken: quote?.quoteToken, ...(order ? { reason: reason.trim() } : {}) }
  const commandUuid = useCommand(payload)
  const title = mode === 'replace' ? 'replaceDining' : mode === 'new' ? 'placeNewDining' : 'addDining'
  const hint = mode === 'replace' ? 'diningReplaceHint' : mode === 'new' ? 'diningNewHint' : 'diningAddHint'
  const itemQuantity = (specificationUuid: string) => orderLines.find((line) => line.specificationUuid === specificationUuid)?.quantity ?? 0
  const choiceBlocked = (item: { specificationUuid: string; productUuid?: string; categoryUuid?: string | null }) => isChoiceSelectionBlocked(diningMode.selected, orderLines, { ...item, quantity: 1 })
  const addItem = (item: { specificationUuid: string; productName: string; specificationName: string; productUuid?: string; categoryUuid?: string | null; available: boolean; price?: number }) => {
    if (orderLines.length >= 30 && !itemQuantity(item.specificationUuid) || itemQuantity(item.specificationUuid) >= 99 || choiceBlocked(item)) return
    const choiceQuantity = diningMode.selected?.rules.find((rule): rule is ChoiceDiningRule => rule.type === 'choice_group' && isChoiceRuleCandidate(rule, { specificationUuid: item.specificationUuid, productUuid: item.productUuid, categoryUuid: item.categoryUuid, quantity: 1 }))?.quantityPerSelection ?? 1
    setItems((current) => current.some((line) => line.specificationUuid === item.specificationUuid)
      ? current.map((line) => line.specificationUuid === item.specificationUuid ? { ...line, quantity: Math.min(99, line.quantity + 1) } : line)
      : [...current, { ...item, quantity: choiceQuantity, ruleUuids: [], locked: false }])
  }
  const switchMode = (next: DiningMode) => {
    diningMode.setSelectedUuid(next.uuid)
    setItems([])
    setRemovedRules(new Set())
    setCategoryFilter(null)
  }
  const removeRuleLine = (line: ModernCartLine) => setRemovedRules((current) => new Set([...current, ...line.ruleUuids]))
  const clearItem = (specificationUuid: string) => {
    const manual = items.find((line) => line.specificationUuid === specificationUuid)
    if (manual) {
      setItems((current) => current.filter((line) => line.specificationUuid !== specificationUuid))
      return
    }
    const automatic = ruleLines.find((line) => line.specificationUuid === specificationUuid)
    if (automatic && !automatic.locked) removeRuleLine(automatic)
  }
  const decreaseItem = (specificationUuid: string) => {
    const manual = items.find((line) => line.specificationUuid === specificationUuid)
    if (manual) {
      if (manual.quantity > 1) setItems((current) => current.map((line) => line.specificationUuid === specificationUuid ? { ...line, quantity: line.quantity - 1 } : line))
      else setItems((current) => current.filter((line) => line.specificationUuid !== specificationUuid))
      return
    }
    const automatic = ruleLines.find((line) => line.specificationUuid === specificationUuid)
    if (automatic && !automatic.locked) removeRuleLine(automatic)
  }
  const canDecreaseItem = (specificationUuid: string) => Boolean(items.find((line) => line.specificationUuid === specificationUuid)) || Boolean(ruleLines.find((line) => line.specificationUuid === specificationUuid && !line.locked))
  const clearCurrentCart = () => {
    setQuote(null)
    setAttempted(false)
    setItems([])
    setRemovedRules(new Set())
    setNotes('')
    setCategoryFilter(null)
    if (!isAdd) setParticipantCount(1)
    diningMode.setSelectedUuid(null)
  }
  const submitDirectAdd = async () => {
    setAttempted(true)
    // Additions are independent Trade Orders. Create them through the same endpoint
    // as the customer ordering app; there is no counter quote step for an add-round.
    await api.legacyCreateOrderFromCart(
      access.store.code,
      table.counter.uuid,
      table.currency,
      cart.items,
      cart.notes,
      diningMode.selected ?? undefined,
    )
  }
  const choiceRules = diningMode.selected?.rules.filter((rule): rule is ChoiceDiningRule => rule.type === 'choice_group') ?? []
  const invalidRules = new Set(issues.map((issue) => issue.ruleUuid))
  const quoteDisabled = !orderLines.length || Boolean(issues.length) || ruleCatalog.loading || Boolean(ruleCatalog.error) || diningMode.configured && !diningMode.selected
  const primaryLabel: MessageKey = quote ? 'diningConfirm' : isAdd ? 'diningConfirm' : 'diningQuote'
  return <LiveSheet open title={t(title)} className="dining-editor" toolbar={!quote && categories.length >= 2 && <MenuCategoryFilter categories={categories} selected={categoryFilter} onChange={setCategoryFilter} />} footer={<div className="dining-confirm-bar">{quote ? <Button disabled={attempted || submitting} onClick={() => setQuote(null)}>{t('diningEditCart')}</Button> : !order ? <Button disabled={submitting} onClick={() => void clearCurrentCart()}>{t('clearCart')}</Button> : null}<Button block type="submit" form={formId} color="primary" loading={submitting} disabled={quote ? submitting : quoteDisabled}>{t(primaryLabel)}</Button></div>} onClose={onClose}>
    <Notice subtle>{t(hint)}</Notice>
    <DiningModePicker state={diningMode} timeZone={access.store.timezone} participantCount={participantCount} onParticipantCount={setParticipantCount} showGuests={!isAdd} onSelect={switchMode} />
    {!isAdd && diningMode.selected && <ModeRuleGuide mode={diningMode.selected} lines={orderLines} issues={issues} />}
    {!isAdd && choiceRules.map((rule) => <ChoiceRulePicker key={rule.uuid} rule={rule} items={menu.items as LegacyCatalogItem[]} lines={orderLines} currency={table.currency} invalid={invalidRules.has(rule.uuid)} disabled={ruleCatalog.loading} onSelect={(item) => addItem({ specificationUuid: item.uuid, productName: item.productName, specificationName: item.name, productUuid: item.productUuid, categoryUuid: item.categoryUuid, price: item.price, available: item.available })} onClear={clearItem} />)}
    {ruleCatalog.error && <Notice>{t(ruleCatalog.error)}</Notice>}
    {!quote ? <MutationForm id={formId} actions={false} submit={isAdd ? submitDirectAdd : async () => { setQuote(await api.orderQuote(table.counter.uuid, cart)) }} onDone={isAdd ? done : () => {}} onBusyChange={setSubmitting}>
      {(!diningMode.configured || diningMode.selected) && <ListFeedback state={visibleMenu} empty="noProducts" hint="adjustSearch" icon={ReceiptText} />}
      <ul className="dining-menu">{visibleMenu.items.map((item) => {
        const legacy = item as LegacyCatalogItem
        const quantity = itemQuantity(item.uuid)
        return <DiningMenuRow key={item.uuid} item={{ productName: item.productName, specificationName: item.name, price: item.price, categoryName: legacy.categoryName }} quantity={quantity} currency={table.currency} disabled={!item.available || orderLines.length >= 30 && !quantity || quantity >= 99 || choiceBlocked({ specificationUuid: item.uuid, productUuid: legacy.productUuid, categoryUuid: legacy.categoryUuid })} canDecrease={canDecreaseItem(item.uuid)} onIncrease={() => addItem({ specificationUuid: item.uuid, productName: item.productName, specificationName: item.name, productUuid: legacy.productUuid, categoryUuid: legacy.categoryUuid, price: item.price, available: item.available })} onDecrease={() => decreaseItem(item.uuid)} />
      })}</ul><PageControls state={menu} />
        {ruleCatalog.loading && <p className="helper" role="status">{t('diningRulesLoading')}</p>}
        <label className="field">{t('orderNote')}<textarea maxLength={1000} value={notes} onChange={(event) => setNotes(event.target.value)} /></label>
        {order && <label className="field">{t('diningReason')}<textarea required maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} /></label>}
      </MutationForm>
    : <MutationForm id={formId} actions={false} submit={() => (setAttempted(true), api.counterCommand(table.counter.uuid, order ? 'replace' : 'create', { ...payload, commandUuid }))} onDone={done} onBusyChange={setSubmitting}>
      <h3>{t('diningQuoteReview')}</h3><DiningLines items={quote.items} currency={quote.currency} /><div className="dining-quote-total"><span>{t('amount')}</span><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: quote.currency }).format(quote.totalAmount / 100)}</strong></div>{notes && <p className="dining-notes">{t('orderNote')} · {notes}</p>}<Notice subtle>{t('diningQuoteHint')}</Notice>{attempted && <Notice>{t('diningRetryHint')}</Notice>}
    </MutationForm>}
  </LiveSheet>
}

function LegacyDiningEditor({ api, access, table, mode, onClose, done }: { api: StoreApi; access: StoreAccess; table: CounterSessionView; mode: 'new' | 'add'; onClose: () => void; done: () => void }) {
  const { t, locale } = useI18n()
  const reviewFormId = useId()
  const isAdd = mode === 'add'
  const [categoryFilter, setCategoryFilter] = useState<string | null>(null)
  const [items, setItems] = useState<(LegacyCatalogItem & { quantity: number })[]>([])
  const [notes, setNotes] = useState('')
  const [participantCount, setParticipantCount] = useState(table.session?.participantCount ?? 1)
  const [removedRules, setRemovedRules] = useState<Set<string>>(() => new Set())
  const [submitting, setSubmitting] = useState(false)
  const [reviewing, setReviewing] = useState(false)
  const boundDiningMode = useMemo(
    () => resolveTableDiningMode(table, access.store.settings?.diningModes),
    [access.store.settings?.diningModes, table],
  )
  const diningMode = useDiningModeState(access.store.settings?.diningModes, access.store.timezone, boundDiningMode)
  const ruleCatalog = useRuleCatalog(api, access.store.code, isAdd ? null : diningMode.selected)
  const ruleLines = useMemo(() => defaultRuleLines(ruleCatalog.items, participantCount, removedRules), [participantCount, removedRules, ruleCatalog.items])
  const manualLines = useMemo<ModeCartLine[]>(() => items.map((item) => ({ ...item, specificationUuid: item.uuid, ruleUuids: [], locked: false })), [items])
  const orderLines = useMemo(() => mergeModeLines([...ruleLines, ...manualLines]), [manualLines, ruleLines])
  const issues = isAdd ? [] : validateDiningRules(diningMode.selected, orderLines)
  const load = useCallback((_page: number) => api.legacyCatalogAll('', access.store.code, diningMode.selected?.catalog), [access.store.code, api, diningMode.selected?.catalog])
  const menu = usePage(load, !diningMode.configured || Boolean(diningMode.selected))
  const categories = useMemo(() => categoryOptions(menu.items, t('uncategorized')), [menu.items, t])
  const visibleMenu = useMemo(() => {
    const visible = menu.items.filter((item) => {
      if (categoryFilter === null) return true
      if (categoryFilter === 'uncategorized') return !item.categoryUuid
      return item.categoryUuid === categoryFilter
    })
    return { ...menu, items: visible }
  }, [categoryFilter, menu])
  const totalAmount = [...ruleLines, ...items].reduce((total, item) => total + item.price * item.quantity, 0)
  const title = mode === 'new' ? 'placeNewDining' : 'addDining'
  const hint = mode === 'new' ? 'diningNewHint' : 'diningAddHint'
  const reviewTitle = locale === 'zh-CN' ? '订单确认' : 'Review order'
  const createOrderLabel = locale === 'zh-CN' ? '创建订单' : 'Create order'
  const reviewHint = locale === 'zh-CN' ? '请核对本次下单的商品、数量和金额；点击“创建订单”后才会提交。' : 'Check the products, quantities and total. The order is not submitted until you choose Create order.'
  const itemQuantity = (item: LegacyCatalogItem) => orderLines.find((line) => line.uuid === item.uuid)?.quantity ?? 0
  const choiceBlocked = (item: LegacyCatalogItem) => isChoiceSelectionBlocked(diningMode.selected, orderLines, { ...item, specificationUuid: item.uuid, quantity: 1 })
  const addItem = (item: LegacyCatalogItem) => {
    if (orderLines.length >= 30 && !itemQuantity(item)) return
    if (itemQuantity(item) >= 99 || choiceBlocked(item)) return
    const choiceQuantity = diningMode.selected?.rules.find((rule): rule is ChoiceDiningRule => rule.type === 'choice_group' && isChoiceRuleCandidate(rule, { ...item, specificationUuid: item.uuid, quantity: 1 }))?.quantityPerSelection ?? 1
    setItems((current) => current.some((line) => line.uuid === item.uuid)
      ? current.map((line) => line.uuid === item.uuid ? { ...line, quantity: Math.min(99, line.quantity + 1) } : line)
      : [...current, { ...item, quantity: choiceQuantity }])
  }
  const switchMode = (next: DiningMode) => {
    diningMode.setSelectedUuid(next.uuid)
    setItems([])
    setRemovedRules(new Set())
    setCategoryFilter(null)
  }
  const removeRuleLine = (line: ModeCartLine) => setRemovedRules((current) => new Set([...current, ...line.ruleUuids]))
  const clearItem = (specificationUuid: string) => {
    const manual = items.find((item) => item.uuid === specificationUuid)
    if (manual) {
      setItems((current) => current.filter((item) => item.uuid !== specificationUuid))
      return
    }
    const automatic = ruleLines.find((line) => line.uuid === specificationUuid)
    if (automatic && !automatic.locked) removeRuleLine(automatic)
  }
  const decreaseItem = (specificationUuid: string) => {
    const manual = items.find((item) => item.uuid === specificationUuid)
    if (manual) {
      if (manual.quantity > 1) setItems((current) => current.map((item) => item.uuid === specificationUuid ? { ...item, quantity: item.quantity - 1 } : item))
      else setItems((current) => current.filter((item) => item.uuid !== specificationUuid))
      return
    }
    const automatic = ruleLines.find((line) => line.uuid === specificationUuid)
    if (automatic && !automatic.locked) removeRuleLine(automatic)
  }
  const canDecreaseItem = (specificationUuid: string) => Boolean(items.find((item) => item.uuid === specificationUuid)) || Boolean(ruleLines.find((line) => line.uuid === specificationUuid && !line.locked))
  const clearCurrentCart = () => {
    setReviewing(false)
    setItems([])
    setRemovedRules(new Set())
    setNotes('')
    setCategoryFilter(null)
    if (!isAdd) setParticipantCount(1)
    diningMode.setSelectedUuid(null)
  }
  const choiceRules = diningMode.selected?.rules.filter((rule): rule is ChoiceDiningRule => rule.type === 'choice_group') ?? []
  const invalidRules = new Set(issues.map((issue) => issue.ruleUuid))
  const submitDisabled = !orderLines.length || Boolean(issues.length) || ruleCatalog.loading || Boolean(ruleCatalog.error) || diningMode.configured && !diningMode.selected
  const createOrder = () => api.legacyCreateOrder(
    access.store.code,
    table.counter.uuid,
    access.store.currency,
    orderLines.map(({ specificationId, quantity }) => ({ specificationId, quantity })),
    notes,
    diningMode.selected ?? undefined,
    isAdd ? table.session?.participantCount ?? 0 : participantCount,
  )
  return <LiveSheet open title={reviewing ? reviewTitle : t(title)} className="dining-editor" toolbar={!reviewing && categories.length >= 2 && <MenuCategoryFilter categories={categories} selected={categoryFilter} onChange={setCategoryFilter} />} footer={<div className="dining-confirm-bar">{reviewing ? <Button disabled={submitting} onClick={() => setReviewing(false)}>{t('diningEditCart')}</Button> : <Button disabled={submitting} onClick={() => void clearCurrentCart()}>{t('clearCart')}</Button>}<Button block type={reviewing ? 'submit' : 'button'} form={reviewing ? reviewFormId : undefined} color="primary" loading={submitting} disabled={!reviewing && submitDisabled} onClick={reviewing ? undefined : (event) => { event.preventDefault(); setReviewing(true) }}>{reviewing ? createOrderLabel : t('diningConfirm')}</Button></div>} onClose={onClose}>
    {reviewing ? <MutationForm id={reviewFormId} actions={false} onBusyChange={setSubmitting} submit={createOrder} onDone={done}>
      <Notice subtle>{reviewHint}</Notice>
      <section className="dining-order-review" aria-label={reviewTitle}>
        <header><div><span>{table.counter.name}</span><strong>{diningMode.selected?.name ?? t('readyToOpen')}</strong></div>{!isAdd && <small>{participantCount} {t('guestUnit')}</small>}</header>
        <ul className="dining-lines">{orderLines.map((item) => <li key={item.uuid}><span>{item.productName}<small>{item.name}</small></span><b>×{item.quantity}</b><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: table.currency }).format(item.price * item.quantity / 100)}</strong></li>)}</ul>
        <div className="dining-quote-total"><span>{t('amount')}</span><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: table.currency }).format(totalAmount / 100)}</strong></div>
        {notes && <p className="dining-notes">{t('orderNote')} · {notes}</p>}
      </section>
    </MutationForm> : <>
    <Notice subtle>{t(hint)}</Notice>
    <DiningModePicker state={diningMode} timeZone={access.store.timezone} participantCount={participantCount} onParticipantCount={setParticipantCount} showGuests={!isAdd} onSelect={switchMode} />
    {!isAdd && diningMode.selected && <ModeRuleGuide mode={diningMode.selected} lines={orderLines} issues={issues} />}
    {!isAdd && choiceRules.map((rule) => <ChoiceRulePicker key={rule.uuid} rule={rule} items={menu.items} lines={orderLines} currency={table.currency} invalid={invalidRules.has(rule.uuid)} disabled={ruleCatalog.loading} onSelect={addItem} onClear={clearItem} />)}
    {ruleCatalog.error && <Notice>{t(ruleCatalog.error)}</Notice>}
    <div className="form-stack">
      <ListFeedback state={visibleMenu} empty="noProducts" hint="noProductsHint" icon={ReceiptText} />
      <ul className="dining-menu">{visibleMenu.items.map((item) => <DiningMenuRow key={item.uuid} item={{ productName: item.productName, specificationName: item.name, price: item.price, categoryName: item.categoryName }} quantity={itemQuantity(item)} currency={table.currency} disabled={!item.available || orderLines.length >= 30 && !itemQuantity(item) || itemQuantity(item) >= 99 || choiceBlocked(item)} canDecrease={canDecreaseItem(item.uuid)} onIncrease={() => addItem(item)} onDecrease={() => decreaseItem(item.uuid)} />)}</ul>
      <PageControls state={menu} />
      {ruleCatalog.loading && <p className="helper" role="status">{t('diningRulesLoading')}</p>}
      <label className="field">{t('orderNote')}<textarea maxLength={1000} value={notes} onChange={(event) => setNotes(event.target.value)} /></label>
      <div className="dining-quote-total"><span>{t('amount')}</span><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: table.currency }).format(totalAmount / 100)}</strong></div>
    </div>
    </>}
  </LiveSheet>
}
