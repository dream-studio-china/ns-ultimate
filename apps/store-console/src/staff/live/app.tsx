import { useCallback, useLayoutEffect, useRef, useState } from 'react'
import { Button } from 'antd-mobile'
import { ArrowRight, Check, ChevronDown, ChevronRight, CircleHelp, CookingPot, Globe2, LayoutGrid, LockKeyhole, LogOut, MapPin, ReceiptText, ShieldCheck, Store, UserRound, WifiOff } from 'lucide-react'
import { ApiClient } from '../adapters/api-client'
import { can, orderActions, StoreApi, StoreConnectionError, storeSchema, type StoreAccess, type ConnectionStep } from '../adapters/live'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { useOnline } from '../../shared/use-online'
import { ThemePicker } from '../../shared/theme-picker'
import { LiveCatalog } from './catalog'
import { LiveTables, LiveOrders } from './tables'
import { errorKey, ListFeedback, Notice, PageControls, PageHeading, StatusPill, usePage, LiveSheet } from './ui'
import { BrandArt } from './brand-art'
import { defaultWorkspacePage, workspacePages, type WorkspacePage } from './navigation'
import './workspace.css'

const navigation = [
  { key: 'products', icon: CookingPot, title: 'kitchenView' },
  { key: 'tables', icon: LayoutGrid, title: 'cashierView' },
  { key: 'orders', icon: ReceiptText, title: 'readOnlyOrders' },
  { key: 'mine', icon: UserRound, title: 'mine' },
] as const

export function LiveApp() {
  const { t, locale, setLocale } = useI18n()
  const online = useOnline()
  const [signedIn, setSignedIn] = useState(false)
  const [access, setAccess] = useState<StoreAccess | null>(null)
  const [error, setError] = useState<MessageKey | null>(null)
  const [client] = useState(() => new ApiClient(fetch, () => { setSignedIn(false); setAccess(null); setError('sessionExpired') }))
  const [page, setPage] = useState<WorkspacePage>('mine')
  const visibleNavigation = access ? navigation.filter(({ key }) => workspacePages(access).includes(key)) : []
  const api = useRef<StoreApi | null>(null)
  useLayoutEffect(() => { window.scrollTo({ top: 0, behavior: 'instant' }) }, [signedIn, access?.store.uuid, page])
  const logout = async () => {
    setAccess(null); api.current = null
    try { await client.logout(); setError(null) } catch { setError('logoutFailed') }
  }
  const changeStore = () => { setAccess(null); api.current = null }
  return <main className={`mobile-app live-app ${access ? 'is-connected' : 'is-onboarding'}`}>
    <header className="workspace-header">
      <div className="workspace-logo" aria-hidden="true"><CookingPot size={23} strokeWidth={1.6} /></div>
      {access ? <button className="workspace-store-switch" aria-label={t('switchStore')} onClick={changeStore}><strong>{access.store.name}<ChevronDown size={14} /></strong><span><i />{t('liveData')}<b>·</b>{access.store.code}</span></button> : <div className="workspace-wordmark"><strong>NS<span>STORE CONSOLE</span></strong><small>{t('staffWorkspace')}</small></div>}
      <button className="workspace-language" onClick={() => setLocale(locale === 'en' ? 'zh-CN' : 'en')}><Globe2 size={16} /><span>{locale === 'en' ? t('zh') : t('en')}</span></button>
      {signedIn && !access && <button className="workspace-icon" aria-label={t('logout')} onClick={() => void logout()}><LogOut size={18} /></button>}
    </header>
    {!online && <div role="alert" className="workspace-offline"><WifiOff size={16} /><span>{t('offline')}</span></div>}
    {access && <nav className="workspace-nav" aria-label={t('app')}>{visibleNavigation.map(({ key, icon: Icon, title }) => <button aria-label={t(key === 'orders' && orderActions.some((action) => can(access, 'order', action)) ? 'storeOrders' : title)} aria-current={page === key ? 'page' : undefined} key={key} onClick={() => setPage(key)}><span><Icon size={21} strokeWidth={page === key ? 1.9 : 1.6} /></span><small>{t(key === 'orders' ? 'orderReference' : title)}</small></button>)}</nav>}
    <div className="workspace-body">
      {error && <div className="workspace-error" role="alert"><CircleHelp size={19} /><p>{t(error)}</p></div>}
      {!signedIn ? <Login client={client} done={() => { setError(null); setSignedIn(true) }} /> : !access ? <StoreSelection client={client} connected={(next) => { api.current = new StoreApi(client, next.store.uuid); setAccess(next); setPage(defaultWorkspacePage(next, new URLSearchParams(window.location.search).get('view'))) }} /> : <>
        <div className="workspace-page" key={`${access.store.uuid}:${page}`}>
          {page === 'products' && <LiveCatalog api={api.current!} access={access} />}
          {page === 'tables' && <LiveTables api={api.current!} access={access} />}
          {page === 'orders' && <LiveOrders api={api.current!} access={access} />}
           {page === 'mine' && <LiveAccount api={api.current!} access={access} logout={() => void logout()} />}
        </div>
        <details className="workspace-connection"><summary><ShieldCheck size={14} />{t('connectionDetails')}<ChevronDown size={14} /></summary><p>{t('operationalScopeHint')}</p></details>
      </>}
      {!access && <div className="workspace-demo-link"><span>{t('demoModeHint')}</span><a href="?mode=demo" aria-label={t('demoEntry')}>{t('demoModeLink')}<ArrowRight size={13} /></a></div>}
      <footer className="workspace-signature"><CookingPot size={14} strokeWidth={1.3} /><span>NS STORE CONSOLE</span><i />{t(access ? 'liveMode' : 'secureSession')}</footer>
    </div>
  </main>
}

