import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { I18nProvider } from '../shared/i18n'
import { PreferencesProvider } from '../shared/preferences'
import { MobileConfig } from '../shared/providers'
import { ErrorBoundary } from '../shared/error-boundary'
import { StaffRoot } from './root'
import '../styles.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode><ErrorBoundary><I18nProvider><PreferencesProvider><MobileConfig><StaffRoot /></MobileConfig></PreferencesProvider></I18nProvider></ErrorBoundary></StrictMode>,
)
