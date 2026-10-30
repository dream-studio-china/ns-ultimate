import { useCallback, useState } from 'react'
import { Button } from 'antd-mobile'
import { ArrowUpRight, Clock3, LayoutGrid, Plus, ReceiptText, UsersRound } from 'lucide-react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, StoreApi, type StoreAccess, type CounterSessionView, type SessionOrder, type OrderQuote, type OrderCart, type CounterCommand } from '../adapters/live'
import { EditorPreview, EditorSheet, ListFeedback, LiveSheet, MutationForm, Notice, PageControls, PageHeading, PageScope, SearchField, StatusPill, useCommand, usePage } from './ui'
import './dining.css'

const labels: Record<CounterCommand, MessageKey> = { update: 'updateDining', end: 'endDining', create: 'placeDining', replace: 'replaceDining' }
const tradeLabels: Record<string, MessageKey> = { draft: 'diningDraft', pending: 'diningPending', confirmed: 'diningConfirmed', paid: 'diningPaid', fulfilled: 'stateFulfilled', completed: 'diningCompleted', cancelled: 'stateCancelled', refunded: 'diningRefunded', merged: 'diningMerged' }
const operationLabels: Record<string, MessageKey> = { pending_validation: 'statePendingValidation', awaiting_inventory: 'stateAwaitingInventory', accepted: 'stateAccepted', rejected: 'stateRejected', fulfillment_pending: 'stateFulfillmentPending', fulfilling: 'stateFulfilling', fulfilled: 'stateFulfilled', verified: 'stateVerified', cancelled: 'stateCancelled' }

/** Session commands and order commands use different resources, so each action is checked separately. */
function permitted(access: StoreAccess, command: CounterCommand): boolean {
  if (command === 'update' || command === 'end') return can(access, 'counter_session', command)
  return can(access, 'counter_order', command)
}

export function LiveDining({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t, locale } = useI18n()
  const [query, setQuery] = useState('')
  const load = useCallback((page: number) => api.counterSessions(page, query), [api, query])
  const list = usePage(load)
  const [selected, setSelected] = useState<string | null>(null)
  const amount = (value: number, currency: string) => new Intl.NumberFormat(locale, { style: 'currency', currency }).format(value / 100)
  return <><PageHeading eyebrow="tablesEyebrow" title="diningBoard" subtitle="diningSubtitle" /><PageScope count={list.items.length} total={list.total} />
    <SearchField server value={query} onChange={(value) => { setQuery(value); list.setPage(1) }} /><ListFeedback state={list} empty="firstTable" hint={query.trim() ? 'adjustSearch' : 'existingTablesHint'} icon={LayoutGrid} />
    <div className="dining-board">{list.items.map((table) => <article className={`dining-card ${table.session ? 'is-occupied' : ''} ${table.counter.enabled ? '' : 'is-disabled'}`} key={table.counter.uuid}>
      <div className="dining-card-heading"><span className="dining-card-code">{table.counter.code}</span><StatusPill muted={!table.session}>{t(table.session ? 'dining' : table.counter.enabled ? 'idle' : 'tableDisabled')}</StatusPill></div>
      <h2>{table.counter.name}</h2><div className="dining-card-meta"><span><UsersRound size={14} />{table.session?.participantCount ?? '—'} {t('guestUnit')}</span>{table.session && <span><Clock3 size={14} /><time dateTime={table.session.startedAt}>{formatDiningDate(table.session.startedAt, locale)}</time></span>}</div>
      {table.session ? <>{table.ordersVisible ? <><div className="dining-card-total"><span>{t('diningOpenAmount')}</span><strong>{amount(table.openAmount, table.currency)}</strong></div><p className="dining-card-caption">{table.orders.length} {t('diningBatches')}{table.paymentHold && ` · ${t('diningPaymentHold')}`}</p></> : <p className="dining-card-caption">{t('sessionOrdersRestricted')}</p>}</> : <p className="dining-card-idle">{t(table.counter.enabled ? 'readyToOpen' : 'tableDisabled')}</p>}
      <Button block className="dining-card-action" onClick={() => setSelected(table.counter.uuid)}>{t('viewDining')}<ArrowUpRight size={15} /></Button>
    </article>)}</div><PageControls state={list} /><Notice subtle>{t('diningScopeHint')}</Notice>
    {selected && <LiveSheet open title={t('viewDining')} onClose={() => setSelected(null)}><DiningDetail api={api} access={access} id={selected} changed={list.reload} /></LiveSheet>}
  </>
}

