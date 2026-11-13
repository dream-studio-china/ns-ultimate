import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { Button } from 'antd-mobile'
import JSZip from 'jszip'
import { ChevronDown, Clock3, Download, LayoutGrid, Minus, Pencil, Plus, Printer, QrCode, ReceiptText, RotateCcw, UsersRound, XCircle } from 'lucide-react'
import { QRCodeCanvas } from 'qrcode.react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, type CounterSessionView, type StoreAccess, StoreApi, type LiveCounter, type SessionOrder } from '../adapters/live'
import { ApiError } from '../adapters/api-client'
import { minorUnitsToInput, paidOrderCanRefund, paymentStatusMessage, refundReasonBytes, refundResultKind, validateRefundAmount } from '../order-refund'
import { OrderRefundDetails } from './OrderRefundDetails'
import { DEFAULT_TRADE_ORDER_STATUSES, filterTradeOrders } from './order-filters'
import { counterQrFileNames, customerOrderingUrl } from '../table-qr'
import { AccessNotice, EditorAdvanced, EditorSection, EditorSheet, EditorToggle, ListFeedback, MutationForm, Notice, PageControls, StatusPill, useCommand, usePage, LiveSheet as Sheet } from './ui'
import { LiveDining } from './dining'
import { canManuallyPrintOrderStatus, ManualPrintSheet } from './ManualPrintSheet'

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
  const [selectedQr, setSelectedQr] = useState<LiveCounter | null>(null)
  const [qrBusy, setQrBusy] = useState(false)
  const [qrFeedback, setQrFeedback] = useState<MessageKey | null>(null)
  const qrCanvases = useRef(new Map<string, HTMLCanvasElement>())
  const visible = list.items
  const downloadQrs = async (counters: LiveCounter[], all: boolean) => {
    if (qrBusy || !counters.length) return
    setQrBusy(true)
    setQrFeedback(null)
    try {
      const canvases = counters.map((counter) => {
        const canvas = qrCanvases.current.get(counter.uuid)
        if (!canvas) throw new Error('QR canvas is not ready')
        return canvas
      })
      if (!all) {
        downloadBlob(await canvasPngBlob(canvases[0]), counterQrFileNames(counters)[0])
        setQrFeedback('tableQrDownloadReady')
        return
      }
      const zip = new JSZip()
      const names = counterQrFileNames(counters)
      for (let index = 0; index < canvases.length; index += 1) {
        zip.file(names[index], await canvasPngBlob(canvases[index]))
      }
      const archive = await zip.generateAsync({ type: 'blob', compression: 'DEFLATE', compressionOptions: { level: 6 } })
      downloadBlob(archive, `桌台点餐二维码-${new Date().toISOString().slice(0, 10)}.zip`)
      setQrFeedback('tableQrDownloadAllReady')
    } catch {
      setQrFeedback('tableQrDownloadFailed')
    } finally {
      setQrBusy(false)
    }
  }
  const feedback = qrFeedback && <p className={`table-qr-feedback ${qrFeedback === 'tableQrDownloadFailed' ? 'is-error' : ''}`} role="status">{t(qrFeedback)}</p>
  return <>{!can(access, 'counter', 'read') ? <AccessNotice /> : <>
      <ListFeedback state={{ ...list, items: visible }} empty="firstTable" hint="existingTablesHint" icon={LayoutGrid} />
      <div className="table-qr-toolbar"><div><strong>{t('tableQrBatchTitle')}</strong><small>{t('tableQrBatchHint')}</small></div><Button loading={qrBusy} disabled={!visible.length || qrBusy} onClick={() => void downloadQrs(visible, true)}><Download size={15} />{qrBusy ? t('tableQrDownloading') : t('tableQrDownloadAll')}</Button></div>
      {!selectedQr && feedback}
      <div className="workspace-table-grid workspace-table-grid-compact">{visible.map((counter) => <article className={`workspace-table-card workspace-table-card-compact ${counter.enabled ? '' : 'is-disabled'}`} key={counter.uuid}>
        <div className="table-config-top"><h2>{counter.name}</h2><Button size="mini" className="table-config-edit" aria-label={t('edit')} disabled={!can(access, 'counter', 'update')} onClick={() => setEditing(counter)}><Pencil size={13} /></Button></div><p className="table-config-seats"><UsersRound size={14} />{counter.capacity ?? '—'} {t('capacity')}</p>
        <Button block className="table-qr-open" onClick={() => { setQrFeedback(null); setSelectedQr(counter) }}><QrCode size={14} />{t('tableQrView')}</Button>
      </article>)}</div><PageControls state={list} detail />
      <div className="table-qr-export-stage" aria-hidden="true">{visible.map((counter) => <QRCodeCanvas key={counter.uuid} ref={(canvas) => { if (canvas) qrCanvases.current.set(counter.uuid, canvas); else qrCanvases.current.delete(counter.uuid) }} data-table-export-qr={counter.uuid} value={customerOrderingUrl(counter.uuid, access.store.uuid)} size={320} level="M" marginSize={2} bgColor="#ffffff" fgColor="#201813" />)}</div>
    </>}
    {editing && <CounterForm api={api} counter={editing} onClose={() => setEditing(null)} done={() => { setEditing(null); list.reload() }} />}
    {selectedQr && <Sheet open title={t('tableQrTitle')} onClose={() => { setSelectedQr(null); setQrFeedback(null) }} className="table-qr-sheet">
      <div className="table-qr-dialog"><header><span>{t('tableNumber')}</span><h2>{selectedQr.name}</h2><small>{selectedQr.code}</small></header><div className="table-qr-code"><QRCodeCanvas data-table-qr={selectedQr.uuid} value={customerOrderingUrl(selectedQr.uuid, access.store.uuid)} size={220} level="M" marginSize={2} bgColor="#ffffff" fgColor="#201813" title={`${selectedQr.name || selectedQr.code} ${t('tableQrTitle')}`} /></div><p className="table-qr-hint">{t('tableQrScanHint')}</p><code className="table-qr-url" title={customerOrderingUrl(selectedQr.uuid, access.store.uuid)}>{customerOrderingUrl(selectedQr.uuid, access.store.uuid)}</code><Button block className="table-qr-download" loading={qrBusy} disabled={qrBusy} onClick={() => void downloadQrs([selectedQr], false)}><Download size={14} />{t('tableQrDownload')}</Button>{feedback}</div>
    </Sheet>}
  </>
}

