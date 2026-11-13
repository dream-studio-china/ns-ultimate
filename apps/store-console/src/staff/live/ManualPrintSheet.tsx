import { useEffect, useMemo, useRef, useState } from 'react'
import { Button } from 'antd-mobile'
import { Printer } from 'lucide-react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, StoreApi, type PrintOption, type StoreAccess } from '../adapters/live'
import { ApiError } from '../adapters/api-client'
import { errorKey, LiveSheet, Notice } from './ui'
import './manual-print.css'

export const canManuallyPrintOrderStatus = (status: string) => ['confirmed', 'paid', 'completed'].includes(status)

export function ManualPrintButton({ label, disabled = false, onClick }: { label: MessageKey; disabled?: boolean; onClick: () => void }) {
  const { t } = useI18n()
  return <Button disabled={disabled} onClick={onClick}><Printer size={14} />{t(label)}</Button>
}

export function ManualPrintSheet({ api, access, tradeOrderUuid, orderLabel, onlyKind, onClose }: {
  api: StoreApi
  access: StoreAccess
  tradeOrderUuid: string
  orderLabel?: string
  onlyKind?: PrintOption['kind']
  onClose: () => void
}) {
  const { t } = useI18n()
  const allowed = can(access, 'order', 'print')
  const [options, setOptions] = useState<PrintOption[]>([])
  const [selectedRuleUuid, setSelectedRuleUuid] = useState('')
  const [reason, setReason] = useState(() => t('manualPrintDefaultReason'))
  const [loading, setLoading] = useState(allowed)
  const [loadError, setLoadError] = useState<MessageKey | null>(null)
  const [busy, setBusy] = useState(false)
  const [outcome, setOutcome] = useState<MessageKey | null>(null)
  const [error, setError] = useState<MessageKey | null>(null)
  const [commandVersion, setCommandVersion] = useState(0)
  const lock = useRef(false)
  const commandUuid = useMemo(
    () => crypto.randomUUID(),
    [commandVersion, reason, selectedRuleUuid, tradeOrderUuid],
  )
  const available = useMemo(() => onlyKind ? options.filter((option) => option.kind === onlyKind) : options, [onlyKind, options])
  const selected = available.find((option) => option.ruleUuid === selectedRuleUuid) ?? null

  useEffect(() => {
    let active = true
    if (!allowed) {
      setLoading(false)
      setOptions([])
      return () => { active = false }
    }
    setLoading(true)
    setLoadError(null)
    void api.printOptions().then((items) => {
      if (!active) return
      setOptions(items)
      const matching = onlyKind ? items.filter((option) => option.kind === onlyKind) : items
      setSelectedRuleUuid((current) => matching.some((option) => option.ruleUuid === current) ? current : matching[0]?.ruleUuid ?? '')
    }).catch((cause: unknown) => {
      if (active) setLoadError(errorKey(cause))
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => { active = false }
  }, [allowed, api, onlyKind])

  const submit = async () => {
    if (lock.current || !selected || !allowed) return
    const trimmedReason = reason.trim()
    if (!trimmedReason || new TextEncoder().encode(trimmedReason).length > 500) {
      setError('manualPrintInvalid')
      return
    }
    if (!navigator.onLine) {
      setError('offline')
      return
    }
    lock.current = true
    setBusy(true)
    setOutcome(null)
    setError(null)
    try {
      await api.manualPrintOrder(tradeOrderUuid, selected.ruleUuid, commandUuid, trimmedReason)
      setOutcome('manualPrintQueued')
      // A successful command is consumed. A later click is an explicit reprint.
      setCommandVersion((current) => current + 1)
    } catch (cause) {
      if (cause instanceof ApiError) {
        if (cause.kind === 'network') setError('manualPrintNetworkUnknown')
        else if (cause.status === 403) setError('manualPrintPermissionRequired')
        else if (cause.serverMessage === 'order_not_printable') setError('manualPrintOrderUnavailable')
        else if (cause.serverMessage === 'print_rule_not_manual' || cause.serverMessage === 'print_rule_unavailable' || cause.serverMessage === 'print_output_unavailable') setError('manualPrintUnavailable')
        else if (cause.serverMessage === 'no_printable_items') setError('manualPrintNoItems')
        else if (cause.serverMessage === 'print_limit_exceeded') setError('manualPrintLimit')
        else if (cause.serverMessage === 'invalid_fields') setError('manualPrintInvalid')
        else setError(errorKey(cause))
      } else {
        setError('invalidResponse')
      }
    } finally {
      lock.current = false
      setBusy(false)
    }
  }

  const emptyMessage: MessageKey | null = allowed && !loading && !loadError && available.length === 0
    ? onlyKind === 'receipt' && options.length > 0 ? 'manualPrintNoReceiptOptions' : 'manualPrintNoOptions'
    : null
  const uncertain = error === 'manualPrintNetworkUnknown'
  const footer = <div className="dining-confirm-bar"><Button disabled={busy} onClick={onClose}>{t('close')}</Button><Button block color="primary" loading={busy} disabled={busy || loading || !selected || Boolean(loadError)} onClick={() => void submit()}><Printer size={15} />{t(outcome ? 'manualPrintAgain' : 'manualPrintSubmit')}</Button></div>

  return <LiveSheet open title={t('manualPrintTitle')} className="manual-print-sheet" onClose={() => { if (!busy) onClose() }} footer={footer}>
    <Notice subtle>{t('manualPrintHint')}</Notice>
    {orderLabel && <section className="manual-print-order"><span>{t('orderNo')}</span><strong>{orderLabel}</strong></section>}
    {!allowed && <Notice>{t('manualPrintPermissionRequired')}</Notice>}
    {allowed && loading && <p className="manual-print-loading" role="status">{t('manualPrintLoading')}</p>}
    {allowed && loadError && <Notice>{t(loadError)}</Notice>}
    {emptyMessage && <Notice title={emptyMessage}>{t('manualPrintConfigurationHint')}</Notice>}
    {allowed && available.length > 0 && <section className="manual-print-options" aria-label={t('manualPrintTitle')}>
      <h3>{t('manualPrintChooseRule')}</h3>
      {available.map((option) => <button type="button" key={option.ruleUuid} className={option.ruleUuid === selectedRuleUuid ? 'is-active' : ''} aria-pressed={option.ruleUuid === selectedRuleUuid} disabled={busy || uncertain} onClick={() => { setSelectedRuleUuid(option.ruleUuid); setOutcome(null); setError(null) }}>
        <span><strong>{option.name}</strong><small>{option.deviceName} · {t(option.kind === 'receipt' ? 'manualPrintReceiptKind' : 'manualPrintLabelKind')}</small></span>
        <em>{t('manualPrintCopies').replace('{count}', String(option.copies))} · {t(option.ticketMode === 'single' ? 'manualPrintTicketSingle' : 'manualPrintTicketPerUnit')}</em>
      </button>)}
    </section>}
    {allowed && available.length > 0 && <label className="field"><span>{t('manualPrintReason')}</span><textarea rows={3} maxLength={500} value={reason} disabled={busy || uncertain} onChange={(event) => { setReason(event.target.value); setOutcome(null); setError(null) }} placeholder={t('manualPrintDefaultReason')} /></label>}
    {outcome && <Notice title={outcome}>{t('manualPrintQueueHint')}</Notice>}
    {error && <Notice>{t(error)}</Notice>}
  </LiveSheet>
}
