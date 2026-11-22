import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { ReceiptText } from 'lucide-react'
import { z } from 'zod'
import { useI18n, type MessageKey } from '../../shared/i18n'
import type { StoreApi } from '../adapters/live'
import { errorKey, ListFeedback, PageControls, PageHeading, usePage } from './ui'

const isoDate = z.string().regex(/^\d{4}-\d{2}-\d{2}$/)
const minor = z.string().regex(/^-?\d+$/)
const bookSchema = z.object({ entity: z.string(), currency: z.string(), scale: z.number().int().min(0).max(6), opening_date: isoDate, timezone: z.string() })
const journalSchema = z.object({
  id: z.string().uuid(), summary: z.string(), accounting_date: isoDate,
  number: z.string().nullable(), state: z.enum(['draft', 'posted', 'voided']),
})
const detailSchema = journalSchema.extend({ lines: z.array(z.object({
  position: z.number(), account_code: z.string(), side: z.enum(['debit', 'credit']), amount: minor,
})) })
const trialSchema = z.object({
  data: z.array(z.object({ code: z.string(), name: z.string(), closing_debit: minor, closing_credit: minor })),
  report: z.object({ cutoff: minor }),
  paginator: z.object({ page: z.number(), has_next: z.boolean() }),
})
type Book = z.infer<typeof bookSchema>
type DateRange = { from: string; to: string }
const states = { draft: 'accountingDraft', posted: 'accountingPosted', voided: 'accountingVoided' } as const

function money(amount: string, book: Book) {
  const sign = amount.startsWith('-') ? '-' : ''
  const raw = amount.replace(/^-/, '').padStart(book.scale + 1, '0')
  return `${sign}${book.scale ? `${raw.slice(0, -book.scale)}.${raw.slice(-book.scale)}` : raw} ${book.currency}`
}

export function LiveAccounting({ api }: { api: StoreApi }) {
  const { t } = useI18n()
  const base = `${api.base}/accounting`
  const [book, setBook] = useState<Book | null>(null)
  const [error, setError] = useState<MessageKey | null>(null)
  useEffect(() => {
    let active = true
    void api.client.data(`${base}/workspace`, z.object({ book: bookSchema })).then(result => {
      if (active) setBook(result.book)
    }).catch(cause => { if (active) setError(errorKey(cause)) })
    return () => { active = false }
  }, [api, base])
  return <>
    <PageHeading eyebrow="staffWorkspace" title="accountingBook" subtitle="accountingReadOnly" />
    {error && <p role="alert">{t(error)}</p>}
    {!book && !error && <p role="status">{t('loadingRecords')}</p>}
    {book && <StoreBook api={api} base={base} book={book} />}
  </>
}

function StoreBook({ api, base, book }: { api: StoreApi; base: string; book: Book }) {
  const { t } = useI18n()
  const [selected, setSelected] = useState('')
  const [detail, setDetail] = useState<z.infer<typeof detailSchema> | null>(null)
  const [error, setError] = useState<MessageKey | null>(null)
  const load = useCallback((page: number) => api.client.list(`${base}/journals`, journalSchema, page), [api, base])
  const state = usePage(load)
  useEffect(() => {
    if (!selected) return
    let active = true
    void api.client.data(`${base}/journals/${selected}`, detailSchema).then(result => {
      if (active) setDetail(result)
    }).catch(cause => { if (active) setError(errorKey(cause)) })
    return () => { active = false }
  }, [api, base, selected])
  return <>
    <h2>{book.entity}</h2>
    <h3>{t('accountingJournals')}</h3>
    <ListFeedback state={state} empty="accountingNoJournals" hint="accountingReadOnly" icon={ReceiptText} />
    <ul>{state.items.map(row => <li key={row.id}><button type="button" onClick={() => {
      if (selected === row.id) return
      setDetail(null); setError(null); setSelected(row.id)
    }}>{row.accounting_date} · {row.number ?? '—'} · {row.summary} · {t(states[row.state])}</button></li>)}</ul>
    <PageControls state={state} />
    {error && <p role="alert">{t(error)}</p>}
    {selected && !detail && !error && <p role="status">{t('loadingRecords')}</p>}
    {detail && <section>
      <h3>{detail.summary}</h3>
      <button type="button" onClick={() => { setSelected(''); setDetail(null) }}>{t('close')}</button>
      <ul>{detail.lines.map(line => <li key={line.position}>{line.account_code} · {t(line.side === 'debit' ? 'accountingDebit' : 'accountingCredit')} · {money(line.amount, book)}</li>)}</ul>
    </section>}
    <TrialBalance api={api} base={base} book={book} />
  </>
}

function TrialBalance({ api, base, book }: { api: StoreApi; base: string; book: Book }) {
  const { t } = useI18n()
  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false }
  }, [])
  const [range, setRange] = useState<DateRange>(() => ({
    from: book.opening_date,
    to: [book.opening_date, new Intl.DateTimeFormat('sv-SE', { timeZone: book.timezone }).format(new Date())].sort()[1]!,
  }))
  const [result, setResult] = useState<(z.infer<typeof trialSchema> & { range: DateRange }) | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<MessageKey | null>(null)
  async function run(page: number, previous?: typeof result) {
    const filters = previous?.range ?? range
    setBusy(true); setError(null)
    try {
      const params = new URLSearchParams({ ...filters, page: String(page), ...(previous && { cutoff: previous.report.cutoff }) })
      const response = await api.client.request(`${base}/reports/trial?${params}`)
      setResult({ ...trialSchema.parse(response), range: filters })
    } catch (cause) { setError(errorKey(cause)) } finally { setBusy(false) }
  }
  async function download() {
    if (!result) return
    setBusy(true); setError(null)
    try {
      const params = new URLSearchParams({ ...result.range, cutoff: result.report.cutoff })
      const file = await api.client.downloadCsv(`${base}/reports/trial/csv?${params}`)
      if (!mounted.current) return
      const url = URL.createObjectURL(file)
      const link = document.createElement('a')
      link.href = url
      link.download = 'accounting-trial.csv'
      link.click()
      window.setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (cause) { setError(errorKey(cause)) } finally { setBusy(false) }
  }
  function submit(event: FormEvent) { event.preventDefault(); void run(1) }
  return <section>
    <h3>{t('accountingTrial')}</h3>
    <form onSubmit={submit}>
      <label>{t('accountingFrom')} <input type="date" required value={range.from} onChange={event => setRange({ ...range, from: event.target.value })} /></label>
      <label>{t('accountingTo')} <input type="date" required min={range.from} value={range.to} onChange={event => setRange({ ...range, to: event.target.value })} /></label>
      <button type="submit" disabled={busy}>{t('accountingRun')}</button>
    </form>
    {error && <p role="alert">{t(error)}</p>}
    {result && <>
      <p>{result.range.from} – {result.range.to}</p>
      <ul>{result.data.map(row => <li key={row.code}>{row.code} · {row.name} · {t('accountingDebit')}: {money(row.closing_debit, book)} · {t('accountingCredit')}: {money(row.closing_credit, book)}</li>)}</ul>
      <PageControls state={{
        page: result.paginator.page, hasNext: result.paginator.has_next, loading: busy, error,
        setPage: page => void run(page, result), reload: () => void run(result.paginator.page, result),
      }} />
      <button type="button" disabled={busy} onClick={() => void download()}>{t('accountingExport')}</button>
    </>}
  </section>
}
