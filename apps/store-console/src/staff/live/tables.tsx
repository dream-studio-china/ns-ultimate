import { useCallback, useId, useState } from 'react'
import { Button } from 'antd-mobile'
import { ChevronDown, Clock3, LayoutGrid, LockKeyhole, Minus, Pencil, Plus, ReceiptText, UsersRound } from 'lucide-react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, orderActions, type OrderAction, type StoreAccess, StoreApi, type LiveCounter, type LiveOrder } from '../adapters/live'
import { AccessNotice, EditorAdvanced, EditorPreview, EditorSection, EditorSheet, EditorToggle, ListFeedback, Notice, PageControls, PageHeading, PageScope, RecordIdentity, SearchField, StatusPill, useCommand, usePage, LiveSheet as Sheet } from './ui'
import { LiveDining } from './dining'

function CounterForm({ api, counter, done, onClose }: { api: StoreApi; counter: LiveCounter; done: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const [name, setName] = useState(counter.name)
  const [code, setCode] = useState(counter.code)
  const [capacity, setCapacity] = useState(counter.capacity?.toString() ?? '')
  const [sort, setSort] = useState(counter.sort)
  const [enabled, setEnabled] = useState(counter.enabled)
  const capacityId = useId()
  return <EditorSheet title={t('tableDetail')} description="tableFormHint" onClose={onClose} onDone={done} submit={() => api.save('counters', counter.uuid, { name: name.trim(), code: code.trim(), capacity: capacity === '' ? null : Number(capacity), sort, enabled })} preview={<div className="editor-preview counter-preview"><div className="counter-preview-art"><TableGlyph /></div><div><p>{t('editorTablePreview')}</p><h3>{name.trim() || t('editorNewTable')}</h3><small>{code || '—'}<span>·</span>{capacity ? `${capacity} ${t('editorSeatUnit')}` : t('seatsUnknown')}</small></div><StatusPill muted={!enabled}>{t(enabled ? 'tableEnabled' : 'tableDisabled')}</StatusPill></div>}>
    <EditorSection title="editorBasics" index="01"><div className="editor-field-grid"><label className="field">{t('tableName')}<input required maxLength={120} value={name} placeholder={t('tableName')} onChange={(event) => setName(event.target.value)} /></label><label className="field">{t('tableCode')}<input required maxLength={50} value={code} placeholder="A01" onChange={(event) => setCode(event.target.value)} /></label></div></EditorSection>
    <EditorSection title="editorSeats" index="02"><div className="editor-capacity"><div><label htmlFor={capacityId}>{t('capacity')}</label><p>{t('seatsHint')}</p></div><div className="capacity-stepper"><button type="button" aria-label={t('decreaseSeats')} disabled={!capacity || Number(capacity) <= 1} onClick={() => setCapacity(String(Number(capacity) - 1))}><Minus size={16} /></button><input id={capacityId} type="number" min="1" max="100" step="1" value={capacity} onChange={(event) => setCapacity(event.target.value)} /><button type="button" aria-label={t('increaseSeats')} disabled={Number(capacity) >= 100} onClick={() => setCapacity(String(Number(capacity) + 1))}><Plus size={16} /></button></div></div><EditorToggle label="enabledField" hint="tableEnabledHint" checked={enabled} onChange={setEnabled} /></EditorSection>
    <EditorAdvanced><label className="field">{t('sortOrder')}<input required type="number" step="1" value={sort} onChange={(event) => setSort(Number(event.target.value))} /></label></EditorAdvanced>
  </EditorSheet>
}
function TableGlyph() {
  return <svg viewBox="0 0 120 75" fill="none" aria-hidden="true"><rect x="29" y="19" width="62" height="37" rx="11" fill="currentColor" fillOpacity=".08" stroke="currentColor" strokeWidth="1.4" /><rect x="42" y="9" width="14" height="6" rx="3" fill="currentColor" fillOpacity=".3" /><rect x="64" y="9" width="14" height="6" rx="3" fill="currentColor" fillOpacity=".3" /><rect x="42" y="60" width="14" height="6" rx="3" fill="currentColor" fillOpacity=".3" /><rect x="64" y="60" width="14" height="6" rx="3" fill="currentColor" fillOpacity=".3" /><path d="M43 37h34" stroke="currentColor" strokeOpacity=".2" strokeLinecap="round" /><circle cx="60" cy="37" r="8" stroke="currentColor" strokeOpacity=".3" /></svg>
}
export function LiveTables({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const [configuration, setConfiguration] = useState(!can(access, 'counter_session', 'read'))
  return <>{can(access, 'counter_session', 'read') && can(access, 'counter', 'read') && <div className="workspace-filters">{([false, true] as const).map((value) => <button key={String(value)} aria-pressed={configuration === value} className={configuration === value ? 'active' : ''} onClick={() => setConfiguration(value)}>{t(value ? 'tableConfiguration' : 'diningBoard')}</button>)}</div>}{configuration ? <CounterConfiguration api={api} access={access} /> : <LiveDining api={api} access={access} />}</>
}
function CounterConfiguration({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState<'all' | 'enabled' | 'disabled'>('all')
  const load = useCallback((page: number) => api.counters(page, query, filter), [api, query, filter])
  const list = usePage(load, can(access, 'counter', 'read'))
  const [editing, setEditing] = useState<LiveCounter | null>(null)
  const visible = list.items
  return <><PageHeading eyebrow="tablesEyebrow" title="tables" subtitle="tablesLiveSubtitle"><span className="configuration-badge"><LayoutGrid size={13} />{t('configurationOnly')}</span></PageHeading>
    {!can(access, 'counter', 'read') ? <AccessNotice /> : <>
      <PageScope count={list.items.length} total={list.total} /><div className="workspace-toolbar"><SearchField server value={query} onChange={(value) => { setQuery(value); list.setPage(1) }} /></div>
      <div className="workspace-filters">{(['all', 'enabled', 'disabled'] as const).map((value) => <button key={value} className={filter === value ? 'active' : ''} aria-pressed={filter === value} onClick={() => { setFilter(value); list.setPage(1) }}>{t(value === 'all' ? 'allStatuses' : value === 'enabled' ? 'tableEnabled' : 'tableDisabled')}</button>)}</div>
      <ListFeedback state={{ ...list, items: visible }} empty={query.trim() || filter !== 'all' ? 'noSearchResults' : 'firstTable'} hint={query.trim() || filter !== 'all' ? 'adjustSearch' : 'existingTablesHint'} icon={LayoutGrid} />
      <div className="workspace-table-grid">{visible.map((counter) => <article className={`workspace-table-card ${counter.enabled ? '' : 'is-disabled'}`} key={counter.uuid}>
        <div className="table-config-top"><strong title={counter.code}>{counter.code}</strong><StatusPill muted={!counter.enabled}>{t(counter.enabled ? 'tableEnabled' : 'tableDisabled')}</StatusPill></div><TableGlyph /><h2>{counter.name}</h2><p className="table-config-seats"><UsersRound size={14} />{counter.capacity ?? '—'} {t('capacity')}</p>
        <div className="table-config-actions"><Button disabled={!can(access, 'counter', 'update')} onClick={() => setEditing(counter)}><Pencil size={14} />{t('edit')}</Button></div><RecordIdentity id={counter.uuid}><dt>{t('tableCode')}</dt><dd>{counter.code}</dd></RecordIdentity>
      </article>)}</div><PageControls state={list} /><Notice subtle icon={LayoutGrid}>{t('basicTableHint')}</Notice>
    </>}
    {editing && <CounterForm api={api} counter={editing} onClose={() => setEditing(null)} done={() => { setEditing(null); list.reload() }} />}
  </>
}

const statusLabels: Record<string, MessageKey> = { pending_validation: 'statePendingValidation', awaiting_inventory: 'stateAwaitingInventory', accepted: 'stateAccepted', rejected: 'stateRejected', fulfillment_pending: 'stateFulfillmentPending', fulfilling: 'stateFulfilling', fulfilled: 'stateFulfilled', verified: 'stateVerified', cancelled: 'stateCancelled' }
export function LiveOrders({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t, locale } = useI18n()
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('all')
  const load = useCallback((page: number) => api.orders(page, query, status), [api, query, status])
  const list = usePage(load, can(access, 'order', 'read'))
  const [selected, setSelected] = useState<string | null>(null)
  const visible = list.items
  const writable = orderActions.some((action) => can(access, 'order', action))
  return <><PageHeading eyebrow="ordersEyebrow" title={writable ? 'storeOrders' : 'readOnlyOrders'} subtitle="ordersLiveSubtitle"><span className="configuration-badge"><LockKeyhole size={13} />{t(writable ? 'orderOperations' : 'readOnly')}</span></PageHeading>
    {!can(access, 'order', 'read') ? <AccessNotice /> : <>
      <PageScope count={list.items.length} total={list.total} /><SearchField server value={query} onChange={(value) => { setQuery(value); list.setPage(1) }} /><label className="field order-status-filter">{t('orderStatus')}<select value={status} onChange={(event) => { setStatus(event.target.value); list.setPage(1) }}><option value="all">{t('allStatuses')}</option>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</select></label><ListFeedback state={{ ...list, items: visible }} empty={query.trim() || status !== 'all' ? 'noSearchResults' : 'firstOrder'} hint={query.trim() || status !== 'all' ? 'adjustSearch' : 'firstOrderHint'} icon={ReceiptText} />
      <div className="workspace-order-list">{visible.map((order) => <article key={order.uuid}><div className="order-card-heading"><span><ReceiptText size={19} />{t('orderReference')}</span><StatusPill muted={['cancelled', 'rejected'].includes(order.operationalStatus)}>{statusLabels[order.operationalStatus] ? t(statusLabels[order.operationalStatus]) : order.operationalStatus}</StatusPill></div><div className="order-card-body"><div><h2 title={order.tradeOrderUuid}>#{order.tradeOrderUuid.slice(0, 8).toUpperCase()}</h2><p><Clock3 size={12} /><time dateTime={order.createdAt}>{formatDate(order.createdAt, locale)}</time></p></div><strong>{formatOrderAmount(order, locale)}</strong></div><div className="order-card-footer"><span>{t('operationalStatusHint')}</span><Button onClick={() => setSelected(order.uuid)}>{t('view')}<ChevronDown size={14} /></Button></div></article>)}</div>
      <PageControls state={list} /><Notice subtle icon={LockKeyhole}>{t('operationalStatusHint')}</Notice>
    </>}
    {selected && <Sheet open title={t('orderDetails')} onClose={() => setSelected(null)}><OrderDetail api={api} access={access} id={selected} changed={list.reload} /></Sheet>}
  </>
}
function formatOrderAmount(order: LiveOrder, locale: string) { return new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(order.totalAmount / 100) }
function formatDate(value: string, locale: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(date)
}
function snapshotItems(snapshot: unknown): { name?: string; quantity?: number }[] {
  if (!snapshot || typeof snapshot !== 'object' || !('items' in snapshot) || !Array.isArray(snapshot.items)) return []
  return snapshot.items.flatMap((item: unknown) => {
    if (!item || typeof item !== 'object') return []
    const value = item as Record<string, unknown>
    const product = value.productSnapshot && typeof value.productSnapshot === 'object' ? value.productSnapshot as Record<string, unknown> : null
    const name = typeof value.productName === 'string' ? value.productName : typeof product?.name === 'string' ? product.name : undefined
    const quantity = typeof value.quantity === 'number' && Number.isInteger(value.quantity) && value.quantity > 0 ? value.quantity : undefined
    return name || quantity ? [{ name, quantity }] : []
  })
}
const actionLabels: Record<OrderAction, MessageKey> = { accept: 'acceptOrder', reject: 'rejectOrder', fulfill: 'fulfillOrder', verify: 'verifyOrder' }
function OrderDetail({ api, access, id, changed }: { api: StoreApi; access: StoreAccess; id: string; changed: () => void }) {
  const { t, locale } = useI18n()
  const load = useCallback(async () => ({ items: [await api.order(id)], hasNext: false }), [api, id])
  const detail = usePage(load)
  const [operation, setOperation] = useState<{ order: LiveOrder; action: OrderAction } | null>(null)
  return <><ListFeedback state={detail} empty="emptyLive" hint="firstOrderHint" icon={ReceiptText} />{detail.items.map((order) => <div className="workspace-order-detail" key={order.uuid}>
    <div className="order-detail-hero"><span><ReceiptText size={28} strokeWidth={1.3} /></span><p>{t('amount')}</p><strong>{formatOrderAmount(order, locale)}</strong><StatusPill>{statusLabels[order.operationalStatus] ? t(statusLabels[order.operationalStatus]) : order.operationalStatus}</StatusPill><small>{order.operationalStatus}</small></div>
    <dl className="order-detail-meta"><dt>{t('orderNo')}</dt><dd>{order.tradeOrderUuid}</dd><dt>{t('createdAt')}</dt><dd>{formatDate(order.createdAt, locale)}</dd></dl>
    <h3>{t('orderItems')}</h3><div className="order-snapshot-items">{snapshotItems(order.orderSnapshot).length ? snapshotItems(order.orderSnapshot).map((item, index) => <div key={index}><span className="snapshot-item-index">{String(index + 1).padStart(2, '0')}</span><span>{item.name ?? t('orderItem')}</span>{item.quantity !== undefined && <strong>×{item.quantity}</strong>}</div>) : <p className="helper">{t('noSnapshotItems')}</p>}</div>
    {order.rejectionReason && <p className="helper">{t('rejectionReason')} · {order.rejectionReason}</p>}
    <div className="order-operation-actions">{order.allowedActions.filter((action) => can(access, 'order', action)).map((action) => <Button key={action} color={action === 'reject' ? 'default' : 'primary'} onClick={() => setOperation({ order, action })}>{t(actionLabels[action])}</Button>)}</div>
    <Notice icon={LockKeyhole} subtle>{t('operationalStatusHint')}</Notice><details className="workspace-advanced"><summary>{t('rawSnapshot')}<ChevronDown size={14} /></summary><pre>{JSON.stringify(order.orderSnapshot ?? null, null, 2)}</pre></details>
  </div>)}<PageControls state={detail} detail />{operation && <OrderOperation api={api} {...operation} onClose={() => setOperation(null)} done={() => { setOperation(null); detail.reload(); changed() }} />}</>
}

function OrderOperation({ api, order, action, onClose, done }: { api: StoreApi; order: LiveOrder; action: OrderAction; onClose: () => void; done: () => void }) {
  const { t } = useI18n()
  const [reason, setReason] = useState('')
  const command = useCommand({ uuid: order.uuid, version: order.version, action, reason: reason.trim() })
  return <EditorSheet title={t(actionLabels[action])} description="orderCommandHint" onClose={onClose} onDone={done} submit={() => api.orderCommand(order, action, reason, command)} preview={<EditorPreview icon={ReceiptText} label="orderReference" name={`#${order.tradeOrderUuid.slice(0, 8).toUpperCase()}`} detail={t(statusLabels[order.operationalStatus] ?? 'orderStatus')} />}><Notice subtle>{t('orderCommandHint')}</Notice>{action === 'reject' && <label className="field">{t('rejectionReason')}<textarea required maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} /></label>}</EditorSheet>
}
