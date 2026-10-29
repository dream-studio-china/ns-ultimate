import { useState } from 'react'
import { ArrowUpRight, ReceiptText, Search } from 'lucide-react'
import type { DemoOrder } from '../adapters/gateway'
import { count } from '../domain/cart'
import { dateTime, money, useI18n } from '../lib/i18n'
import { Button } from '../components/ui/button'

export function OrdersPage({ orders, onView }: { orders: DemoOrder[]; onView: (order: DemoOrder) => void }) {
  const { t, locale } = useI18n()
  const [search, setSearch] = useState('')
  const filtered = orders.filter((order) => order.id.toLowerCase().includes(search.toLowerCase().trim()))
  return <div className="page-scroll"><div className="page-heading"><p className="eyebrow">ORDER HISTORY</p><h1>{t('orderHistory')}</h1><p>{t('orderHistorySubtitle')}</p></div><div className="content-card"><div className="mb-6 flex items-center justify-between gap-4"><h2 className="font-semibold">{t('completedOrders')} <span className="ml-2 rounded-md bg-secondary px-2 py-1 text-xs text-primary">{orders.length}</span></h2><div className="relative max-w-xs"><Search className="absolute left-4 top-4 size-4 text-muted-foreground" /><input className="search-input w-full pl-11" aria-label={t('searchOrders')} placeholder={t('searchOrders')} value={search} onChange={(event) => setSearch(event.target.value)} /></div></div>{filtered.length ? <div className="overflow-x-auto"><table className="order-table w-full text-left text-sm"><thead><tr>{(['orderNumber', 'time', 'items', 'paymentMethod', 'amount', 'status', 'actions'] as const).map((key) => <th key={key}>{t(key)}</th>)}</tr></thead><tbody>{filtered.map((order) => <tr key={order.id}><td className="font-mono text-xs font-medium">{order.id}</td><td className="text-muted-foreground">{dateTime(order.createdAt, locale)}</td><td>{count(order.basket.lines)}</td><td>{t(order.method)}</td><td className="font-semibold tabular-nums">{money(order.total)}</td><td><span className="status-badge"><span />{t('paid')}</span></td><td><Button variant="ghost" size="sm" onClick={() => onView(order)}>{t('view')}<ArrowUpRight /></Button></td></tr>)}</tbody></table></div> : <div className="empty-state min-h-80"><ReceiptText /><h3>{search ? t('noProducts') : t('emptyOrders')}</h3><p>{t('emptyOrdersHint')}</p></div>}<p className="mt-6 border-t border-border pt-4 text-xs text-muted-foreground">{t('sessionOnly')}</p></div></div>
}
