import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { Button } from 'antd-mobile'
import { ChevronDown, Clock3, LayoutGrid, Minus, Pencil, Plus, ReceiptText, RotateCcw, UsersRound } from 'lucide-react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, legacyCounterSession, StoreApi, type StoreAccess, type CounterSessionView, type SessionOrder, type OrderQuote, type OrderCart, type CounterCommand, type LegacyCatalogItem, type LiveCounter } from '../adapters/live'
import { availableDiningModes, diningModeLabel, isChoiceRuleCandidate, isChoiceSelectionBlocked, normalizeDiningModes, resolveBoundDiningMode, ruleQuantity, validateDiningRules, type CheckoutRule, type ChoiceDiningRule, type DiningLine, type DiningMode, type DiningRule, type DiningRuleIssue, type FixedDiningRule, type PerGuestDiningRule } from '../dining-mode'
import { reconcileCheckoutQuantities } from '../checkout-reconciliation'
import { ApiError } from '../adapters/api-client'
import { EditorPreview, EditorSheet, errorKey, ListFeedback, LiveSheet, MutationForm, Notice, PageControls, StatusPill, useCommand, usePage, usePageRefresh } from './ui'
import './dining.css'

const labels: Record<CounterCommand, MessageKey> = { update: 'updateDining', end: 'endDining', create: 'placeDining', replace: 'replaceDining', cancel: 'cancelOrder' }
const tradeLabels: Record<string, MessageKey> = { draft: 'diningDraft', pending: 'diningPending', confirmed: 'diningConfirmed', paid: 'diningPaid', fulfilled: 'stateFulfilled', completed: 'diningCompleted', cancelled: 'stateCancelled', refunded: 'diningRefunded', merged: 'diningMerged' }
const operationLabels: Record<string, MessageKey> = { pending_validation: 'statePendingValidation', awaiting_inventory: 'stateAwaitingInventory', accepted: 'stateAccepted', rejected: 'stateRejected', fulfillment_pending: 'stateFulfillmentPending', fulfilling: 'stateFulfilling', fulfilled: 'stateFulfilled', verified: 'stateVerified', cancelled: 'stateCancelled' }
const effectiveDiningStatuses = new Set(['pending', 'confirmed'])

function effectiveDiningOrders(table: CounterSessionView): SessionOrder[] {
  return table.ordersVisible ? table.orders.filter((order) => effectiveDiningStatuses.has(order.status)) : []
}

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

async function waitForCheckoutOrder(api: StoreApi, counterUuid: string, matches: (order: SessionOrder) => boolean): Promise<{ table: CounterSessionView; order: SessionOrder }> {
  const deadline = Date.now() + staffProjectionTimeout
  while (true) {
    const table = await api.counterSession(counterUuid)
    const order = effectiveDiningOrders(table).find(matches)
    if (order) return { table, order }
    if (Date.now() >= deadline) throw new ApiError('request', 409)
    await wait(staffProjectionInterval)
  }
}

async function waitForCheckoutReplacement(api: StoreApi, counterUuid: string, orderUuid: string) {
  return waitForCheckoutOrder(api, counterUuid, (order) => order.uuid === orderUuid && order.canReplace)
}

/** Session commands and order commands use different resources, so each action is checked separately. */
function permitted(access: StoreAccess, command: CounterCommand | 'merge'): boolean {
  if (command === 'update' || command === 'end') return can(access, 'counter_session', command)
  return can(access, 'counter_order', command)
}

const counterPageSize = 30

const hasModernCounterRead = (access: StoreAccess) => can(access, 'counter_session', 'read')
const hasLegacyCounterRead = (access: StoreAccess) => !hasModernCounterRead(access) && can(access, 'counter', 'read')

async function loadAllCounterSessions(api: StoreApi): Promise<CounterSessionView[]> {
  const tables: CounterSessionView[] = []
  let page = 1
  while (true) {
    const result = await api.counterSessions(page)
    tables.push(...result.items)
    if (!result.hasNext) return tables
    if (!result.items.length) throw new Error('Invalid empty counter page')
    page += 1
  }
}

