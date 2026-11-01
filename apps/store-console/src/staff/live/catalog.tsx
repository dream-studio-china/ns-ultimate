import { useCallback, useEffect, useRef, useState } from 'react'
import { Button } from 'antd-mobile'
import { ArrowDown, ArrowUp, ArrowUpDown, Ban, ChevronDown, CookingPot, Layers3, Pencil, Plus, RotateCcw, Tag, Trash2 } from 'lucide-react'
import { useI18n } from '../../shared/i18n'
import { can, type StoreAccess, StoreApi, type LiveProduct, type LiveCategory, type LiveSpecification } from '../adapters/live'
import { parseAmount } from '../domain'
import { AccessNotice, EditorAdvanced, EditorPreview, EditorSection, EditorSheet, EditorToggle, errorKey, ListFeedback, Notice, PageControls, PageScope, RecordIdentity, SearchField, StatusPill, useCommand, useDelete, usePage, LiveSheet as Sheet } from './ui'
import { categorySlug } from './category-slug'

function moveItem<T>(items: readonly T[], index: number, direction: -1 | 1): T[] {
  const destination = index + direction
  if (index < 0 || destination < 0 || destination >= items.length) return [...items]
  const moved = [...items]
  ;[moved[index], moved[destination]] = [moved[destination], moved[index]]
  return moved
}

function StatusField({ value, set }: { value: 'active' | 'inactive'; set: (value: 'active' | 'inactive') => void }) {
  return <EditorToggle label="productStatus" hint="listingHint" checked={value === 'active'} onChange={(enabled) => set(enabled ? 'active' : 'inactive')} />
}

