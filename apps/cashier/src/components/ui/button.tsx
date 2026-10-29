import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'
import type { ButtonHTMLAttributes } from 'react'
import { cn } from '../../lib/utils'

const buttonVariants = cva('inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-xl px-4 text-sm font-medium transition-colors touch-manipulation focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-primary/35 disabled:pointer-events-none disabled:opacity-40 [&_svg]:size-4 [&_svg]:shrink-0', {
  variants: { variant: {
    default: 'bg-primary text-primary-foreground hover:bg-primary/90',
    secondary: 'bg-secondary text-foreground hover:bg-secondary/70',
    outline: 'border border-border bg-background hover:bg-secondary',
    ghost: 'hover:bg-secondary text-muted-foreground hover:text-foreground',
    destructive: 'bg-destructive/10 text-destructive hover:bg-destructive/20',
  }, size: { default: '', icon: 'size-12 p-0', lg: 'min-h-14 px-6 text-base', sm: 'min-h-11 px-3 text-xs' } },
  defaultVariants: { variant: 'default', size: 'default' },
})
export function Button({ className, variant, size, asChild = false, type = 'button', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
  const Component = asChild ? Slot : 'button'
  return <Component type={type} className={cn(buttonVariants({ variant, size }), className)} {...props} />
}
