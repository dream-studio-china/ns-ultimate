import { useEffect, useState } from 'react'
import { ChevronDown, CookingPot, House, LayoutGrid, ReceiptText, ShieldCheck, Store, UserRound, Wifi, WifiOff, X } from 'lucide-react'
import { useI18n } from '../shared/i18n'
import { DemoBadge } from '../shared/ui'
import { useStore } from './use-store'
import { HomePage } from './pages/home'
import { ProductsPage } from './pages/products'
import { TablesPage } from './pages/tables'
import { ReportsPage } from './pages/reports'
import { SettingsPage } from './pages/settings'
import { TableDetail } from './components/table-detail'

export type Page = 'home' | 'products' | 'tables' | 'reports' | 'mine' | 'history'
const navigation = [
  { id: 'home', icon: House }, { id: 'products', icon: CookingPot }, { id: 'tables', icon: LayoutGrid },
  { id: 'reports', icon: ReceiptText }, { id: 'mine', icon: UserRound },
] as const
const readPage = (): Page => {
  const value = window.location.hash.slice(1)
  return [...navigation.map((item) => item.id), 'history'].includes(value) ? value as Page : 'home'
}

export function StaffApp() {
  const { t } = useI18n()
  const store = useStore()
  const [page, setPage] = useState<Page>(readPage)
  const [focusedTable, setFocusedTable] = useState<string | null>(null)
  const table = store.tables.find((item) => item.id === focusedTable)
  useEffect(() => {
    const onHash = () => { setPage(readPage()); window.scrollTo(0, 0) }
    window.addEventListener('hashchange', onHash)
    return () => window.removeEventListener('hashchange', onHash)
  }, [])
  const navigate = (next: Page) => { window.location.hash = next }
  return <main className="mobile-app">
    <header className="app-header"><div className="brand-mark"><CookingPot size={21} strokeWidth={1.5} /></div><button className="store-selector" onClick={() => store.setNotice('securityHint')}><strong>{t('store')}</strong><span>{t('branch')}<ChevronDown size={11} /></span></button><div className={`connectivity ${store.online ? '' : 'offline'}`} title={t(store.online ? 'online' : 'offlineShort')}>{store.online ? <Wifi size={15} /> : <WifiOff size={15} />}</div><DemoBadge /></header>
    <div className="demo-strip"><ShieldCheck size={12} />{t('demoNotice')}</div>
    {!store.online && <div className="offline-banner" role="alert">{t('offline')}</div>}
    <div className="page-content">
      {page === 'home' && <HomePage store={store} navigate={navigate} openTable={setFocusedTable} />}
      {page === 'products' && <ProductsPage store={store} />}
      {(page === 'tables' || page === 'history') && <TablesPage key={page} store={store} initialPaid={page === 'history'} />}
      {page === 'reports' && <ReportsPage store={store} />}
      {page === 'mine' && <SettingsPage store={store} />}
    </div>
    <nav className="bottom-nav" aria-label={t('app')}>{navigation.map(({ id, icon: Icon }) => <a href={`#${id}`} key={id} aria-current={page === id || (id === 'tables' && page === 'history') ? 'page' : undefined}><span><Icon size={22} strokeWidth={1.5} /></span><small>{t(id)}</small></a>)}</nav>
    {table && <TableDetail key={table.id} table={table} store={store} onClose={() => setFocusedTable(null)} />}
    {store.notice && <div className="workspace-toast" role="status"><span>{t(store.notice)}</span><button aria-label={t('close')} onClick={() => store.setNotice(null)}><X size={16} /></button></div>}
    <div className="desktop-watermark"><Store size={14} /><span>NS STORE CONSOLE</span><small>MOBILE WORKSPACE</small></div>
  </main>
}