async function loadLegacyCounterSessions(api: StoreApi, access: StoreAccess): Promise<CounterSessionView[]> {
  const counters = (await api.legacyCounters()).filter((counter) => counter.enabled)
  return Promise.all(counters.map(async (counter) => legacyCounterSession(
    counter,
    await api.legacyCounterOrders(counter.uuid, access.store.code),
    access.store.currency,
  )))
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
    if (table.session) dining += 1
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
  const [settling, setSettling] = useState<{ table: CounterSessionView; autoMerged: boolean } | null>(null)
  const [checkoutEditing, setCheckoutEditing] = useState<CounterSessionView | null>(null)
  const [actionError, setActionError] = useState<MessageKey | null>(null)
  const amount = (value: number, currency: string) => new Intl.NumberFormat(locale, { style: 'currency', currency }).format(value / 100)
  const orderable = list.legacy ? can(access, 'counter', 'read') : permitted(access, 'create')
  const startCheckout = async (table: CounterSessionView) => {
    setSelected(null)
    setActionError(null)
    if (!navigator.onLine) { setActionError('offline'); return }
    try {
      // Rules and their matching order content are reviewed before any checkout edit starts.
      // Legacy reads do not expose the versioned session, so resolve it first.
      const current = list.legacy ? await api.counterSession(table.counter.uuid) : table
      setSettling({ table: current, autoMerged: false })
    } catch (error) {
      setActionError(errorKey(error))
    }
  }
  const finishCheckoutEdit = async () => {
    if (!checkoutEditing) return
    try {
      const refreshed = await api.counterSession(checkoutEditing.counter.uuid)
      setCheckoutEditing(null)
      setSettling({ table: refreshed, autoMerged: false })
      list.reload()
    } catch (error) {
      setActionError(errorKey(error))
    }
  }
  const startOrder = (table: CounterSessionView) => {
    if (!orderable || !table.counter.enabled) return
    if (!navigator.onLine) { setActionError('offline'); return }
    setActionError(null)
    setOrdering({ table, mode: !table.session || (table.ordersVisible && table.orders.length === 0) ? 'new' : 'add' })
  }
  return <><DiningSummary tables={list.allItems} loading={list.loading} currency={access.store.currency} locale={locale} /><ListFeedback state={list} empty="firstTable" hint="existingTablesHint" icon={LayoutGrid} />
    {actionError && <Notice>{t(actionError)}</Notice>}
    <div className="dining-board">{list.items.map((table) => {
      const hasOrders = table.ordersVisible && table.orders.length > 0
      return <article className={`dining-card ${table.session ? 'has-session' : ''} ${hasOrders ? 'has-orders' : ''} ${table.counter.enabled ? '' : 'is-disabled'}`} key={table.counter.uuid} onClick={() => setSelected(table.counter.uuid)}>
        <div className="dining-card-heading"><h2><button type="button" className="dining-card-title">{table.counter.name}</button></h2><div className="dining-card-heading-actions"><StatusPill muted={!table.session}>{t(table.session ? 'dining' : table.counter.enabled ? 'idle' : 'tableDisabled')}</StatusPill>{onEdit && can(access, 'counter', 'update') && <Button size="mini" className="dining-card-edit" aria-label={t('edit')} onClick={(event) => { event.stopPropagation(); void api.legacyCounter(table.counter.uuid, access.store.code).then(onEdit).catch((error: unknown) => setActionError(errorKey(error))) }}><Pencil size={12} /></Button>}</div></div>
        <div className="dining-card-meta"><span><UsersRound size={14} />{table.session?.participantCount ?? '—'} {t('guestUnit')}</span>{table.session && <span><Clock3 size={14} /><time dateTime={table.session.startedAt}>{formatDiningDate(table.session.startedAt, locale)}</time></span>}</div>
        {hasOrders ? <><div className="dining-card-total"><span>{t('diningOpenAmount')}</span><strong>{amount(table.openAmount, table.currency)}</strong></div><p className="dining-card-caption">{table.orders.length} {t('diningBatches')}{table.paymentHold && ` · ${t('diningPaymentHold')}`}</p></> : table.session ? <p className="dining-card-caption">{table.ordersVisible ? t('noItems') : t('sessionOrdersRestricted')}</p> : <p className="dining-card-idle">{t(table.counter.enabled ? 'readyToOpen' : 'tableDisabled')}</p>}
      </article>
    })}</div><PageControls state={list} />
    {selected && <LiveSheet open title={t('viewDining')} onClose={() => setSelected(null)}><DiningDetail api={api} access={access} legacy={list.legacy} orderable={orderable} id={selected} changed={list.reload} onOrder={(table) => { setReturnTo(table.counter.uuid); setSelected(null); startOrder(table) }} onCheckout={(table) => void startCheckout(table)} /></LiveSheet>}
    {ordering && <LegacyDiningEditor api={api} access={access} table={ordering.table} mode={ordering.mode} onClose={() => { setOrdering(null); setReturnTo(null) }} done={() => { setOrdering(null); list.reload(); setSelected(returnTo); setReturnTo(null) }} />}
    {checkoutEditing && <DiningEditor api={api} access={access} table={checkoutEditing} mode="checkout" onClose={() => setCheckoutEditing(null)} done={() => void finishCheckoutEdit()} />}
    {settling && <DiningSettlement table={settling.table} storeDiningModes={access.store.settings?.diningModes} autoMerged={settling.autoMerged} onClose={() => setSettling(null)} onAdjust={(table) => { setSettling(null); setCheckoutEditing(table) }} />}
  </>
}

function formatDiningDate(value: string, locale: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(date)
}

