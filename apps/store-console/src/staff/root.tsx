import { lazy, Suspense } from 'react'
import { useI18n } from '../shared/i18n'

const DemoApp = lazy(() => import('./app').then((module) => ({ default: module.StaffApp })))
const LiveApp = lazy(() => import('./live/app').then((module) => ({ default: module.LiveApp })))
export function StaffRoot() {
  const { t } = useI18n()
  const demo = new URLSearchParams(window.location.search).get('mode') === 'demo'
  return <Suspense fallback={<main className="mobile-app p-6" role="status">{t('loading')}</main>}>{demo ? <><DemoApp /><a className="demo-exit" href="?mode=live">{t('liveEntry')}</a></> : <LiveApp />}</Suspense>
}
