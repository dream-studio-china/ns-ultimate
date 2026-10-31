import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { Button, Dialog, PullToRefresh } from 'antd-mobile'
import { AlertCircle, ArrowLeft, ArrowRight, Check, CheckCircle2, ChevronDown, Hash, LockKeyhole, RefreshCw, Search, X, type LucideIcon } from 'lucide-react'
import { ApiError } from '../adapters/api-client'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { Sheet } from '../../shared/ui'
import { StoreConnectionError } from '../adapters/live'
import './editor.css'

const BusyContext = createContext<((busy: boolean) => void) | null>(null)
type RefreshTask = () => Promise<void>
const RefreshContext = createContext<((task: RefreshTask) => () => void) | null>(null)

export function WorkspaceRefreshProvider({ children }: { children: ReactNode }) {
  const { t } = useI18n()
  const tasks = useRef(new Set<RefreshTask>())
  const register = useCallback((task: RefreshTask) => {
    tasks.current.add(task)
    return () => { tasks.current.delete(task) }
  }, [])
  const refresh = useCallback(async () => {
    window.scrollTo({ top: 0, behavior: 'smooth' })
    await Promise.allSettled([...tasks.current].map((task) => task()))
  }, [])
  return <div className="workspace-refresh"><RefreshContext.Provider value={register}>
    <PullToRefresh onRefresh={refresh} pullingText={t('pullToRefresh')} canReleaseText={t('releaseToRefresh')} refreshingText={t('refreshingData')} completeText={t('refreshComplete')}>{children}</PullToRefresh>
  </RefreshContext.Provider></div>
}

export function usePageRefresh(task: RefreshTask) {
  const register = useContext(RefreshContext)
  useEffect(() => register?.(task), [register, task])
}

export function LiveSheet({ children, toolbar, ...props }: { children: ReactNode; toolbar?: ReactNode; open: boolean; title: string; onClose: () => void; footer?: ReactNode; className?: string }) {
  const [busy, setBusy] = useState(false)
  return <BusyContext.Provider value={setBusy}><Sheet {...props} locked={busy}>{toolbar && <div className="live-sheet-toolbar">{toolbar}</div>}<div className="live-sheet-content">{children}</div></Sheet></BusyContext.Provider>
}

export function errorKey(error: unknown): MessageKey {
  if (error instanceof StoreConnectionError && error.step === 'checkMembership' && error.status === 404) return 'noMembership'
  if (!(error instanceof ApiError)) return 'invalidResponse'
  if (error.status === 409) return 'conflictingRecord'
  if (error.status === 422) return 'validInput'
  return { unauthorized: 'sessionExpired', forbidden: 'noPermission', network: 'networkFailed', invalid: 'invalidResponse', request: 'requestFailed' }[error.kind] as MessageKey
}

