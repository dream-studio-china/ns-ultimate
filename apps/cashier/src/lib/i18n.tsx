import { createContext, useContext, useState, useEffect, type ReactNode } from 'react'
import { zhCN } from '../locales/zh-CN'
import { en } from '../locales/en'

export type Locale = 'zh-CN' | 'en'
export type MessageKey = keyof typeof zhCN
export function translate(locale: Locale, key: MessageKey): string { return locale === 'en' ? en[key] : zhCN[key] }
const Context = createContext({ locale: 'zh-CN' as Locale, setLocale: (_locale: Locale) => {}, t: (key: MessageKey): string => zhCN[key] })
export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocale] = useState<Locale>('zh-CN')
  useEffect(() => { document.documentElement.lang = locale === 'en' ? 'en' : 'zh-CN' }, [locale])
  return <Context.Provider value={{ locale, setLocale, t: (key) => translate(locale, key) }}>{children}</Context.Provider>
}
export const useI18n = () => useContext(Context)
export function money(cents: number): string { return `¥${(cents / 100).toFixed(2)}` }
export function dateTime(timestamp: number, locale: Locale): string {
  return new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(timestamp)
}
