import { ConfigProvider } from 'antd-mobile'
import enUS from 'antd-mobile/es/locales/en-US'
import zhCN from 'antd-mobile/es/locales/zh-CN'
import type { ReactNode } from 'react'
import { useI18n } from './i18n'

export function MobileConfig({ children }: { children: ReactNode }) {
  const { locale } = useI18n()
  return <ConfigProvider locale={locale === 'en' ? enUS : zhCN}>{children}</ConfigProvider>
}
