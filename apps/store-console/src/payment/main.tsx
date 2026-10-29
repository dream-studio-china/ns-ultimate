import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { I18nProvider } from '../shared/i18n'
import { MobileConfig } from '../shared/providers'
import { ErrorBoundary } from '../shared/error-boundary'
import { PaymentApp } from './app'
import '../styles.css'

// This entry deliberately has no dependency on staff modules, state or identity.
createRoot(document.getElementById('root')!).render(
  <StrictMode><ErrorBoundary><I18nProvider><MobileConfig><PaymentApp /></MobileConfig></I18nProvider></ErrorBoundary></StrictMode>,
)