function ProductForm({ api, access, product, done, onClose }: { api: StoreApi; access: StoreAccess; product: LiveProduct | null; done: () => void; onClose: () => void }) {
  const { t } = useI18n()
  const [name, setName] = useState(product?.name ?? '')
  const [status, setStatus] = useState(product?.status ?? 'active')
  const [category, setCategory] = useState(product?.category?.uuid ?? '')
  const [description, setDescription] = useState(product?.description ?? '')
  const [subtitle, setSubtitle] = useState(product?.metadata?.subtitle ?? '')
  const [isSoldout, setSoldout] = useState(product?.isSoldout ?? false)
  const [sortOrder, setSortOrder] = useState(product?.sortOrder ?? 0)
  const [recommendationLevel, setRecommendation] = useState(product?.recommendationLevel ?? 0)
  const [kitchenPrinterEnable, setKitchen] = useState(product?.settings.kitchenPrinterEnable ?? false)
  // Sale windows are temporarily hidden in the kitchen UI, but existing values must survive edits.
  const [startSaleTime] = useState(product?.settings.startSaleTime ?? '')
  const [endSaleTime] = useState(product?.settings.endSaleTime ?? '')
  const [image, setImage] = useState<File | null>(null)
  const [uploadCommand, setUploadCommand] = useState(() => crypto.randomUUID())
  const [removeCover, setRemoveCover] = useState(false)
  const imageInput = useRef<HTMLInputElement>(null)
  const uploaded = useRef<{ file: File; uuid: string } | null>(null)
  const extended = product?.extensionEditable ?? true
  const body = { name: name.trim(), status, category: category || null, description: description.trim(), subtitle: subtitle.trim(),
    ...(extended ? { isSoldout, sortOrder, recommendationLevel, kitchenPrinterEnable, startSaleTime, endSaleTime } : {}),
    ...(product ? { version: product.version } : {}), ...(removeCover ? { coverImageUuid: null } : {}) }
  const commandUuid = useCommand({ body, image: image ? uploadCommand : null })
  return <EditorSheet title={t('basicProduct')} description="productInfoHint" onClose={onClose} onDone={done} submit={async () => {
    let coverImageUuid: string | null | undefined = removeCover ? null : undefined
    if (image) {
      if (uploaded.current?.file !== image) uploaded.current = { file: image, uuid: (await api.uploadImage(image, uploadCommand)).uuid }
      coverImageUuid = uploaded.current.uuid
    }
    await api.saveProduct(product?.uuid ?? null, { ...body, commandUuid, ...(coverImageUuid !== undefined ? { coverImageUuid } : {}) })
  }} preview={<EditorPreview icon={CookingPot} label="products" name={name.trim() || t('editorNewProduct')}><StatusPill muted={status !== 'active'}>{t(status === 'active' ? 'listingActive' : 'listingInactive')}</StatusPill></EditorPreview>}>
    <EditorSection title="editorBasics" index="01"><label className="field">{t('productName')}<input required maxLength={60} value={name} placeholder={t('productName')} onChange={(event) => setName(event.target.value)} /></label><CategoryPicker api={api} enabled={can(access, 'product_category', 'read')} value={category} choose={setCategory} /></EditorSection>
    <EditorSection title="productDisplay" index="02"><label className="field">{t('productSubtitle')}<input maxLength={255} value={subtitle} onChange={(event) => setSubtitle(event.target.value)} /></label><label className="field">{t('productDescription')}<textarea maxLength={5000} rows={3} value={description} onChange={(event) => setDescription(event.target.value)} /></label><label className="field">{t('coverUpload')}<input ref={imageInput} type="file" accept="image/png,image/jpeg,image/webp" disabled={!can(access, 'catalog_image', 'create')} onChange={(event) => {
      const file = event.target.files?.[0] ?? null
      if (file && (file.size > 2 * 1024 * 1024 || !['image/png', 'image/jpeg', 'image/webp'].includes(file.type))) { event.target.setCustomValidity(t('imageUploadHint')); return }
      event.target.setCustomValidity(''); setImage(file); setUploadCommand(crypto.randomUUID()); setRemoveCover(false)
    }} /><small>{t('imageUploadHint')}</small></label>{(product?.metadata?.cover || image) && !removeCover && <button type="button" className="text-action" onClick={() => { setImage(null); setRemoveCover(true); if (imageInput.current) { imageInput.current.value = ''; imageInput.current.setCustomValidity('') } }}>{t('removeImage')}</button>}</EditorSection>
    <EditorSection title="editorAvailability" index="03"><StatusField value={status} set={setStatus} />{extended && <><EditorToggle label="soldOut" hint="soldoutRuleHint" checked={isSoldout} onChange={setSoldout} /><EditorToggle label="kitchenPrint" hint="kitchenRuleHint" checked={kitchenPrinterEnable} onChange={setKitchen} /></>}</EditorSection>
    <EditorAdvanced><label className="field">{t('category')} UUID<input pattern="[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}" value={category} onChange={(event) => setCategory(event.target.value)} /></label>{extended && <div className="editor-field-grid"><label className="field">{t('sortOrder')}<input required type="number" min="0" max="1000000" step="1" value={sortOrder} onChange={(event) => setSortOrder(Number(event.target.value))} /></label><label className="field">{t('recommendationLevel')}<input required type="number" min="0" max="100" step="1" value={recommendationLevel} onChange={(event) => setRecommendation(Number(event.target.value))} /></label></div>}</EditorAdvanced>
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
  const [slugSeed] = useState(() => crypto.randomUUID())
  const [sort, setSort] = useState(category?.sortOrder ?? 0)
  const [enabled, setEnabled] = useState(category?.enabled ?? true)
  const slug = category?.slug ?? categorySlug(name, slugSeed)
  return <EditorSheet title={t('categoryManagement')} description="categoryHint" onClose={onClose} onDone={done} submit={() => api.save('product-categories', category?.uuid ?? null, { name: name.trim(), slug, sortOrder: sort, enabled })} preview={<EditorPreview icon={Tag} label="categoryManagement" name={name.trim() || t('editorNewCategory')} detail={`${t('sortOrder')} ${sort}`}><StatusPill muted={!enabled}>{t(enabled ? 'enabled' : 'disabled')}</StatusPill></EditorPreview>}>
    <EditorSection title="editorBasics" index="01"><label className="field">{t('categoryName')}<input required maxLength={100} value={name} placeholder={t('categoryName')} onChange={(event) => setName(event.target.value)} /></label></EditorSection>
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
  const products = can(access, 'product', 'read')
  const categories = can(access, 'product_category', 'read')
  const [tab, setTab] = useState<'products' | 'categories'>(products ? 'products' : 'categories')
  return <>
    {products && categories && <div className="workspace-tabs"><button className={tab === 'products' ? 'active' : ''} onClick={() => setTab('products')}><CookingPot size={16} />{t('products')}</button><button className={tab === 'categories' ? 'active' : ''} onClick={() => setTab('categories')}><Tag size={16} />{t('categoryManagement')}</button></div>}
    {tab === 'products' ? <ProductList api={api} access={access} /> : <CategoryList api={api} access={access} />}
    <details className="workspace-capability"><summary>{t('serviceConnection')}<ChevronDown size={14} /></summary><p>{t('catalogOperationalHint')}</p></details>
  </>
}
function ProductVisual({ product }: { product: LiveProduct }) {
  const [broken, setBroken] = useState(false)
  const cover = product.metadata?.cover
  useEffect(() => { setBroken(false) }, [cover])
  const safe = cover && (/^https:\/\//.test(cover) || /^\/(?!\/)/.test(cover)) && !cover.includes('\\')
  return <div className="catalog-product-visual" aria-hidden="true">{safe && !broken ? <img src={cover} alt="" loading="lazy" referrerPolicy="no-referrer" onError={() => setBroken(true)} /> : <><CookingPot size={31} strokeWidth={1.15} /><span>{product.name.slice(0, 1)}</span></>}</div>
}
function ProductList({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const [category, setCategory] = useState('all')
  const loadProducts = useCallback(() => api.allProducts(), [api])
  const loadCategories = useCallback(() => api.allCategories(), [api])
  const list = usePage(loadProducts, can(access, 'product', 'read'))
  const categoryList = usePage(loadCategories, can(access, 'product_category', 'read'))
  const deletion = useDelete(list.reload)
  const [editing, setEditing] = useState<LiveProduct | null | undefined>()
  const [specs, setSpecs] = useState<LiveProduct | null>(null)
  const [availability, setAvailability] = useState<{ products: LiveProduct[]; soldout: boolean } | null>(null)
  const [sorting, setSorting] = useState(false)
  const [sortError, setSortError] = useState<ReturnType<typeof errorKey> | null>(null)
  const sortLock = useRef(false)
  if (!can(access, 'product', 'read')) return <AccessNotice />
  const orderedCategories = [...categoryList.items].sort((left, right) => left.sortOrder - right.sortOrder)
  const categoryNames = new Map(orderedCategories.map((item) => [item.uuid, item.name]))
  const categoryOrder = new Map(orderedCategories.map((item, index) => [item.uuid, index]))
  const grouped = new Map<string, { uuid: string | null; name: string; sort: number; products: LiveProduct[] }>()
  for (const product of list.items) {
    const uuid = product.category?.uuid ?? null
    const key = uuid ?? '__uncategorized__'
    const group = grouped.get(key) ?? {
      uuid,
      name: uuid ? categoryNames.get(uuid) ?? t('products') : t('uncategorized'),
      sort: uuid ? categoryOrder.get(uuid) ?? Number.MAX_SAFE_INTEGER : Number.MAX_SAFE_INTEGER,
      products: [],
    }
    group.products.push(product)
    grouped.set(key, group)
  }
  for (const group of grouped.values()) group.products.sort((left, right) => left.sortOrder - right.sortOrder)
  const categoryGroups = [...grouped.values()].sort((left, right) => left.sort - right.sort || left.name.localeCompare(right.name))
  const visibleGroups = category === 'all' ? categoryGroups : categoryGroups.filter((group) => (group.uuid ?? 'uncategorized') === category)
  const visible = visibleGroups.flatMap((group) => group.products)
  const restoreTargets = visible.filter((product) => product.isSoldout && product.extensionEditable)
  const reorderProducts = async (products: LiveProduct[], index: number, direction: -1 | 1) => {
    if (sortLock.current || !can(access, 'product', 'update') || products.some((product) => !product.extensionEditable)) return
    const reordered = moveItem(products, index, direction)
    const changes = reordered.flatMap((product, sortOrder) => product.sortOrder === sortOrder ? [] : [{ product, sortOrder }])
    if (!changes.length) return
    sortLock.current = true
    setSorting(true)
    setSortError(null)
    try {
      await Promise.all(changes.map(({ product, sortOrder }) => api.saveProduct(product.uuid, { commandUuid: crypto.randomUUID(), version: product.version, sortOrder })))
      list.reload()
    } catch (error) {
      setSortError(errorKey(error))
      list.reload()
    } finally {
      sortLock.current = false
      setSorting(false)
    }
  }
  return <>
    {sortError && <p className="workspace-sort-status error" role="alert">{t(sortError)}</p>}
    {sorting && <p className="workspace-sort-status" role="status">{t('sortingRecords')}</p>}
    {can(access, 'product', 'create') && <div className="workspace-toolbar catalog-toolbar"><Button className="workspace-primary" color="primary" disabled={deletion.busy} onClick={() => setEditing(null)}><Plus size={17} />{t('addProduct')}</Button></div>}
    {categoryGroups.length > 0 && <div className="workspace-filters catalog-category-filters" aria-label={t('diningCategoryFilter')}><button className={category === 'all' ? 'active' : ''} aria-pressed={category === 'all'} onClick={() => setCategory('all')}>{t('all')}</button>{categoryGroups.map((group) => <button key={group.uuid ?? 'uncategorized'} className={category === (group.uuid ?? 'uncategorized') ? 'active' : ''} aria-pressed={category === (group.uuid ?? 'uncategorized')} onClick={() => setCategory(group.uuid ?? 'uncategorized')}>{group.name}</button>)}</div>}
    {can(access, 'product', 'availability') && restoreTargets.length > 0 && <button className="text-action catalog-restore-all" disabled={!restoreTargets.length} onClick={() => setAvailability({ products: restoreTargets, soldout: false })}>{t('restoreLoadedSoldout')}</button>}
    {deletion.feedback}
    <ListFeedback state={{ ...list, items: visible }} empty={category !== 'all' ? 'noSearchResults' : 'firstProduct'} hint={category !== 'all' ? 'adjustSearch' : 'firstProductHint'} icon={CookingPot} />
    <div className="catalog-category-sections">{visibleGroups.map((group) => <section className="catalog-category-section" key={group.uuid ?? 'uncategorized'}>
      <div className="catalog-category-heading"><h2>{group.name}</h2><span>{group.products.length}</span></div>
      <div className="catalog-product-grid">{group.products.map((product, index) => <article className="catalog-product-card" key={product.uuid}>
        <div className="catalog-product-top"><ProductVisual product={product} /><div className="catalog-product-info"><div className="catalog-product-statuses"><StatusPill muted={product.status !== 'active'}>{t(product.status === 'active' ? 'listingActive' : 'listingInactive')}</StatusPill><StatusPill muted={!product.isSoldout}>{t(product.isSoldout ? 'soldOut' : 'onSale')}</StatusPill></div><h2>{product.name}</h2><p>{product.metadata?.subtitle || t('specifications')}</p></div></div>
        {['update', 'delete'].some((action) => can(access, 'product', action)) || can(access, 'specification', 'read') ? <div className="catalog-product-actions">{can(access, 'product', 'update') && <div className="catalog-sort-actions" role="group" aria-label={`${t('sortOrder')} ${product.name}`}><button type="button" className="workspace-icon" aria-label={`${t('moveUp')} ${product.name}`} disabled={sorting || index === 0 || group.products.some((item) => !item.extensionEditable)} onClick={() => void reorderProducts(group.products, index, -1)}><ArrowUp size={16} /></button><button type="button" className="workspace-icon" aria-label={`${t('moveDown')} ${product.name}`} disabled={sorting || index === group.products.length - 1 || group.products.some((item) => !item.extensionEditable)} onClick={() => void reorderProducts(group.products, index, 1)}><ArrowDown size={16} /></button></div>}{can(access, 'product', 'update') && <Button disabled={deletion.busy || sorting} onClick={() => setEditing(product)}><Pencil size={14} />{t('edit')}</Button>}{can(access, 'specification', 'read') && <Button disabled={deletion.busy || sorting} onClick={() => setSpecs(product)}><Layers3 size={14} />{t('specManagement')}</Button>}{can(access, 'product', 'delete') && <button className="workspace-icon danger" aria-label={t('delete')} disabled={deletion.busy || sorting} onClick={() => void deletion.remove(() => api.remove('products', product.uuid))}><Trash2 size={16} /></button>}</div> : null}
        {can(access, 'product', 'availability') && product.extensionEditable && <Button block className={`catalog-availability-action ${product.isSoldout ? 'is-restore' : ''}`} onClick={() => setAvailability({ products: [product], soldout: !product.isSoldout })}>{product.isSoldout ? <RotateCcw size={15} /> : <Ban size={15} />}{t(product.isSoldout ? 'restoreAvailability' : 'markSoldout')}</Button>}<RecordIdentity id={product.uuid} />
      </article>)}</div>
    </section>)}</div>
    <PageControls state={list} detail />
    {editing !== undefined && <ProductForm api={api} access={access} product={editing} onClose={() => setEditing(undefined)} done={() => { setEditing(undefined); list.reload() }} />}
    {availability && <AvailabilityForm api={api} products={availability.products} soldout={availability.soldout} onClose={() => setAvailability(null)} done={() => { setAvailability(null); list.reload() }} />}
    {specs && <Sheet open title={`${specs.name} · ${t('specifications')}`} onClose={() => setSpecs(null)}><SpecificationList key={specs.uuid} api={api} access={access} product={specs} /></Sheet>}
  </>
}
function CategoryList({ api, access }: { api: StoreApi; access: StoreAccess }) {
  const { t } = useI18n()
  const [query, setQuery] = useState('')
  const load = useCallback((page: number) => api.categories(page, query), [api, query])
  const list = usePage(load, can(access, 'product_category', 'read'))
  const deletion = useDelete(list.reload)
  const [editing, setEditing] = useState<LiveCategory | null | undefined>()
  const [sorting, setSorting] = useState(false)
  const [sortError, setSortError] = useState<ReturnType<typeof errorKey> | null>(null)
  const sortLock = useRef(false)
  const visible = [...list.items].sort((left, right) => left.sortOrder - right.sortOrder)
  const reorderCategories = async (uuid: string, direction: -1 | 1) => {
    if (sortLock.current || !can(access, 'product_category', 'update')) return
    sortLock.current = true
    setSorting(true)
    setSortError(null)
    try {
      const all = (await api.allCategories()).items
      const index = all.findIndex((category) => category.uuid === uuid)
      const reordered = moveItem(all, index, direction)
      const changes = reordered.flatMap((category, sortOrder) => category.sortOrder === sortOrder ? [] : [{ category, sortOrder }])
      await Promise.all(changes.map(({ category, sortOrder }) => api.save('product-categories', category.uuid, { name: category.name, slug: category.slug, sortOrder, enabled: category.enabled })))
      list.reload()
    } catch (error) {
      setSortError(errorKey(error))
      list.reload()
    } finally {
      sortLock.current = false
      setSorting(false)
    }
  }
  if (!can(access, 'product_category', 'read')) return <AccessNotice />
  return <><PageScope count={list.items.length} total={list.total} /><div className="workspace-toolbar"><SearchField server value={query} onChange={(value) => { setQuery(value); list.setPage(1) }} />{can(access, 'product_category', 'create') && <Button color="primary" className="workspace-primary" disabled={deletion.busy} onClick={() => setEditing(null)}><Plus size={17} />{t('addCategory')}</Button>}</div>{deletion.feedback}
    {sortError && <p className="workspace-sort-status error" role="alert">{t(sortError)}</p>}
    {sorting && <p className="workspace-sort-status" role="status">{t('sortingRecords')}</p>}
    <ListFeedback state={{ ...list, items: visible }} empty={query.trim() ? 'noSearchResults' : 'firstCategory'} hint={query.trim() ? 'adjustSearch' : 'firstCategoryHint'} icon={Tag} />
    <div className="workspace-category-list">{visible.map((category, index) => <article key={category.uuid}><div className="category-main"><span className="category-symbol"><Tag size={21} strokeWidth={1.5} /></span><div><h2>{category.name}</h2></div><StatusPill muted={!category.enabled}>{t(category.enabled ? 'enabled' : 'disabled')}</StatusPill></div><div className="category-meta"><span><ArrowUpDown size={13} />{t('sortOrder')} {category.sortOrder}</span><div>{can(access, 'product_category', 'update') && <div className="category-sort-actions" role="group" aria-label={`${t('sortOrder')} ${category.name}`}><button type="button" className="workspace-icon" aria-label={`${t('moveUp')} ${category.name}`} disabled={sorting || index === 0} onClick={() => void reorderCategories(category.uuid, -1)}><ArrowUp size={16} /></button><button type="button" className="workspace-icon" aria-label={`${t('moveDown')} ${category.name}`} disabled={sorting || index === visible.length - 1} onClick={() => void reorderCategories(category.uuid, 1)}><ArrowDown size={16} /></button></div>}{can(access, 'product_category', 'update') && <Button disabled={deletion.busy || sorting} onClick={() => setEditing(category)}><Pencil size={14} />{t('edit')}</Button>}{can(access, 'product_category', 'delete') && <button className="workspace-icon danger" aria-label={t('delete')} disabled={deletion.busy || sorting} onClick={() => void deletion.remove(() => api.remove('product-categories', category.uuid))}><Trash2 size={16} /></button>}</div></div><RecordIdentity id={category.uuid} /></article>)}</div>
    <PageControls state={list} />
    {editing !== undefined && <CategoryForm api={api} category={editing} onClose={() => setEditing(undefined)} done={() => { setEditing(undefined); list.reload() }} />}
  </>
}
function AvailabilityForm({ api, products, soldout, onClose, done }: { api: StoreApi; products: LiveProduct[]; soldout: boolean; onClose: () => void; done: () => void }) {
  const { t } = useI18n()
  const command = useCommand({ items: products.map(({ uuid, version }) => ({ uuid, version })), soldout })
  return <EditorSheet title={t(soldout ? 'markSoldout' : 'restoreAvailability')} description="soldoutRuleHint" preview={<EditorPreview icon={CookingPot} label="products" name={`${products.length} ${t('recordsUnit')}`} />} onClose={onClose} onDone={done} submit={() => api.availability(products, soldout, command)}><Notice subtle>{t('explicitBatchHint')}</Notice><ul className="operation-records">{products.map((product) => <li key={product.uuid}>{product.name}</li>)}</ul></EditorSheet>
}
function SpecificationList({ api, access, product }: { api: StoreApi; access: StoreAccess; product: LiveProduct }) {
  const { t } = useI18n()
  const load = useCallback((page: number) => api.specifications(product.uuid, page), [api, product.uuid])
  const list = usePage(load, can(access, 'specification', 'read'))
  const deletion = useDelete(list.reload)
  const [editing, setEditing] = useState<LiveSpecification | null | undefined>()
  return <><Notice icon={Layers3} subtle>{t('variantHint')}</Notice><div className="variant-toolbar"><span>{t('specifications')}</span>{can(access, 'specification', 'create') && <Button className="workspace-primary" color="primary" disabled={deletion.busy} onClick={() => setEditing(null)}><Plus size={16} />{t('addSpec')}</Button>}</div>{deletion.feedback}
    <ListFeedback state={list} empty="emptyLive" hint="chooseProduct" icon={Layers3} />
    <div className="workspace-variant-list">{list.items.map((specification) => <article key={specification.uuid}><div><h2>{specification.name}</h2><StatusPill muted={specification.status !== 'active'}>{t(specification.status === 'active' ? 'listingActive' : 'listingInactive')}</StatusPill></div><strong className="variant-price">{new Intl.NumberFormat(undefined, { style: 'currency', currency: access.store.currency }).format(specification.price / 100)}</strong><div className="variant-actions">{can(access, 'specification', 'update') && <Button disabled={deletion.busy} onClick={() => setEditing(specification)}><Pencil size={14} />{t('edit')}</Button>}{can(access, 'specification', 'delete') && <button className="workspace-icon danger" aria-label={t('delete')} disabled={deletion.busy} onClick={() => void deletion.remove(() => api.removeSpecification(product.uuid, specification.uuid))}><Trash2 size={16} /></button>}</div></article>)}</div>
    <PageControls state={list} />
    {editing !== undefined && <SpecificationForm api={api} currency={access.store.currency} product={product} specification={editing} onClose={() => setEditing(undefined)} done={() => { setEditing(undefined); list.reload() }} />}
  </>
}
