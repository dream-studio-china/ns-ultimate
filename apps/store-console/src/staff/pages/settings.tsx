import { Button, Dialog } from 'antd-mobile'
import { ChevronRight, CircleHelp, Globe2, PlugZap, ShieldCheck, Store, UserRound } from 'lucide-react'
import type { StoreState } from '../use-store'
import { useI18n } from '../../shared/i18n'
import { ThemePicker } from '../../shared/theme-picker'

export function SettingsPage({ store }: { store: StoreState }) {
  const { t, locale, setLocale } = useI18n()
  return <>
    <div className="page-title-row"><div><p className="eyebrow">MAKE YOURSELF AT HOME</p><h1>{t('settingsTitle')}</h1></div></div>
    <section className="profile-card"><div className="profile-avatar"><UserRound size={28} strokeWidth={1.3} /></div><div><h2>{t('manager')}</h2><p>{t('staffRole')}</p></div><span className="demo-badge ml-auto">{t('demo')}</span></section>
    <div className="store-account"><Store size={18} /><div><strong>{t('storeFull')}</strong><p>{t('storeCode')}</p></div><ChevronRight size={16} /></div>
    <h2 className="settings-label">{t('preferences')}</h2>
    <section className="surface-card">
      <div className="setting-line"><h3><Globe2 size={17} />{t('language')}</h3><div className="language-toggle"><button className={locale === 'zh-CN' ? 'active' : ''} onClick={() => setLocale('zh-CN')}>{t('zh')}</button><button className={locale === 'en' ? 'active' : ''} onClick={() => setLocale('en')}>{t('en')}</button></div></div>
      <ThemePicker />
    </section>
    <h2 className="settings-label">{t('serviceConnection')}</h2>
    <section className="surface-card"><div className="connection-heading"><PlugZap size={20} /><strong>{t('notConnected')}</strong></div><p className="helper mt-3">{t('connectionHint')}</p><div className="setting-line mt-3"><h3><ShieldCheck size={17} />{t('security')}</h3></div><p className="helper mt-3">{t('securityHint')}</p></section>
    <section className="surface-card mt-5"><button className="settings-link" onClick={() => void Dialog.alert({ title: t('help'), content: t('helpText'), confirmText: t('confirm') })}><CircleHelp size={18} />{t('help')}<ChevronRight size={16} /></button><div className="settings-link border-t border-[#eee8df]"><span>{t('about')}</span><small className="ml-auto">NS Store Console · 0.1.0</small></div></section>
    <Button block fill="outline" className="mt-6" disabled={!store.online} onClick={async () => { if (await Dialog.confirm({ title: t('resetTitle'), content: t('resetHint'), confirmText: t('confirm'), cancelText: t('cancel') })) store.reset() }}>{t('resetDemo')}</Button>
    <p className="page-note">{t('sessionNotice')}</p>
  </>
}