function Login({ client, done }: { client: ApiClient; done: () => void }) {
  const { t } = useI18n()
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<MessageKey | null>(null)
  const lock = useRef(false)
  return <div className="workspace-login">
    <section className="login-story"><span className="story-kicker"><i />{t('workspaceReady')}</span><p className="story-title">{t('workspaceTagline')}</p><p className="story-subtitle">{t('workspaceIntro')}</p><BrandArt /><div className="story-caption"><span>01 / NS</span><CookingPot size={17} /><span>THOUGHTFUL EVERY DAY</span></div></section>
    <form className="workspace-login-form" onSubmit={(event) => {
      event.preventDefault()
      if (lock.current || !navigator.onLine) return
      lock.current = true; setBusy(true); setError(null)
      const secret = password; setPassword('')
      void client.login(identifier.trim(), secret).then(done).catch((error: unknown) => setError(errorKey(error) === 'sessionExpired' ? 'loginFailed' : errorKey(error))).finally(() => { lock.current = false; setBusy(false) })
    }}>
      <div className="login-form-heading"><span className="workspace-eyebrow">{t('welcomeBack')}</span><h1>{t('login')}</h1><p>{t('loginSubtitle')}</p></div>
      <label className="field">{t('identifier')}<div className="workspace-input-icon"><UserRound size={18} /><input required autoComplete="username" value={identifier} placeholder={t('identifier')} disabled={busy} onChange={(event) => setIdentifier(event.target.value)} /></div></label>
      <label className="field">{t('password')}<div className="workspace-input-icon"><LockKeyhole size={18} /><input required type="password" autoComplete="current-password" value={password} placeholder={t('password')} disabled={busy} onChange={(event) => setPassword(event.target.value)} /></div></label>
      {error && <div className="workspace-error" role="alert"><CircleHelp size={18} /><p>{t(error)}</p></div>}
      <Button className="workspace-primary" color="primary" block type="submit" loading={busy}>{t('login')}<ArrowRight size={17} /></Button>
      <p className="login-security"><ShieldCheck size={15} />{t('loginHint')}</p>
    </form>
  </div>
}