function canvasPngBlob(canvas: HTMLCanvasElement): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('PNG generation failed')), 'image/png')
  })
}

function downloadBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 0)
}

const statusLabels: Record<string, MessageKey> = { draft: 'diningDraft', pending: 'diningPending', confirmed: 'diningConfirmed', paid: 'diningPaid', fulfilled: 'stateFulfilled', completed: 'diningCompleted', cancelled: 'stateCancelled', refunded: 'diningRefunded', merged: 'diningMerged' }
const hiddenOrderStatuses = new Set(['cancelled', 'merged'])
const selectableOrderStatuses = Object.entries(statusLabels).filter(([value]) => !hiddenOrderStatuses.has(value))
interface CounterTradeOrder {
  order: SessionOrder
  counter: CounterSessionView['counter']
  currency: string
  version: string
}

export function LiveOrders({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t, locale } = useI18n()
  const [statuses, setStatuses] = useState<Set<string>>(() => new Set(DEFAULT_TRADE_ORDER_STATUSES))
  const [tableCode, setTableCode] = useState('')
  const [date, setDate] = useState('')
  const canReadOrders = can(access, 'counter_order', 'read')
  const canCancelOrders = can(access, 'counter_order', 'cancel')
  const canRefundOrders = can(access, 'order', 'refund')
  const canPrintOrders = can(access, 'order', 'print')
  const load = useCallback(async () => {
    const tables = await api.allCounterSessions()
    const orders = new Map<string, CounterTradeOrder>()
    for (const table of tables) {
      if (!table.ordersVisible) continue
      for (const order of table.orders) {
        if (hiddenOrderStatuses.has(order.status)) continue
        orders.set(order.uuid, { order, counter: table.counter, currency: table.currency, version: table.version })
      }
    }
    const items = [...orders.values()].sort((left, right) => right.order.createdAt.localeCompare(left.order.createdAt) || right.order.uuid.localeCompare(left.order.uuid))
    return { items, hasNext: false, total: items.length }
  }, [api])
  const list = usePage(load, canReadOrders)
  const [selected, setSelected] = useState<CounterTradeOrder | null>(null)
  const [refunding, setRefunding] = useState<CounterTradeOrder | null>(null)
  const [cancelling, setCancelling] = useState<CounterTradeOrder | null>(null)
  const [printing, setPrinting] = useState<CounterTradeOrder | null>(null)
  const counterCodes = [...new Set(list.items.map(({ counter }) => counter.code).filter(Boolean))].sort((left, right) => left.localeCompare(right))
  const allStatusesSelected = selectableOrderStatuses.every(([value]) => statuses.has(value))
  const visible = filterTradeOrders(list.items, { statuses, tableCode, date, timeZone: access.store.timezone })
  const toggleStatus = (value: string) => setStatuses((current) => {
    const next = new Set(current)
    if (next.has(value)) next.delete(value)
    else next.add(value)
    return next
  })
  const resetFilters = () => {
    setStatuses(new Set(DEFAULT_TRADE_ORDER_STATUSES))
    setTableCode('')
    setDate('')
  }
  const openRefund = (target: CounterTradeOrder) => {
    setSelected(null)
    setRefunding(target)
  }
  return <>
    {!canReadOrders ? <AccessNotice /> : <>
      <section className="trade-order-filters" aria-label={t('orderFilters')}>
        <div className="trade-order-filter-grid">
          <label className="field"><span>{t('tableNumber')}</span><select aria-label={t('tableNumber')} value={tableCode} onChange={(event) => setTableCode(event.target.value)}><option value="">{t('allTables')}</option>{counterCodes.map((code) => <option key={code} value={code}>{code}</option>)}</select></label>
          <label className="field"><span>{t('tradeOrderDate')}</span><input aria-label={t('tradeOrderDate')} type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label>
        </div>
        <div className="trade-order-statuses" role="group" aria-label={t('orderStatus')}>
          <span>{t('orderStatus')}</span>
          <button type="button" aria-pressed={allStatusesSelected} onClick={() => setStatuses(allStatusesSelected ? new Set() : new Set(selectableOrderStatuses.map(([value]) => value)))}>{t('allStatuses')}</button>
          {selectableOrderStatuses.map(([value, label]) => <button type="button" key={value} aria-pressed={statuses.has(value)} onClick={() => toggleStatus(value)}>{t(label)}</button>)}
        </div>
        <button type="button" className="trade-order-filter-reset" onClick={resetFilters}>{t('resetFilters')}</button>
      </section>
      <ListFeedback state={{ ...list, items: visible }} empty={list.items.length ? 'noSearchResults' : 'firstOrder'} hint={list.items.length ? 'adjustSearch' : 'firstOrderHint'} icon={ReceiptText} />
      <div className="workspace-order-list">{visible.map((item) => {
        const { order, counter, currency } = item
        const refundable = paidOrderCanRefund(order)
        const paymentLabel = paymentStatusMessage(order.paymentStatus)
        const confirmed = order.status === 'confirmed'
        return <article key={order.uuid}><div className="order-card-heading"><span><ReceiptText size={19} />{t('tableNumber')} {counter.code}</span><StatusPill muted={order.status !== 'pending' && order.status !== 'confirmed'}>{statusLabels[order.status] ? t(statusLabels[order.status]) : order.status}</StatusPill></div>
          {paymentLabel && <p>{t('diningPaymentState')} · {t(paymentLabel)}</p>}
          <div className="order-card-body"><div><h2 title={order.uuid}>#{order.uuid.slice(0, 8).toUpperCase()}</h2><p><Clock3 size={12} /><time dateTime={order.createdAt}>{formatDate(order.createdAt, locale)}</time></p></div><strong>{formatOrderAmount(order, currency, locale)}</strong></div><div className="order-card-footer"><span>{t('diningMode')} · {order.diningModeName ?? t('diningModeNotRecorded')}</span><div className="order-card-actions">{canPrintOrders && canManuallyPrintOrderStatus(order.status) && <Button className="order-card-print" onClick={() => setPrinting(item)}><Printer size={14} />{t('manualPrintAction')}</Button>}{confirmed && <Button color="danger" fill="outline" disabled={!canCancelOrders} aria-label={canCancelOrders ? t('cancelOrder') : t('cancelOrderPermissionRequired')} onClick={() => setCancelling(item)}><XCircle size={14} />{t('cancelOrder')}</Button>}{refundable && <Button disabled={!canRefundOrders} aria-label={canRefundOrders ? t('refund') : t('refundPermissionRequired')} onClick={() => openRefund(item)}><RotateCcw size={14} />{t('refund')}</Button>}<Button onClick={() => setSelected(item)}>{t('view')}<ChevronDown size={14} /></Button></div></div></article>
      })}</div>
      <PageControls state={list} />
    </>}
    {selected && <Sheet open title={t('orderDetails')} onClose={() => setSelected(null)}><OrderDetail {...selected} canCancel={selected.order.status === 'confirmed'} cancelAllowed={canCancelOrders} onCancel={() => { setSelected(null); setCancelling(selected) }} canRefund={paidOrderCanRefund(selected.order)} refundAllowed={canRefundOrders} onRefund={() => openRefund(selected)} canPrint={canPrintOrders && canManuallyPrintOrderStatus(selected.order.status)} onPrint={() => { setPrinting(selected); setSelected(null) }} />{selected.order.hasInvoice && <OrderRefundDetails api={api} tradeOrderUuid={selected.order.tradeOrderUuid} historyAllowed={canRefundOrders} />}</Sheet>}
    {refunding && <OrderRefundSheet api={api} target={refunding} onClose={() => setRefunding(null)} onRefunded={list.reload} />}
    {cancelling && <CancelOrderSheet api={api} target={cancelling} onClose={() => setCancelling(null)} onCancelled={() => { setCancelling(null); list.reload() }} />}
    {printing && <ManualPrintSheet api={api} access={access} tradeOrderUuid={printing.order.tradeOrderUuid} orderLabel={`#${printing.order.uuid.slice(0, 8).toUpperCase()}`} onClose={() => setPrinting(null)} />}
  </>
}
function formatOrderAmount(order: SessionOrder, currency: string, locale: string) { return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(order.totalAmount / 100) }
function formatDate(value: string, locale: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(date)
}
function OrderDetail({ order, counter, currency, canCancel, cancelAllowed, onCancel, canRefund, refundAllowed, onRefund, canPrint, onPrint }: CounterTradeOrder & { canCancel: boolean; cancelAllowed: boolean; onCancel: () => void; canRefund: boolean; refundAllowed: boolean; onRefund: () => void; canPrint: boolean; onPrint: () => void }) {
  const { t, locale } = useI18n()
  return <div className="workspace-order-detail">
    <div className="order-detail-hero"><span><ReceiptText size={28} strokeWidth={1.3} /></span><p>{t('amount')}</p><strong>{formatOrderAmount(order, currency, locale)}</strong><StatusPill>{statusLabels[order.status] ? t(statusLabels[order.status]) : order.status}</StatusPill><small>{order.status}</small></div>
    <dl className="order-detail-meta"><dt>{t('tableNumber')}</dt><dd>{counter.name} · {counter.code}</dd><dt>{t('orderNo')}</dt><dd>{order.uuid}</dd><dt>{t('createdAt')}</dt><dd>{formatDate(order.createdAt, locale)}</dd><dt>{t('diningMode')}</dt><dd>{order.diningModeName ?? t('diningModeNotRecorded')}</dd><dt>{t('diningPaymentState')}</dt><dd>{order.paymentStatus ?? t('diningNoPayment')}</dd></dl>
    <h3>{t('orderItems')}</h3><div className="order-snapshot-items">{order.items.map((item, index) => <div key={`${item.specificationUuid ?? item.productName}-${index}`}><span className="snapshot-item-index">{String(index + 1).padStart(2, '0')}</span><span>{item.productName}{item.specificationName ? ` · ${item.specificationName}` : ''}</span><strong>×{item.quantity}</strong></div>)}</div>
    {canCancel && <Button block color="danger" fill="outline" disabled={!cancelAllowed} aria-label={cancelAllowed ? t('cancelOrder') : t('cancelOrderPermissionRequired')} onClick={onCancel}><XCircle size={15} />{t('cancelOrder')}</Button>}
    {canRefund && <Button block className="order-detail-refund" disabled={!refundAllowed} onClick={onRefund}><RotateCcw size={15} />{refundAllowed ? t('refundOrder') : t('refundPermissionRequired')}</Button>}
    {canPrint && <Button block className="order-detail-print" onClick={onPrint}><Printer size={15} />{t('manualPrintAction')}</Button>}
  </div>
}

