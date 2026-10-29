import { ArrowRight, ArrowUpRight, ChartNoAxesCombined, ChefHat, ChevronRight, CookingPot, ReceiptText, ScanLine, Sparkles, UtensilsCrossed } from 'lucide-react'
import type { StoreState } from '../use-store'
import { due } from '../domain'
import { money, useI18n } from '../../shared/i18n'
import { Empty } from '../../shared/ui'
import { TableCard } from './tables'
import type { Page } from '../app'

export function HomePage({ store, navigate, openTable }: { store: StoreState; navigate: (page: Page) => void; openTable: (id: string) => void }) {
  const { t } = useI18n()
  const dining = store.tables.filter((table) => table.lines.length > 0)
  const collected = store.receipts.reduce((sum, receipt) => sum + receipt.total, 0)
  const pending = dining.reduce((sum, table) => sum + due(table), 0)
  const shortcuts = [
    { key: 'kitchen', hint: 'kitchenHint', page: 'products', icon: ChefHat, tone: 'warm' },
    { key: 'cashier', hint: 'cashierHint', page: 'tables', icon: UtensilsCrossed, tone: 'sage' },
    { key: 'history', hint: 'historyHint', page: 'history', icon: ReceiptText, tone: 'blue' },
    { key: 'reports', hint: 'reportSubtitle', page: 'reports', icon: ChartNoAxesCombined, tone: 'sand' },
  ] as const
  return <>
    <div className="home-greeting"><div><p className="eyebrow">A GOOD DAY AT NANSU</p><h1>{t('greeting')}</h1><p>{t('greetingSub')}</p></div><div className="greeting-art"><CookingPot size={43} strokeWidth={1} /><Sparkles size={15} /></div></div>
    <section className="revenue-card"><div className="flex items-center justify-between"><span className="revenue-label">{t('overview')}</span><button onClick={() => navigate('reports')}>{t('viewReport')}<ArrowUpRight size={13} /></button></div><p className="revenue-main">{money(collected)}</p><span className="revenue-caption">{t('revenue')} · {t('demo')}</span><div className="revenue-divider" /><div className="revenue-metrics"><div><p>{t('pendingAmount')}</p><strong>{money(pending)}</strong></div><div><p>{t('completed')}</p><strong>{String(store.receipts.length).padStart(2, '0')}<small>{t('ordersUnit')}</small></strong></div><div><p>{t('activeTables')}</p><strong>{String(dining.length).padStart(2, '0')}<small>{t('tablesUnit')}</small></strong></div></div><span className="revenue-orbit" /></section>
    <div className="section-heading mt-7"><h2>{t('shortcuts')}</h2><span className="section-mark">QUICK ACCESS</span></div>
    <div className="shortcut-grid">{shortcuts.map(({ key, hint, page, icon: Icon, tone }) => <button key={key} onClick={() => navigate(page)}><span className={`shortcut-icon ${tone}`}><Icon size={23} strokeWidth={1.5} /></span><div><strong>{t(key)}</strong><p>{t(hint)}</p></div><ChevronRight size={15} /></button>)}</div>
    <div className="section-heading mt-7"><h2>{t('currentService')}<span className="count-dot">{dining.length}</span></h2><button className="text-action" onClick={() => navigate('tables')}>{t('allTables')}<ArrowRight size={14} /></button></div>
    {dining.length ? <div className="table-grid">{dining.slice(0, 4).map((table) => <TableCard key={table.id} table={table} onClick={() => openTable(table.id)} />)}</div> : <Empty title={t('noActiveTables')} />}
    <div className="home-footer"><ScanLine size={17} /><span>NS STORE CONSOLE</span><span className="ml-auto">{t('demo')}</span></div>
  </>
}
