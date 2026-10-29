import { useState } from 'react'
import { Button, Dialog } from 'antd-mobile'
import { ArrowRight, CircleCheck, CircleHelp, Copy, ExternalLink, Plus, Printer, ShieldCheck } from 'lucide-react'
import type { Table } from '../domain'
import { due, parseAmount, subtotal } from '../domain'
import type { StoreState } from '../use-store'
import { label, money, time, useI18n } from '../../shared/i18n'
import { paymentUrl } from '../../shared/demo-payment'
import { Empty, Sheet, Stepper, Submit } from '../../shared/ui'
import { FoodArt } from './food-art'

export function TableDetail({ table, store, onClose }: { table: Table; store: StoreState; onClose: () => void }) {
  const { t, locale } = useI18n()
  const [mode, setMode] = useState<'detail' | 'review' | 'payment'>(table.paymentId ? 'payment' : 'detail')
  const [paymentId, setPaymentId] = useState(table.paymentId)
  const [discount, setDiscount] = useState((table.discount / 100).toFixed(2))
  const [note, setNote] = useState(table.note)
  const [adding, setAdding] = useState(false)
  const [category, setCategory] = useState('all')
  const [printOpen, setPrintOpen] = useState(false)
  const [checked, setChecked] = useState(false)
  const payment = store.payments.find((item) => item.id === (table.paymentId ?? paymentId))
  const locked = !!table.paymentId
  const save = () => {
    const amount = parseAmount(discount)
    if (amount === null) { store.setNotice('invalidDiscount'); return false }
    return store.saveTable(table.id, amount, note)
  }
  const review = () => { if (save()) { setChecked(false); setMode('review') } }
  const generate = () => {
    const payment = store.checkout(table.id)
    if (payment) { setPaymentId(payment.id); setMode('payment') }
  }
  const copy = async () => {
    if (!payment) return
    try { await navigator.clipboard.writeText(paymentUrl(payment.id)); store.setNotice('copied') }
    catch { store.setNotice('copyFailed') }
  }
  const paymentPending = payment && ['processing', 'unknown'].includes(payment.status)
  return <>
    <Sheet open onClose={onClose} title={`${table.name} · ${t(mode === 'review' ? 'checkoutTitle' : mode === 'payment' ? 'paymentLink' : 'tableDetail')}`} footer={
      mode === 'detail' ? <div className="flex gap-3"><Button disabled={!store.online || locked} onClick={() => { if (save()) store.setNotice('orderSaved') }}>{t('save')}</Button><div className="flex-1"><Submit disabled={!store.online || !table.lines.length || locked || subtotal(table.lines) <= 0} onClick={review}>{t('checkout')} <ArrowRight size={16} className="inline" /></Submit></div></div>
        : mode === 'review' ? <Submit disabled={!store.online || !checked || due(table) <= 0} onClick={generate}>{t('generatePayment')}</Submit>
          : <Button block onClick={onClose}>{t('close')}</Button>
    }>
      {mode === 'detail' && <>
        <div className="table-detail-summary"><span className="pill">{t(table.lines.length ? 'dining' : 'idle')}</span><span>{table.guests || table.capacity} {t('guestUnit')}</span><span className="ml-auto text-brand font-semibold">{money(due(table))}</span></div>
        <div className="section-heading"><h3>{t('orderDetails')}</h3><button className="text-action" disabled={locked || !store.online} onClick={() => setAdding(true)}><Plus size={16} />{t('addItems')}</button></div>
        {table.lines.length ? <div className="order-line-list">{table.lines.map((line) => <div className="order-line" key={line.id}><div><h4>{label(line.name, t)}</h4><p>{label(line.variant, t)} · {money(line.unitPrice)}</p></div><Stepper value={line.quantity} disabled={locked || !store.online} onChange={(quantity) => store.changeQuantity(table.id, line.id, quantity)} /></div>)}</div> : <Empty title={t('noItems')} hint={t('noItemsHint')} />}
        <div className="bill-total"><span>{t('subtotal')}</span><strong>{money(subtotal(table.lines))}</strong></div>
        <label className="field mt-5">{t('discount')}<div className="amount-input"><span>¥</span><input inputMode="decimal" value={discount} disabled={locked} onChange={(event) => setDiscount(event.target.value)} aria-label={t('discount')} /></div><small>{t('discountHint')}</small></label>
        <label className="field mt-5">{t('orderNote')}<textarea rows={2} maxLength={200} disabled={locked} value={note} placeholder={t('notePlaceholder')} onChange={(event) => setNote(event.target.value)} /></label>
        <div className="mt-6 flex justify-between gap-2"><Button fill="none" onClick={() => setPrintOpen(true)}><Printer size={16} className="inline" /> {t('print')}</Button><Button color="danger" fill="none" disabled={locked || !store.online || !table.lines.length} onClick={async () => { if (await Dialog.confirm({ title: t('cancelBillTitle'), content: t('cancelBillHint'), confirmText: t('confirm'), cancelText: t('cancel') })) { store.cancelOrder(table.id); onClose() } }}>{t('cancelBill')}</Button></div>
      </>}
      {mode === 'review' && <>
        <div className="checkout-steps"><span className="active">01 {t('checkItems')}</span><span>02 {t('checkAmount')}</span></div>
        <div className="review-lines">{table.lines.map((line) => <div key={line.id}><span>{label(line.name, t)} <small>×{line.quantity}</small></span><strong>{money(line.unitPrice * line.quantity)}</strong></div>)}</div>
        <div className="notice-card mt-5"><ShieldCheck size={20} /><div><strong>{t('checkExtras')}</strong><p>{t('checkExtrasHint')}</p></div></div>
        <div className="bill-total mt-5"><span>{t('subtotal')}</span><strong>{money(subtotal(table.lines))}</strong></div><div className="bill-total"><span>{t('discount')}</span><strong>−{money(table.discount)}</strong></div><div className="bill-total final"><span>{t('amountDue')}</span><strong>{money(due(table))}</strong></div>
        <label className="confirm-check"><input type="checkbox" checked={checked} onChange={(event) => setChecked(event.target.checked)} /><span>{t('checkAmount')} · {t('demo')}</span></label><button className="text-action mt-4" onClick={() => setMode('detail')}>{t('back')}</button>
      </>}
      {mode === 'payment' && payment && <div className="payment-link-view">
        <div className={`payment-symbol ${payment.status === 'paid' ? 'paid' : ''}`}>{payment.status === 'paid' ? <CircleCheck size={46} strokeWidth={1.3} /> : <CircleHelp size={46} strokeWidth={1.3} />}</div>
        <h3>{t(payment.status === 'paid' ? 'paymentSuccess' : paymentPending ? 'paymentUnknown' : 'paymentLink')}</h3><p className="helper">{t(payment.status === 'paid' ? 'paymentThanks' : 'paymentLinkHint')}</p><strong className="payment-amount">{money(payment.total)}</strong>
        {payment.status !== 'paid' && <>
          <div className="link-box">{paymentUrl(payment.id)}</div><p className="helper mb-5">{t('expires')} {time(payment.expiresAt, locale)}</p>
          <a className="primary-link" href={paymentUrl(payment.id)} target="_blank" rel="noopener noreferrer">{t('openPayment')}<ExternalLink size={16} /></a>
          <Button block fill="outline" className="mt-3" onClick={() => void copy()}><Copy size={16} className="inline" /> {t('copyLink')}</Button>
          <Button block fill="none" disabled={!store.online} className="mt-3" onClick={() => store.lookup(table.id)}>{t('lookup')}</Button>
          {!paymentPending && <Button block color="danger" fill="none" disabled={!store.online} onClick={() => { store.cancelPayment(table.id); onClose() }}>{t('cancelPayment')}</Button>}
          {paymentPending && <p className="helper">{t('paymentLocked')}</p>}
        </>}
      </div>}
    </Sheet>
    <Sheet open={adding} onClose={() => setAdding(false)} title={`${t('addItems')} · ${table.name}`} footer={<Submit onClick={() => setAdding(false)}>{t('confirm')}</Submit>}>
      <div className="chips mb-4"><button className={category === 'all' ? 'active' : ''} onClick={() => setCategory('all')}>{t('all')}</button>{store.categories.map((item) => <button key={item.id} className={category === item.id ? 'active' : ''} onClick={() => setCategory(item.id)}>{label(item.name, t)}</button>)}</div>
      <div className="add-menu">{store.products.filter((product) => category === 'all' || product.categoryId === category).map((product) => <article key={product.id}>
        <FoodArt category={product.categoryId} cover={product.cover} /><div className="flex-1 min-w-0"><h3>{label(product.name, t)}</h3>{product.variants.map((variant) => <div className="menu-variant" key={variant.id}><span>{label(variant.name, t)} <strong>{money(variant.price)}</strong></span><button className="icon-button" disabled={product.soldOut || locked || !store.online} aria-label={`${t('addItems')} ${label(product.name, t)} ${label(variant.name, t)}`} onClick={() => store.addItem(table.id, product.id, variant.id)}>{product.soldOut ? <span className="text-[10px]">{t('soldOut')}</span> : <Plus size={18} />}</button></div>)}</div>
      </article>)}</div>
    </Sheet>
    <Sheet open={printOpen} onClose={() => setPrintOpen(false)} title={t('printTitle')}>
      <p className="helper mb-5">{t('printUnavailable')}</p><div className="space-y-3">{(['printKitchen', 'printCut', 'printReceipt'] as const).map((key) => <Button block disabled key={key}><Printer size={16} className="inline" /> {t(key)}</Button>)}</div>
    </Sheet>
  </>
}
