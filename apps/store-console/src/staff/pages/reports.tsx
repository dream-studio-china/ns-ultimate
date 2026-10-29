import { useState } from 'react'
import { ArrowUpRight, CalendarDays, ChartNoAxesCombined, ReceiptText } from 'lucide-react'
import type { StoreState } from '../use-store'
import { label, money, useI18n } from '../../shared/i18n'
import { Empty } from '../../shared/ui'

export function ReportsPage({ store }: { store: StoreState }) {
  const { t } = useI18n()
  const [range, setRange] = useState<'today' | 'week' | 'month'>('today')
  const today = new Date()
  const start = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  if (range === 'week') start.setDate(start.getDate() - (start.getDay() + 6) % 7)
  if (range === 'month') start.setDate(1)
  const receipts = store.receipts.filter((receipt) => receipt.paidAt >= start.getTime())
  const total = receipts.reduce((sum, receipt) => sum + receipt.total, 0)
  const sales = new Map<string, { name: string; quantity: number; amount: number }>()
  for (const receipt of receipts) for (const line of receipt.lines) {
    const previous = sales.get(line.productId)
    sales.set(line.productId, { name: label(line.name, t), quantity: (previous?.quantity ?? 0) + line.quantity, amount: (previous?.amount ?? 0) + line.quantity * line.unitPrice })
  }
  const ranking = [...sales.values()].sort((a, b) => b.quantity - a.quantity)
  return <>
    <div className="page-title-row"><div><p className="eyebrow">SMALL MOMENTS, REAL INSIGHTS</p><h1>{t('reportTitle')}</h1><p>{t('reportSubtitle')}</p></div><CalendarDays size={23} className="text-brand" strokeWidth={1.5} /></div>
    <div className="segmented">{(['today', 'week', 'month'] as const).map((value) => <button key={value} className={range === value ? 'active' : ''} onClick={() => setRange(value)}>{t(value)}</button>)}</div>
    <section className="report-hero"><p>{t('revenue')}</p><h2>{money(total)}</h2><span><ArrowUpRight size={14} />{t('demo')}</span><div className="report-hero-metrics"><div><ReceiptText size={18} /><p>{t('completed')}</p><strong>{receipts.length}</strong></div><div><ChartNoAxesCombined size={18} /><p>{t('avgOrder')}</p><strong>{money(receipts.length ? Math.round(total / receipts.length) : 0)}</strong></div></div></section>
    <p className="helper px-1 my-4">{t('reportRangeHint')}</p>
    <section className="surface-card"><div className="section-heading"><h2>{t('salesRanking')}</h2><span className="section-mark">TOP PRODUCTS</span></div>
      {ranking.length ? <div className="sales-ranking">{ranking.map((item, index) => <div key={item.name}><span className="rank-number">{String(index + 1).padStart(2, '0')}</span><div><h3>{item.name}</h3><div className="rank-track"><span style={{ width: `${item.quantity / ranking[0].quantity * 100}%` }} /></div></div><strong>{item.quantity}<small>{t('portion')}</small></strong></div>)}</div> : <Empty title={t('noSales')} />}
    </section>
    <section className="surface-card mt-4"><div className="section-heading"><h2>{t('paymentMix')}</h2></div><div className="payment-mix">{(['wechat', 'alipay'] as const).map((method) => <div key={method}><span className={`method-dot ${method}`} />{t(method)}<strong>{money(receipts.filter((item) => item.method === method).reduce((sum, item) => sum + item.total, 0))}</strong></div>)}</div></section>
    <p className="page-note">{t('demoReportHint')}</p>
  </>
}
