import { Check, Palette } from 'lucide-react'
import { useI18n } from './i18n'
import { themes, usePreferences, type Theme } from './preferences'
import './theme-picker.css'

export function ThemePicker() {
  const { t } = useI18n()
  const { theme, setTheme, storageFailed } = usePreferences()
  return <div className="theme-picker"><div className="theme-picker-heading"><Palette size={18} /><h3>{t('appearance')}</h3><span>{t('themeApplied')} · {t(theme)}</span></div><p>{t('localThemeHint')}</p><div className="theme-picker-options" role="group" aria-label={t('theme')}>{(Object.keys(themes) as Theme[]).map((value) => <button type="button" key={value} aria-label={t(value)} aria-pressed={theme === value} onClick={() => setTheme(value)}><span style={{ backgroundColor: themes[value] }}>{theme === value && <Check size={15} />}</span><small>{t(value)}</small></button>)}</div><div className="theme-picker-preview" aria-label={t('themePreview')}><span /><div><i /><i /></div><b /><span /></div>{storageFailed && <p className="error-text" role="alert">{t('storageFailed')}</p>}</div>
}