function DiningDetail({ api, access, legacy, orderable, id, changed, onOrder, onCheckout }: { api: StoreApi; access: StoreAccess; legacy: boolean; orderable: boolean; id: string; changed: () => void; onOrder: (table: CounterSessionView) => void; onCheckout: (table: CounterSessionView) => void }) {
  const { t, locale } = useI18n()
  const load = useCallback(async () => ({ items: [legacy
    ? legacyCounterSession(await api.legacyCounter(id, access.store.code), await api.legacyCounterOrders(id, access.store.code), access.store.currency)
    : await api.counterSession(id)], hasNext: false }), [access.store.code, access.store.currency, api, id, legacy])
  const detail = usePage(load)
  const [operation, setOperation] = useState<{ table: CounterSessionView; command: CounterCommand; order?: SessionOrder } | null>(null)
  const done = () => { setOperation(null); detail.reload(); changed() }
  return <><ListFeedback state={detail} empty="emptyLive" hint="diningScopeHint" icon={LayoutGrid} />{detail.items.map((table) => {
    const orders = effectiveDiningOrders(table)
    const hasOrders = orders.length > 0
    const hasAnyOrders = table.ordersVisible && table.orders.length > 0
    const pendingOrders = table.ordersVisible ? table.orders.filter((order) => order.status === 'pending') : []
    return <section className="dining-detail" key={table.counter.uuid}>
    <div className="dining-detail-hero"><div className="dining-detail-heading"><div><h3>{table.counter.name}</h3></div><StatusPill muted={!table.session}>{t(table.session ? 'dining' : table.counter.enabled ? 'idle' : 'tableDisabled')}</StatusPill></div><div className="dining-detail-summary"><span><UsersRound size={15} />{table.session?.participantCount ?? '—'} {t('guestUnit')}</span>{table.session && <span><Clock3 size={15} /><time dateTime={table.session.startedAt}>{formatDiningDate(table.session.startedAt, locale)}</time></span>}</div>{hasOrders && <div className="dining-detail-amount"><span>{t('diningOpenAmount')}</span><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: table.currency }).format(effectiveDiningAmount(table) / 100)}</strong></div>}</div>
    {table.session?.notes && <p className="dining-notes dining-party-notes"><strong>{t('diningPartyNotes')}</strong>{table.session.notes}</p>}
    <div className="order-operation-actions">{orderable && <Button color="primary" disabled={!table.counter.enabled || (table.ordersVisible && table.paymentHold)} onClick={() => onOrder(table)}>{t(hasOrders ? 'addDining' : 'placeNewDining')}</Button>}{!legacy && table.session && (['update', 'end'] as const).filter((command) => permitted(access, command)).map((command) => <Button key={command} disabled={command === 'end' && !table.canEnd} onClick={() => setOperation({ table, command })}>{t(command === 'end' && !hasAnyOrders ? 'clearDining' : labels[command])}</Button>)}{hasOrders && <Button onClick={() => onCheckout(table)}>{t('checkoutDining')}</Button>}</div>
    {pendingOrders.length > 0 && permitted(access, 'cancel') && <CancelPendingOrders api={api} access={access} legacy={legacy} table={table} pendingOrders={pendingOrders} onDone={done} />}
    {table.ordersVisible && table.paymentHold && <Notice>{t('diningPaymentHold')}</Notice>}{table.session && !table.canEnd && <p className="helper">{t('diningEndBlocked')}</p>}
    {!table.ordersVisible ? <Notice subtle>{t('sessionOrdersRestricted')}</Notice> : <>
      <div className="dining-section-heading"><h3>{t('diningBatches')}</h3><span>{orders.length}</span></div>{!orders.length && <p className="dining-empty-orders">{t('noItems')}</p>}
      {[...orders].sort((a, b) => a.createdAt.localeCompare(b.createdAt) || a.uuid.localeCompare(b.uuid)).map((order, index) => <article className="dining-round" key={order.uuid}>
        <header><strong>{String(index + 1).padStart(2, '0')} · #{order.uuid.slice(0, 8)}</strong><StatusPill>{tradeLabels[order.status] ? t(tradeLabels[order.status]) : order.status}</StatusPill></header>
        <p><time dateTime={order.createdAt}>{formatDiningDate(order.createdAt, locale)}</time></p>
        <p>{t('diningOperationalState')} · {order.operationalStatus ? t(operationLabels[order.operationalStatus] ?? 'orderStatus') : t('diningProjecting')}</p>
        <p>{t('diningPaymentState')} · {order.paymentStatus ?? t('diningNoPayment')}{order.hasInvoice && ` · ${t('diningHasInvoice')}`}</p>
        <DiningLines items={order.items} currency={order.currency} /><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(order.totalAmount / 100)}</strong>
        {order.notes && <p className="dining-notes">{order.notes}</p>}
        {order.canReplace && permitted(access, 'replace') && <Button disabled={!table.counter.enabled || table.paymentHold} onClick={() => setOperation({ table, order, command: 'replace' })}>{t('replaceDining')}</Button>}
        {order.canCancel && permitted(access, 'cancel') && <Button onClick={() => setOperation({ table, order, command: 'cancel' })}>{t('cancelOrder')}</Button>}
      </article>)}
    </>}
  </section>})}<PageControls state={detail} detail />{operation && (operation.command === 'replace' ? <DiningEditor api={api} access={access} mode="replace" {...operation} onClose={() => setOperation(null)} done={done} /> : operation.command === 'cancel' ? <OrderCancel api={api} table={operation.table} order={operation.order!} onClose={() => setOperation(null)} done={done} /> : <DiningOperation api={api} {...operation} onClose={() => setOperation(null)} done={done} />)}</>
}

