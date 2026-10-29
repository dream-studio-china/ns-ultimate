import { defaultTheme, isThemeId, type ThemeId } from '../../shared/theme'

export const themeStorageKey = 'ns-cashier.theme.v1'
export interface ThemeStorage {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
}

// Dark primary colors keep white action labels legible, including yellow.
export const palettes: Record<ThemeId, { primary: string; sidebar: string; accent: string; secondary: string; swatch: string }> = {
  red: { primary: '#a52d3c', sidebar: '#381b22', accent: '#ffd2d8', secondary: '#fcf0f2', swatch: '#dc4556' },
  orange: { primary: '#a34412', sidebar: '#38251b', accent: '#ffdab8', secondary: '#fcf2e9', swatch: '#ea7a24' },
  yellow: { primary: '#806008', sidebar: '#322d18', accent: '#f8e89d', secondary: '#faf6e5', swatch: '#e9c638' },
  green: { primary: '#344f3d', sidebar: '#182d27', accent: '#d3e3b9', secondary: '#f0f3ec', swatch: '#5c855f' },
  blue: { primary: '#245ea5', sidebar: '#192c43', accent: '#cbe3ff', secondary: '#edf4fc', swatch: '#4289d9' },
  indigo: { primary: '#4945a4', sidebar: '#25223f', accent: '#dcd7ff', secondary: '#f1effb', swatch: '#6860cb' },
  purple: { primary: '#7b3c99', sidebar: '#322039', accent: '#eed0fa', secondary: '#f8effb', swatch: '#ab64c9' },
}

export function readSavedTheme(storage: ThemeStorage | null): ThemeId {
  try {
    const value = storage?.getItem(themeStorageKey)
    return isThemeId(value) ? value : defaultTheme
  } catch { return defaultTheme }
}

export function saveTheme(storage: ThemeStorage | null, theme: ThemeId): boolean {
  try {
    if (!storage) return false
    storage.setItem(themeStorageKey, theme)
    return true
  } catch { return false }
}

export function browserThemeStorage(): ThemeStorage | null {
  try { return window.localStorage } catch { return null }
}

export function applyTheme(theme: ThemeId) {
  const palette = palettes[theme]
  const root = document.documentElement
  root.dataset.theme = theme
  root.style.setProperty('--primary', palette.primary)
  root.style.setProperty('--secondary', palette.secondary)
  root.style.setProperty('--sidebar', palette.sidebar)
  root.style.setProperty('--accent', palette.accent)
}
