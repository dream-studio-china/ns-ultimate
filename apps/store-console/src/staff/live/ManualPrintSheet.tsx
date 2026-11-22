import { useEffect, useMemo, useRef, useState } from 'react'
import { Button } from 'antd-mobile'
import { Check, Printer } from 'lucide-react'
import { useI18n, type MessageKey } from '../../shared/i18n'
import { can, StoreApi, type PrintOption, type StoreAccess } from '../adapters/live'
import { ApiError } from '../adapters/api-client'
import { errorKey, LiveSheet, Notice } from './ui'
import './manual-print.css'

export const canManuallyPrintOrderStatus = (status: string) => ['confirmed', 'paid', 'completed'].includes(status)

export type ManualPrintPurpose = 'reprint' | 'checkout'

const printCopy = {
  reprint: {
    title: 'manualPrintTitle', hint: 'manualPrintHint', loading: 'manualPrintLoading',
    noOptions: 'manualPrintNoOptions', configurationHint: 'manualPrintConfigurationHint',
    chooseRule: 'manualPrintChooseRule', chooseHint: 'manualPrintChooseHint',
    reason: 'manualPrintReason', defaultReason: 'manualPrintDefaultReason',
    submit: 'manualPrintSubmit', submitEmpty: 'manualPrintSubmitEmpty',
    queued: 'manualPrintQueued', queueHint: 'manualPrintQueueHint',
    partialTitle: 'manualPrintPartialTitle', partialResult: 'manualPrintPartialResult',
  },
  checkout: {
    title: 'checkoutPrintTitle', hint: 'checkoutPrintHint', loading: 'checkoutPrintLoading',
    noOptions: 'checkoutPrintNoOptions', configurationHint: 'checkoutPrintConfigurationHint',
    chooseRule: 'checkoutPrintChooseRule', chooseHint: 'checkoutPrintChooseHint',
    reason: 'checkoutPrintReason', defaultReason: 'checkoutPrintDefaultReason',
    submit: 'checkoutPrintSubmit', submitEmpty: 'checkoutPrintSubmitEmpty',
    queued: 'checkoutPrintQueued', queueHint: 'checkoutPrintQueueHint',
    partialTitle: 'checkoutPrintPartialTitle', partialResult: 'checkoutPrintPartialResult',
  },
} satisfies Record<ManualPrintPurpose, Record<string, MessageKey>>

function optionsForPurpose(options: PrintOption[], purpose: ManualPrintPurpose) {
  if (purpose === 'checkout') {
    // Checkout has one explicit target. The deployed legacy endpoint can return
    // every enabled rule without events, so match the configured receipt name
    // instead of treating all returned rules as printable checkout receipts.
    const option = options.find((candidate) => {
      if (candidate.kind !== 'receipt') return false
      const name = candidate.name.trim().toLocaleLowerCase()
      return name.includes('结账小票') || name.includes('checkout receipt')
    })
    return option ? [option] : []
  }
  return options.filter((option) => {
    if (!option.events) return false
    const events = new Set(option.events)
    if (events.has('paid')) return false
    return events.has('confirmed') && !events.has('manual')
  })
}

function printFailureKey(cause: unknown): MessageKey {
  if (!(cause instanceof ApiError)) return 'invalidResponse'
  if (cause.kind === 'network' || cause.kind === 'invalid') return 'manualPrintNetworkUnknown'
  if (cause.status === 403) return 'manualPrintPermissionRequired'
  if (cause.serverMessage === 'order_not_printable') return 'manualPrintOrderUnavailable'
  if (cause.serverMessage === 'print_rule_not_manual' || cause.serverMessage === 'print_rule_unavailable' || cause.serverMessage === 'print_output_unavailable') return 'manualPrintUnavailable'
  if (cause.serverMessage === 'no_printable_items') return 'manualPrintNoItems'
  if (cause.serverMessage === 'print_limit_exceeded') return 'manualPrintLimit'
  if (cause.serverMessage === 'invalid_fields') return 'manualPrintInvalid'
  return errorKey(cause)
}

function printCount(key: MessageKey, count: number, t: (key: MessageKey) => string) {
  return t(key).replace('{count}', String(count))
}

export function ManualPrintButton({ label, disabled = false, onClick }: { label: MessageKey; disabled?: boolean; onClick: () => void }) {
  const { t } = useI18n()
  return <Button disabled={disabled} onClick={onClick}><Printer size={14} />{t(label)}</Button>
}