function CancelOrderSheet({ api, target, onClose, onCancelled }: { api: StoreApi; target: CounterTradeOrder; onClose: () => void; onCancelled: () => void }) {
  const { t } = useI18n()
  const formId = useId()
  const [reason, setReason] = useState(() => t('cancelConfirmedReason'))
  const [busy, setBusy] = useState(false)
  const commandUuid = useCommand({ version: target.version, orderUuid: target.order.uuid, reason })
  return <Sheet open title={t('cancelConfirmedTitle')} onClose={() => { if (!busy) onClose() }} footer={<div className="editor-footer"><p><XCircle size={13} />{t('orderCancelHint')}</p><div><Button disabled={busy} onClick={onClose}>{t('cancel')}</Button><Button type="submit" form={formId} color="danger" loading={busy}><XCircle size={15} />{t('cancelPendingConfirm')}</Button></div></div>}>
    <MutationForm id={formId} actions={false} submit={() => api.counterCommand(target.counter.uuid, 'cancel', {
      version: target.version,
      orderUuid: target.order.uuid,
      reason: reason.trim(),
      commandUuid,
    })} onDone={onCancelled} onBusyChange={setBusy}>
      <Notice title="cancelConfirmedTitle">{t('cancelConfirmedHint').replace('{order}', `#${target.order.uuid.slice(0, 8).toUpperCase()}`)}</Notice>
      <label className="field"><span>{t('cancelReason')}</span><textarea required rows={3} maxLength={500} value={reason} disabled={busy} onChange={(event) => setReason(event.target.value)} /></label>
    </MutationForm>
  </Sheet>
}

