import { useState } from 'react'
import { Button, SearchBar } from 'antd-mobile'
import { ArrowUpRight, Clock3, Plus, QrCode, UsersRound } from 'lucide-react'
import type { Receipt, Table } from '../domain'
import { due } from '../domain'
import type { StoreState } from '../use-store'
import { money, time, useI18n } from '../../shared/i18n'
import { Empty, Sheet, Submit } from '../../shared/ui'
import { TableDetail } from '../components/table-detail'
import { ReceiptSheet } from '../components/receipt'

export function TableCard({ table, onClick }: { table: Table; onClick: () => void }) {
  const { t } = useI18n()
  const occupied = table.lines.length > 0
  return <button className={`table-card ${occupied ? 'occupied' : ''}`} onClick={onClick} aria-label={`${table.name} ${t(table.paymentId ? 'settling' : occupied ? 'dining' : 'idle')}`}>
    <div className="flex items-center justify-between"><strong>{table.name}</strong><span className={`table-state ${occupied ? 'active' : ''}`}>{t(table.paymentId ? 'settling' : occupied ? 'dining' : 'idle')}</span></div>
    <p className="table-guests"><UsersRound size={13} />{occupied ? table.guests : table.capacity} {t('guestUnit')}{occupied && <span className="ml-auto flex items-center gap-1"><Clock3 size={12} />{Math.max(1, Math.floor((Date.now() - (table.openedAt ?? Date.now())) / 60000))}′</span>}</p>
    <div className="table-bottom"><span>{occupied ? money(due(table)) : '—'}</span><ArrowUpRight size={16} /></div>
  </button>
}
export function TablesPage({ store, initialPaid = false }: { store: StoreState; initialPaid?: boolean }) {
  const { t, locale } = useI18n()
  const [paid, setPaid] = useState(initialPaid)
  const [filter, setFilter] = useState('all')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [receipt, setReceipt] = useState<Receipt | null>(null)
  const [adding, setAdding] = useState(false)
  const [codes, setCodes] = useState(false)
  const [name, setName] = useState('')
  const [capacity, setCapacity] = useState('4')
  const [query, setQuery] = useState('')
  const [date, setDate] = useState('')
  const selected = store.tables.find((item) => item.id === selectedId)
  const occupied = store.tables.filter((table) => table.lines.length > 0).length
  const receipts = store.receipts.filter((receipt) => `${receipt.id} ${receipt.tableName}`.toLowerCase().includes(query.toLowerCase()) && (!date || new Date(receipt.paidAt).toLocaleDateString('en-CA') === date))
  return <>
    <div className="page-title-row"><div><p className="eyebrow">A SEAT FOR EVERY STORY</p><h1>{t('tableTitle')}</h1><p>{t('tableSubtitle')}</p></div><button className="icon-button" onClick={() => setCodes(true)} aria-label={t('tableCodes')}><QrCode size={22} /></button></div>
    <div className="segmented"><button className={!paid ? 'active' : ''} onClick={() => setPaid(false)}>{t('unpaid')}<span>{occupied}</span></button><button className={paid ? 'active' : ''} onClick={() => setPaid(true)}>{t('paid')}<span>{store.receipts.length}</span></button></div>
    {!paid ? <>
      <div className="table-toolbar"><div className="chips"><button className={filter === 'all' ? 'active' : ''} onClick={() => setFilter('all')}>{t('allTables')}</button><button className={filter === 'dining' ? 'active' : ''} onClick={() => setFilter('dining')}>{t('dining')}</button><button className={filter === 'idle' ? 'active' : ''} onClick={() => setFilter('idle')}>{t('idle')}</button></div><span>{occupied}/{store.tables.length} {t('tablesUnit')}</span></div>
      <div className="table-grid">{store.tables.filter((table) => filter === 'all' || (table.lines.length > 0 ? filter === 'dining' : filter === 'idle')).map((table) => <TableCard key={table.id} table={table} onClick={() => setSelectedId(table.id)} />)}<button className="add-table" disabled={!store.online} onClick={() => setAdding(true)}><Plus size={24} /><span>{t('addTable')}</span></button></div>
    </> : <>
      <div className="mt-5"><SearchBar aria-label={t('searchOrders')} placeholder={t('searchOrders')} value={query} onChange={setQuery} /><label className="history-date">{t('paidAt')}<input aria-label={t('paidAt')} type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label></div>
      {receipts.length ? <div className="history-list">{receipts.map((item) => <button key={item.id} onClick={() => setReceipt(item)}><div><strong>{item.tableName}<small>{item.id}</small></strong><b>{money(item.total)}</b></div><p>{time(item.paidAt, locale)}<span>{t(item.method)} · {t('paid')}</span></p></button>)}</div> : <Empty title={t('emptyHistory')} />}
    </>}
    <p className="page-note">{t('sessionNotice')}</p>
    {selected && <TableDetail key={selected.id} table={selected} store={store} onClose={() => setSelectedId(null)} />}
    <ReceiptSheet receipt={receipt} onClose={() => setReceipt(null)} />
    <Sheet open={adding} onClose={() => setAdding(false)} title={t('addTable')} footer={<Submit disabled={!store.online} onClick={() => { if (store.addTable(name, Number(capacity))) { setAdding(false); setName('') } }}>{t('save')}</Submit>}>
      <div className="form-stack"><label className="field">{t('tableName')}<input maxLength={20} value={name} onChange={(event) => setName(event.target.value)} placeholder="A09" /></label><label className="field">{t('capacity')}<input inputMode="numeric" value={capacity} onChange={(event) => setCapacity(event.target.value)} /></label></div>
    </Sheet>
    <Sheet open={codes} onClose={() => setCodes(false)} title={t('codesTitle')}><div className="code-placeholder"><QrCode size={64} strokeWidth={1} /><h3>{t('noQr')}</h3><p>{t('codesHint')}</p></div><Button block onClick={() => setCodes(false)}>{t('close')}</Button></Sheet>
  </>
}
