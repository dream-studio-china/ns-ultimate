import { useState } from 'react'
import { Button, Switch } from 'antd-mobile'
import { Plus, Trash2 } from 'lucide-react'
import type { Category, Product } from '../domain'
import { parseAmount } from '../domain'
import { label, useI18n } from '../../shared/i18n'
import { Sheet, Submit } from '../../shared/ui'

export function ProductEditor({ product, categories, onClose, onSave, online }: {
  product: Product | null; categories: Category[]; onClose: () => void; onSave: (product: Product) => boolean; online: boolean
}) {
  const { t } = useI18n()
  const [name, setName] = useState(product ? label(product.name, t) : '')
  const [categoryId, setCategoryId] = useState(product?.categoryId ?? categories[0]?.id ?? '')
  const [variants, setVariants] = useState(() => product?.variants.map((variant) => ({ id: variant.id, name: label(variant.name, t), price: (variant.price / 100).toFixed(2) }))
    ?? [{ id: crypto.randomUUID(), name: t('plate'), price: '' }])
  const [print, setPrint] = useState(product?.kitchenPrint ?? true)
  const [limited, setLimited] = useState(!!product?.schedule)
  const [start, setStart] = useState(product?.schedule?.start ?? '10:00')
  const [end, setEnd] = useState(product?.schedule?.end ?? '22:00')
  const [error, setError] = useState('')
  const [cover, setCover] = useState(product?.cover)
  const [reading, setReading] = useState(false)
  const upload = (file?: File) => {
    if (!file) return
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) { setError(t('imageError')); return }
    setReading(true)
    const reader = new FileReader()
    reader.onload = () => { setCover(String(reader.result)); setReading(false); setError('') }
    reader.onerror = () => { setReading(false); setError(t('imageError')) }
    reader.readAsDataURL(file)
  }
  const save = () => {
    if (!name.trim() || !categoryId || variants.some((item) => !item.name.trim() || parseAmount(item.price) === null || parseAmount(item.price)! <= 0)) { setError(t('invalidProduct')); return }
    if (limited && (!start || !end || start === end)) { setError(t('invalidSchedule')); return }
    const result = onSave({
      id: product?.id ?? crypto.randomUUID(), code: product?.code ?? `P${Date.now().toString().slice(-6)}`,
      name: product && name === label(product.name, t) ? product.name : { custom: name.trim() }, categoryId,
      variants: variants.map((item) => ({ id: item.id, name: product?.variants.find((variant) => variant.id === item.id && label(variant.name, t) === item.name)?.name ?? { custom: item.name.trim() }, price: parseAmount(item.price)! })),
      soldOut: product?.soldOut ?? false, kitchenPrint: print, popular: product?.popular, cover,
      schedule: limited ? { start, end } : undefined,
    })
    if (result) onClose()
  }
  return <Sheet open onClose={onClose} title={t(product ? 'editProduct' : 'addProduct')} footer={<Submit disabled={!online || reading} onClick={save}>{t('save')}</Submit>}>
    <div className="form-stack">
      <label className="field">{t('productName')}<input maxLength={60} value={name} onChange={(event) => setName(event.target.value)} placeholder={t('productName')} /></label>
      <label className="field">{t('category')}<select value={categoryId} onChange={(event) => setCategoryId(event.target.value)}>{categories.map((item) => <option key={item.id} value={item.id}>{label(item.name, t)}</option>)}</select></label>
      <label className="field">{t('productImage')}{cover && <img className="h-24 w-24 rounded-xl object-cover" src={cover} alt={name} />}<input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => upload(event.target.files?.[0])} /><small>{t(reading ? 'imageLoading' : 'imageHint')}</small></label>
      <div><h3 className="field-title">{t('specifications')}</h3><div className="space-y-3">{variants.map((variant, index) => <div className="variant-form" key={variant.id}>
        <label className="field">{t('specificationName')}<input aria-label={`${t('specificationName')} ${index + 1}`} maxLength={30} value={variant.name} onChange={(event) => setVariants((current) => current.map((item) => item.id === variant.id ? { ...item, name: event.target.value } : item))} /></label>
        <label className="field">{t('price')}<input aria-label={`${t('price')} ${index + 1}`} inputMode="decimal" value={variant.price} onChange={(event) => setVariants((current) => current.map((item) => item.id === variant.id ? { ...item, price: event.target.value } : item))} /></label>
        {variants.length > 1 && <button className="icon-button text-red-700" aria-label={`${t('removeSpec')} ${index + 1}`} onClick={() => setVariants((current) => current.filter((item) => item.id !== variant.id))}><Trash2 size={16} /></button>}
      </div>)}</div><Button className="mt-3" block fill="outline" disabled={variants.length >= 8} onClick={() => setVariants((current) => [...current, { id: crypto.randomUUID(), name: '', price: '' }])}><Plus size={16} className="inline" /> {t('addSpec')}</Button></div>
      <div className="setting-line"><div><h3>{t('kitchenPrint')}</h3><p>{t('printFlagHint')}</p></div><Switch checked={print} onChange={setPrint} aria-label={t('kitchenPrint')} /></div>
      <div className="setting-line"><h3>{t('limitedSale')}</h3><Switch checked={limited} onChange={setLimited} aria-label={t('limitedSale')} /></div>
      {limited && <><div className="grid grid-cols-2 gap-3"><label className="field">{t('startTime')}<input type="time" value={start} onChange={(event) => setStart(event.target.value)} /></label><label className="field">{t('endTime')}<input type="time" value={end} onChange={(event) => setEnd(event.target.value)} /></label></div><p className="helper">{t('scheduleHint')}</p></>}
      {error && <p className="error-text" role="alert">{error}</p>}
    </div>
  </Sheet>
}
