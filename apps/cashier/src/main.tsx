import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { I18nProvider } from './lib/i18n'
import { App } from './app'
import { CustomerPage } from './pages/customer'
import { ThemeProvider } from './lib/theme'
import './styles.css'

const customer = new URLSearchParams(window.location.search).get('screen') === 'customer'
createRoot(document.getElementById('root')!).render(<StrictMode><I18nProvider><ThemeProvider persist={!customer}>{customer ? <CustomerPage /> : <App />}</ThemeProvider></I18nProvider></StrictMode>)