export function usePage<T>(load: (page: number) => Promise<{ items: T[]; hasNext: boolean; total?: number }>, enabled = true) {
  const [page, setPage] = useState(1)
  const [state, setState] = useState({ items: [] as T[], hasNext: false, total: undefined as number | undefined, loading: true, error: null as MessageKey | null })
  const request = useRef(0)
  const refresh = useCallback(async () => {
    const requestId = ++request.current
    if (!enabled) { setState({ items: [], hasNext: false, total: undefined, loading: false, error: 'noPermission' }); return }
    setState((current) => ({ ...current, loading: true, error: null }))
    try {
      const data = await load(page)
      if (requestId === request.current) setState({ total: undefined, ...data, loading: false, error: null })
    } catch (error) {
      if (requestId === request.current) setState({ items: [], hasNext: false, total: undefined, loading: false, error: errorKey(error) })
    }
  }, [enabled, load, page])
  useEffect(() => { void refresh() }, [refresh])
  useEffect(() => () => { request.current += 1 }, [])
  usePageRefresh(refresh)
  return { ...state, page, setPage, reload: () => { void refresh() } }
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
export function SearchField({ value, onChange, server = false }: { value: string; onChange: (value: string) => void; server?: boolean }) {
  const { t } = useI18n()
  return <div className="workspace-search"><Search size={18} aria-hidden="true" /><input maxLength={100} aria-label={t(server ? 'searchStore' : 'searchCurrentPage')} placeholder={t(server ? 'searchStore' : 'searchCurrentPage')} value={value} onChange={(event) => onChange(event.target.value)} />{value && <button type="button" onClick={() => onChange('')} aria-label={t('close')}><X size={16} /></button>}</div>
}
export function PageScope({ count, total }: { count: number; total?: number }) {
  const { t } = useI18n()
  return <div className="page-scope"><span>{t('pageRecords')}<strong>{String(count).padStart(2, '0')}</strong></span><small>{total === undefined ? t('currentPageOnly') : `${t('matchingStoreRecords')} · ${total}`}</small></div>
}

/** New payloads get new keys; a deliberate retry of unchanged input reuses its durable receipt. */
export function useCommand(payload: unknown) {
  const fingerprint = JSON.stringify(payload)
  const command = useRef({ fingerprint, uuid: crypto.randomUUID() })
  if (command.current.fingerprint !== fingerprint) command.current = { fingerprint, uuid: crypto.randomUUID() }
  return command.current.uuid
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
export function MutationForm({ children, submit, onDone, id, actions = true, onBusyChange }: { children: ReactNode; submit: () => Promise<unknown>; onDone: () => void; id?: string; actions?: boolean; onBusyChange?: (busy: boolean) => void }) {
  const { t } = useI18n()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<MessageKey | null>(null)
  const locked = useRef(false)
  const mounted = useRef(true)
  const errorRef = useRef<HTMLParagraphElement>(null)
  const setParentBusy = useContext(BusyContext)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { if (error) errorRef.current?.scrollIntoView({ block: 'nearest' }) }, [error])
  return <form id={id} className="form-stack" aria-busy={busy} onInvalidCapture={(event) => {
    // Advanced fields remain keyboard reachable when native validation reports an error.
    const details = (event.target as HTMLElement).closest('details')
    if (details) details.open = true
  }} onSubmit={(event) => {
    event.preventDefault()
    if (locked.current) return
    if (!navigator.onLine) { setError('offline'); return }
    locked.current = true; setBusy(true); setParentBusy?.(true); onBusyChange?.(true); setError(null)
    void Promise.resolve().then(submit).then(() => { if (mounted.current) onDone() }).catch((error: unknown) => {
      if (mounted.current) setError(errorKey(error))
    }).finally(() => { locked.current = false; if (mounted.current) { setBusy(false); setParentBusy?.(false); onBusyChange?.(false) } })
  }}><fieldset disabled={busy} className="form-stack">{children}{actions && <Button type="submit" block color="primary" loading={busy}>{t('save')}</Button>}</fieldset>{error && <p ref={errorRef} className="error-text" role="alert">{t(error)}</p>}</form>
}

export function EditorSheet({ title, description, preview, children, submit, onDone, onClose, submitLabel = 'save' }: {
  title: string; description: MessageKey; preview: ReactNode; children: ReactNode; submit: () => Promise<unknown>; onDone: () => void; onClose: () => void; submitLabel?: MessageKey
}) {
  const { t } = useI18n()
  const id = useId()
  const [busy, setBusy] = useState(false)
  return <LiveSheet open title={title} className="resource-editor" onClose={onClose} footer={<div className="editor-footer"><p><LockKeyhole size={13} />{t(description)}</p><div><Button disabled={busy} onClick={onClose}>{t('cancel')}</Button><Button type="submit" form={id} color="primary" loading={busy}><Check size={17} />{t(submitLabel)}</Button></div></div>}>
    {preview}<MutationForm id={id} actions={false} submit={submit} onDone={onDone} onBusyChange={setBusy}>{children}</MutationForm>
  </LiveSheet>
}
export function EditorPreview({ icon: Icon, label, name, detail, children }: { icon: LucideIcon; label: MessageKey; name: string; detail?: string; children?: ReactNode }) {
  const { t } = useI18n()
  return <div className="editor-preview"><div className="editor-preview-icon"><Icon size={32} strokeWidth={1.25} /></div><div><p>{t(label)}</p><h3>{name}</h3>{detail && <small>{detail}</small>}</div>{children}</div>
}
export function EditorSection({ title, index, children }: { title: MessageKey; index: string; children: ReactNode }) {
  const { t } = useI18n()
  return <section className="editor-section"><div className="editor-section-heading"><span>{index}</span><h3>{t(title)}</h3></div><div className="editor-section-fields">{children}</div></section>
}
export function EditorToggle({ label, hint, checked, onChange }: { label: MessageKey; hint: MessageKey; checked: boolean; onChange: (checked: boolean) => void }) {
  const { t } = useI18n()
  const hintId = useId()
  return <div className="editor-toggle"><div><strong>{t(label)}</strong><p id={hintId}>{t(hint)}</p></div><button type="button" role="switch" aria-label={t(label)} aria-describedby={hintId} aria-checked={checked} onClick={() => onChange(!checked)}><span /></button></div>
}
export function EditorAdvanced({ children }: { children: ReactNode }) {
  const { t } = useI18n()
  return <details className="editor-advanced"><summary><span>{t('advancedFields')}</span><ChevronDown size={15} /></summary><div>{children}</div></details>
}
