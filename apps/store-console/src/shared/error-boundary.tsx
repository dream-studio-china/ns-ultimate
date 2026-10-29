import { Component, type ReactNode } from 'react'
import { translate } from './i18n'

export class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }
  static getDerivedStateFromError() { return { failed: true } }
  render() {
    if (!this.state.failed) return this.props.children
    const locale = document.documentElement.lang.startsWith('en') ? 'en' : 'zh-CN'
    return <main className="mobile-app error-screen"><h1>{translate(locale, 'errorTitle')}</h1><p>{translate(locale, 'errorHint')}</p><button onClick={() => window.location.reload()}>{translate(locale, 'reload')}</button></main>
  }
}
