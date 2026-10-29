import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { Button, Dialog } from 'antd-mobile'
import { AlertCircle, ArrowLeft, ArrowRight, CheckCircle2, ChevronDown, Hash, LockKeyhole, RefreshCw, Search, X, type LucideIcon } from 'lucide-react'
import { ApiError } from '../adapters/api-client'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { Sheet } from '../../shared/ui'
import { StoreConnectionError } from '../adapters/live'

const BusyContext = createContext<((busy: boolean) => void) | null>(null)
export function LiveSheet({ children, ...props }: { children: ReactNode; open: boolean; title: string; onClose: () => void }) {
  const [busy, setBusy] = useState(false)
  return <BusyContext.Provider value={setBusy}><Sheet {...props} locked={busy}><div className="live-sheet-content">{children}</div></Sheet></BusyContext.Provider>
}

export function errorKey(error: unknown): MessageKey {
  if (error instanceof StoreConnectionError && error.step === 'checkMembership' && error.status === 404) return 'noMembership'
  if (!(error instanceof ApiError)) return 'invalidResponse'
  return { unauthorized: 'sessionExpired', forbidden: 'noPermission', network: 'networkFailed', invalid: 'invalidResponse', request: 'requestFailed' }[error.kind] as MessageKey
}

export function usePage<T>(load: (page: number) => Promise<{ items: T[]; hasNext: boolean }>, enabled = true) {
  const [page, setPage] = useState(1)
  const [revision, setRevision] = useState(0)
  const [state, setState] = useState({ items: [] as T[], hasNext: false, loading: true, error: null as MessageKey | null })
  useEffect(() => {
    let active = true
    if (!enabled) { setState({ items: [], hasNext: false, loading: false, error: 'noPermission' }); return }
    setState({ items: [], hasNext: false, loading: true, error: null })
    void load(page).then((data) => {
      if (active) setState({ ...data, loading: false, error: null })
    }).catch((error: unknown) => {
      if (active) setState({ items: [], hasNext: false, loading: false, error: errorKey(error) })
    })
    return () => { active = false }
  }, [load, page, revision, enabled])
  return { ...state, page, setPage, reload: () => setRevision((value) => value + 1) }
}
interface PageState { page: number; loading: boolean; hasNext: boolean; error: MessageKey | null; setPage: (page: number) => void; reload: () => void }
export function PageControls({ state, detail = false }: { state: PageState; detail?: boolean }) {
  const { t } = useI18n()
  return <div className="live-pagination">{!detail && <><button type="button" aria-label={t('previous')} disabled={state.loading || state.page <= 1} onClick={() => state.setPage(state.page - 1)}><ArrowLeft size={16} /><span>{t('previous')}</span></button><span className="page-index">{String(state.page).padStart(2, '0')}</span><button type="button" aria-label={t('next')} disabled={state.loading || !state.hasNext} onClick={() => state.setPage(state.page + 1)}><span>{t('next')}</span><ArrowRight size={16} /></button></>}<button className="refresh-control" type="button" aria-label={t('refresh')} disabled={state.loading} onClick={state.reload}><RefreshCw size={16} className={state.loading ? 'is-spinning' : ''} /><span>{t('refresh')}</span></button></div>
}
export function PageHeading({ eyebrow, title, subtitle, children }: { eyebrow: MessageKey; title: MessageKey; subtitle: MessageKey; children?: ReactNode }) {
  const { t } = useI18n()
  return <div className="workspace-heading"><div><p className="workspace-eyebrow">{t(eyebrow)}</p><h1>{t(title)}</h1><p className="workspace-subtitle">{t(subtitle)}</p></div>{children}</div>
}
export function Notice({ children, title, icon: Icon = AlertCircle, subtle = false }: { children: ReactNode; title?: MessageKey; icon?: LucideIcon; subtle?: boolean }) {
  const { t } = useI18n()
  return <div className={`workspace-notice ${subtle ? 'subtle' : ''}`}><Icon size={17} aria-hidden="true" /><div>{title && <strong>{t(title)}</strong>}<p>{children}</p></div></div>
}
export function AccessNotice() {
  const { t } = useI18n()
  return <div className="workspace-empty" role="alert"><span className="empty-illustration"><LockKeyhole size={28} /></span><h2>{t('accessRestricted')}</h2><p>{t('noPermission')}</p><small>{t('accessRestrictedHint')}</small></div>
}
export function ListFeedback({ state, empty, hint, icon: Icon }: { state: { loading: boolean; error: MessageKey | null; items: unknown[] }; empty: MessageKey; hint: MessageKey; icon: LucideIcon }) {
  const { t } = useI18n()
  if (state.loading) return <div className="workspace-skeleton" role="status" aria-label={t('loadingRecords')}>{[0, 1, 2].map((value) => <div key={value} aria-hidden="true"><span /><i /><i /></div>)}</div>
  if (state.error) return <div className="workspace-error" role="alert"><AlertCircle size={20} /><p>{t(state.error)}</p></div>
  if (state.items.length) return null
  return <div className="workspace-empty"><span className="empty-illustration"><Icon size={30} strokeWidth={1.3} /></span><h2>{t(empty)}</h2><p>{t(hint)}</p></div>
}
export function StatusPill({ children, muted = false }: { children: ReactNode; muted?: boolean }) {
  return <span className={`workspace-status ${muted ? 'muted' : ''}`}><i />{children}</span>
}
export function RecordIdentity({ id, children }: { id: string; children?: ReactNode }) {
  const { t } = useI18n()
  return <details className="record-identity"><summary><Hash size={13} />{t('recordDetails')}<ChevronDown size={13} /></summary><dl><dt>{t('recordId')}</dt><dd>{id}</dd>{children}</dl></details>
}
export function SearchField({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const { t } = useI18n()
  return <div className="workspace-search"><Search size={18} aria-hidden="true" /><input aria-label={t('searchCurrentPage')} placeholder={t('searchCurrentPage')} value={value} onChange={(event) => onChange(event.target.value)} />{value && <button type="button" onClick={() => onChange('')} aria-label={t('close')}><X size={16} /></button>}</div>
}
export function PageScope({ count }: { count: number }) {
  const { t } = useI18n()
  return <div className="page-scope"><span>{t('pageRecords')}<strong>{String(count).padStart(2, '0')}</strong></span><small>{t('currentPageOnly')}</small></div>
}
export function useDelete(reload: () => void) {
  const { t } = useI18n()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<MessageKey | null>(null)
  const [notice, setNotice] = useState(false)
  const lock = useRef(false)
  const remove = async (action: () => Promise<unknown>) => {
    if (lock.current) return
    if (!navigator.onLine) { setError('offline'); return }
    lock.current = true; setBusy(true); setError(null); setNotice(false)
    try {
      if (await Dialog.confirm({ title: t('confirmLiveDelete'), content: t('liveDeleteHint'), confirmText: t('delete'), cancelText: t('cancel') })) {
        await action(); reload(); setNotice(true)
      }
    } catch (error) { setError(errorKey(error)) } finally { lock.current = false; setBusy(false) }
  }
  return { busy, remove, feedback: <>{error && <div className="workspace-error" role="alert"><AlertCircle size={18} /><p>{t(error)}</p></div>}{notice && <p className="workspace-success" role="status"><CheckCircle2 size={16} />{t('deletedLive')}</p>}</> }
}
export function MutationForm({ children, submit, onDone }: { children: ReactNode; submit: () => Promise<unknown>; onDone: () => void }) {
  const { t } = useI18n()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<MessageKey | null>(null)
  const locked = useRef(false)
  const mounted = useRef(true)
  const setParentBusy = useContext(BusyContext)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  return <form className="form-stack" onSubmit={(event) => {
    event.preventDefault()
    if (locked.current) return
    if (!navigator.onLine) { setError('offline'); return }
    locked.current = true; setBusy(true); setParentBusy?.(true); setError(null)
    void submit().then(() => { if (mounted.current) onDone() }).catch((error: unknown) => {
      if (mounted.current) setError(errorKey(error))
    }).finally(() => { locked.current = false; if (mounted.current) { setBusy(false); setParentBusy?.(false) } })
  }}><fieldset disabled={busy} className="form-stack">{children}<Button type="submit" block color="primary" loading={busy}>{t('save')}</Button></fieldset>{error && <p className="error-text" role="alert">{t(error)}</p>}</form>
}
