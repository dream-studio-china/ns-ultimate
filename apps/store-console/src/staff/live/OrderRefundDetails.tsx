import { useEffect, useState } from 'react'
import { Button } from 'antd-mobile'
import { useI18n } from '../../shared/i18n'
import { type OrderRefundResult, StoreApi } from '../adapters/live'
import { paymentStatusMessage, refundResultKind, type RefundPreview } from '../order-refund'

/** Read-only visibility; refreshing never submits a refund or calls the provider. */
export function OrderRefundDetails({ api, tradeOrderUuid, historyAllowed }: { api: StoreApi; tradeOrderUuid: string; historyAllowed: boolean }) {
  const { t, locale } = useI18n()
  const [revision, setRevision] = useState(0)
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const [preview, setPreview] = useState<RefundPreview | null>(null)
  const [history, setHistory] = useState<OrderRefundResult[]>([])
  useEffect(() => {
    let active = true
    setLoading(true); setFailed(false); setPreview(null); setHistory([])
    void Promise.all([api.refundPreview(tradeOrderUuid), historyAllowed ? api.refundHistory(tradeOrderUuid) : Promise.resolve([])])
      .then(([next, rows]) => { if (active) { setPreview(next); setHistory(rows) } })
      .catch(() => { if (active) setFailed(true) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [api, tradeOrderUuid, historyAllowed, revision])
  const money = (amount: number) => new Intl.NumberFormat(locale, { style: 'currency', currency: preview?.currency ?? 'CNY' }).format(amount / 100)
  const paymentLabel = preview ? paymentStatusMessage(preview.status) : null
  return <section className="order-refund-visibility">
    <h3>{t('refundDetails')}</h3>
    {loading && <p role="status">{t('loading')}</p>}
    {failed && <p role="alert">{t('refundLoadFailed')}</p>}
    {preview && <>
      <dl className="order-detail-meta"><dt>{t('diningPaymentState')}</dt><dd>{paymentLabel ? t(paymentLabel) : preview.status}</dd>
        <dt>{t('refundPaidAmount')}</dt><dd>{money(preview.amount)}</dd><dt>{t('refundAlreadyAmount')}</dt><dd>{money(preview.refundedAmount)}</dd>
        <dt>{t('refundableAmount')}</dt><dd>{money(preview.refundableAmount)}</dd></dl>
      {historyAllowed && <><h3>{t('refundHistory')}</h3>{history.length === 0 ? <p>{t('refundHistoryEmpty')}</p> : <ul>{history.map((row) => {
        const kind = refundResultKind(row.status)
        const key = ({ processing: 'refundProcessing', unknown: 'refundNetworkUnknown', succeeded: 'refundCompleted', failed: 'refundResultFailed' } as const)[kind]
        return <li key={row.uuid}><strong>{money(row.amount)} · {t(key)}</strong><p>{row.reason}</p><small>{row.createdAt}</small></li>
      })}</ul>}<p>{t('refundHistoryHint')}</p></>}
    </>}
    <Button block loading={loading} disabled={loading} onClick={() => setRevision((value) => value + 1)}>{t('refundRefreshDetails')}</Button>
  </section>
}
