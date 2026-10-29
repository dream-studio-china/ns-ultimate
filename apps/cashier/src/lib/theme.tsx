import { createContext, useCallback, useContext, useLayoutEffect, useState, type ReactNode } from 'react'
import { defaultTheme, type ThemeId } from '../../shared/theme'
import { applyTheme, browserThemeStorage, readSavedTheme, saveTheme } from './theme-preferences'

const ThemeContext = createContext({ theme: defaultTheme, setTheme: (_theme: ThemeId) => {}, storageFailed: false })

export function ThemeProvider({ children, persist = true }: { children: ReactNode; persist?: boolean }) {
  const [theme, updateTheme] = useState(() => readSavedTheme(browserThemeStorage()))
  const [storageFailed, setStorageFailed] = useState(false)
  useLayoutEffect(() => { applyTheme(theme) }, [theme])
  const setTheme = useCallback((next: ThemeId) => {
    updateTheme(next)
    if (persist) setStorageFailed(!saveTheme(browserThemeStorage(), next))
  }, [persist])
  return <ThemeContext.Provider value={{ theme, setTheme, storageFailed }}>{children}</ThemeContext.Provider>
}

export const useTheme = () => useContext(ThemeContext)