function formatDiningDate(value: string, locale: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(date)
}

function DiningDetail({ api, access, id, changed }: { api: StoreApi; access: StoreAccess; id: string; changed: () => void }) {
  const { t, locale } = useI18n()
  const load = useCallback(async () => ({ items: [await api.counterSession(id)], hasNext: false }), [api, id])
  const detail = usePage(load)
  const [operation, setOperation] = useState<{ table: CounterSessionView; command: CounterCommand; order?: SessionOrder } | null>(null)
  const done = () => { setOperation(null); detail.reload(); changed() }
  return <><ListFeedback state={detail} empty="emptyLive" hint="diningScopeHint" icon={LayoutGrid} />{detail.items.map((table) => <section className="dining-detail" key={table.counter.uuid}>
    <div className="dining-detail-hero"><div className="dining-detail-heading"><div><span>{table.counter.code}</span><h3>{table.counter.name}</h3></div><StatusPill muted={!table.session}>{t(table.session ? 'dining' : table.counter.enabled ? 'idle' : 'tableDisabled')}</StatusPill></div><div className="dining-detail-summary"><span><UsersRound size={15} />{table.session?.participantCount ?? '—'} {t('guestUnit')}</span>{table.session && <span><Clock3 size={15} /><time dateTime={table.session.startedAt}>{formatDiningDate(table.session.startedAt, locale)}</time></span>}</div>{table.session && table.ordersVisible && <div className="dining-detail-amount"><span>{t('diningOpenAmount')}</span><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: table.currency }).format(table.openAmount / 100)}</strong></div>}</div>
    {table.session?.notes && <p className="dining-notes dining-party-notes"><strong>{t('diningPartyNotes')}</strong>{table.session.notes}</p>}
    <div className="order-operation-actions">{(table.session ? ['update', 'create', 'end'] as const : []).filter((command) => permitted(access, command)).map((command) => <Button key={command} color={command === 'create' ? 'primary' : 'default'} disabled={command === 'create' && (!table.counter.enabled || (table.ordersVisible && table.paymentHold)) || command === 'end' && !table.canEnd} onClick={() => setOperation({ table, command })}>{t(labels[command])}</Button>)}</div>
    {table.ordersVisible && table.paymentHold && <Notice>{t('diningPaymentHold')}</Notice>}{table.session && !table.canEnd && <p className="helper">{t('diningEndBlocked')}</p>}
    {!table.ordersVisible ? <Notice subtle>{t('sessionOrdersRestricted')}</Notice> : <>
      <div className="dining-section-heading"><h3>{t('diningBatches')}</h3><span>{table.orders.length}</span></div>{!table.orders.length && <p className="dining-empty-orders">{t('noItems')}</p>}
      {[...table.orders].sort((a, b) => a.createdAt.localeCompare(b.createdAt) || a.uuid.localeCompare(b.uuid)).map((order, index) => <article className="dining-round" key={order.uuid}>
        <header><strong>{String(index + 1).padStart(2, '0')} · #{order.uuid.slice(0, 8)}</strong><StatusPill muted={['cancelled', 'refunded', 'merged'].includes(order.status)}>{tradeLabels[order.status] ? t(tradeLabels[order.status]) : order.status}</StatusPill></header>
        <p><time dateTime={order.createdAt}>{formatDiningDate(order.createdAt, locale)}</time></p>
        <p>{t('diningOperationalState')} · {order.operationalStatus ? t(operationLabels[order.operationalStatus] ?? 'orderStatus') : t('diningProjecting')}</p>
        <p>{t('diningPaymentState')} · {order.paymentStatus ?? t('diningNoPayment')}{order.hasInvoice && ` · ${t('diningHasInvoice')}`}</p>
        <DiningLines items={order.items} currency={order.currency} /><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(order.totalAmount / 100)}</strong>
        {order.notes && <p className="dining-notes">{order.notes}</p>}
        {order.canReplace && permitted(access, 'replace') && <Button disabled={!table.counter.enabled || table.paymentHold} onClick={() => setOperation({ table, order, command: 'replace' })}>{t('replaceDining')}</Button>}
      </article>)}
    </>}
    <Notice subtle>{t('diningScopeHint')}</Notice>
  </section>)}<PageControls state={detail} detail />{operation && (operation.command === 'create' || operation.command === 'replace' ? <DiningEditor api={api} currency={operation.table.currency} {...operation} onClose={() => setOperation(null)} done={done} /> : <DiningOperation api={api} {...operation} onClose={() => setOperation(null)} done={done} />)}</>
}

