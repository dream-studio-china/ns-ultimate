import { useState } from 'react'
import { ArrowRight, Coffee, Croissant, IceCreamBowl, Leaf, Plus, Search, SearchX, SlidersHorizontal, Sparkles } from 'lucide-react'
import type { Product, Category } from '../domain/catalog'
import type { Selection } from '../domain/cart'
import { useI18n, money } from '../lib/i18n'
import { cn } from '../lib/utils'
import { Button } from './ui/button'
import { Dialog } from './ui/dialog'
import { ProductArt } from './product-art'
import { QuantityControl } from './quantity-control'

const categories = [{ id: 'all', icon: SlidersHorizontal }, { id: 'coffee', icon: Coffee }, { id: 'tea', icon: Leaf }, { id: 'bakery', icon: Croissant }, { id: 'dessert', icon: IceCreamBowl }] as const
export function Catalog({ products, disabled, onAdd }: { products: Product[]; disabled: boolean; onAdd: (product: Product, selection: Selection, quantity: number) => void }) {
  const { t } = useI18n()
  const [category, setCategory] = useState<Category | 'all'>('all')
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<Product | null>(null)
  const [size, setSize] = useState<Selection['size']>('regular')
  const [temperature, setTemperature] = useState<Selection['temperature']>('hot')
  const [quantity, setQuantity] = useState(1)
  const filtered = products.filter((product) => (category === 'all' || product.category === category) && `${t(product.name)} ${product.code}`.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()))
  const select = (product: Product) => { setSize('regular'); setTemperature('hot'); setQuantity(1); setSelected(product) }
  return <section className="catalog-panel flex min-w-0 flex-1 flex-col overflow-hidden">
    <div className="px-7 pt-7"><div className="mb-6 flex items-end justify-between gap-3"><div><p className="eyebrow mb-2">GOOD COFFEE, GOOD DAY</p><h1 className="text-[25px] font-semibold tracking-tight">{t('welcome')}</h1><p className="mt-2 text-sm text-muted-foreground">{t('subtitle')}</p></div><div className="hidden rounded-2xl border border-[#e1e6db] bg-[#edf1e7] px-4 py-3 text-[#61704f] xl:block"><Coffee className="size-7" strokeWidth={1.3} /></div></div>
      <div className="relative"><Search className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" /><input className="search-input w-full pr-16 pl-12" aria-label={t('searchLabel')} placeholder={t('search')} value={search} onChange={(event) => setSearch(event.target.value)} /><span className="absolute right-4 top-1/2 -translate-y-1/2 rounded border bg-background px-1.5 py-1 font-mono text-[10px] text-muted-foreground">⌕</span></div>
      <div className="no-scrollbar mt-5 flex gap-2 overflow-x-auto pb-1" aria-label={t('all')}>{categories.map(({ id, icon: Icon }) => <button key={id} onClick={() => setCategory(id)} aria-pressed={category === id} className={cn('flex min-h-12 shrink-0 items-center gap-2 rounded-xl border px-4 text-sm font-medium transition-colors', category === id ? 'border-primary bg-primary text-primary-foreground' : 'border-transparent bg-white text-muted-foreground hover:border-border')}><Icon className="size-4" />{t(id)}</button>)}</div>
      <div className="mt-5 mb-3 flex items-center justify-between"><h2 className="flex items-center gap-2 text-sm font-semibold">{t(category)}<span className="rounded-md bg-[#e8ece5] px-1.5 py-0.5 text-[11px] font-normal text-muted-foreground">{filtered.length}</span></h2><span className="text-xs text-muted-foreground">{t('scanHint')}</span></div>
    </div>
    <div className="min-h-0 flex-1 overflow-y-auto px-7 pb-7">
      {filtered.length ? <div className="product-grid grid grid-cols-2 gap-4 xl:grid-cols-3 2xl:grid-cols-4">{filtered.map((product) => <button key={product.id} disabled={product.soldOut || disabled} onClick={() => select(product)} aria-label={`${t(product.name)} ${money(product.price)}`} className={cn('product-card group relative overflow-hidden rounded-2xl border border-white bg-white text-left transition-all hover:border-primary/25 hover:shadow-md focus-visible:ring-3 focus-visible:ring-primary/40 disabled:cursor-not-allowed', product.soldOut && 'opacity-55')}>
        <ProductArt kind={product.illustration} className="aspect-[1.6] w-full transition-transform group-hover:scale-[1.025]" />
        {product.popular && <span className="absolute left-2.5 top-2.5 flex items-center gap-1 rounded-md bg-white/85 px-2 py-1 text-[10px] font-medium text-[#806746]"><Sparkles className="size-3" />{t('popular')}</span>}
        {product.soldOut && <span className="absolute inset-x-0 top-[28%] text-center text-sm font-semibold text-foreground">{t('soldOut')}</span>}
        <div className="relative p-3.5"><h3 className="truncate text-sm font-semibold">{t(product.name)}</h3><p className="mt-1.5 font-mono text-[10px] tracking-wide text-muted-foreground">NO. {product.code}</p><div className="mt-3 flex items-end justify-between"><span className="font-semibold tabular-nums">{money(product.price)}{product.customizable && <span className="ml-1 text-[10px] font-normal text-muted-foreground">{t('from')}</span>}</span><span className="flex size-8 items-center justify-center rounded-full bg-[#eff3e9] text-primary group-hover:bg-primary group-hover:text-white"><Plus className="size-4" /></span></div></div>
      </button>)}</div> : <div className="empty-state"><SearchX /><h3>{t('noProducts')}</h3><p>{t('noProductsHint')}</p><Button variant="outline" onClick={() => { setSearch(''); setCategory('all') }}>{t('resetSearch')}</Button></div>}
    </div>
    <Dialog open={!!selected} onOpenChange={(open) => { if (!open) setSelected(null) }} title={selected ? t(selected.name) : ''} description={selected ? t(selected.description) : ''}>
      {selected && <><ProductArt kind={selected.illustration} className="mb-6 h-44 rounded-2xl" />
        {selected.customizable && <><fieldset className="mb-5"><legend className="field-label">{t('size')}</legend><div className="grid grid-cols-2 gap-3">{(['regular', 'large'] as const).map((value) => <button className={cn('choice-button', size === value && 'selected')} key={value} aria-pressed={size === value} onClick={() => setSize(value)}>{t(value)}{value === 'large' && <span className="ml-2 text-xs">+¥4</span>}</button>)}</div></fieldset><fieldset className="mb-6"><legend className="field-label">{t('temperature')}</legend><div className="grid grid-cols-2 gap-3">{(['hot', 'iced'] as const).map((value) => <button className={cn('choice-button', temperature === value && 'selected')} key={value} aria-pressed={temperature === value} onClick={() => setTemperature(value)}>{t(value)}</button>)}</div></fieldset></>}
        <div className="mb-6 flex items-center justify-between"><span className="text-sm font-medium">{t('quantity')}</span><QuantityControl quantity={quantity} onChange={setQuantity} min={1} /></div>
        <Button size="lg" className="w-full justify-between" disabled={disabled} onClick={() => { onAdd(selected, { size, temperature }, quantity); setSelected(null) }}><span>{t('addToCart')}</span><span className="flex items-center gap-3 tabular-nums">{money((selected.price + (selected.customizable && size === 'large' ? 400 : 0)) * quantity)}<ArrowRight /></span></Button>
      </>}
    </Dialog>
  </section>
}
