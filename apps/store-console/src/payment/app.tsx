import { useEffect, useRef, useState } from 'react'
import { Button } from 'antd-mobile'
import { Check, CircleCheck, CircleHelp, CookingPot, LoaderCircle, ShieldCheck, XCircle } from 'lucide-react'
import { collectPayment, readPayment, resolvePayment, subscribePayments, type DemoOutcome, type DemoPayment } from '../shared/demo-payment'
import { money, time, useI18n } from '../shared/i18n'
import { useOnline } from '../shared/use-online'

export function PaymentApp() {
  const { t, locale, setLocale } = useI18n()
  const id = new URLSearchParams(window.location.search).get('session') ?? ''
  const [payment, setPayment] = useState<DemoPayment | null>(null)
  const [storageError, setStorageError] = useState(false)
  const [now, setNow] = useState(Date.now)
  const [method, setMethod] = useState<'wechat' | 'alipay'>('wechat')
  const [outcome, setOutcome] = useState<DemoOutcome>('success')
  const [busy, setBusy] = useState(false)
  const lock = useRef(false)
  const online = useOnline()
  useEffect(() => {
    const refresh = () => {
      try { setPayment(readPayment(id)); setStorageError(false) }
      catch { setStorageError(true) }
    }
    refresh()
    const unsubscribe = subscribePayments(refresh)
    const timer = setInterval(() => { setNow(Date.now()); refresh() }, 2000)
    return () => { unsubscribe(); clearInterval(timer) }
  }, [id])
  const collect = async () => {
    if (lock.current || !navigator.onLine) return
    lock.current = true; setBusy(true)
    try { setPayment(await collectPayment(id, method, outcome)) }
    catch { setStorageError(true) }
    finally { lock.current = false; setBusy(false) }
  }
  const lookup = () => {
    if (!online || lock.current) return
    try { setPayment(resolvePayment(id)) } catch { setStorageError(true) }
  }
  const unknown = payment && ['processing', 'unknown'].includes(payment.status)
  const expired = payment && (payment.status === 'void' || (payment.expiresAt <= now && !['paid', 'processing', 'unknown'].includes(payment.status)))
  return <main className="mobile-app customer-payment">
    <header className="payment-header"><span className="brand-mark"><CookingPot size={22} strokeWidth={1.4} /></span><strong>{payment?.storeName ?? t('store')}</strong><button className="locale-button" onClick={() => setLocale(locale === 'en' ? 'zh-CN' : 'en')}>{locale === 'en' ? t('zh') : t('en')}</button></header>
    <div className="customer-hero"><p className="eyebrow">A GOOD MEAL, A WARM GOODBYE</p><h1>{t('paymentTitle')}</h1><p>{t('paymentSubtitle')}</p></div>
    <div className="customer-demo"><ShieldCheck size={14} />{t('customerDemo')}</div>
    {!online && <p className="offline-banner" role="alert">{t('offline')}</p>}
    {storageError && <p className="error-card" role="alert">{t('paymentStorageHint')}</p>}
    {!payment || expired ? <div className="customer-empty"><CircleHelp size={46} strokeWidth={1.2} /><h2>{t(expired ? 'paymentExpired' : 'paymentMissing')}</h2><p>{t('paymentMissingHint')}</p></div>
      : payment.status === 'paid' ? <section className="customer-result"><span className="result-icon"><CircleCheck size={50} strokeWidth={1.3} /></span><h2>{t('paymentSuccess')}</h2><p>{t('paymentThanks')}</p><strong>{money(payment.total)}</strong><small>DEMO-{payment.id.slice(0, 8).toUpperCase()}</small></section>
        : <section className="customer-bill">
          <div className="customer-bill-head"><h2>{t('yourBill')}</h2><span>{payment.tableName}</span></div>
          <div className="review-lines">{payment.lines.map((line, index) => <div key={index}><div><span>{line.name}</span><p>{line.variant} × {line.quantity}</p></div><strong>{money(line.unitPrice * line.quantity)}</strong></div>)}</div>
          <div className="bill-total"><span>{t('discount')}</span><strong>−{money(payment.discount)}</strong></div>
          <div className="customer-due"><span>{t('amountDue')}</span><strong>{money(payment.total)}</strong></div>
          {unknown ? <div className="unknown-panel" aria-live="polite">{busy ? <LoaderCircle className="animate-spin" size={25} /> : <CircleHelp size={25} />}<h3>{t(busy ? 'paymentProcessing' : 'paymentUnknown')}</h3><p>{t('paymentUnknownHint')}</p><Button block color="primary" loading={busy} disabled={!online || busy} onClick={lookup}>{t('lookup')}</Button></div>
            : <>
              {payment.status === 'failed' && <div className="payment-failure" role="alert"><XCircle size={18} /><div><strong>{t('paymentFailed')}</strong><p>{t('paymentFailedHint')}</p></div></div>}
              <h3 className="field-title mt-6">{t('payMethod')}</h3><div className="payment-methods">{(['wechat', 'alipay'] as const).map((value) => <button key={value} aria-pressed={method === value} className={method === value ? 'active' : ''} onClick={() => setMethod(value)}><span className={`method-logo ${value}`}>{value === 'wechat' ? 'W' : 'A'}</span>{t(value)}{method === value && <Check size={16} />}</button>)}</div>
              <Button block size="large" color="primary" loading={busy} disabled={!online || busy} onClick={() => void collect()}>{t('payNow')} · {money(payment.total)}</Button>
              <label className="demo-outcome">{t('demoTools')}<select aria-label={t('demoTools')} value={outcome} onChange={(event) => setOutcome(event.target.value as DemoOutcome)}><option value="success">{t('success')}</option><option value="failure">{t('failure')}</option><option value="timeout">{t('timeout')}</option></select></label>
            </>}
          <p className="customer-expiry">{t('expires')} {time(payment.expiresAt, locale)}</p>
        </section>}
    <footer className="customer-payment-footer"><ShieldCheck size={15} /><p>{t('paymentScopeHint')}</p><small>{t('noRealCode')}</small></footer>
  </main>
}
