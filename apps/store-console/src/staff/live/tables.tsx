import { useCallback, useId, useState } from 'react'
import { Button } from 'antd-mobile'
import { ChevronDown, Clock3, LayoutGrid, Minus, Pencil, Plus, ReceiptText, UsersRound } from 'lucide-react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, type CounterSessionView, type StoreAccess, StoreApi, type LiveCounter, type SessionOrder } from '../adapters/live'
import { AccessNotice, EditorAdvanced, EditorSection, EditorSheet, EditorToggle, ListFeedback, PageControls, StatusPill, usePage, LiveSheet as Sheet } from './ui'
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
  const cashier = can(access, 'counter_session', 'read')
  const configurationAccess = can(access, 'counter', 'read')
  const [configuration, setConfiguration] = useState(!cashier && configurationAccess)
  const [editing, setEditing] = useState<LiveCounter | null>(null)
  const [refreshKey, setRefreshKey] = useState(0)
  return <>
    {cashier && configurationAccess && <div className="workspace-filters cashier-view-tabs">{[false, true].map((value) => <button type="button" key={String(value)} aria-pressed={configuration === value} className={configuration === value ? 'active' : ''} onClick={() => setConfiguration(value)}>{t(value ? 'tableConfiguration' : 'diningBoard')}</button>)}</div>}
    {configuration ? <CounterConfiguration api={api} access={access} /> : <LiveDining api={api} access={access} onEdit={setEditing} refreshKey={refreshKey} />}
    {editing && <CounterForm api={api} counter={editing} onClose={() => setEditing(null)} done={() => { setEditing(null); setRefreshKey((value) => value + 1) }} />}
  </>
}
function CounterConfiguration({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const load = useCallback(() => api.allCounters(), [api])
  const list = usePage(load, can(access, 'counter', 'read'))
  const [editing, setEditing] = useState<LiveCounter | null>(null)
  const visible = list.items
  return <>{!can(access, 'counter', 'read') ? <AccessNotice /> : <>
      <ListFeedback state={{ ...list, items: visible }} empty="firstTable" hint="existingTablesHint" icon={LayoutGrid} />
      <div className="workspace-table-grid workspace-table-grid-compact">{visible.map((counter) => <article className={`workspace-table-card workspace-table-card-compact ${counter.enabled ? '' : 'is-disabled'}`} key={counter.uuid}>
        <div className="table-config-top"><h2>{counter.name}</h2><Button size="mini" className="table-config-edit" aria-label={t('edit')} disabled={!can(access, 'counter', 'update')} onClick={() => setEditing(counter)}><Pencil size={13} /></Button></div><p className="table-config-seats"><UsersRound size={14} />{counter.capacity ?? '—'} {t('capacity')}</p>
      </article>)}</div><PageControls state={list} detail />
    </>}
    {editing && <CounterForm api={api} counter={editing} onClose={() => setEditing(null)} done={() => { setEditing(null); list.reload() }} />}
  </>
}

const statusLabels: Record<string, MessageKey> = { draft: 'diningDraft', pending: 'diningPending', confirmed: 'diningConfirmed', paid: 'diningPaid', fulfilled: 'stateFulfilled', completed: 'diningCompleted', cancelled: 'stateCancelled', refunded: 'diningRefunded', merged: 'diningMerged' }
const hiddenOrderStatuses = new Set(['cancelled', 'refunded', 'merged'])
interface CounterTradeOrder {
  order: SessionOrder
  counter: CounterSessionView['counter']
  currency: string
}

export function LiveOrders({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t, locale } = useI18n()
  const [status, setStatus] = useState('all')
  const canReadOrders = can(access, 'counter_order', 'read')
  const load = useCallback(async () => {
    const tables = await api.allCounterSessions()
    const orders = new Map<string, CounterTradeOrder>()
    for (const table of tables) {
      if (!table.ordersVisible) continue
      for (const order of table.orders) {
        if (hiddenOrderStatuses.has(order.status)) continue
        orders.set(order.uuid, { order, counter: table.counter, currency: table.currency })
      }
    }
    const items = [...orders.values()].sort((left, right) => right.order.createdAt.localeCompare(left.order.createdAt) || right.order.uuid.localeCompare(left.order.uuid))
    return { items, hasNext: false, total: items.length }
  }, [api])
  const list = usePage(load, canReadOrders)
  const [selected, setSelected] = useState<CounterTradeOrder | null>(null)
  const visible = status === 'all' ? list.items : list.items.filter(({ order }) => order.status === status)
  return <>
    {!canReadOrders ? <AccessNotice /> : <>
      <label className="field order-status-filter">{t('orderStatus')}<select value={status} onChange={(event) => setStatus(event.target.value)}><option value="all">{t('allStatuses')}</option>{Object.entries(statusLabels).filter(([value]) => !hiddenOrderStatuses.has(value)).map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</select></label><ListFeedback state={{ ...list, items: visible }} empty={status !== 'all' ? 'noSearchResults' : 'firstOrder'} hint={status !== 'all' ? 'adjustSearch' : 'firstOrderHint'} icon={ReceiptText} />
      <div className="workspace-order-list">{visible.map(({ order, counter, currency }) => <article key={order.uuid}><div className="order-card-heading"><span><ReceiptText size={19} />{t('tableNumber')} {counter.code}</span><StatusPill muted={order.status !== 'pending' && order.status !== 'confirmed'}>{statusLabels[order.status] ? t(statusLabels[order.status]) : order.status}</StatusPill></div><div className="order-card-body"><div><h2 title={order.uuid}>#{order.uuid.slice(0, 8).toUpperCase()}</h2><p><Clock3 size={12} /><time dateTime={order.createdAt}>{formatDate(order.createdAt, locale)}</time></p></div><strong>{formatOrderAmount(order, currency, locale)}</strong></div><div className="order-card-footer"><span>{t('diningMode')} · {order.diningModeName ?? t('diningModeNotRecorded')}</span><Button onClick={() => setSelected({ order, counter, currency })}>{t('view')}<ChevronDown size={14} /></Button></div></article>)}</div>
      <PageControls state={list} />
    </>}
    {selected && <Sheet open title={t('orderDetails')} onClose={() => setSelected(null)}><OrderDetail {...selected} /></Sheet>}
  </>
}
function formatOrderAmount(order: SessionOrder, currency: string, locale: string) { return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(order.totalAmount / 100) }
function formatDate(value: string, locale: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(date)
}
function OrderDetail({ order, counter, currency }: CounterTradeOrder) {
  const { t, locale } = useI18n()
  return <div className="workspace-order-detail">
    <div className="order-detail-hero"><span><ReceiptText size={28} strokeWidth={1.3} /></span><p>{t('amount')}</p><strong>{formatOrderAmount(order, currency, locale)}</strong><StatusPill>{statusLabels[order.status] ? t(statusLabels[order.status]) : order.status}</StatusPill><small>{order.status}</small></div>
    <dl className="order-detail-meta"><dt>{t('tableNumber')}</dt><dd>{counter.name} · {counter.code}</dd><dt>{t('orderNo')}</dt><dd>{order.uuid}</dd><dt>{t('createdAt')}</dt><dd>{formatDate(order.createdAt, locale)}</dd><dt>{t('diningMode')}</dt><dd>{order.diningModeName ?? t('diningModeNotRecorded')}</dd><dt>{t('diningPaymentState')}</dt><dd>{order.paymentStatus ?? t('diningNoPayment')}</dd></dl>
    <h3>{t('orderItems')}</h3><div className="order-snapshot-items">{order.items.map((item, index) => <div key={`${item.specificationUuid ?? item.productName}-${index}`}><span className="snapshot-item-index">{String(index + 1).padStart(2, '0')}</span><span>{item.productName}{item.specificationName ? ` · ${item.specificationName}` : ''}</span><strong>×{item.quantity}</strong></div>)}</div>
  </div>
}
