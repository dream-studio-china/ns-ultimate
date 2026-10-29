import { CookingPot } from 'lucide-react'
import type { Receipt } from '../domain'
import { label, money, time, useI18n } from '../../shared/i18n'
import { Sheet } from '../../shared/ui'

export function ReceiptSheet({ receipt, onClose }: { receipt: Receipt | null; onClose: () => void }) {
  const { t, locale } = useI18n()
  return <Sheet open={!!receipt} onClose={onClose} title={t('receipt')}>
    {receipt && <div className="receipt-paper">
      <CookingPot className="mx-auto mb-3 text-brand" size={32} strokeWidth={1.2} />
      <h3 className="text-center text-lg font-semibold">{t('store')}</h3><p className="helper text-center">{t('receiptHint')}</p>
      <dl className="receipt-meta"><div><dt>{t('orderNo')}</dt><dd>{receipt.id}</dd></div><div><dt>{t('tableDetail')}</dt><dd>{receipt.tableName}</dd></div><div><dt>{t('paidAt')}</dt><dd>{time(receipt.paidAt, locale)}</dd></div><div><dt>{t('method')}</dt><dd>{t(receipt.method)}</dd></div></dl>
      <div className="divide-y divide-dashed divide-[#e9e3d8]">{receipt.lines.map((line) => <div key={line.id} className="flex justify-between gap-3 py-4 text-sm"><div>{label(line.name, t)}<p className="helper">{label(line.variant, t)} × {line.quantity}</p></div><strong>{money(line.unitPrice * line.quantity)}</strong></div>)}</div>
      <div className="bill-total"><span>{t('discount')}</span><span>−{money(receipt.discount)}</span></div><div className="bill-total final"><span>{t('amount')}</span><strong>{money(receipt.total)}</strong></div>
    </div>}
  </Sheet>
}
