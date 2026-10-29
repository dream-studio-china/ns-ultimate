import { createContext, useContext, useState, type ReactNode } from 'react'
import { zhCN } from '../locales/zh-CN'
import { en } from '../locales/en'

export type MessageKey = keyof typeof zhCN
export type Locale = 'zh-CN' | 'en'
export type Label = MessageKey | { custom: string }
export type Translate = (key: MessageKey) => string
export const translate = (locale: Locale, key: MessageKey): string => locale === 'en' ? en[key] : zhCN[key]
export const label = (value: Label, t: Translate) => typeof value === 'string' ? t(value) : value.custom
const Context = createContext({ locale: 'zh-CN' as Locale, setLocale: (_locale: Locale) => {}, t: ((key) => zhCN[key]) as Translate })

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, updateLocale] = useState<Locale>('zh-CN')
  const setLocale = (next: Locale) => { document.documentElement.lang = next === 'en' ? 'en' : 'zh-CN'; updateLocale(next) }
  return <Context.Provider value={{ locale, setLocale, t: (key) => translate(locale, key) }}>{children}</Context.Provider>
}
export const useI18n = () => useContext(Context)
export const money = (cents: number) => `¥${(cents / 100).toFixed(2)}`
export function time(value: number, locale: Locale) {
  return new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(value)
}