function DiningLines({ items, currency }: { items: SessionOrder['items']; currency: string }) {
  const { locale } = useI18n()
  return <ul className="dining-lines">{items.map((item, index) => <li key={`${item.specificationUuid}:${index}`}><span>{item.productName}<small>{item.specificationName}</small></span><b>×{item.quantity}</b><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency }).format(item.amount / 100)}</strong></li>)}</ul>
}

function DiningOperation({ api, table, command, onClose, done }: { api: StoreApi; table: CounterSessionView; command: CounterCommand; onClose: () => void; done: () => void }) {
  const { t } = useI18n()
  const [participantCount, setParticipantCount] = useState(table.session?.participantCount ?? 1)
  const [notes, setNotes] = useState(table.session?.notes ?? '')
  const payload = { version: table.version, ...(command === 'update' ? { participantCount, notes } : {}) }
  const commandUuid = useCommand({ command, counter: table.counter.uuid, ...payload })
  return <EditorSheet title={t(labels[command])} description="diningCommandHint" preview={<EditorPreview icon={UsersRound} label="diningBoard" name={table.counter.name} />} onClose={onClose} onDone={done} submit={() => api.counterCommand(table.counter.uuid, command, { ...payload, commandUuid })}>
    {command === 'update' && <><label className="field">{t('people')}<input required type="number" min={1} max={100} step={1} value={participantCount} onChange={(event) => setParticipantCount(Number(event.target.value))} /></label><label className="field">{t('diningPartyNotes')}<textarea maxLength={1000} value={notes} onChange={(event) => setNotes(event.target.value)} /></label></>}
    {command === 'end' && <Notice>{t('diningEndHint')}</Notice>}
  </EditorSheet>
}