function StoreSelection({ client, connected }: { client: ApiClient; connected: (access: StoreAccess) => void }) {
  const { t } = useI18n()
  const load = useCallback((page: number) => client.list('/api/v1/app/stores', storeSchema, page), [client])
  const list = usePage(load)
  const [uuid, setUuid] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<MessageKey | null>(null)
  const [detail, setDetail] = useState<{ step: ConnectionStep; status: number } | null>(null)
  const lock = useRef(false)
  const selected = list.items.find((store) => store.uuid === uuid)
  return <section className="store-selection">
    <div className="selection-intro"><span className="selection-icon"><Store size={28} strokeWidth={1.4} /></span><p className="workspace-eyebrow">{t('staffWorkspace')}</p><h1>{t('selectStore')}</h1><p>{t('selectStoreSubtitle')}</p></div>
    <Notice icon={ShieldCheck} subtle>{t('storeDirectoryHint')}</Notice>
    <ListFeedback state={list} empty="emptyLive" hint="storeDirectoryHint" icon={Store} />
    <form onSubmit={(event) => {
      event.preventDefault()
      if (lock.current || !navigator.onLine) return
      lock.current = true; setBusy(true); setError(null); setDetail(null)
      void StoreApi.access(client, uuid).then((access) => {
        if (access.membership.status !== 'active') { setError('noMembership'); return }
        connected(access)
      }).catch((error: unknown) => {
        setError(errorKey(error))
        if (error instanceof StoreConnectionError) setDetail({ step: error.step, status: error.status })
      }).finally(() => { lock.current = false; setBusy(false) })
    }}>
      <div className="store-choice-grid">{list.items.map((store, index) => <button className={`store-choice ${store.uuid === uuid ? 'is-selected' : ''}`} type="button" aria-label={`${store.name} ${store.code}`} aria-pressed={store.uuid === uuid} key={store.uuid} disabled={busy} onClick={() => { setUuid(store.uuid); setError(null); setDetail(null) }}><span className={`store-choice-art art-${index % 3}`}><Store size={29} strokeWidth={1.3} /><i /></span><span className="store-choice-info"><strong>{store.name}</strong><small><MapPin size={12} />{store.code}</small></span><span className="store-choice-check">{store.uuid === uuid ? <Check size={16} /> : <ChevronRight size={18} />}</span></button>)}</div>
      <PageControls state={list} />
      <details className="workspace-advanced"><summary>{t('manualStore')}<ChevronDown size={15} /></summary><label className="field">{t('storeUuid')}<input required pattern="[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}" disabled={busy} value={uuid} onChange={(event) => setUuid(event.target.value)} /></label></details>
      {error && <div role="alert" className="workspace-error"><CircleHelp size={19} /><div><p>{t(error)}</p>{detail && <small>{t(detail.step)}{detail.status > 0 && ` · HTTP ${detail.status}`}</small>}</div></div>}
      <div className="store-connect-bar"><div><small>{t('selectedStore')}</small><strong>{selected?.name ?? t(uuid ? 'customStore' : 'storeNotSelected')}</strong></div><Button className="workspace-primary" color="primary" type="submit" loading={busy} disabled={!uuid}>{t('connectStore')}<ArrowRight size={17} /></Button></div>
    </form>
  </section>
}

