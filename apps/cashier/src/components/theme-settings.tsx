import { Check, Palette } from 'lucide-react'
import { themeIds } from '../../shared/theme'
import { useI18n } from '../lib/i18n'
import { useTheme } from '../lib/theme'
import { palettes } from '../lib/theme-preferences'
import { cn } from '../lib/utils'

export function ThemeSettings() {
  const { t } = useI18n()
  const { theme, setTheme, storageFailed } = useTheme()
  return (
    <section className="content-card">
      <h2 className="section-title"><Palette />{t('colorScheme')}</h2>
      <p className="mb-5 text-xs leading-relaxed text-muted-foreground">{t('colorSchemeHint')}</p>
      <div className="grid grid-cols-4 gap-3 xl:grid-cols-7" role="group" aria-label={t('colorScheme')}>
        {themeIds.map((id) => (
          <button
            key={id}
            type="button"
            aria-pressed={theme === id}
            onClick={() => setTheme(id)}
            className={cn('theme-option flex min-h-24 flex-col items-center justify-center gap-3 rounded-2xl border-2 p-3 text-sm transition-colors', theme === id ? 'border-primary bg-secondary font-semibold text-primary' : 'border-border hover:bg-secondary')}
          >
            <span className="flex size-9 items-center justify-center rounded-full" style={{ backgroundColor: palettes[id].swatch }} aria-hidden="true">
              {theme === id && <Check className="size-5" style={{ color: id === 'yellow' ? '#322d18' : '#ffffff' }} />}
            </span>
            {t(id)}
          </button>
        ))}
      </div>
      {storageFailed && <p className="mt-4 text-xs text-destructive" role="alert">{t('themeStorageFailed')}</p>}
    </section>
  )
}