function OrderRefundSheet({ api, target, onClose, onRefunded }: { api: StoreApi; target: CounterTradeOrder; onClose: () => void; onRefunded: () => void }) {
  const { t, locale } = useI18n()
  const formId = useId()
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const [preview, setPreview] = useState<Awaited<ReturnType<StoreApi['refundPreview']>> | null>(null)
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [touched, setTouched] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [outcome, setOutcome] = useState<MessageKey | null>(null)
  const [error, setError] = useState('')
  const lock = useRef(false)
  const formatMoney = (cents: number, currency: string) => {
    try { return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(cents / 100) }
    catch { return `${currency} ${(cents / 100).toFixed(2)}` }
  }
  const failure = (cause: unknown, fallback: MessageKey) => {
    if (!(cause instanceof ApiError)) return t(fallback)
    if (cause.status === 403) return t('refundPermissionRequired')
    if (cause.kind === 'network') return t('refundNetworkUnknown')
    if (cause.serverMessage === 'refund_pending') return t('refundAlreadyProcessing')
    if (cause.serverMessage === 'invalid_refund_amount') return t('refundAmountExceeds')
    if (cause.serverMessage === 'order_not_refundable') return t('refundNoBalance')
    if (cause.serverMessage === 'order_invoice_mismatch') return t('refundContextChanged')
    if (cause.serverMessage === 'refund_idempotency_conflict') return t('refundContextChanged')
    return cause.serverMessage || t(fallback)
  }
  useEffect(() => {
    let active = true
    setLoading(true)
    void api.refundPreview(target.order.tradeOrderUuid).then((next) => {
      if (!active) return
      if (!next.canRefund) { setError(t('refundNoBalance')); return }
      setPreview(next)
      setAmount(minorUnitsToInput(next.refundableAmount))
      setLoaded(true)
    }).catch((cause: unknown) => { if (active) setError(failure(cause, 'refundLoadFailed')) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [api, target.order.tradeOrderUuid])
  const validation = preview ? validateRefundAmount(amount, preview.refundableAmount) : { cents: null, error: 'refundAmountPositive' as const }
  const amountError = touched && validation.error ? t(validation.error) : ''
  const reasonTooLong = refundReasonBytes(reason.trim()) > 1000
  const reasonError = touched && (!reason.trim() || reasonTooLong) ? t(reasonTooLong ? 'refundReasonTooLong' : 'refundReasonRequired') : ''
  const idempotencyKey = useCommand({ tradeOrderUuid: target.order.tradeOrderUuid, amount: validation.cents, reason: reason.trim() })
  const close = () => { if (!submitting) onClose() }
  const submit = async () => {
    if (lock.current || outcome || !preview || !loaded) return
    setTouched(true)
    if (validation.error || !validation.cents || !reason.trim() || reasonTooLong) return
    if (!confirming) { setConfirming(true); return }
    lock.current = true
    setSubmitting(true)
    setError('')
    try {
      const result = await api.refundOrder(target.order.tradeOrderUuid, validation.cents, reason, idempotencyKey)
      const kind = refundResultKind(result.status)
      setOutcome(({ processing: 'refundProcessing', unknown: 'refundNetworkUnknown', succeeded: 'refundCompleted', failed: 'refundResultFailed' } as const)[kind])
      onRefunded()
    } catch (cause) {
      setError(failure(cause, 'refundFailed'))
      setConfirming(false)
    } finally {
      lock.current = false
      setSubmitting(false)
    }
  }
  return <Sheet open title={t('refundOrder')} onClose={close} footer={<div className="editor-footer order-refund-footer">{outcome ? <><p><RotateCcw size={13} />{t(outcome)}</p><Button block color="primary" onClick={close}>{t('confirm')}</Button></> : <><p><RotateCcw size={13} />{confirming && preview && validation.cents ? t('refundConfirm').replace('{amount}', formatMoney(validation.cents, preview.currency)) : t('refundHint')}</p><div><Button disabled={submitting} onClick={() => confirming ? setConfirming(false) : close()}>{t(confirming ? 'refundBackToEdit' : 'cancel')}</Button><Button type="submit" form={formId} color="primary" loading={submitting} disabled={!loaded || loading}>{t(confirming ? 'confirm' : 'refundSubmit')}</Button></div></>}</div>}>
    <form id={formId} className="order-refund-form" onSubmit={(event) => { event.preventDefault(); void submit() }}>
      <header className="order-refund-heading"><span><ReceiptText size={22} /></span><div><small>{t('orderNo')}</small><strong>#{target.order.uuid.slice(0, 8).toUpperCase()}</strong><p>{target.counter.name} · {target.counter.code}</p></div></header>
      {loading && <div className="workspace-skeleton compact" role="status"><div><span /><i /><i /></div></div>}
      {error && <div className="workspace-error" role="alert"><RotateCcw size={18} /><p>{error}</p></div>}
      {preview && <>
        <div className="order-refund-summary"><div><span>{t('refundPaidAmount')}</span><strong>{formatMoney(preview.amount, preview.currency)}</strong></div><div><span>{t('refundAlreadyAmount')}</span><strong>{formatMoney(preview.refundedAmount, preview.currency)}</strong></div><div><span>{t('refundableAmount')}</span><strong>{formatMoney(preview.refundableAmount, preview.currency)}</strong></div><div><span>{t('method')}</span><strong>{preview.payment}</strong></div></div>
        {outcome ? <div className={`order-refund-result ${outcome === 'refundResultFailed' ? 'is-failed' : ''}`} role={outcome === 'refundResultFailed' ? 'alert' : 'status'}><RotateCcw size={22} /><div><strong>{t(outcome)}</strong><p>{t('refundHint')}</p></div></div> : <>
          <label className="field"><span>{t('refundAmount')}</span><input required inputMode="decimal" maxLength={16} value={amount} disabled={submitting || confirming} onBlur={() => setTouched(true)} onChange={(event) => { setAmount(event.target.value); setConfirming(false) }} placeholder={t('refundAmountPlaceholder')} />{amountError ? <small className="error-text">{amountError}</small> : <small>{t('refundAmountHint').replace('{amount}', formatMoney(preview.refundableAmount, preview.currency))}</small>}</label>
          <label className="field"><span>{t('refundReason')}</span><textarea required rows={3} maxLength={1000} value={reason} disabled={submitting || confirming} onBlur={() => setTouched(true)} onChange={(event) => { setReason(event.target.value); setConfirming(false) }} placeholder={t('refundReasonPlaceholder')} />{reasonError && <small className="error-text">{reasonError}</small>}</label>
          {confirming && <div className="order-refund-confirm" role="alert"><strong>{t('refundConfirm').replace('{amount}', formatMoney(validation.cents ?? 0, preview.currency))}</strong><p>{t('refundHint')}</p></div>}
        </>}
      </>}
    </form>
  </Sheet>
}