const roleLabels: Record<string, MessageKey> = { owner: 'membershipOwner', manager: 'membershipManager', clerk: 'membershipClerk', fulfillment: 'membershipFulfillment' }
const resourceLabels: Record<string, MessageKey> = { product: 'products', product_category: 'categoryManagement', specification: 'specifications', counter: 'tables', order: 'orderReference', catalog_image: 'coverUpload', operation: 'operationHistory', counter_session: 'serviceSessions', counter_order: 'sessionOrders' }
const actionLabels: Record<string, MessageKey> = { read: 'permissionRead', create: 'permissionCreate', update: 'permissionUpdate', delete: 'permissionDelete', availability: 'availabilityControl', accept: 'acceptOrder', reject: 'rejectOrder', fulfill: 'fulfillOrder', verify: 'verifyOrder', place: 'placeDining', replace: 'replaceDining', end: 'endDining' }
function LiveAccount({ api, access, logout }: { api: StoreApi; access: StoreAccess; logout: () => void }) {
  const { t, locale, setLocale } = useI18n()
  const [history, setHistory] = useState(false)
  return <>
    <PageHeading eyebrow="accountEyebrow" title="liveAccount" subtitle="accountSubtitle" />
    {workspacePages(access).length === 1 && <Notice>{t('noWorkspaceAccess')}</Notice>}
    <section className="workspace-profile"><div className="profile-monogram">{(access.profile.username ?? 'NS').slice(0, 1).toUpperCase()}<span><ShieldCheck size={13} /></span></div><div><h2>{access.profile.username ?? access.profile.uuid}</h2><p>{t(roleLabels[access.membership.role] ?? 'liveAccount')}</p></div><StatusPill>{t('liveMode')}</StatusPill></section>
    <section className="account-store-card"><span><Store size={25} strokeWidth={1.5} /></span><div><strong>{access.store.name}</strong><small>{access.store.code} · {access.store.currency}</small></div><MapPin size={17} /><p>{t('storeContextHint')}</p></section>
    <div className="account-section-title"><h2>{t('devicePreferences')}</h2><span>{t('preferences')}</span></div>
    <section className="workspace-panel"><div className="account-setting"><span className="setting-symbol"><Globe2 size={19} /></span><div><h3>{t('language')}</h3><small>{locale === 'en' ? t('en') : t('zh')}</small></div><button onClick={() => setLocale(locale === 'en' ? 'zh-CN' : 'en')}>{locale === 'en' ? t('zh') : t('en')}<ChevronRight size={16} /></button></div><ThemePicker /></section>
    <div className="account-section-title"><h2>{t('profileAndAccess')}</h2><span>{t('scopedAccess')}</span></div>
    <section className="workspace-panel account-permissions">{Object.entries(resourceLabels).map(([resource, label]) => <div key={resource}><strong>{t(label)}</strong><span>{Object.entries(actionLabels).filter(([action]) => can(access, resource, action)).map(([action, label]) => <small key={action}>{t(label)}</small>)}{!Object.keys(actionLabels).some((action) => can(access, resource, action)) && <small className="no-grant">{t('noGrantedActions')}</small>}</span></div>)}</section>
    {can(access, 'operation', 'read') && <Button block className="account-history-button" onClick={() => setHistory(true)}>{t('operationHistory')}</Button>}
    {history && <LiveSheet open title={t('operationHistory')} onClose={() => setHistory(false)}><OperationHistory api={api} /></LiveSheet>}
    <section className="account-session"><h2>{t('sessionSettings')}</h2><p>{t('accountSessionHint')}</p><Button block className="logout-button" onClick={logout}><LogOut size={17} />{t('logout')}</Button><a href="?mode=demo">{t('demoModeLink')}<ArrowRight size={14} /></a></section>
  </>
}

function OperationHistory({ api }: { api: StoreApi }) {
  const { t, locale } = useI18n()
  const load = useCallback((page: number) => api.history(page), [api])
  const list = usePage(load)
  return <><Notice subtle>{t('historyScopeHint')}</Notice><ListFeedback state={list} empty="emptyLive" hint="historyScopeHint" icon={ShieldCheck} /><ul className="operation-records">{list.items.map((operation) => <li key={operation.uuid}><strong>{operation.action}</strong><p>{new Date(operation.createdAt).toLocaleString(locale)}</p><small>{t('operationActor')} · {operation.actorUuid}</small></li>)}</ul><PageControls state={list} /></>
}
