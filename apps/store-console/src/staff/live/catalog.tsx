import { useCallback, useState } from 'react'
import { Button } from 'antd-mobile'
import { ArrowUpDown, ChevronDown, CookingPot, Layers3, Pencil, Plus, Tag, Trash2 } from 'lucide-react'
import { useI18n } from '../../shared/i18n'
import { can, type StoreAccess, StoreApi, type LiveProduct, type LiveCategory, type LiveSpecification } from '../adapters/live'
import { parseAmount } from '../domain'
import { AccessNotice, EditorAdvanced, EditorPreview, EditorSection, EditorSheet, EditorToggle, ListFeedback, Notice, PageControls, PageHeading, PageScope, RecordIdentity, SearchField, StatusPill, useDelete, usePage, LiveSheet as Sheet } from './ui'

function StatusField({ value, set }: { value: 'active' | 'inactive'; set: (value: 'active' | 'inactive') => void }) {
  return <EditorToggle label="productStatus" hint="listingHint" checked={value === 'active'} onChange={(enabled) => set(enabled ? 'active' : 'inactive')} />
}

function ProductForm({ api, access, product, done, onClose }: { api: StoreApi; access: StoreAccess; product: LiveProduct | null; done: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const [name, setName] = useState(product?.name ?? '')
  const [status, setStatus] = useState(product?.status ?? 'active')
  const [category, setCategory] = useState(product?.category?.uuid ?? '')
  return <EditorSheet title={t('basicProduct')} description="productInfoHint" onClose={onClose} onDone={done} submit={() => api.save('products', product?.uuid ?? null, { name: name.trim(), status, category: category || null })} preview={<EditorPreview icon={CookingPot} label="products" name={name.trim() || t('editorNewProduct')}><StatusPill muted={status !== 'active'}>{t(status === 'active' ? 'listingActive' : 'listingInactive')}</StatusPill></EditorPreview>}>
    <EditorSection title="editorBasics" index="01"><label className="field">{t('productName')}<input required maxLength={60} value={name} placeholder={t('productName')} onChange={(event) => setName(event.target.value)} /></label><CategoryPicker api={api} enabled={can(access, 'product_category', 'read')} value={category} choose={setCategory} /></EditorSection>
    <EditorSection title="editorAvailability" index="02"><StatusField value={status} set={setStatus} /></EditorSection>
    <EditorAdvanced><label className="field">{t('category')} UUID<input pattern="[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}" value={category} onChange={(event) => setCategory(event.target.value)} /></label></EditorAdvanced>
  </EditorSheet>
}
function CategoryPicker({ api, value, choose, enabled }: { api: StoreApi; value: string; choose: (uuid: string) => void; enabled: boolean }) {
  const { t } = useI18n()
  const load = useCallback((page: number) => api.categories(page), [api])
  const list = usePage(load, enabled)
  return <div className="category-picker"><label className="field">{t('category')}<select value={value} onChange={(event) => choose(event.target.value)}><option value="">{t('uncategorized')}</option>{value && !list.items.some((category) => category.uuid === value) && <option value={value}>{t('currentCategory')} · {value.slice(0, 8)}</option>}{list.items.map((category) => <option value={category.uuid} disabled={!category.enabled} key={category.uuid}>{category.name}</option>)}</select></label>{list.loading && <p className="helper" role="status">{t('loading')}</p>}{list.error && <p className="error-text" role="alert">{t(list.error)}</p>}{(list.hasNext || list.page > 1) && <PageControls state={list} />}</div>
}
function CategoryForm({ api, category, done, onClose }: { api: StoreApi; category: LiveCategory | null; done: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const [name, setName] = useState(category?.name ?? '')
  const [slug, setSlug] = useState(category?.slug ?? '')
  const [sort, setSort] = useState(category?.sortOrder ?? 0)
  const [enabled, setEnabled] = useState(category?.enabled ?? true)
  return <EditorSheet title={t('categoryManagement')} description="categoryHint" onClose={onClose} onDone={done} submit={() => api.save('product-categories', category?.uuid ?? null, { name: name.trim(), slug: slug.trim(), sortOrder: sort, enabled })} preview={<EditorPreview icon={Tag} label="categoryManagement" name={name.trim() || t('editorNewCategory')} detail={slug}><StatusPill muted={!enabled}>{t(enabled ? 'enabled' : 'disabled')}</StatusPill></EditorPreview>}>
    <EditorSection title="editorBasics" index="01"><label className="field">{t('categoryName')}<input required maxLength={100} value={name} placeholder={t('categoryName')} onChange={(event) => setName(event.target.value)} /></label><label className="field">{t('slug')}<input required maxLength={100} value={slug} placeholder="category-slug" onChange={(event) => setSlug(event.target.value)} /></label></EditorSection>
    <EditorSection title="editorAvailability" index="02"><EditorToggle label="enabledField" hint="categoryEnabledHint" checked={enabled} onChange={setEnabled} /></EditorSection>
    <EditorAdvanced><label className="field">{t('sortOrder')}<input required type="number" step="1" value={sort} onChange={(event) => setSort(Number(event.target.value))} /></label></EditorAdvanced>
  </EditorSheet>
}
function SpecificationForm({ api, currency, product, specification, done, onClose }: { api: StoreApi; currency: string; product: LiveProduct; specification: LiveSpecification | null; done: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const [name, setName] = useState(specification?.name ?? '')
  const [price, setPrice] = useState(specification ? (specification.price / 100).toFixed(2) : '')
  const [status, setStatus] = useState(specification?.status ?? 'active')
  const [sort, setSort] = useState(specification?.sort ?? 0)
  return <EditorSheet title={t('specifications')} description="variantHint" onClose={onClose} onDone={done} submit={() => api.saveSpecification(product.uuid, specification?.uuid ?? null, { name: name.trim(), price: parseAmount(price), status, sort })} preview={<EditorPreview icon={Layers3} label="specifications" name={name.trim() || t('editorNewVariant')} detail={product.name}><StatusPill muted={status !== 'active'}>{t(status === 'active' ? 'listingActive' : 'listingInactive')}</StatusPill></EditorPreview>}>
    <EditorSection title="editorBasics" index="01"><label className="field">{t('specificationName')}<input required maxLength={100} value={name} placeholder={t('specificationName')} onChange={(event) => setName(event.target.value)} /></label><label className="field editor-price-field">{t('livePrice')} ({currency})<input className="variant-price-input" required inputMode="decimal" pattern="[0-9]{1,6}(\.[0-9]{1,2})?" placeholder="0.00" value={price} onChange={(event) => setPrice(event.target.value)} /></label></EditorSection>
    <EditorSection title="editorAvailability" index="02"><StatusField value={status} set={setStatus} /></EditorSection>
    <EditorAdvanced><label className="field">{t('sortOrder')}<input required type="number" step="1" value={sort} onChange={(event) => setSort(Number(event.target.value))} /></label></EditorAdvanced>
  </EditorSheet>
}

export function LiveCatalog({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const [tab, setTab] = useState<'products' | 'categories'>('products')
  return <>
    <PageHeading eyebrow="catalogEyebrow" title="kitchenTitle" subtitle="catalogSubtitle" />
    <div className="workspace-tabs"><button className={tab === 'products' ? 'active' : ''} onClick={() => setTab('products')}><CookingPot size={16} />{t('products')}</button><button className={tab === 'categories' ? 'active' : ''} onClick={() => setTab('categories')}><Tag size={16} />{t('categoryManagement')}</button></div>
    {tab === 'products' ? <ProductList api={api} access={access} /> : <CategoryList api={api} access={access} />}
    <details className="workspace-capability"><summary>{t('serviceConnection')}<ChevronDown size={14} /></summary><p>{t('extensionUnavailable')}</p></details>
  </>
}
function ProductVisual({ product }: { product: LiveProduct }) {
  const [broken, setBroken] = useState(false)
  const cover = product.metadata?.cover
  const safe = cover && (/^https:\/\//.test(cover) || /^\/(?!\/)/.test(cover)) && !cover.includes('\\')
  return <div className="catalog-product-visual" aria-hidden="true">{safe && !broken ? <img src={cover} alt="" loading="lazy" referrerPolicy="no-referrer" onError={() => setBroken(true)} /> : <><CookingPot size={31} strokeWidth={1.15} /><span>{product.name.slice(0, 1)}</span></>}</div>
}
function ProductList({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const load = useCallback((page: number) => api.products(page), [api])
  const list = usePage(load, can(access, 'product', 'read'))
  const deletion = useDelete(list.reload)
  const [editing, setEditing] = useState<LiveProduct | null | undefined>()
  const [specs, setSpecs] = useState<LiveProduct | null>(null)
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState<'all' | 'active' | 'inactive'>('all')
  const visible = list.items.filter((product) => (status === 'all' || product.status === status) && `${product.name} ${product.uuid}`.toLowerCase().includes(query.trim().toLowerCase()))
  if (!can(access, 'product', 'read')) return <AccessNotice />
  return <>
    <PageScope count={list.items.length} />
    <div className="workspace-toolbar"><SearchField value={query} onChange={setQuery} /><Button className="workspace-primary" color="primary" disabled={!can(access, 'product', 'create') || deletion.busy} onClick={() => setEditing(null)}><Plus size={17} />{t('addProduct')}</Button></div>
    <div className="workspace-filters">{(['all', 'active', 'inactive'] as const).map((value) => <button key={value} className={status === value ? 'active' : ''} aria-pressed={status === value} onClick={() => setStatus(value)}>{t(value === 'all' ? 'allStatuses' : value === 'active' ? 'listingActive' : 'listingInactive')}</button>)}</div>
    {deletion.feedback}
    <ListFeedback state={{ ...list, items: visible }} empty={list.items.length ? 'noSearchResults' : 'firstProduct'} hint={list.items.length ? 'adjustSearch' : 'firstProductHint'} icon={CookingPot} />
    <div className="catalog-product-grid">{visible.map((product) => <article className="catalog-product-card" key={product.uuid}>
      <div className="catalog-product-top"><ProductVisual product={product} /><div className="catalog-product-info"><StatusPill muted={product.status !== 'active'}>{t(product.status === 'active' ? 'listingActive' : 'listingInactive')}</StatusPill><h2>{product.name}</h2><p>{product.metadata?.subtitle || t('specifications')}{product.isSoldout && ` · ${t('soldOut')}`}</p></div></div>
      <div className="catalog-product-actions"><Button disabled={!can(access, 'product', 'update') || deletion.busy} onClick={() => setEditing(product)}><Pencil size={14} />{t('edit')}</Button><Button disabled={!can(access, 'specification', 'read') || deletion.busy} onClick={() => setSpecs(product)}><Layers3 size={14} />{t('specManagement')}</Button><button className="workspace-icon danger" aria-label={t('delete')} disabled={!can(access, 'product', 'delete') || deletion.busy} onClick={() => void deletion.remove(() => api.remove('products', product.uuid))}><Trash2 size={16} /></button></div>
      <RecordIdentity id={product.uuid} />
    </article>)}</div>
    <PageControls state={list} />
    {editing !== undefined && <ProductForm api={api} access={access} product={editing} onClose={() => setEditing(undefined)} done={() => { setEditing(undefined); list.reload() }} />}
    {specs && <Sheet open title={`${specs.name} · ${t('specifications')}`} onClose={() => setSpecs(null)}><SpecificationList key={specs.uuid} api={api} access={access} product={specs} /></Sheet>}
  </>
}
function CategoryList({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const load = useCallback((page: number) => api.categories(page), [api])
  const list = usePage(load, can(access, 'product_category', 'read'))
  const deletion = useDelete(list.reload)
  const [editing, setEditing] = useState<LiveCategory | null | undefined>()
  const [query, setQuery] = useState('')
  const visible = list.items.filter((category) => `${category.name} ${category.slug}`.toLowerCase().includes(query.trim().toLowerCase()))
  if (!can(access, 'product_category', 'read')) return <AccessNotice />
  return <><PageScope count={list.items.length} /><div className="workspace-toolbar"><SearchField value={query} onChange={setQuery} /><Button color="primary" className="workspace-primary" disabled={!can(access, 'product_category', 'create') || deletion.busy} onClick={() => setEditing(null)}><Plus size={17} />{t('addCategory')}</Button></div>{deletion.feedback}
    <ListFeedback state={{ ...list, items: visible }} empty={list.items.length ? 'noSearchResults' : 'firstCategory'} hint={list.items.length ? 'adjustSearch' : 'firstCategoryHint'} icon={Tag} />
    <div className="workspace-category-list">{visible.map((category) => <article key={category.uuid}><div className="category-main"><span className="category-symbol"><Tag size={21} strokeWidth={1.5} /></span><div><h2>{category.name}</h2><p>{category.slug}</p></div><StatusPill muted={!category.enabled}>{t(category.enabled ? 'enabled' : 'disabled')}</StatusPill></div><div className="category-meta"><span><ArrowUpDown size={13} />{t('sortOrder')} {category.sortOrder}</span><div><Button disabled={!can(access, 'product_category', 'update') || deletion.busy} onClick={() => setEditing(category)}><Pencil size={14} />{t('edit')}</Button><button className="workspace-icon danger" aria-label={t('delete')} disabled={!can(access, 'product_category', 'delete') || deletion.busy} onClick={() => void deletion.remove(() => api.remove('product-categories', category.uuid))}><Trash2 size={16} /></button></div></div><RecordIdentity id={category.uuid} /></article>)}</div>
    <PageControls state={list} />
    {editing !== undefined && <CategoryForm api={api} category={editing} onClose={() => setEditing(undefined)} done={() => { setEditing(undefined); list.reload() }} />}
  </>
}
function SpecificationList({ api, access, product }: { api: StoreApi; access: StoreAccess; product: LiveProduct }) {
  const { t } = useI18n()
  const load = useCallback((page: number) => api.specifications(product.uuid, page), [api, product.uuid])
  const list = usePage(load, can(access, 'specification', 'read'))
  const deletion = useDelete(list.reload)
  const [editing, setEditing] = useState<LiveSpecification | null | undefined>()
  return <><Notice icon={Layers3} subtle>{t('variantHint')}</Notice><div className="variant-toolbar"><span>{t('specifications')}</span><Button className="workspace-primary" color="primary" disabled={!can(access, 'specification', 'create') || deletion.busy} onClick={() => setEditing(null)}><Plus size={16} />{t('addSpec')}</Button></div>{deletion.feedback}
    <ListFeedback state={list} empty="emptyLive" hint="chooseProduct" icon={Layers3} />
    <div className="workspace-variant-list">{list.items.map((specification) => <article key={specification.uuid}><div><h2>{specification.name}</h2><StatusPill muted={specification.status !== 'active'}>{t(specification.status === 'active' ? 'listingActive' : 'listingInactive')}</StatusPill></div><strong className="variant-price">{new Intl.NumberFormat(undefined, { style: 'currency', currency: access.store.currency }).format(specification.price / 100)}</strong><div className="variant-actions"><Button disabled={!can(access, 'specification', 'update') || deletion.busy} onClick={() => setEditing(specification)}><Pencil size={14} />{t('edit')}</Button><button className="workspace-icon danger" aria-label={t('delete')} disabled={!can(access, 'specification', 'delete') || deletion.busy} onClick={() => void deletion.remove(() => api.removeSpecification(product.uuid, specification.uuid))}><Trash2 size={16} /></button></div></article>)}</div>
    <PageControls state={list} />
    {editing !== undefined && <SpecificationForm api={api} currency={access.store.currency} product={product} specification={editing} onClose={() => setEditing(undefined)} done={() => { setEditing(undefined); list.reload() }} />}
  </>
}