function CancelPendingOrders({ api, access, legacy, table, pendingOrders, onDone }: { api: StoreApi; access: StoreAccess; legacy: boolean; table: CounterSessionView; pendingOrders: SessionOrder[]; onDone: () => void }) {
  const { t, locale } = useI18n()
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [result, setResult] = useState<string | null>(null)
  const commandUuids = useRef(new Map<string, string>())
  const amount = pendingOrders.reduce((total, order) => total + order.totalAmount, 0)
  const formattedAmount = new Intl.NumberFormat(locale, { style: 'currency', currency: pendingOrders[0]?.currency ?? table.currency }).format(amount / 100)
  const loadTable = async () => legacy
    ? legacyCounterSession(await api.legacyCounter(table.counter.uuid, access.store.code), await api.legacyCounterOrders(table.counter.uuid, access.store.code), access.store.currency)
    : api.counterSession(table.counter.uuid)
  const cancelPending = async () => {
    if (busy || !pendingOrders.length) return
    if (!navigator.onLine) {
      setResult(t('networkFailed'))
      return
    }
    setBusy(true)
    setResult(null)
    let cancelled = 0
    let failed = 0
    try {
      for (const target of pendingOrders) {
        try {
          const current = await loadTable()
          if (!current.ordersVisible) { failed += 1; continue }
          const order = current.orders.find((entry) => entry.uuid === target.uuid)
          if (!order) { failed += 1; continue }
          if (order.status === 'cancelled') { cancelled += 1; continue }
          if (order.status !== 'pending' || !order.canCancel) { failed += 1; continue }
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
      onDone()
      if (!failed) setConfirming(false)
    } finally {
      setBusy(false)
    }
  }
  return <><div className="dining-batch-cancel"><Button block color="danger" fill="outline" disabled={busy} onClick={() => { setResult(null); setConfirming(true) }}>{t('cancelPendingOrders')}</Button></div>
    <LiveSheet open={confirming} title={t('cancelPendingTitle')} className="dining-batch-cancel-confirm" onClose={() => { if (!busy) setConfirming(false) }} footer={<div className="dining-confirm-bar"><Button disabled={busy} onClick={() => setConfirming(false)}>{t('cancel')}</Button><Button block color="danger" loading={busy} disabled={busy} onClick={() => void cancelPending()}>{t('cancelPendingConfirm')}</Button></div>}>
      <Notice>{t('cancelPendingHint').replace('{count}', String(pendingOrders.length)).replace('{amount}', formattedAmount)}</Notice>
      {result && <Notice>{result}</Notice>}
    </LiveSheet>
  </>
}

function DiningSettlement({ table, storeDiningModes, autoMerged, onClose, onAdjust }: { table: CounterSessionView; storeDiningModes: unknown; autoMerged: boolean; onClose: () => void; onAdjust: (table: CounterSessionView) => void }) {
  const { t, locale } = useI18n()
  const currency = table.currency
  const diningMode = resolveBoundDiningMode(table.session?.diningMode, table.session?.diningModeUuid, storeDiningModes)
  const currentOrders = effectiveDiningOrders(table)
  return <LiveSheet open title={t('checkoutDining')} className="dining-settlement" onClose={onClose}>
    {autoMerged && <Notice title="diningMergedWithoutModeTitle">{t('diningMergedWithoutModeHint')}</Notice>}
    {diningMode && diningMode.checkoutRules.length > 0 && <DiningCheckoutRules table={table} mode={diningMode} />}
    {!diningMode && <Notice subtle>{t('checkoutNoModeHint')}</Notice>}
    {table.ordersVisible && currentOrders.length > 0 && <section className="dining-settlement-current-order"><header><h3>{t('checkoutRuleOrderTitle')}</h3><small>{currentOrders.length} {t('diningBatches')}</small></header><div className="dining-settlement-orders">{currentOrders.map((order) => <section key={order.uuid}><header><strong>#{order.uuid.slice(0, 8).toUpperCase()}</strong><time dateTime={order.createdAt}>{formatDiningDate(order.createdAt, locale)}</time></header><DiningLines items={order.items} currency={order.currency} /><b>{new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(order.totalAmount / 100)}</b></section>)}</div></section>}
    <div className="dining-settlement-total"><span>{t('diningOpenAmount')}</span><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency }).format(effectiveDiningAmount(table) / 100)}</strong><small>{table.counter.name}{diningMode ? ` · ${diningMode.name}` : ''}</small></div>
    <Notice title="checkoutUnavailableTitle">{t('checkoutUnavailableHint')}</Notice>
    <div className="dining-settlement-actions"><Button onClick={onClose}>{t('back')}</Button><Button block color="primary" onClick={() => onAdjust(table)}>{t('checkoutAdjustOrder')}</Button></div>
  </LiveSheet>
}

interface CheckoutRuleLine { specificationUuid: string | null; quantity: number; productName?: string; specificationName?: string }

function DiningCheckoutRules({ table, mode, lines, participantCount: participantOverride }: { table: CounterSessionView; mode: DiningMode; lines?: CheckoutRuleLine[]; participantCount?: number | null }) {
  const { t } = useI18n()
  const [confirmed, setConfirmed] = useState<Set<string>>(() => new Set())
  const orderItems: CheckoutRuleLine[] = lines ?? (table.ordersVisible ? effectiveDiningOrders(table).flatMap((order) => order.items) : [])
  const quantities = new Map<string, number>()
  for (const item of orderItems) if (item.specificationUuid) quantities.set(item.specificationUuid, (quantities.get(item.specificationUuid) ?? 0) + item.quantity)
  const participantCount = participantOverride === undefined ? table.session?.participantCount ?? null : participantOverride
  const requiredItems = mode.rules.filter((rule): rule is FixedDiningRule | PerGuestDiningRule => (
    (rule.type === 'fixed_item' || rule.type === 'per_guest_item') && rule.required
  )).map((rule) => {
    const expected = ruleQuantity(rule, participantCount ?? 1)
    const actual = quantities.get(rule.specificationUuid) ?? 0
    return { rule, expected, actual, satisfied: actual >= expected && (rule.type !== 'per_guest_item' || participantCount !== null) }
  })
  const toggle = (rule: CheckoutRule) => setConfirmed((current) => {
    const next = new Set(current)
    if (next.has(rule.uuid)) next.delete(rule.uuid)
    else next.add(rule.uuid)
    return next
  })
  const itemLabel = (specificationUuid: string, fallback: string) => {
    const item = orderItems.find((entry) => entry.specificationUuid === specificationUuid && entry.productName)
    return item ? `${item.productName}${item.specificationName ? ` · ${item.specificationName}` : ''}` : fallback || t('checkoutRequiredItem')
  }
  const ruleTitle = (rule: CheckoutRule) => rule.title.trim() || rule.name.trim() || t(rule.type === 'confirm_participants' ? 'checkoutGuestRule' : rule.type === 'confirm_required_items' ? 'checkoutRequiredItemsRule' : 'checkoutCategoryRule')
  return <section className="dining-checkout-rules" aria-label={t('checkoutRules')}>
    <header><div><span>{t('checkoutRulesForMode')}</span><strong>{mode.name}</strong></div><small>{mode.checkoutRules.length}</small></header>
    {mode.checkoutRules.map((rule, index) => {
      const blocked = rule.type === 'confirm_participants'
        ? participantCount === null
        : rule.type === 'confirm_required_items' && requiredItems.some((item) => !item.satisfied)
      return <article key={rule.uuid} className={`${confirmed.has(rule.uuid) ? 'is-confirmed' : ''} ${blocked ? 'is-blocked' : ''}`}>
        <div className="dining-checkout-rule-title"><span>{String(index + 1).padStart(2, '0')}</span><strong>{ruleTitle(rule)}</strong><small>{t(rule.required ? 'checkoutRuleRequired' : 'checkoutRuleOptional')}</small></div>
        {rule.type === 'confirm_participants' && <p>{participantCount === null ? t('checkoutParticipantsMissing') : t('checkoutParticipantsValue').replace('{count}', String(participantCount))}</p>}
        {rule.type === 'confirm_required_items' && <div className="dining-checkout-item-list">{requiredItems.length === 0 ? <p>{t('checkoutRequiredItemsNone')}</p> : requiredItems.map(({ rule: itemRule, expected, actual, satisfied }) => <div key={itemRule.uuid}><span>{itemLabel(itemRule.specificationUuid, itemRule.name)}</span><b className={satisfied ? 'is-ok' : 'is-missing'}>{t('checkoutItemQuantity').replace('{actual}', String(actual)).replace('{expected}', String(expected))}</b></div>)}</div>}
        {rule.type === 'suggest_category' && <p>{t('checkoutCategorySuggestion')}</p>}
        <label className="dining-checkout-confirm"><input type="checkbox" checked={confirmed.has(rule.uuid)} disabled={blocked} onChange={() => toggle(rule)} /><span>{blocked ? t('checkoutRuleBlocked') : confirmed.has(rule.uuid) ? t('checkoutRuleConfirmed') : t('checkoutRuleConfirm')}</span></label>
      </article>
    })}
  </section>
}

function DiningLines({ items, currency }: { items: SessionOrder['items']; currency: string }) {
  const { locale } = useI18n()
  return <ul className="dining-lines">{items.map((item, index) => <li key={`${item.specificationUuid}:${index}`}><span>{item.productName}<small>{item.specificationName}</small></span><b>×{item.quantity}</b><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency }).format(item.amount / 100)}</strong></li>)}</ul>
}

