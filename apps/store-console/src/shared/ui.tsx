import { Popup, Button } from 'antd-mobile'
import { X, Plus, Minus, CookingPot } from 'lucide-react'
import { useEffect, useRef, type ReactNode } from 'react'
import { useI18n } from './i18n'

export function Sheet({ open, onClose, title, children, footer, locked = false }: {
  open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode; locked?: boolean
}) {
  const { t } = useI18n()
  const content = useRef<HTMLElement>(null)
  const previousFocus = useRef<HTMLElement | null>(null)
  useEffect(() => { if (open) previousFocus.current = document.activeElement as HTMLElement }, [open])
  return <Popup visible={open} onMaskClick={locked ? undefined : onClose} bodyClassName="mobile-sheet" destroyOnClose
    afterShow={() => content.current?.focus({ preventScroll: true })}
    afterClose={() => { if (previousFocus.current?.isConnected) previousFocus.current.focus({ preventScroll: true }) }}>
    <section ref={content} tabIndex={-1} className="outline-none" role="dialog" aria-modal="true" aria-label={title} onKeyDown={(event) => {
      if (event.key === 'Escape') { event.stopPropagation(); if (!locked) onClose(); return }
      if (event.key !== 'Tab') return
      const elements = [...(content.current?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]') ?? [])].filter((element) => element.getClientRects().length)
      const first = elements[0]
      const last = elements.at(-1)
      if (!first) { event.preventDefault(); return }
      if (event.shiftKey && (document.activeElement === first || document.activeElement === content.current)) { event.preventDefault(); last?.focus() }
      else if (!event.shiftKey && (document.activeElement === last || document.activeElement === content.current)) { event.preventDefault(); first.focus() }
    }}>
      <div className="sheet-handle" />
      <header className="sheet-header"><h2>{title}</h2>{!locked && <button className="icon-button" onClick={onClose} aria-label={t('close')}><X size={20} /></button>}</header>
      <div className="sheet-scroll">{children}</div>
      {footer && <div className="sheet-footer">{footer}</div>}
    </section>
  </Popup>
}
export function Stepper({ value, onChange, disabled = false, min = 0, max = 99 }: { value: number; onChange: (value: number) => void; disabled?: boolean; min?: number; max?: number }) {
  const { t } = useI18n()
  return <div className="stepper"><button disabled={disabled || value <= min} onClick={() => onChange(value - 1)} aria-label={t('decrease')}><Minus size={15} /></button><span>{value}</span><button disabled={disabled || value >= max} onClick={() => onChange(value + 1)} aria-label={t('increase')}><Plus size={15} /></button></div>
}
export function Empty({ title, hint }: { title: string; hint?: string }) {
  return <div className="empty"><span><CookingPot size={32} strokeWidth={1.2} /></span><h3>{title}</h3>{hint && <p>{hint}</p>}</div>
}
export function DemoBadge() {
  const { t } = useI18n()
  return <span className="demo-badge">{t('demo')}</span>
}
export function Submit({ children, ...props }: { children: ReactNode; disabled?: boolean; loading?: boolean; onClick: () => void }) {
  return <Button block color="primary" size="large" {...props}>{children}</Button>
}
