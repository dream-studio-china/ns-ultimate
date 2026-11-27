import { useEffect, useMemo, useState } from 'react'
import { Button } from 'antd-mobile'
import { Camera, ClipboardCheck, ReceiptText, Settings2, Target, WalletCards, type LucideIcon } from 'lucide-react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, type StoreAccess, type StoreApi } from '../adapters/live'
import { loadTradeReportingOrders, ordersForRefundReporting, summarizeReport } from '../reporting'
import { loadRefundSummary } from '../refund-summary'
import { Notice, PageHeading } from './ui'

type CostTab = 'receipt' | 'fixed' | 'expense' | 'break-even'
type RevenueState =
  | { status: 'loading' }
  | { status: 'unavailable' }
  | { status: 'ready'; gross: number; refunds: number; refundFailures: number }

const expenseCategories = ['salary', 'rent', 'utility', 'maintenance', 'other_expense'] as const
const expenseLabels: Record<(typeof expenseCategories)[number], MessageKey> = {
  salary: 'costCategorySalary',
  rent: 'costCategoryRent',
  utility: 'costCategoryUtility',
  maintenance: 'costCategoryMaintenance',
  other_expense: 'costCategoryOther',
}

function BackendIssue({ id }: { id: string }) {
  const { t } = useI18n()
  return <Notice subtle><span>{t('costBackendBlocked').replace('{issue}', id)}</span></Notice>
}

export function LiveCostAnalysis({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t, locale } = useI18n()
  const [tab, setTab] = useState<CostTab>('receipt')
  const [revenue, setRevenue] = useState<RevenueState>({ status: 'loading' })
  const today = useMemo(() => new Intl.DateTimeFormat('sv-SE', { timeZone: access.store.timezone || 'UTC' }).format(new Date()), [access.store.timezone])
  const canReadOrders = can(access, 'counter_session', 'read') || can(access, 'order', 'read')

  useEffect(() => {
    let active = true
    if (!canReadOrders) {
      setRevenue({ status: 'unavailable' })
      return () => { active = false }
    }
    setRevenue({ status: 'loading' })
    void loadTradeReportingOrders(api).then(async (orders) => {
      const summary = summarizeReport(orders, 'today', 1, new Date(), access.store.timezone)
      const refunds = await loadRefundSummary(api, ordersForRefundReporting(orders, 'today', 1, new Date(), access.store.timezone))
      if (active) setRevenue({ status: 'ready', gross: summary.settledAmount, refunds: refunds.amount, refundFailures: refunds.failed })
    }).catch(() => {
      if (active) setRevenue({ status: 'unavailable' })
    })
    return () => { active = false }
  }, [access.store.timezone, api, canReadOrders])

  const money = (cents: number) => new Intl.NumberFormat(locale, { style: 'currency', currency: access.store.currency }).format(cents / 100)
  const completed = revenue.status === 'ready' ? Math.max(0, revenue.gross - revenue.refunds) : null
  const tabs: { key: CostTab; icon: LucideIcon; label: MessageKey }[] = [
    { key: 'receipt', icon: ClipboardCheck, label: 'costTabReceipt' },
    { key: 'fixed', icon: Settings2, label: 'costTabFixed' },
    { key: 'expense', icon: WalletCards, label: 'costTabExpense' },
    { key: 'break-even', icon: Target, label: 'costTabBreakEven' },
  ]

  return <>
    <PageHeading eyebrow="costEyebrow" title="costAnalysis" subtitle="costAnalysisHint" />
    <Notice>{t('costBackendNotice')}</Notice>
    <section className="cost-hero" aria-label={t('costCompleted')}>
      <div><span>{t('costCompleted')}</span><strong>{completed === null ? '—' : money(completed)}</strong><small>{today}</small></div>
      <div><span>{t('refundAmount')}</span><strong>{revenue.status === 'ready' ? money(revenue.refunds) : '—'}</strong></div>
      <div><span>{t('costBreakEven')}</span><strong>—</strong></div>
    </section>
    {revenue.status === 'unavailable' && <p className="cost-footnote" role="status">{t('costRevenueUnavailable')}</p>}
    {revenue.status === 'ready' && revenue.refundFailures > 0 && <p className="cost-footnote" role="status">{t('costRefundPartial').replace('{count}', String(revenue.refundFailures))}</p>}
    <div className="cost-tabs" role="tablist" aria-label={t('costAnalysis')}>{tabs.map(({ key, icon: Icon, label }) => <button type="button" role="tab" aria-selected={tab === key} className={tab === key ? 'active' : ''} key={key} onClick={() => setTab(key)}><Icon size={17} />{t(label)}</button>)}</div>
    {tab === 'receipt' && <ReceiptPanel />}
    {tab === 'fixed' && <FixedCostPanel />}
    {tab === 'expense' && <ExpensePanel />}
    {tab === 'break-even' && <BreakEvenPanel completed={completed} money={money} />}
  </>
}

