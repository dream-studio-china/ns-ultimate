import { useCallback, useMemo, useState } from 'react'
import { CalendarDays, ChartNoAxesCombined, ReceiptText } from 'lucide-react'
import { useI18n } from '../../shared/i18n'
import { can, type LiveOrder, type SessionOrder, type StoreAccess, type StoreApi } from '../adapters/live'
import {
  readReportingDay,
  reportDateKey,
  reportingLoadWindow,
  summarizeReport,
  type ReportPaymentMethod,
  type ReportRange,
  type ReportingOrder,
} from '../reporting'
import { ListFeedback, PageHeading, usePage } from './ui'

function counterLabel(order: SessionOrder, currency: string): ReportingOrder {
  return {
    uuid: order.uuid,
    tradeOrderUuid: order.uuid,
    status: order.status,
    paymentStatus: order.paymentStatus,
    paymentMethod: null,
    paidAt: null,
    totalAmount: order.totalAmount,
    currency,
    createdAt: order.createdAt,
    orderSnapshot: { items: order.items.map((item) => ({ productName: item.productName, quantity: item.quantity, amount: item.amount })) },
  }
}

function mergeSessionState(orders: ReportingOrder[], tables: Awaited<ReturnType<StoreApi['allCounterSessions']>>): ReportingOrder[] {
  const sessionOrders = new Map<string, SessionOrder>()
  for (const table of tables) {
    if (!table.ordersVisible) continue
    for (const order of table.orders) sessionOrders.set(order.uuid, order)
  }
  return orders.map((order) => {
    const live = order.tradeOrderUuid ? sessionOrders.get(order.tradeOrderUuid) : undefined
    if (!live) return order
    const snapshot = order.orderSnapshot && typeof order.orderSnapshot === 'object' && !Array.isArray(order.orderSnapshot)
      ? order.orderSnapshot as Record<string, unknown>
      : {}
    const items = Array.isArray(snapshot.items) ? snapshot.items : live.items.map((item) => ({ productName: item.productName, quantity: item.quantity, amount: item.amount }))
    return {
      ...order,
      status: live.status,
      paymentStatus: live.paymentStatus,
      orderSnapshot: { ...snapshot, items },
    }
  })
}

async function enrichTradeOrders(api: StoreApi, orders: ReportingOrder[], manageOrders: boolean): Promise<ReportingOrder[]> {
  const rows = orders.map((order) => ({ ...order }))
  let next = 0
  const workers = Array.from({ length: Math.min(6, rows.length) }, async () => {
    while (next < rows.length) {
      const index = next++
      const order = rows[index]!
      if (!order.tradeOrderUuid) continue
      try {
        const record = await api.tradeOrderState(order.tradeOrderUuid, manageOrders)
        if (!record) continue
        rows[index] = {
          ...order,
          status: record.status ?? order.status,
          paymentStatus: record.paymentStatus ?? order.paymentStatus,
          paymentMethod: record.paymentMethod ?? order.paymentMethod,
          paidAt: record.paidAt ?? order.paidAt,
          totalAmount: record.totalAmount ?? order.totalAmount,
          currency: record.currency ?? order.currency,
        }
      } catch {
        // A payment detail can be unavailable to a store-scoped reader. Keep the operational row.
      }
    }
  })
  await Promise.all(workers)
  return rows
}

async function loadReportingOrders(api: StoreApi, access: StoreAccess, reportingDay: number): Promise<ReportingOrder[]> {
  const canReadOrders = can(access, 'order', 'read')
  const canReadSessions = can(access, 'counter_session', 'read')
  const emptyOrders = Promise.resolve({ items: [] as LiveOrder[] })
  const emptySessions = Promise.resolve([] as Awaited<ReturnType<StoreApi['allCounterSessions']>>)
  const [orders, tables] = await Promise.all([
    canReadOrders ? api.allOrders() : emptyOrders,
    canReadSessions ? api.allCounterSessions().catch(() => emptySessions) : emptySessions,
  ])
  const operational: ReportingOrder[] = mergeSessionState(orders.items, tables)
  if (canReadOrders) {
    const today = new Date()
    const loadWindow = reportingLoadWindow(reportingDay, today, access.store.timezone)
    const candidates = operational.filter((order) => {
      const created = new Date(order.createdAt)
      if (Number.isNaN(created.getTime())) return false
      const key = reportDateKey(created, access.store.timezone)
      return key >= loadWindow.start && key <= loadWindow.end
    })
    const enriched = new Map((await enrichTradeOrders(api, candidates, access.profile.roles.includes('ROLE_ADMIN'))).map((order) => [order.uuid, order]))
    return operational.map((order) => enriched.get(order.uuid) ?? order)
  }
  return tables.flatMap((table) => table.ordersVisible ? table.orders.map((order) => counterLabel(order, table.currency)) : [])
}