function DiningOperation({ api, table, command, onClose, done }: { api: StoreApi; table: CounterSessionView; command: CounterCommand; onClose: () => void; done: () => void }) {
  const { t } = useI18n()
  const clear = command === 'end' && (!table.ordersVisible || table.orders.length === 0)
  const [participantCount, setParticipantCount] = useState(table.session?.participantCount ?? 1)
  const [notes, setNotes] = useState(table.session?.notes ?? '')
  const payload = { version: table.version, ...(command === 'update' ? { participantCount, notes } : {}) }
  const commandUuid = useCommand({ command, counter: table.counter.uuid, ...payload })
  return <EditorSheet title={t(clear ? 'clearDining' : labels[command])} description={clear ? 'diningClearHint' : command === 'end' ? 'diningEndHint' : 'diningCommandHint'} preview={<EditorPreview icon={clear ? RotateCcw : UsersRound} label={clear ? 'clearDining' : 'diningBoard'} name={table.counter.name} />} onClose={onClose} onDone={done} submitLabel={clear ? 'confirmClearDining' : 'save'} submit={() => api.counterCommand(table.counter.uuid, command, { ...payload, commandUuid })}>
    {command === 'update' && <><label className="field">{t('people')}<input required type="number" min={1} max={100} step={1} value={participantCount} onChange={(event) => setParticipantCount(Number(event.target.value))} /></label><label className="field">{t('diningPartyNotes')}<textarea maxLength={1000} value={notes} onChange={(event) => setNotes(event.target.value)} /></label></>}
    {command === 'end' && <Notice>{t(clear ? 'diningClearHint' : 'diningEndHint')}</Notice>}
  </EditorSheet>
}

