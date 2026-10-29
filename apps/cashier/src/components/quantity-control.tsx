import { Minus, Plus } from 'lucide-react'
import { useI18n } from '../lib/i18n'

export function QuantityControl({ quantity, onChange, min = 0, disabled = false }: { quantity: number; onChange: (quantity: number) => void; min?: number; disabled?: boolean }) {
  const { t } = useI18n()
  return <div className="inline-flex items-center rounded-xl border border-border bg-background"><button className="quantity-button" disabled={disabled || quantity <= min} onClick={() => onChange(quantity - 1)} aria-label={t('decrease')}><Minus className="size-3.5" /></button><span className="min-w-6 text-center text-sm font-semibold tabular-nums">{quantity}</span><button className="quantity-button" disabled={disabled || quantity >= 99} onClick={() => onChange(quantity + 1)} aria-label={t('increase')}><Plus className="size-3.5" /></button></div>
}
