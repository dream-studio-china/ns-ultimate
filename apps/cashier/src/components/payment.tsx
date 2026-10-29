import { useEffect, useState } from 'react'
import { ArrowRight, Banknote, Check, CircleCheck, CircleHelp, CircleX, Delete, LoaderCircle, QrCode, ScanLine, ShieldCheck } from 'lucide-react'
import type { CashierState } from '../hooks/use-cashier'
import type { DemoOrder, PaymentMethod, PaymentOutcome } from '../adapters/gateway'
import { count, parseCash, total } from '../domain/cart'
import { useI18n, money } from '../lib/i18n'
import { cn } from '../lib/utils'
import { Button } from './ui/button'
import { Dialog } from './ui/dialog'

const methods = [{ id: 'cash', icon: Banknote }, { id: 'customerScan', icon: QrCode }, { id: 'merchantScan', icon: ScanLine }] as const
export function PaymentDialog({ state, onReceipt }: { state: CashierState; onReceipt: (order: DemoOrder) => void }) {
  const { t } = useI18n()
  const [method, setMethod] = useState<PaymentMethod>('cash')
  const [cash, setCash] = useState('')
  const [outcome, setOutcome] = useState<PaymentOutcome>('success')
  const [checking, setChecking] = useState(false)
  const amount = total(state.basket.lines)
  const received = parseCash(cash)
  useEffect(() => { if (state.paymentOpen && state.paymentStatus === 'idle') { setCash((amount / 100).toFixed(2)); setOutcome('success') } }, [state.paymentOpen, amount, state.paymentStatus])
  const key = (value: string) => {
    if (value === 'backspace') { setCash((current) => current.slice(0, -1)); return }
    if (value === 'clear') { setCash(''); return }
    setCash((current) => {
      const next = (current === '0' && value !== '.' ? '' : current) + value
      return /^\d{0,7}(\.\d{0,2})?$/.test(next) ? next : current
    })
  }
  const status = state.paymentStatus
  const resolvedView = status === 'success' || status === 'unknown' || status === 'pending'
  return <Dialog open={state.paymentOpen} onOpenChange={(open) => { if (!open) state.closePayment() }} locked={status === 'pending' || status === 'unknown'} title={t('payment')} description={t('paymentDescription')} className={resolvedView ? 'max-w-lg' : 'max-w-[860px]'}>
    {resolvedView ? <div className="py-3 text-center" aria-live="polite"><div className={cn('mx-auto mb-6 flex size-24 items-center justify-center rounded-full', status === 'success' ? 'bg-primary/10 text-primary' : status === 'unknown' ? 'bg-amber-50 text-amber-600' : 'bg-secondary text-primary')}>{status === 'success' ? <CircleCheck className="size-12" strokeWidth={1.5} /> : status === 'unknown' ? <CircleHelp className="size-12" strokeWidth={1.5} /> : <LoaderCircle className="size-12 animate-spin" strokeWidth={1.5} />}</div><h3 className="text-xl font-semibold">{t(status === 'success' ? 'paymentSuccess' : status === 'unknown' ? 'paymentUnknown' : 'processing')}</h3><p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-muted-foreground">{t(status === 'success' ? 'paymentSuccessHint' : status === 'unknown' ? 'paymentUnknownHint' : 'pendingHint')}</p><p className="my-7 text-4xl font-semibold tabular-nums">{money(amount)}</p>
      {state.currentOrder && <p className="mb-6 font-mono text-xs text-muted-foreground">{state.currentOrder.id}</p>}
      {status === 'unknown' && <Button className="w-full" disabled={!state.online || checking} onClick={async () => { setChecking(true); await state.checkPayment(); setChecking(false) }}>{checking && <LoaderCircle className="animate-spin" />}{t('checkPayment')}</Button>}
      {status === 'success' && <div className="space-y-3"><Button className="w-full" size="lg" onClick={state.finish}>{t('finishOrder')}<ArrowRight /></Button><Button variant="outline" className="w-full" onClick={() => { if (state.currentOrder) onReceipt(state.currentOrder) }}>{t('receipt')}</Button></div>}
    </div> : <>
      {status === 'failed' && <div className="mb-5 flex gap-3 rounded-xl bg-red-50 p-4 text-sm text-red-700" role="alert"><CircleX className="size-5 shrink-0" /><div><strong>{t('paymentFailed')}</strong><p className="mt-1 text-xs">{t('paymentFailedHint')}</p></div></div>}
      <div className="grid gap-7 md:grid-cols-[1fr_1.05fr]"><div><div className="mb-6 rounded-2xl bg-secondary/70 p-5"><p className="text-xs text-muted-foreground">{t('total')}</p><p className="mt-2 text-4xl font-semibold tracking-tight tabular-nums">{money(amount)}</p><p className="mt-3 text-xs text-muted-foreground">{count(state.basket.lines)} {t('itemCount')} · {t(state.basket.service)}</p></div><p className="field-label">{t('paymentMethod')}</p><div className="space-y-2">{methods.map(({ id, icon: Icon }) => <button key={id} aria-pressed={method === id} onClick={() => setMethod(id)} className={cn('choice-button flex w-full items-center gap-3 text-left', method === id && 'selected')}><Icon className="size-5" /><span className="flex-1">{t(id)}</span>{method === id && <Check className="size-4" />}</button>)}</div><p className="mt-5 text-xs leading-relaxed text-muted-foreground">{t(method === 'cash' ? 'cashHint' : method === 'customerScan' ? 'customerScanHint' : 'merchantScanHint')}</p><div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-relaxed text-amber-800"><ShieldCheck className="mr-1 inline size-4" />{t('demo')}</div></div>
      <div>{method === 'cash' ? <><label className="field-label" htmlFor="cash-received">{t('received')}</label><div className="relative"><span className="absolute left-4 top-1/2 -translate-y-1/2 text-xl text-muted-foreground">¥</span><input id="cash-received" autoComplete="off" inputMode="decimal" className="text-input h-16 w-full pl-10 text-right text-3xl font-semibold tabular-nums" value={cash} onChange={(event) => { const value = event.target.value; if (/^\d{0,7}(\.\d{0,2})?$/.test(value)) setCash(value) }} /></div><div className="mt-3 grid grid-cols-3 gap-2">{[50, 100].map((value) => <Button key={value} variant="secondary" disabled={value * 100 < amount} onClick={() => setCash(value.toFixed(2))}>¥{value}</Button>)}<Button variant="secondary" onClick={() => setCash((amount / 100).toFixed(2))}>{t('exact')}</Button></div><div className="mt-3 grid grid-cols-3 gap-2">{['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0', 'backspace'].map((value) => <button key={value} className="keypad-button" onClick={() => key(value)} aria-label={value === 'backspace' ? t('delete') : value}>{value === 'backspace' ? <Delete className="mx-auto size-5" /> : value}</button>)}</div><div className="mt-4 flex justify-between rounded-xl bg-secondary px-4 py-3 text-sm"><span className="text-muted-foreground">{t('change')}</span><strong className="tabular-nums">{money(Math.max(0, (received ?? 0) - amount))}</strong></div></> : <div className="flex min-h-[350px] flex-col items-center justify-center rounded-2xl border border-dashed border-border bg-secondary/40 p-6 text-center"><div className="mb-5 flex size-28 items-center justify-center rounded-3xl bg-white text-primary/40">{method === 'customerScan' ? <QrCode className="size-16" strokeWidth={1} /> : <ScanLine className="size-16" strokeWidth={1} />}</div><p className="text-sm font-medium">{t(method === 'customerScan' ? 'noRealQr' : 'noRealCode')}</p><span className="mt-4 rounded-md bg-amber-100 px-3 py-1 text-xs text-amber-800">{t('demo')}</span></div>}</div></div>
      <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-border pt-5"><label className="text-xs text-muted-foreground" htmlFor="demo-outcome">{t('demoResult')}</label><select id="demo-outcome" className="text-input mr-auto text-xs" value={outcome} onChange={(event) => setOutcome(event.target.value as PaymentOutcome)}><option value="success">{t('resultSuccess')}</option><option value="failed">{t('resultFailed')}</option><option value="unknown">{t('resultUnknown')}</option></select><Button variant="outline" onClick={state.closePayment}>{t('backToCart')}</Button><Button disabled={!state.online || (method === 'cash' && (received === null || received < amount))} onClick={() => void state.collect(method, method === 'cash' ? received! : amount, outcome)}>{t('simulatePayment')}<ArrowRight /></Button></div>
      {method === 'cash' && received !== null && received < amount && <p className="mt-2 text-right text-xs text-destructive">{t('insufficient')}</p>}
    </>}
  </Dialog>
}
