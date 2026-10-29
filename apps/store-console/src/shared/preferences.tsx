import { createContext, useCallback, useContext, useLayoutEffect, useState, type ReactNode } from 'react'

export const themes = {
  red: '#a83240', orange: '#ad492c', yellow: '#806008', green: '#34624e', blue: '#245ea5', indigo: '#4945a4', purple: '#7b3c99',
} as const
export type Theme = keyof typeof themes
const key = 'ns-store-console.theme.v1'
const isTheme = (value: string | null): value is Theme => value !== null && Object.hasOwn(themes, value)
function loadTheme(): Theme {
  try { const value = localStorage.getItem(key); return isTheme(value) ? value : 'orange' } catch { return 'orange' }
}
const Context = createContext({ theme: 'orange' as Theme, setTheme: (_theme: Theme) => {}, storageFailed: false })
export function PreferencesProvider({ children }: { children: ReactNode }) {
  const [theme, update] = useState<Theme>(loadTheme)
  const [storageFailed, setStorageFailed] = useState(false)
  useLayoutEffect(() => {
    document.documentElement.style.setProperty('--brand', themes[theme])
    document.documentElement.dataset.theme = theme
  }, [theme])
  const setTheme = useCallback((next: Theme) => {
    update(next)
    try { localStorage.setItem(key, next); setStorageFailed(false) } catch { setStorageFailed(true) }
  }, [])
  return <Context.Provider value={{ theme, setTheme, storageFailed }}>{children}</Context.Provider>
}
export const usePreferences = () => useContext(Context)
