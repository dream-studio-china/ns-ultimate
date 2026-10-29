import { useState } from 'react'
import { Dialog, SearchBar } from 'antd-mobile'
import { ArrowDown, ArrowUp, Check, ChevronRight, MoreHorizontal, Plus, Printer, SlidersHorizontal, Tag } from 'lucide-react'
import type { Product } from '../domain'
import type { StoreState } from '../use-store'
import { label, money, useI18n } from '../../shared/i18n'
import { Empty, Sheet, Submit } from '../../shared/ui'
import { FoodArt } from '../components/food-art'
import { ProductEditor } from '../components/product-editor'

export function ProductsPage({ store }: { store: StoreState }) {
  const { t } = useI18n()
  const [category, setCategory] = useState('all')
  const [query, setQuery] = useState('')
  const [editing, setEditing] = useState<Product | null | undefined>(undefined)
  const [actions, setActions] = useState<string | null>(null)
  const [categoriesOpen, setCategoriesOpen] = useState(false)
  const [categoryName, setCategoryName] = useState('')
  const soldOut = store.products.filter((product) => product.soldOut).length
  const selected = store.products.find((product) => product.id === actions)
  const visible = store.products.filter((product) => (category === 'all' || product.categoryId === category) && `${label(product.name, t)} ${product.code}`.toLowerCase().includes(query.toLowerCase().trim()))
  return <>
    <div className="page-title-row"><div><p className="eyebrow">THE KITCHEN EDIT</p><h1>{t('kitchenTitle')}</h1><p>{t('kitchenSubtitle')}</p></div><button className="add-circle" aria-label={t('addProduct')} onClick={() => setEditing(null)}><Plus size={22} /></button></div>
    <div className="availability-summary"><div><span className="status-dot" />{t('onSale')} <strong>{store.products.length - soldOut}</strong><span className="mx-3 opacity-20">|</span><span className="text-[#9c7869]">{t('soldOut')} <strong>{soldOut}</strong></span></div><button disabled={!soldOut || !store.online} onClick={async () => { if (await Dialog.confirm({ title: t('restoreAllTitle'), content: t('restoreAllHint'), confirmText: t('confirm'), cancelText: t('cancel') })) store.restoreProducts() }}>{t(soldOut ? 'restoreAll' : 'allAvailable')}</button></div>
    <SearchBar placeholder={t('searchProducts')} value={query} onChange={setQuery} aria-label={t('searchProducts')} />
    <div className="filter-row"><div className="chips"><button className={category === 'all' ? 'active' : ''} onClick={() => setCategory('all')}>{t('all')}</button>{store.categories.map((item) => <button className={category === item.id ? 'active' : ''} key={item.id} onClick={() => setCategory(item.id)}>{label(item.name, t)}</button>)}</div><button className="icon-button filter-control" aria-label={t('categoryManagement')} onClick={() => setCategoriesOpen(true)}><SlidersHorizontal size={18} /></button></div>
    <div className="product-list">{visible.length ? visible.map((product) => <article className={`product-row ${product.soldOut ? 'sold-out' : ''}`} key={product.id}>
      <FoodArt category={product.categoryId} cover={product.cover} />
      <div className="product-info"><div className="flex items-start justify-between gap-1"><button className="product-name" onClick={() => setEditing(product)}>{label(product.name, t)}</button><button className="icon-button compact" aria-label={`${t('more')} ${label(product.name, t)}`} onClick={() => setActions(product.id)}><MoreHorizontal size={19} /></button></div><p>{label(store.categories.find((item) => item.id === product.categoryId)?.name ?? 'all', t)}{product.variants.length > 1 && ` · ${product.variants.length} ${t('specifications')}`}</p><div className="mt-3 flex items-center justify-between gap-2"><strong className="price">{money(product.variants[0].price)}</strong><button className={`availability-toggle ${product.soldOut ? 'off' : ''}`} disabled={!store.online} onClick={() => store.toggleProduct(product.id)} aria-label={`${t(product.soldOut ? 'restore' : 'markSoldOut')} ${label(product.name, t)}`}>{product.soldOut ? t('restore') : <><Check size={12} />{t('onSale')}</>}</button></div></div>
    </article>) : <Empty title={t('noProducts')} hint={t('noProductsHint')} />}</div>
    <p className="page-note">{t('sessionNotice')}</p>
    {editing !== undefined && <ProductEditor key={editing?.id ?? 'new'} product={editing} categories={store.categories} online={store.online} onClose={() => setEditing(undefined)} onSave={store.saveProduct} />}
    <Sheet open={!!selected} onClose={() => setActions(null)} title={selected ? label(selected.name, t) : ''}>
      {selected && <div className="action-list">
        <button onClick={() => { setEditing(selected); setActions(null) }}><Tag size={18} />{t('editProduct')}<ChevronRight size={16} /></button>
        <button disabled={!store.online} onClick={() => { store.saveProduct({ ...selected, kitchenPrint: !selected.kitchenPrint }); setActions(null) }}><Printer size={18} />{t('kitchenPrint')}<span className="ml-auto text-xs">{t(selected.kitchenPrint ? 'enabled' : 'disabled')}</span></button>
        <button disabled={!store.online} onClick={() => { store.moveProduct(selected.id, -1); setActions(null) }}><ArrowUp size={18} />{t('moveUp')}</button>
        <button disabled={!store.online} onClick={() => { store.moveProduct(selected.id, 1); setActions(null) }}><ArrowDown size={18} />{t('moveDown')}</button>
        <button className="danger" disabled={!store.online} onClick={async () => { if (await Dialog.confirm({ title: t('deleteProductTitle'), content: t('deleteProductHint'), confirmText: t('delete'), cancelText: t('cancel') })) { store.deleteProduct(selected.id); setActions(null) } }}>{t('delete')}</button>
      </div>}
    </Sheet>
    <Sheet open={categoriesOpen} onClose={() => setCategoriesOpen(false)} title={t('categoryManagement')} footer={<Submit disabled={!store.online} onClick={() => { if (store.addCategory(categoryName)) setCategoryName('') }}>{t('addCategory')}</Submit>}>
      <div className="category-list">{store.categories.map((item, index) => <div key={item.id}><span>{label(item.name, t)}</span><button className="icon-button" disabled={index === 0 || !store.online} aria-label={`${t('moveUp')} ${label(item.name, t)}`} onClick={() => store.moveCategory(item.id, -1)}><ArrowUp size={17} /></button><button className="icon-button" disabled={index === store.categories.length - 1 || !store.online} aria-label={`${t('moveDown')} ${label(item.name, t)}`} onClick={() => store.moveCategory(item.id, 1)}><ArrowDown size={17} /></button></div>)}</div>
      <label className="field mt-5">{t('categoryName')}<input maxLength={40} value={categoryName} onChange={(event) => setCategoryName(event.target.value)} placeholder={t('categoryName')} /></label>
    </Sheet>
  </>
}
