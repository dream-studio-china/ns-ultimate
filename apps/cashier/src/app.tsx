import { useCallback, useMemo, useState } from 'react'
import { ArrowRight, ChevronDown, CircleHelp, Clock3, Coffee, LayoutGrid, LogOut, Maximize, MonitorUp, ReceiptText, Settings2, ShieldCheck, ShoppingBag, Store, UserRound, Wifi, WifiOff, X } from 'lucide-react'
import { useCashier } from './hooks/use-cashier'
import { useDesktop, usePublishSnapshot } from './hooks/use-desktop'
import { count, total } from './domain/cart'
import type { CustomerSnapshot } from '../shared/desktop'
import type { DemoOrder } from './adapters/gateway'
import { useI18n, money, dateTime, translate } from './lib/i18n'
import { cn } from './lib/utils'
import { useTheme } from './lib/theme'
import { Catalog } from './components/catalog'
import { BasketPanel } from './components/basket'
import { Button } from './components/ui/button'
import { Dialog } from './components/ui/dialog'
import { PaymentDialog } from './components/payment'
import { ReceiptDialog } from './components/receipt'
import { OrdersPage } from './pages/orders'
import { ShiftPage } from './pages/shift'
import { SettingsPage } from './pages/settings'

const navigation = [{ id: 'cashier', icon: LayoutGrid }, { id: 'orders', icon: ReceiptText }, { id: 'shift', icon: Clock3 }, { id: 'settings', icon: Settings2 }] as const
export function App() {
  const { t, locale, setLocale } = useI18n()
  const { theme } = useTheme()
  const state = useCashier()
  const [active, setActive] = useState(true)
  const [startedAt, setStartedAt] = useState(Date.now)
  const [page, setPage] = useState<typeof navigation[number]['id']>('cashier')
  const [helpOpen, setHelpOpen] = useState(false)
  const [signOutOpen, setSignOutOpen] = useState(false)
  const [heldOpen, setHeldOpen] = useState(false)
  const [receipt, setReceipt] = useState<DemoOrder | null>(null)
  const desktopError = useCallback(() => state.setNotice('savingError'), [state.setNotice])
  const desktop = useDesktop(desktopError)
  const snapshot = useMemo<CustomerSnapshot>(() => ({
    locale, theme, storeName: translate(locale, 'storeName'), online: state.online,
    lines: active ? state.basket.lines.map((line) => ({ id: line.id, name: translate(locale, line.product.name), detail: line.product.customizable ? `${translate(locale, line.selection.size)} / ${translate(locale, line.selection.temperature)}` : translate(locale, 'standard'), quantity: line.quantity, total: line.unitPrice * line.quantity })) : [],
    total: active ? total(state.basket.lines) : 0, status: active ? state.paymentStatus : 'idle',
  }), [locale, theme, state.online, state.basket.lines, state.paymentStatus, active])
  usePublishSnapshot(snapshot, desktopError)
  if (!active) return <main className="welcome-page flex min-h-dvh items-center justify-center bg-primary p-8"><div className="w-full max-w-lg rounded-[32px] bg-[#f6f7f1] p-10 text-center shadow-2xl"><span className="mx-auto mb-8 flex size-20 items-center justify-center rounded-3xl bg-primary/10 text-primary"><Coffee className="size-10" strokeWidth={1.3} /></span><p className="eyebrow mb-5">NS CASHIER</p><h1 className="text-3xl font-semibold leading-normal tracking-tight">{t('loginTitle')}</h1><p className="mt-4 text-sm text-muted-foreground">{t('loginSubtitle')}</p><Button size="lg" className="mt-10 w-full" onClick={() => { setActive(true); setPage('cashier'); setStartedAt(Date.now()) }}>{t('enterDemo')}<ArrowRight /></Button><p className="mt-6 text-xs leading-relaxed text-muted-foreground">{t('loginNotice')}</p><div className="mt-6 flex justify-center gap-3"><Button variant="ghost" size="sm" onClick={() => setLocale('zh-CN')}>{t('zh')}</Button><Button variant="ghost" size="sm" onClick={() => setLocale('en')}>{t('en')}</Button></div></div></main>
  return <main className="app-shell flex h-dvh overflow-hidden bg-[#f6f7f4] text-foreground"><nav className="sidebar flex w-[88px] shrink-0 flex-col items-center bg-[#182d27] py-6 text-[#b0bdb3]"><div className="mb-2 flex size-12 items-center justify-center rounded-2xl bg-[#d3e3b9] text-[#263d2c]"><Coffee className="size-7" strokeWidth={1.4} /></div><span className="mb-9 text-[8px] font-semibold tracking-[.22em] text-white/45">CASHIER</span><div className="flex w-full flex-col gap-3 px-2.5">{navigation.map(({ id, icon: Icon }) => <button key={id} onClick={() => setPage(id)} aria-current={page === id ? 'page' : undefined} className={cn('flex min-h-[74px] flex-col items-center justify-center gap-2 rounded-2xl text-[11px] transition-colors', page === id ? 'bg-[#d3e3b9] font-semibold text-[#263d2c]' : 'hover:bg-white/5 hover:text-white')}><Icon className="size-[21px]" strokeWidth={1.6} />{t(id)}</button>)}</div><div className="mt-auto flex flex-col gap-3"><button className="sidebar-action" onClick={() => setHelpOpen(true)} aria-label={t('help')}><CircleHelp className="size-5" /></button><button className="sidebar-action" disabled={state.locked} onClick={() => setSignOutOpen(true)} aria-label={t('signOut')}><LogOut className="size-5" /></button></div><span className="mt-5 font-mono text-[9px] text-white/25">v0.1.0</span></nav>
    <div className="flex min-w-0 flex-1 flex-col"><header className="flex min-h-[84px] shrink-0 items-center justify-between gap-4 border-b border-border bg-white px-7"><button className="flex min-h-12 items-center gap-3 text-left" onClick={() => state.setNotice('demoStoreOnly')}><span className="flex size-10 items-center justify-center rounded-xl border border-border bg-[#fafbf7]"><Store className="size-5 text-primary" strokeWidth={1.5} /></span><div><p className="text-sm font-semibold">{t('storeName')}</p><p className="mt-1 text-[10px] tracking-wide text-muted-foreground">{t('storeCode')}</p></div><ChevronDown className="ml-3 size-4 text-muted-foreground" /></button><div className="flex items-center gap-4"><span className={cn('hidden items-center gap-1.5 text-[11px] lg:flex', state.online ? 'text-primary' : 'text-destructive')} title={t('connectivityHint')}>{state.online ? <Wifi className="size-3.5" /> : <WifiOff className="size-3.5" />}{t(state.online ? 'online' : 'offline')}</span><span className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-700">{t('demo')}</span><div className="mx-1 h-7 w-px bg-border" /><div className="flex items-center gap-2.5"><span className="flex size-9 items-center justify-center rounded-full bg-[#eee9de]"><UserRound className="size-4 text-[#8d7d5d]" /></span><div className="hidden xl:block"><p className="text-xs font-medium">{t('operatorName')}</p><p className="mt-1 text-[10px] text-muted-foreground">{t('role')}</p></div></div><Button variant="ghost" size="icon" aria-label={t('toggleFullscreen')} title={t(desktop.isDesktop ? 'toggleFullscreen' : 'desktopOnly')} disabled={!desktop.isDesktop} onClick={() => void desktop.act('fullscreen')}><Maximize /></Button></div></header>
      <div className="demo-strip flex min-h-8 shrink-0 items-center justify-center gap-2 border-b border-[#e8e9df] bg-[#f0f1e8] px-4 py-2 text-[10px] text-[#858972]"><ShieldCheck className="size-3.5 shrink-0" />{t('demoBanner')}</div>
      {!state.online && <div className="shrink-0 bg-red-50 px-6 py-3 text-xs text-red-700" role="alert">{t('offlineBanner')}</div>}
      <div className="flex min-h-0 flex-1 overflow-hidden">{page === 'cashier' ? <><Catalog products={state.products} disabled={state.locked} onAdd={state.add} /><BasketPanel state={state} onHeld={() => setHeldOpen(true)} /></> : page === 'orders' ? <OrdersPage orders={state.orders} onView={setReceipt} /> : page === 'shift' ? <ShiftPage orders={state.orders} startedAt={startedAt} /> : <SettingsPage desktop={desktop} />}</div>
      <footer className="flex h-8 shrink-0 items-center justify-between border-t border-border bg-white px-6 text-[9px] text-muted-foreground"><span>NS CASHIER <span className="mx-2 opacity-40">/</span>{t('footer')}</span><button className="flex min-h-7 items-center gap-1.5 hover:text-primary" onClick={() => setPage('settings')}><MonitorUp className="size-3" />{t('customerScreen')}{desktop.status?.customerOpen && <span className="ml-1 size-1.5 rounded-full bg-primary" />}</button></footer>
    </div>
    <PaymentDialog state={state} onReceipt={setReceipt} /><ReceiptDialog order={receipt} onClose={() => setReceipt(null)} />
    <Dialog open={heldOpen} onOpenChange={setHeldOpen} title={`${t('held')} (${state.held.length})`} description={t('heldHint')}><div className="space-y-3">{state.held.length ? state.held.map((item) => <div key={item.id} className="flex items-center justify-between gap-3 rounded-2xl border border-border p-4"><div><p className="text-sm font-semibold">{money(total(item.basket.lines))}<span className="ml-3 text-xs font-normal text-muted-foreground">{count(item.basket.lines)} {t('itemCount')}</span></p><p className="mt-2 text-xs text-muted-foreground">{dateTime(item.createdAt, locale)} · {t(item.basket.service)}</p></div><Button variant="outline" disabled={state.locked} onClick={() => { if (state.restore(item.id)) { setHeldOpen(false); setPage('cashier') } }}>{t('restore')}</Button></div>) : <div className="empty-state min-h-40"><ShoppingBag /><h3>{t('heldEmpty')}</h3></div>}</div></Dialog>
    <Dialog open={helpOpen} onOpenChange={setHelpOpen} title={t('helpTitle')} description={t('helpDescription')}><ol className="space-y-4">{(['helpCatalog', 'helpHold', 'helpScreen', 'helpNetwork', 'helpStorage'] as const).map((key, index) => <li key={key} className="flex items-start gap-3 text-sm leading-relaxed"><span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary text-xs text-primary">{index + 1}</span>{t(key)}</li>)}</ol><Button className="mt-7 w-full" onClick={() => setHelpOpen(false)}>{t('understood')}</Button></Dialog>
    <Dialog open={signOutOpen} onOpenChange={setSignOutOpen} title={t('signOutTitle')} description={t('signOutDescription')}><div className="flex justify-end gap-3"><Button variant="outline" onClick={() => setSignOutOpen(false)}>{t('cancel')}</Button><Button disabled={state.locked} onClick={() => { state.reset(); setSignOutOpen(false); setActive(false) }}>{t('signOut')}</Button></div></Dialog>
    {state.notice && <div className="fixed bottom-14 left-1/2 z-[70] flex max-w-lg -translate-x-1/2 items-center gap-5 rounded-2xl bg-[#182d27] px-5 py-3 text-sm text-white shadow-xl" role="status">{t(state.notice)}<button aria-label={t('cancel')} className="flex size-9 shrink-0 items-center justify-center rounded-lg hover:bg-white/10" onClick={() => state.setNotice(null)}><X className="size-4" /></button></div>}
  </main>
}
