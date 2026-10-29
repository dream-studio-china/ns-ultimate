import * as Primitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '../../lib/utils'
import { useI18n } from '../../lib/i18n'
import { Button } from './button'

export function Dialog({ open, onOpenChange, title, description, children, className, locked = false }: {
  open: boolean; onOpenChange: (open: boolean) => void; title: string; description: string; children: ReactNode; className?: string; locked?: boolean
}) {
  const { t } = useI18n()
  return <Primitive.Root open={open} onOpenChange={(next) => { if (!locked) onOpenChange(next) }}>
    <Primitive.Portal><Primitive.Overlay className="fixed inset-0 z-40 bg-[#11211c]/45 backdrop-blur-xs data-[state=open]:animate-in" />
      <Primitive.Content onEscapeKeyDown={(event) => { if (locked) event.preventDefault() }} onPointerDownOutside={(event) => event.preventDefault()} className={cn('dialog-content fixed left-1/2 top-1/2 z-50 max-h-[92dvh] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-3xl bg-background p-7 shadow-2xl focus:outline-none', className)}>
        <div className="mb-6 pr-10"><Primitive.Title className="text-xl font-semibold tracking-tight">{title}</Primitive.Title><Primitive.Description className="mt-2 text-sm leading-relaxed text-muted-foreground">{description}</Primitive.Description></div>
        {!locked && <Primitive.Close asChild><Button variant="ghost" size="icon" className="absolute right-4 top-4" aria-label={t('cancel')}><X /></Button></Primitive.Close>}
        {children}
      </Primitive.Content>
    </Primitive.Portal>
  </Primitive.Root>
}