export function ManualPrintSheet({ api, access, tradeOrderUuid, orderLabel, purpose, onClose }: {
  api: StoreApi
  access: StoreAccess
  tradeOrderUuid: string
  orderLabel?: string
  purpose: ManualPrintPurpose
  onClose: () => void
}) {
  const { t } = useI18n()
  const copy = printCopy[purpose]
  const allowed = can(access, 'order', 'print')
  const [options, setOptions] = useState<PrintOption[]>([])
  const [selectedRuleUuids, setSelectedRuleUuids] = useState<string[]>([])
  const [reason, setReason] = useState(() => t(copy.defaultReason))
  const [loading, setLoading] = useState(allowed)
  const [loadError, setLoadError] = useState<MessageKey | null>(null)
  const [busy, setBusy] = useState(false)
  const [outcome, setOutcome] = useState<{ queued: number; failed: number } | null>(null)
  const [failures, setFailures] = useState<Record<string, MessageKey>>({})
  const [error, setError] = useState<MessageKey | null>(null)
  const lock = useRef(false)
  const commandUuids = useRef(new Map<string, { reason: string; commandUuid: string }>())
  const available = useMemo(() => optionsForPurpose(options, purpose), [options, purpose])
  const selected = available.filter((option) => selectedRuleUuids.includes(option.ruleUuid))
  const resultUncertain = Object.values(failures).some((failure) => failure === 'manualPrintNetworkUnknown')

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
      const matching = optionsForPurpose(items, purpose)
      setSelectedRuleUuids(purpose === 'checkout' ? matching.map((option) => option.ruleUuid) : [])
      setFailures({})
      setOutcome(null)
    }).catch((cause: unknown) => {
      if (active) setLoadError(errorKey(cause))
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => { active = false }
  }, [allowed, api, purpose])

  const commandFor = (ruleUuid: string, printReason: string) => {
    const current = commandUuids.current.get(ruleUuid)
    if (current?.reason === printReason) return current.commandUuid
    const commandUuid = crypto.randomUUID()
    commandUuids.current.set(ruleUuid, { reason: printReason, commandUuid })
    return commandUuid
  }

  const toggleRule = (ruleUuid: string) => {
    if (busy || resultUncertain) return
    setSelectedRuleUuids((current) => current.includes(ruleUuid) ? current.filter((value) => value !== ruleUuid) : [...current, ruleUuid])
    setFailures({})
    setOutcome(null)
    setError(null)
  }

  const submit = async () => {
    if (lock.current || !selected.length || !allowed) return
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
    setFailures({})
    setError(null)
    const queuedRuleUuids: string[] = []
    const failed: Record<string, MessageKey> = {}
    for (const option of selected) {
      try {
        const commandUuid = commandFor(option.ruleUuid, trimmedReason)
        await api.manualPrintOrder(tradeOrderUuid, option.ruleUuid, commandUuid, trimmedReason)
        queuedRuleUuids.push(option.ruleUuid)
        // A successful command is consumed. Selecting this rule again starts an explicit reprint.
        commandUuids.current.delete(option.ruleUuid)
      } catch (cause) {
        failed[option.ruleUuid] = printFailureKey(cause)
      }
    }
    const failedRuleUuids = selected.map((option) => option.ruleUuid).filter((ruleUuid) => failed[ruleUuid])
    setSelectedRuleUuids(failedRuleUuids)
    setFailures(failed)
    setOutcome({ queued: queuedRuleUuids.length, failed: failedRuleUuids.length })
    lock.current = false
    setBusy(false)
  }

  const emptyMessage: MessageKey | null = allowed && !loading && !loadError && available.length === 0 ? copy.noOptions : null
  const submitLabel = selected.length ? printCount(copy.submit, selected.length, t) : t(copy.submitEmpty)
  const footer = <div className="dining-confirm-bar"><Button disabled={busy} onClick={onClose}>{t('close')}</Button><Button block color="primary" loading={busy} disabled={busy || loading || !selected.length || Boolean(loadError)} onClick={() => void submit()}>{purpose === 'reprint' && <Printer size={15} />}{submitLabel}</Button></div>

  return <LiveSheet open title={t(copy.title)} className="manual-print-sheet" onClose={() => { if (!busy) onClose() }} footer={footer}>
    <Notice subtle>{t(copy.hint)}</Notice>
    {orderLabel && <section className="manual-print-order"><span>{t('orderNo')}</span><strong>{orderLabel}</strong></section>}
    {!allowed && <Notice>{t('manualPrintPermissionRequired')}</Notice>}
    {allowed && loading && <p className="manual-print-loading" role="status">{t(copy.loading)}</p>}
    {allowed && loadError && <Notice>{t(loadError)}</Notice>}
    {emptyMessage && <Notice title={emptyMessage}>{t(copy.configurationHint)}</Notice>}
    {allowed && available.length > 0 && <section className="manual-print-options" aria-label={t(copy.title)}>
      <header><div><h3>{t(copy.chooseRule)}</h3><p>{t(copy.chooseHint)}</p></div><strong>{printCount('manualPrintSelectedCount', selected.length, t)}</strong></header>
      {available.map((option) => <button type="button" role="checkbox" aria-checked={selectedRuleUuids.includes(option.ruleUuid)} aria-label={option.name} key={option.ruleUuid} className={selectedRuleUuids.includes(option.ruleUuid) ? 'is-active' : ''} disabled={busy || resultUncertain} onClick={() => toggleRule(option.ruleUuid)}>
        <span className="manual-print-check" aria-hidden="true">{selectedRuleUuids.includes(option.ruleUuid) && <Check size={13} strokeWidth={3} />}</span>
        <span className="manual-print-option-copy"><strong>{option.name}</strong><small>{option.deviceName} · {t(option.kind === 'receipt' ? 'manualPrintReceiptKind' : 'manualPrintLabelKind')}</small></span>
        <em>{t('manualPrintCopies').replace('{count}', String(option.copies))} · {t(option.ticketMode === 'single' ? 'manualPrintTicketSingle' : 'manualPrintTicketPerUnit')}</em>
        {failures[option.ruleUuid] && <small className="manual-print-option-error">{t(failures[option.ruleUuid])}</small>}
      </button>)}
    </section>}
    {allowed && available.length > 0 && <label className="field"><span>{t(copy.reason)}</span><textarea rows={3} maxLength={500} value={reason} disabled={busy || resultUncertain} onChange={(event) => { setReason(event.target.value); setFailures({}); setOutcome(null); setError(null) }} placeholder={t(copy.defaultReason)} /></label>}
    {outcome && <Notice title={outcome.failed ? copy.partialTitle : copy.queued}>{outcome.failed ? printCount(copy.partialResult, outcome.failed, t).replace('{queued}', String(outcome.queued)) : t(copy.queueHint)}</Notice>}
    {error && <Notice>{t(error)}</Notice>}
  </LiveSheet>
}