function OrderCancel({ api, table, order, onClose, done }: { api: StoreApi; table: CounterSessionView; order: SessionOrder; onClose: () => void; done: () => void }) {
  const { t, locale } = useI18n()
  const [reason, setReason] = useState('')
  const payload = { version: table.version, orderUuid: order.uuid, reason: reason.trim() }
  const commandUuid = useCommand(payload)
  return <EditorSheet title={t('cancelOrder')} description="orderCancelHint" preview={<EditorPreview icon={ReceiptText} label="orderReference" name={`#${order.uuid.slice(0, 8).toUpperCase()}`} detail={new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(order.totalAmount / 100)} />} onClose={onClose} onDone={done} submit={() => api.counterCommand(table.counter.uuid, 'cancel', { ...payload, commandUuid })}>
    <Notice>{t('orderCancelHint')}</Notice>
    <label className="field">{t('cancelReason')}<textarea required maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} /></label>
  </EditorSheet>
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

type DiningEditorMode = 'new' | 'add' | 'replace' | 'checkout'
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

function DiningProductQuantity({ item, quantity, disabled, canDecrease, onIncrease, onDecrease }: {
  item: { productName: string; specificationName: string }; quantity: number; disabled: boolean; canDecrease: boolean; onIncrease: () => void; onDecrease: () => void
}) {
  const { t } = useI18n()
  const label = `${item.productName} ${item.specificationName}`
  if (quantity === 0) return <Button aria-label={`${t('addItems')} ${label}`} disabled={disabled} onClick={onIncrease}><Plus size={16} /></Button>
  return <div className="dining-product-quantity" aria-label={`${t('diningCart')} ${label} ${quantity}`}>
    <Button aria-label={`${t('decrease')} ${label}`} disabled={!canDecrease} onClick={onDecrease}><Minus size={15} /></Button>
    <strong aria-live="polite">×{quantity}</strong>
    <Button aria-label={`${t('increase')} ${label}`} disabled={disabled} onClick={onIncrease}><Plus size={15} /></Button>
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

function CheckoutOrderReview({ lines, currency }: { lines: ModernCartLine[]; currency: string }) {
  const { t, locale } = useI18n()
  return <section className="dining-checkout-order" aria-label={t('checkoutRuleOrderTitle')}>
    <header><h3>{t('checkoutRuleOrderTitle')}</h3><small>{lines.length} {t('recordsUnit')}</small></header>
    <ul>{lines.map((line) => <li key={line.specificationUuid}>
      <span><strong>{line.productName}</strong>{line.specificationName && <small>{line.specificationName}</small>}</span>
      <b>×{line.quantity}</b>
      {line.price !== undefined && <em>{new Intl.NumberFormat(locale, { style: 'currency', currency }).format(line.price * line.quantity / 100)}</em>}
    </li>)}</ul>
  </section>
}

function CheckoutChangePlan({ table, lines }: { table: CounterSessionView; lines: ModernCartLine[] }) {
  const { t } = useI18n()
  const currentLines = checkoutCartLines(table)
  const reconciliation = reconcileCheckoutQuantities(currentLines, lines.map(({ specificationUuid, quantity }) => ({ specificationUuid, quantity })))
  const names = new Map<string, string>()
  for (const item of effectiveDiningOrders(table).flatMap((order) => order.items)) {
    if (item.specificationUuid) names.set(item.specificationUuid, `${item.productName}${item.specificationName ? ` · ${item.specificationName}` : ''}`)
  }
  for (const line of lines) names.set(line.specificationUuid, `${line.productName}${line.specificationName ? ` · ${line.specificationName}` : ''}`)
  const describe = (items: { specificationUuid: string; quantity: number }[]) => items.map((item) => `${names.get(item.specificationUuid) ?? item.specificationUuid} ×${item.quantity}`).join('、')
  return <section className={`dining-checkout-plan ${reconciliation.changed ? 'has-changes' : ''}`} aria-label={t('checkoutChangePlan')}>
    <header><h3>{t('checkoutChangePlan')}</h3><span>{t('checkoutPlanHint')}</span></header>
    {!reconciliation.changed && <p>{t('checkoutNoChangesPlan')}</p>}
    {reconciliation.additions.length > 0 && <p className="is-addition">{t('checkoutAdditionsPlan').replace('{items}', describe(reconciliation.additions))}</p>}
    {reconciliation.reductions.length > 0 && <p className="is-reduction">{t('checkoutReductionsPlan').replace('{items}', describe(reconciliation.reductions))}</p>}
  </section>
}

function DiningEditor({ api, access, table, order, mode = order ? 'replace' : 'add', onClose, done }: { api: StoreApi; access: StoreAccess; table: CounterSessionView; order?: SessionOrder; mode?: DiningEditorMode; onClose: () => void; done: () => void }) {
  const { t, locale } = useI18n()
  const formId = useId()
  const isAdd = mode === 'add'
  const isCheckout = mode === 'checkout'
  const initialLines = useMemo(() => order?.items.flatMap(({ specificationUuid, quantity, productName, specificationName, unitPrice }) => specificationUuid === null ? [] : [{
    specificationUuid, quantity, productName, specificationName, price: unitPrice, ruleUuids: [], locked: false,
  }]) ?? (isCheckout ? checkoutCartLines(table) : []), [isCheckout, order, table])
  const [categoryFilter, setCategoryFilter] = useState<string | null>(null)
  const [participantCount, setParticipantCount] = useState(table.session?.participantCount ?? 1)
  const [removedRules, setRemovedRules] = useState<Set<string>>(() => new Set())
  // Checkout must use the mode snapshotted when the first order was placed. A historical
  // session without that snapshot goes directly through the order-merge path.
  const boundDiningMode = useMemo(
    () => resolveBoundDiningMode(table.session?.diningMode, table.session?.diningModeUuid, access.store.settings?.diningModes),
    [access.store.settings?.diningModes, table.session?.diningMode, table.session?.diningModeUuid],
  )
  const diningMode = useDiningModeState(access.store.settings?.diningModes, access.store.timezone, boundDiningMode, !isCheckout || Boolean(boundDiningMode))
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
  const cart: OrderCart = { version: table.version, items: orderLines.map(({ specificationUuid, quantity }) => ({ specificationUuid, quantity })), notes, ...(diningMode.selected ? { diningModeUuid: diningMode.selected.uuid } : {}), ...(order ? { replaceOrderUuid: order.uuid } : {}) }
  const payload = { ...cart, quoteToken: quote?.quoteToken, ...(order ? { reason: reason.trim() } : {}) }
  const commandUuid = useCommand(payload)
  const title = mode === 'replace' ? 'replaceDining' : mode === 'new' ? 'placeNewDining' : isCheckout ? 'checkoutDining' : 'addDining'
  const hint = mode === 'replace' ? 'diningReplaceHint' : mode === 'new' ? 'diningNewHint' : isCheckout ? 'checkoutAdjustHint' : 'diningAddHint'
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
    // Keep the server's price, availability and version check, but do not interrupt
    // an add-round flow with a separate quote review. The create command still requires
    // the resulting short-lived quote token.
    const currentQuote = await api.orderQuote(table.counter.uuid, cart)
    await api.counterCommand(table.counter.uuid, 'create', { ...cart, quoteToken: currentQuote.quoteToken, commandUuid })
  }
  const submitCheckout = async () => {
    setAttempted(true)
    const refresh = () => api.counterSession(table.counter.uuid)
    let current = table
    const participantManagedByMode = Boolean(diningMode.selected && (
      diningMode.selected.rules.some((rule) => rule.type === 'per_guest_item')
      || diningMode.selected.checkoutRules.some((rule) => rule.type === 'confirm_participants')
    ))
    if (participantManagedByMode && current.session && participantCount !== current.session.participantCount) {
      if (!permitted(access, 'update')) throw new ApiError('forbidden', 403)
      await api.counterCommand(table.counter.uuid, 'update', {
        version: current.version,
        participantCount,
        notes: current.session.notes ?? '',
        commandUuid: crypto.randomUUID(),
      })
      current = await refresh()
    }
    const intended = orderLines.map(({ specificationUuid, quantity }) => ({ specificationUuid, quantity }))
    const reconciliation = reconcileCheckoutQuantities(checkoutCartLines(current), intended)
    const diningModeUuid = diningMode.selected?.uuid
    let mergedOrderUuid: string | null = null

    // Additions are a separate round. This preserves the original order history and
    // gives the merge operation a complete, server-priced settlement aggregate.
    if (reconciliation.additions.length > 0) {
      const additionCart: OrderCart = { version: current.version, items: reconciliation.additions, notes, ...(diningModeUuid ? { diningModeUuid } : {}) }
      const additionQuote = await api.orderQuote(table.counter.uuid, additionCart)
      const existingUuids = new Set(effectiveDiningOrders(current).map((order) => order.uuid))
      const created = await api.counterCommand(table.counter.uuid, 'create', { ...additionCart, quoteToken: additionQuote.quoteToken, commandUuid: crypto.randomUUID() })
      // Order projection is asynchronous. Wait until the new round is visible before
      // deciding whether a merge is needed, otherwise a later reduction could target
      // the pre-addition order while the new round is still pending projection.
      const projectedAddition = await waitForCheckoutOrder(api, table.counter.uuid, (order) => created.orderUuid ? order.uuid === created.orderUuid : !existingUuids.has(order.uuid))
      current = projectedAddition.table
    }

    const openOrders = effectiveDiningOrders(current)
    if (openOrders.length > 1) {
      const previewMerge = async () => {
        current = await refresh()
        return api.previewCounterMerge(table.counter.uuid, current.version)
      }
      const mergeQuote = reconciliation.additions.length > 0
        ? await retryCheckoutConflict(previewMerge)
        : await previewMerge()
      const merged = await api.confirmCounterMerge(table.counter.uuid, { version: current.version, commandUuid: crypto.randomUUID(), quoteToken: mergeQuote.quoteToken })
      mergedOrderUuid = merged.orderUuid ?? null
      current = await refresh()
    }

    // A reduction cannot be represented by an additive round. After consolidation it
    // becomes one replace command against the merged order (or the only source order).
    if (reconciliation.reductions.length > 0) {
      let target: SessionOrder | undefined
      if (mergedOrderUuid) {
        const replaceable = await waitForCheckoutReplacement(api, table.counter.uuid, mergedOrderUuid)
        current = replaceable.table
        target = replaceable.order
      } else {
        target = effectiveDiningOrders(current).find((entry) => entry.canReplace)
      }
      if (!target) throw new Error('No replaceable checkout order')
      const replacementCart: OrderCart = {
        version: current.version, items: intended, notes,
        ...(diningModeUuid ? { diningModeUuid } : {}), replaceOrderUuid: target.uuid,
      }
      const replacementQuote = await api.orderQuote(table.counter.uuid, replacementCart)
      await api.counterCommand(table.counter.uuid, 'replace', {
        ...replacementCart, quoteToken: replacementQuote.quoteToken, commandUuid: crypto.randomUUID(),
        reason: locale === 'zh-CN' ? '结账时调整菜品数量' : 'Adjust items during checkout',
      })
    }
  }
  const choiceRules = diningMode.selected?.rules.filter((rule): rule is ChoiceDiningRule => rule.type === 'choice_group') ?? []
  const invalidRules = new Set(issues.map((issue) => issue.ruleUuid))
  const quoteDisabled = !orderLines.length || Boolean(issues.length) || ruleCatalog.loading || Boolean(ruleCatalog.error) || diningMode.configured && !diningMode.selected
  const primaryLabel: MessageKey = quote ? (isCheckout ? 'checkoutConfirmCart' : 'diningConfirm') : isAdd ? 'diningConfirm' : isCheckout ? 'checkoutReviewCart' : 'diningQuote'
  return <LiveSheet open title={t(title)} className="dining-editor" toolbar={!quote && categories.length >= 2 && <MenuCategoryFilter categories={categories} selected={categoryFilter} onChange={setCategoryFilter} />} footer={<div className="dining-confirm-bar">{quote ? <Button disabled={attempted || submitting} onClick={() => setQuote(null)}>{t('diningEditCart')}</Button> : !order ? <Button disabled={submitting} onClick={() => void clearCurrentCart()}>{t('clearCart')}</Button> : null}<Button block type="submit" form={formId} color="primary" loading={submitting} disabled={quote ? submitting : quoteDisabled}>{t(primaryLabel)}</Button></div>} onClose={onClose}>
    <Notice subtle>{t(hint)}</Notice>
    {isCheckout && diningMode.selected && diningMode.selected.checkoutRules.length > 0 && <DiningCheckoutRules table={table} mode={diningMode.selected} lines={orderLines} participantCount={participantCount} />}
    {isCheckout && !diningMode.selected && <Notice subtle>{t('checkoutNoModeHint')}</Notice>}
    {isCheckout && <CheckoutOrderReview lines={orderLines} currency={table.currency} />}
    {isCheckout && <CheckoutChangePlan table={table} lines={orderLines} />}
    <DiningModePicker state={diningMode} timeZone={access.store.timezone} participantCount={participantCount} onParticipantCount={setParticipantCount} showGuests={!isAdd} forceGuests={isCheckout && Boolean(diningMode.selected?.checkoutRules.some((rule) => rule.type === 'confirm_participants'))} onSelect={switchMode} />
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
    : <MutationForm id={formId} actions={false} submit={() => isCheckout ? submitCheckout() : (setAttempted(true), api.counterCommand(table.counter.uuid, order ? 'replace' : 'create', { ...payload, commandUuid }))} onDone={done} onBusyChange={setSubmitting}>
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
    () => resolveBoundDiningMode(table.session?.diningMode, table.session?.diningModeUuid, access.store.settings?.diningModes),
    [access.store.settings?.diningModes, table.session?.diningMode, table.session?.diningModeUuid],
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
