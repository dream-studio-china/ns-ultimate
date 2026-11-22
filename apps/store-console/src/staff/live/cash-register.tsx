import { useCallback, useEffect, useRef, useState } from 'react'
import { Button } from 'antd-mobile'
import { can, type StoreAccess, type StoreApi } from '../adapters/live'
import { ApiError } from '../adapters/api-client'
import { cashMinor, cashMoney, readCashCategories, readCashMonth, recordCash, type CashCategoryOption, type CashInput, type CashMonth, type CashUnit } from '../adapters/cash-register'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { errorKey, Notice, PageHeading } from './ui'

const legacyCategoryLabels: Record<string, MessageKey> = {
  purchase: 'cashPurchase', transport: 'cashTransport', maintenance: 'cashMaintenance',
  other_expense: 'cashOtherExpense', other_income: 'cashOtherIncome'
}

export function LiveCashRegister({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const today = new Intl.DateTimeFormat('sv-SE', { timeZone: access.store.timezone ?? 'UTC' }).format(new Date())
  const [month, setMonth] = useState(today.slice(0, 7))
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<CashMonth | null>(null)
  const [readError, setReadError] = useState<MessageKey | null>(null)
  const [writeError, setWriteError] = useState<MessageKey | null>(null)
  const [generation, refresh] = useState(0)
  const [editing, setEditing] = useState(false)
  const [busy, setBusy] = useState(false)
  const [pending, setPending] = useState<CashInput | null>(null)
  const mutex = useRef(false)
  const [direction, setDirection] = useState<'income' | 'expense'>('expense')
  const [category, setCategory] = useState('')
  const [categoryOptions, setCategoryOptions] = useState<CashCategoryOption[]>([])
  const [date, setDate] = useState(today)
  const [amount, setAmount] = useState('')
  const [note, setNote] = useState('')
  const [item, setItem] = useState('')
  const [quantity, setQuantity] = useState('')
  const [unit, setUnit] = useState<CashUnit>('g')
  const [reference, setReference] = useState('')

  const request = useCallback(() => readCashMonth(api, month, page), [api, month, page])
  useEffect(() => {
    let active = true
    void readCashCategories(api).then(({ categories }) => {
      if (!active) return
      setCategoryOptions(categories)
      const nextDirection = categories.some(option => option.direction === direction)
        ? direction
        : categories[0]?.direction ?? direction
      setDirection(nextDirection)
      setCategory(current => categories.some(option => option.id === current && option.direction === nextDirection)
        ? current
        : categories.find(option => option.direction === nextDirection)?.id ?? '')
    })
      .catch(() => { if (active) setCategoryOptions([]) })
    return () => { active = false }
  }, [api, direction])
  const categories = categoryOptions.filter(option => option.direction === direction)
  const categoryName = (id: string) => {
    const name = categoryOptions.find(option => option.id === id)?.name
    return name === id && legacyCategoryLabels[id] ? t(legacyCategoryLabels[id]) : name ?? id
  }
  useEffect(() => {
    let active = true
    setResult(null)
    setReadError(null)
    void request().then(data => {
      if (active) setResult(data)
    }).catch((error: unknown) => {
      if (active) setReadError(errorKey(error))
    })
    return () => { active = false }
  }, [request, generation])

  async function save(entry: CashInput) {
    if (mutex.current) return
    mutex.current = true
    setBusy(true); setWriteError(null); setPending(entry)
    try {
      await recordCash(api, entry)
      setPending(null); setEditing(false); setAmount(''); setNote(''); setItem(''); setQuantity(''); setReference('')
      setMonth(entry.date.slice(0, 7)); setPage(1); refresh(value => value + 1)
    } catch (error) {
      if (error instanceof ApiError && error.status >= 400 && error.status < 500) setPending(null)
      setWriteError(errorKey(error))
    } finally {
      mutex.current = false; setBusy(false)
    }
  }

  return <>
    <PageHeading eyebrow="scopedAccess" title="cashRegister" subtitle="cashRegisterHint" />
    {can(access, 'cash_entry', 'create') && !editing && <Button color="primary" block onClick={() => setEditing(true)}>{t('cashAdd')}</Button>}
    {editing && <form className="workspace-panel" onSubmit={event => {
      event.preventDefault()
      if (mutex.current || pending) return
      const minor = cashMinor(amount)
      if (!minor || !categories.some(option => option.id === category) || (category === 'purchase' && (!item.trim() || !/^(?:0|[1-9]\d{0,8})(?:\.\d{1,3})?$/.test(quantity) || !/[1-9]/.test(quantity) || (unit === 'piece' && quantity.includes('.'))))) {
        setWriteError('cashInvalid'); return
      }
      const bytes = crypto.getRandomValues(new Uint8Array(16))
      bytes[6] = (bytes[6]! & 15) | 64; bytes[8] = (bytes[8]! & 63) | 128
      const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')
      const commandUuid = `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
      void save({ commandUuid, date, direction, category, amount: minor, note, metadata: {
        version: 1, ...(reference.trim() ? { reference: reference.trim() } : {}),
        ...(category === 'purchase' ? { item: item.trim(), quantity, unit } : {}),
      } })
    }}>
      <fieldset disabled={busy || !!pending} style={{ border: 0, padding: 0, margin: 0 }}>
        <label className="field">{t('cashDirection')}<select value={direction} onChange={event => {
          const value = event.target.value === 'income' ? 'income' : 'expense'
          setDirection(value); setCategory(categoryOptions.find(option => option.direction === value)?.id ?? '')
        }}><option value="expense">{t('cashExpense')}</option><option value="income">{t('cashIncome')}</option></select></label>
        <label className="field">{t('cashCategory')}<select required value={category} onChange={event => setCategory(event.target.value)}>{categories.map(value => <option key={value.id} value={value.id}>{value.name}</option>)}</select></label>
        <label className="field">{t('cashDate')}<input required type="date" value={date} onChange={event => setDate(event.target.value)} /></label>
        <label className="field">{t('cashAmount')} · {access.store.currency}<input required inputMode="decimal" value={amount} maxLength={22} onChange={event => setAmount(event.target.value)} /></label>
        {category === 'purchase' && <>
          <label className="field">{t('cashItem')}<input required value={item} maxLength={160} onChange={event => setItem(event.target.value)} /></label>
          <label className="field">{t('cashQuantity')}<input required inputMode="decimal" value={quantity} maxLength={13} onChange={event => setQuantity(event.target.value.trim())} /></label>
          <label className="field">{t('cashUnit')}<select value={unit} onChange={event => setUnit(event.target.value as CashUnit)}>{(['g', 'kg', 'ml', 'l', 'piece'] as const).map(value => <option key={value} value={value}>{value === 'piece' ? t('cashPiece') : value}</option>)}</select></label>
          <label className="field">{t('cashReference')}<input value={reference} maxLength={120} onChange={event => setReference(event.target.value)} /></label>
        </>}
        <label className="field">{t('cashNote')}<textarea value={note} maxLength={500} onChange={event => setNote(event.target.value)} /></label>
        <Button type="submit" color="primary" block loading={busy}>{t('cashSave')}</Button>
      </fieldset>
      {writeError && <p role="alert">{t(writeError)}</p>}
      {pending && !busy && <><Notice>{t('cashUnknown')}</Notice><Button block onClick={() => void save(pending)}>{t('cashRetry')}</Button></>}
      {!pending && <Button block fill="none" onClick={() => setEditing(false)}>{t('cashCancel')}</Button>}
    </form>}
    <label className="field">{t('cashMonth')}<input type="month" value={month} onChange={event => {
      if (event.target.value) { setMonth(event.target.value); setPage(1) }
    }} /></label>
    {readError ? <><p role="alert">{t(readError)}</p><Button onClick={() => refresh(value => value + 1)}>{t('cashRetry')}</Button></> : !result ? <p role="status">{t('loading')}</p> : <>
      {result.totals.map(total => <section className="workspace-panel" key={total.currency}>
        <p>{t('cashIncome')} <strong>{cashMoney(total.income, total.currency)}</strong></p>
        <p>{t('cashExpense')} <strong>{cashMoney(total.expense, total.currency)}</strong></p>
        {Object.entries(total.byCategory).map(([key, value]) => <p key={key}>{categoryName(key)} · {cashMoney(value, total.currency)}</p>)}
      </section>)}
      {result.entries.length ? <ul className="operation-records">{result.entries.map(entry => <li key={entry.uuid}>
         <strong>{categoryName(entry.category)} · {cashMoney(entry.amount, entry.currency)}</strong>
        <p>{entry.date} · {t('cashManual')}{entry.metadata.item ? ` · ${entry.metadata.item}` : ''}</p>
        {entry.purchaseCost && <p>{t('cashPrice')} · {entry.currency} {entry.purchaseCost.unitPrice}/{entry.purchaseCost.baseUnit === 'piece' ? t('cashPiece') : entry.purchaseCost.baseUnit}</p>}
        {entry.metadata.reference && <small>{entry.metadata.reference}</small>}
        {entry.note && <p>{entry.note}</p>}
      </li>)}</ul> : <Notice subtle>{t('cashEmpty')}</Notice>}
      {page > 1 && <Button onClick={() => setPage(page - 1)}>{t('cashPrevious')}</Button>}
      {result.hasMore && <Button onClick={() => setPage(page + 1)}>{t('cashNext')}</Button>}
    </>}
  </>
}
