import { useI18n } from '../../shared/i18n'
import type { SessionOrder } from '../adapters/live'

export function OrderAmountSummary({ order }: { order: SessionOrder }) {
  const { t, locale } = useI18n()
  const format = (value: number) => new Intl.NumberFormat(locale, { style: 'currency', currency: order.currency }).format(value / 100)
  const discounts = order.discounts.length
    ? order.discounts
    : order.discountAmount > 0 ? [{ name: '', type: null, amount: order.discountAmount }] : []
  return <dl className="dining-amount-summary" aria-label={t('orderAmountSummary')}>
    {order.discountAmount > 0 && <div><dt>{t('orderOriginalAmount')}</dt><dd>{format(order.originalAmount)}</dd></div>}
    {discounts.map((discount, index) => <div className="is-discount" key={`${discount.type ?? 'discount'}:${discount.name}:${index}`}>
      <dt>{t('orderDiscountMethod')} · {discount.name || t('orderDiscountFallback')}</dt><dd>-{format(discount.amount)}</dd>
    </div>)}
    <div className="is-actual"><dt>{t('orderActualAmount')}</dt><dd>{format(order.totalAmount)}</dd></div>
  </dl>
}