function formatDateKey(key: string, locale: string): string {
  const date = new Date(`${key}T12:00:00Z`)
  return new Intl.DateTimeFormat(locale, { timeZone: 'UTC', month: '2-digit', day: '2-digit' }).format(date)
}

const paymentOrder: ReportPaymentMethod[] = ['wechat', 'alipay', 'cash', 'other', 'unrecorded']

export function LiveReports({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t, locale } = useI18n()
  const [range, setRange] = useState<ReportRange>('today')
  const reportingDay = useMemo(() => readReportingDay(access.store.uuid), [access.store.uuid])
  const load = useCallback(async () => ({
    items: await loadReportingOrders(api, access, reportingDay),
    page: 1,
    hasNext: false,
  }), [access, api, reportingDay])
  const list = usePage(load)
  const summary = useMemo(() => summarizeReport(list.items, range, reportingDay, new Date(), access.store.timezone), [access.store.timezone, list.items, range, reportingDay])
  const money = (cents: number) => new Intl.NumberFormat(locale, { style: 'currency', currency: access.store.currency }).format(cents / 100)
  const payments = paymentOrder.flatMap((method) => {
    const row = summary.payments.find((payment) => payment.method === method)
    return row ? [row] : []
  })
  const rangeLabel = `${formatDateKey(summary.window.start, locale)} - ${formatDateKey(summary.window.end, locale)}`
  const canReadOrders = can(access, 'order', 'read')
  return <>
    <PageHeading eyebrow="reportEyebrow" title="reportTitle" subtitle="reportLiveSubtitle" />
    <div className="segmented">{(['today', 'week', 'month'] as const).map((value) => <button key={value} className={range === value ? 'active' : ''} onClick={() => setRange(value)}>{t(value)}</button>)}</div>
    <ListFeedback state={{ ...list, items: summary.orders }} empty="noReportOrders" hint={canReadOrders ? 'noReportOrdersHint' : 'reportSessionScopeHint'} icon={ChartNoAxesCombined} />
    {!list.loading && !list.error && <>
      <section className="report-hero" aria-label={t('reportRevenue')}>
        <p>{t('reportRevenue')}</p>
        <h2>{money(summary.totalAmount)}</h2>
        <span><CalendarDays size={13} />{t(range)} · {rangeLabel}</span>
        <div className="report-hero-metrics report-live-metrics">
          <div><ReceiptText size={18} /><p>{t('settledOrders')}</p><strong>{summary.settledOrders.length}</strong></div>
          <div><ChartNoAxesCombined size={18} /><p>{t('pendingOrders')}</p><strong>{summary.pendingOrders.length}</strong></div>
          <div><ReceiptText size={18} /><p>{t('avgOrder')}</p><strong>{money(summary.averageOrder)}</strong></div>
        </div>
      </section>
      <p className="workspace-report-hint">{t('reportScopeHint')}</p>
      {!canReadOrders && <p className="workspace-report-hint">{t('reportSessionScopeHint')}</p>}
      <section className="surface-card workspace-report-card"><div className="section-heading"><h2>{t('salesRanking')}</h2><span className="section-mark">TOP PRODUCTS</span></div>
        {summary.products.length ? <div className="sales-ranking">{summary.products.map((item, index) => <div key={item.name}><span className="rank-number">{String(index + 1).padStart(2, '0')}</span><div><h3>{item.name}</h3><div className="rank-track"><span style={{ width: `${item.quantity / summary.products[0]!.quantity * 100}%` }} /></div></div><strong>{item.quantity}<small>{t('portion')}</small></strong></div>)}</div>
          : <p className="workspace-report-empty">{t('noSales')}</p>}
      </section>
      <section className="surface-card workspace-report-card"><div className="section-heading"><h2>{t('paymentMix')}</h2><span className="section-mark">{t('settledOrders')}</span></div>
        {payments.length ? <div className="payment-mix">{payments.map(({ method, amount }) => <div key={method}><span className={`method-dot ${method === 'unrecorded' || method === 'other' ? 'unknown' : method}`} />{t(method)}<strong>{money(amount)}</strong></div>)}</div> : <p className="workspace-report-empty">{t('noRecordedPayments')}</p>}
      </section>
      <p className="workspace-report-hint">{t('reportDataHint')}</p>
    </>}
  </>
}