function ReceiptPanel() {
  const { t } = useI18n()
  const today = new Intl.DateTimeFormat('sv-SE').format(new Date())
  const [date, setDate] = useState(today)
  const [amount, setAmount] = useState('')
  const [number, setNumber] = useState('')
  const [note, setNote] = useState('')
  const [photo, setPhoto] = useState<File | null>(null)
  const preview = useMemo(() => photo ? URL.createObjectURL(photo) : null, [photo])
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])
  return <section className="cost-panel">
    <header className="cost-panel-heading"><span><ClipboardCheck size={21} /></span><div><h2>{t('costReceiptTitle')}</h2><p>{t('costReceiptHint')}</p></div></header>
    <BackendIssue id="SC-FIN-001" />
    <fieldset className="cost-form" disabled>
      <label className="field">{t('costReceiptDate')}<input type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label>
      <label className="field">{t('costGoodsAmount')} · {'store currency'}<input inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="0.00" /></label>
      <label className="field">{t('costDeliveryNumber')}<input value={number} maxLength={120} onChange={(event) => setNumber(event.target.value)} /></label>
      <label className="field">{t('costDeliveryPhoto')}<span className="cost-photo-input"><Camera size={19} /><span>{photo?.name ?? t('costTakePhoto')}</span><input type="file" accept="image/png,image/jpeg,image/webp" capture="environment" onChange={(event) => setPhoto(event.target.files?.[0] ?? null)} /></span></label>
      {preview && <img className="cost-photo-preview" src={preview} alt={t('costDeliveryPhoto')} />}
      <label className="field">{t('cashNote')}<textarea value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} /></label>
      <Button block color="primary" disabled>{t('costReceiptConfirm')}</Button>
    </fieldset>
    <div className="cost-issue-list"><strong>{t('costDraftVoucher')}</strong><p>SC-FIN-001 · {t('costReceiptDraftPending')}</p></div>
  </section>
}

function FixedCostPanel() {
  const { t } = useI18n()
  const [name, setName] = useState('')
  const [category, setCategory] = useState<(typeof expenseCategories)[number]>('rent')
  const [amount, setAmount] = useState('')
  const [period, setPeriod] = useState<'daily' | 'monthly'>('monthly')
  const [startDate, setStartDate] = useState(new Intl.DateTimeFormat('sv-SE').format(new Date()))
  const [endDate, setEndDate] = useState('')
  return <section className="cost-panel">
    <header className="cost-panel-heading"><span><Settings2 size={21} /></span><div><h2>{t('costFixedTitle')}</h2><p>{t('costFixedHint')}</p></div></header>
    <BackendIssue id="SC-FIN-002" />
    <fieldset className="cost-form" disabled>
      <label className="field">{t('costFixedName')}<input value={name} maxLength={160} onChange={(event) => setName(event.target.value)} /></label>
      <label className="field">{t('costFixedCategory')}<select value={category} onChange={(event) => setCategory(event.target.value as typeof category)}>{expenseCategories.map((value) => <option key={value} value={value}>{t(expenseLabels[value])}</option>)}</select></label>
      <label className="field">{t('costFixedAmount')}<input inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="0.00" /></label>
      <label className="field">{t('costFixedPeriod')}<select value={period} onChange={(event) => setPeriod(event.target.value as typeof period)}><option value="monthly">{t('costPeriodMonthly')}</option><option value="daily">{t('costPeriodDaily')}</option></select></label>
      <label className="field">{t('costStartDate')}<input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} /></label>
      <label className="field">{t('costEndDate')}<input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} /></label>
      <Button block color="primary" disabled>{t('costFixedSave')}</Button>
    </fieldset>
    <div className="cost-issue-list"><strong>{t('costFixedList')}</strong><p>SC-FIN-002 · {t('costFixedPending')}</p></div>
  </section>
}

function ExpensePanel() {
  const { t } = useI18n()
  const [date, setDate] = useState(new Intl.DateTimeFormat('sv-SE').format(new Date()))
  const [category, setCategory] = useState<(typeof expenseCategories)[number]>('other_expense')
  const [amount, setAmount] = useState('')
  const [note, setNote] = useState('')
  return <section className="cost-panel">
    <header className="cost-panel-heading"><span><ReceiptText size={21} /></span><div><h2>{t('costExpenseTitle')}</h2><p>{t('costExpenseHint')}</p></div></header>
    <BackendIssue id="SC-FIN-003" />
    <fieldset className="cost-form" disabled>
      <label className="field">{t('cashDate')}<input type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label>
      <label className="field">{t('costFixedCategory')}<select value={category} onChange={(event) => setCategory(event.target.value as typeof category)}>{expenseCategories.map((value) => <option key={value} value={value}>{t(expenseLabels[value])}</option>)}</select></label>
      <label className="field">{t('cashAmount')}<input inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="0.00" /></label>
      <label className="field">{t('cashNote')}<textarea value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} /></label>
      <Button block color="primary" disabled>{t('costExpenseSave')}</Button>
    </fieldset>
    <div className="cost-issue-list"><strong>{t('costDraftVoucher')}</strong><p>SC-FIN-003 · {t('costExpenseDraftPending')}</p></div>
  </section>
}

function BreakEvenPanel({ completed, money }: { completed: number | null; money: (value: number) => string }) {
  const { t } = useI18n()
  return <section className="cost-panel">
    <header className="cost-panel-heading"><span><Target size={21} /></span><div><h2>{t('costBreakEvenTitle')}</h2><p>{t('costBreakEvenHint')}</p></div></header>
    <BackendIssue id="SC-FIN-004" />
    <div className="cost-break-grid">
      <div><span>{t('costCompleted')}</span><strong>{completed === null ? '—' : money(completed)}</strong></div>
      <div><span>{t('costPurchaseToday')}</span><strong>—</strong></div>
      <div><span>{t('costFixedToday')}</span><strong>—</strong></div>
      <div><span>{t('costOtherToday')}</span><strong>—</strong></div>
      <div><span>{t('costRate')}</span><strong>—</strong></div>
      <div><span>{t('costBreakEven')}</span><strong>—</strong></div>
    </div>
    <p className="cost-formula">{t('costFormula')}</p>
    <div className="cost-issue-list"><strong>{t('costCalculationStatus')}</strong><p>SC-FIN-004 · {t('costCalculationPending')}</p></div>
  </section>
}
