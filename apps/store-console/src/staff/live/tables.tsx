import { useCallback, useState } from 'react'
import { Button } from 'antd-mobile'
import { ChevronDown, Clock3, LayoutGrid, LockKeyhole, Pencil, Plus, ReceiptText, Trash2, UsersRound } from 'lucide-react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, type StoreAccess, StoreApi, type LiveCounter, type LiveOrder } from '../adapters/live'
import { AccessNotice, ListFeedback, MutationForm, Notice, PageControls, PageHeading, PageScope, RecordIdentity, SearchField, StatusPill, useDelete, usePage, LiveSheet as Sheet } from './ui'

function CounterForm({ api, counter, done }: { api: StoreApi; counter: LiveCounter | null; done: () => void }) {
  const { t } = useI18n()
  const [name, setName] = useState(counter?.name ?? '')
  const [code, setCode] = useState(counter?.code ?? '')
  const [capacity, setCapacity] = useState(counter?.capacity?.toString() ?? '')
  const [sort, setSort] = useState(counter?.sort ?? 0)
  const [enabled, setEnabled] = useState(counter?.enabled ?? true)
  return <MutationForm onDone={done} submit={() => api.save('counters', counter?.uuid ?? null, { name: name.trim(), code: code.trim(), capacity: capacity === '' ? null : Number(capacity), sort, enabled })}>
    <Notice icon={LayoutGrid} subtle>{t('tableFormHint')}</Notice>
    <label className="field">{t('tableName')}<input required maxLength={120} value={name} placeholder={t('tableName')} onChange={(event) => setName(event.target.value)} /></label>
    <div className="workspace-form-grid"><label className="field">{t('tableCode')}<input required maxLength={50} value={code} placeholder="A01" onChange={(event) => setCode(event.target.value)} /></label><label className="field">{t('capacity')}<input type="number" min="1" max="100" step="1" value={capacity} onChange={(event) => setCapacity(event.target.value)} /></label></div>
    <label className="field">{t('sortOrder')}<input required type="number" step="1" value={sort} onChange={(event) => setSort(Number(event.target.value))} /></label>
    <label className="workspace-toggle-row"><span>{t('enabledField')}</span><input type="checkbox" checked={enabled} onChange={(event) => setEnabled(event.target.checked)} /></label>
  </MutationForm>
}
function TableGlyph() {
  return <svg viewBox="0 0 120 75" fill="none" aria-hidden="true"><rect x="29" y="19" width="62" height="37" rx="11" fill="currentColor" fillOpacity=".08" stroke="currentColor" strokeWidth="1.4" /><rect x="42" y="9" width="14" height="6" rx="3" fill="currentColor" fillOpacity=".3" /><rect x="64" y="9" width="14" height="6" rx="3" fill="currentColor" fillOpacity=".3" /><rect x="42" y="60" width="14" height="6" rx="3" fill="currentColor" fillOpacity=".3" /><rect x="64" y="60" width="14" height="6" rx="3" fill="currentColor" fillOpacity=".3" /><path d="M43 37h34" stroke="currentColor" strokeOpacity=".2" strokeLinecap="round" /><circle cx="60" cy="37" r="8" stroke="currentColor" strokeOpacity=".3" /></svg>
}
export function LiveTables({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const load = useCallback((page: number) => api.counters(page), [api])
  const list = usePage(load, can(access, 'counter', 'read'))
  const deletion = useDelete(list.reload)
  const [editing, setEditing] = useState<LiveCounter | null | undefined>()
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState<'all' | 'enabled' | 'disabled'>('all')
  const visible = list.items.filter((counter) => `${counter.name} ${counter.code}`.toLowerCase().includes(query.trim().toLowerCase()) && (filter === 'all' || counter.enabled === (filter === 'enabled')))
  return <><PageHeading eyebrow="tablesEyebrow" title="tables" subtitle="tablesLiveSubtitle"><span className="configuration-badge"><LayoutGrid size={13} />{t('configurationOnly')}</span></PageHeading>
    {!can(access, 'counter', 'read') ? <AccessNotice /> : <>
      <PageScope count={list.items.length} /><div className="workspace-toolbar"><SearchField value={query} onChange={setQuery} /><Button className="workspace-primary" color="primary" disabled={!can(access, 'counter', 'create') || deletion.busy} onClick={() => setEditing(null)}><Plus size={17} />{t('addTable')}</Button></div>
      <div className="workspace-filters">{(['all', 'enabled', 'disabled'] as const).map((value) => <button key={value} className={filter === value ? 'active' : ''} aria-pressed={filter === value} onClick={() => setFilter(value)}>{t(value === 'all' ? 'allStatuses' : value === 'enabled' ? 'tableEnabled' : 'tableDisabled')}</button>)}</div>
      {deletion.feedback}<ListFeedback state={{ ...list, items: visible }} empty={list.items.length ? 'noSearchResults' : 'firstTable'} hint={list.items.length ? 'adjustSearch' : 'firstTableHint'} icon={LayoutGrid} />
      <div className="workspace-table-grid">{visible.map((counter) => <article className={`workspace-table-card ${counter.enabled ? '' : 'is-disabled'}`} key={counter.uuid}>
        <div className="table-config-top"><strong title={counter.code}>{counter.code}</strong><StatusPill muted={!counter.enabled}>{t(counter.enabled ? 'tableEnabled' : 'tableDisabled')}</StatusPill></div><TableGlyph /><h2>{counter.name}</h2><p className="table-config-seats"><UsersRound size={14} />{counter.capacity ?? '—'} {t('capacity')}</p>
        <div className="table-config-actions"><Button disabled={!can(access, 'counter', 'update') || deletion.busy} onClick={() => setEditing(counter)}><Pencil size={14} />{t('edit')}</Button><button className="workspace-icon danger" aria-label={t('delete')} disabled={!can(access, 'counter', 'delete') || deletion.busy} onClick={() => void deletion.remove(() => api.remove('counters', counter.uuid))}><Trash2 size={16} /></button></div><RecordIdentity id={counter.uuid}><dt>{t('tableCode')}</dt><dd>{counter.code}</dd></RecordIdentity>
      </article>)}</div><PageControls state={list} /><Notice subtle icon={LayoutGrid}>{t('basicTableHint')}</Notice>
    </>}
    {editing !== undefined && <Sheet open title={t('tableDetail')} onClose={() => setEditing(undefined)}><CounterForm api={api} counter={editing} done={() => { setEditing(undefined); list.reload() }} /></Sheet>}
  </>
}

const statusLabels: Record<string, MessageKey> = { pending_validation: 'statePendingValidation', awaiting_inventory: 'stateAwaitingInventory', accepted: 'stateAccepted', rejected: 'stateRejected', fulfillment_pending: 'stateFulfillmentPending', fulfilling: 'stateFulfilling', fulfilled: 'stateFulfilled', verified: 'stateVerified', cancelled: 'stateCancelled' }
export function LiveOrders({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t, locale } = useI18n()
  const load = useCallback((page: number) => api.orders(page), [api])
  const list = usePage(load, can(access, 'order', 'read'))
  const [selected, setSelected] = useState<string | null>(null)
  const [query, setQuery] = useState('')
  const visible = list.items.filter((order) => `${order.tradeOrderUuid} ${order.operationalStatus}`.toLowerCase().includes(query.trim().toLowerCase()))
  return <><PageHeading eyebrow="ordersEyebrow" title="readOnlyOrders" subtitle="ordersLiveSubtitle"><span className="configuration-badge"><LockKeyhole size={13} />{t('readOnly')}</span></PageHeading>
    {!can(access, 'order', 'read') ? <AccessNotice /> : <>
      <PageScope count={list.items.length} /><SearchField value={query} onChange={setQuery} /><ListFeedback state={{ ...list, items: visible }} empty={list.items.length ? 'noSearchResults' : 'firstOrder'} hint={list.items.length ? 'adjustSearch' : 'firstOrderHint'} icon={ReceiptText} />
      <div className="workspace-order-list">{visible.map((order) => <article key={order.uuid}><div className="order-card-heading"><span><ReceiptText size={19} />{t('orderReference')}</span><StatusPill muted={['cancelled', 'rejected'].includes(order.operationalStatus)}>{statusLabels[order.operationalStatus] ? t(statusLabels[order.operationalStatus]) : order.operationalStatus}</StatusPill></div><div className="order-card-body"><div><h2 title={order.tradeOrderUuid}>#{order.tradeOrderUuid.slice(0, 8).toUpperCase()}</h2><p><Clock3 size={12} /><time dateTime={order.createdAt}>{formatDate(order.createdAt, locale)}</time></p></div><strong>{formatOrderAmount(order, locale)}</strong></div><div className="order-card-footer"><span>{t('readOnly')}</span><Button onClick={() => setSelected(order.uuid)}>{t('view')}<ChevronDown size={14} /></Button></div></article>)}</div>
      <PageControls state={list} /><Notice subtle icon={LockKeyhole}>{t('operationalStatusHint')}</Notice>
    </>}
    {selected && <Sheet open title={t('orderDetails')} onClose={() => setSelected(null)}><OrderDetail api={api} id={selected} /></Sheet>}
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
function OrderDetail({ api, id }: { api: StoreApi; id: string }) {
  const { t, locale } = useI18n()
  const load = useCallback(async () => ({ items: [await api.order(id)], hasNext: false }), [api, id])
  const detail = usePage(load)
  return <><ListFeedback state={detail} empty="emptyLive" hint="firstOrderHint" icon={ReceiptText} />{detail.items.map((order) => <div className="workspace-order-detail" key={order.uuid}>
    <div className="order-detail-hero"><span><ReceiptText size={28} strokeWidth={1.3} /></span><p>{t('amount')}</p><strong>{formatOrderAmount(order, locale)}</strong><StatusPill>{statusLabels[order.operationalStatus] ? t(statusLabels[order.operationalStatus]) : order.operationalStatus}</StatusPill><small>{order.operationalStatus}</small></div>
    <dl className="order-detail-meta"><dt>{t('orderNo')}</dt><dd>{order.tradeOrderUuid}</dd><dt>{t('createdAt')}</dt><dd>{formatDate(order.createdAt, locale)}</dd></dl>
    <h3>{t('orderItems')}</h3><div className="order-snapshot-items">{snapshotItems(order.orderSnapshot).length ? snapshotItems(order.orderSnapshot).map((item, index) => <div key={index}><span className="snapshot-item-index">{String(index + 1).padStart(2, '0')}</span><span>{item.name ?? t('orderItem')}</span>{item.quantity !== undefined && <strong>×{item.quantity}</strong>}</div>) : <p className="helper">{t('noSnapshotItems')}</p>}</div>
    <Notice icon={LockKeyhole} subtle>{t('operationalStatusHint')}</Notice><details className="workspace-advanced"><summary>{t('rawSnapshot')}<ChevronDown size={14} /></summary><pre>{JSON.stringify(order.orderSnapshot ?? null, null, 2)}</pre></details>
  </div>)}<PageControls state={detail} detail /></>
}