function DiningEditor({ api, table, order, currency, onClose, done }: { api: StoreApi; table: CounterSessionView; order?: SessionOrder; currency: string; onClose: () => void; done: () => void }) {
  const { t, locale } = useI18n()
  const [query, setQuery] = useState('')
  const load = useCallback((page: number) => api.orderableCatalog(page, query), [api, query])
  const menu = usePage(load)
  const [items, setItems] = useState(() => order?.items.flatMap(({ specificationUuid, quantity, productName, specificationName }) => specificationUuid === null ? [] : [{ specificationUuid, quantity, productName, specificationName }]) ?? [])
  const [notes, setNotes] = useState(order?.notes ?? '')
  const [reason, setReason] = useState('')
  const [quote, setQuote] = useState<OrderQuote | null>(null)
  const [attempted, setAttempted] = useState(false)
  const cart: OrderCart = { version: table.version, items: items.map(({ specificationUuid, quantity }) => ({ specificationUuid, quantity })), notes, ...(order ? { replaceOrderUuid: order.uuid } : {}) }
  const payload = { ...cart, quoteToken: quote?.quoteToken, ...(order ? { reason: reason.trim() } : {}) }
  const commandUuid = useCommand(payload)
  return <LiveSheet open title={t(order ? 'replaceDining' : 'placeDining')} className="dining-editor" onClose={onClose}>
    <Notice subtle>{t(order ? 'diningReplaceHint' : 'diningAddHint')}</Notice>
    {!quote ? <MutationForm actions={false} submit={async () => { setQuote(await api.orderQuote(table.counter.uuid, cart)) }} onDone={() => {}}><SearchField server value={query} onChange={(value) => { setQuery(value); menu.setPage(1) }} /><ListFeedback state={menu} empty="noProducts" hint="adjustSearch" icon={ReceiptText} />
      <ul className="dining-menu">{menu.items.map((item) => <li key={item.uuid}><span><strong>{item.productName}</strong><small>{item.name} · {new Intl.NumberFormat(locale, { style: 'currency', currency }).format(item.price / 100)}</small></span><Button aria-label={`${t('addItems')} ${item.productName} ${item.name}`} disabled={!item.available || items.length >= 30 && !items.some((line) => line.specificationUuid === item.uuid) || items.some((line) => line.specificationUuid === item.uuid && line.quantity >= 99)} onClick={() => setItems((current) => current.some((line) => line.specificationUuid === item.uuid) ? current.map((line) => line.specificationUuid === item.uuid ? { ...line, quantity: line.quantity + 1 } : line) : [...current, { specificationUuid: item.uuid, quantity: 1, productName: item.productName, specificationName: item.name }])}><Plus size={16} /></Button></li>)}</ul><PageControls state={menu} />
        <h3>{t('diningCart')} · {items.length}/30</h3>{items.map((item) => <div className="dining-cart-row" key={item.specificationUuid}><label className="field">{item.productName} · {item.specificationName}<input aria-label={`${t('quantity')} ${item.productName} ${item.specificationName}`} required type="number" min={1} max={99} step={1} value={item.quantity} onChange={(event) => setItems((current) => current.map((line) => line.specificationUuid === item.specificationUuid ? { ...line, quantity: Number(event.target.value) } : line))} /></label><Button onClick={() => setItems((current) => current.filter((line) => line.specificationUuid !== item.specificationUuid))}>{t('remove')}</Button></div>)}
        <label className="field">{t('orderNote')}<textarea maxLength={1000} value={notes} onChange={(event) => setNotes(event.target.value)} /></label>
        {order && <label className="field">{t('diningReason')}<textarea required maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} /></label>}
        <Button block color="primary" type="submit" disabled={!items.length}>{t('diningQuote')}</Button>
      </MutationForm>
    : <MutationForm actions={false} submit={() => { setAttempted(true); return api.counterCommand(table.counter.uuid, order ? 'replace' : 'create', { ...payload, commandUuid }) }} onDone={done}>
      <h3>{t('diningQuoteReview')}</h3><DiningLines items={quote.items} currency={quote.currency} /><div className="dining-quote-total"><span>{t('amount')}</span><strong>{new Intl.NumberFormat(locale, { style: 'currency', currency: quote.currency }).format(quote.totalAmount / 100)}</strong></div>{notes && <p className="dining-notes">{t('orderNote')} · {notes}</p>}<Notice subtle>{t('diningQuoteHint')}</Notice>{attempted && <Notice>{t('diningRetryHint')}</Notice>}<Button block type="submit" color="primary">{t('diningConfirm')}</Button><Button block disabled={attempted} onClick={() => setQuote(null)}>{t('diningEditCart')}</Button>
    </MutationForm>}
  </LiveSheet>
}
