import admin from '@/config'
import { finance } from './finance-context'
import { newCommandId } from './ledger-ui.mjs'

/*
 * Reads the `adapters.finance` policy of an Accounting collection config. Used
 * by the route adapter and the record dialogs; neither defines fields itself.
 *
 *   requires      capability needed to open the resource at all
 *   requiresBook  the resource needs an initialized book
 *   allow         { action: (finance) => boolean } for EasyAdmin actions
 *   fields        { detailProperty: capability } for restricted detail values
 *   commandId     create forms carry a command key for exact retries
 */
export function entityConfig(name) {
  const config = admin.entities[name]
  if (!config) throw new Error(`Missing Accounting collection config "${name}".`)
  return config
}

export const policyOf = config => config.adapters?.finance ?? {}

export function allowed(config, action) {
  const rule = policyOf(config).allow?.[action]
  return typeof rule === 'function' ? Boolean(rule(finance)) : rule !== false
}

export function disabledActions(config) {
  const configured = config.list?.disabled_actions ?? []
  const gated = Object.keys(policyOf(config).allow ?? {}).filter(action => !allowed(config, action))
  return [...new Set([...configured, ...gated])]
}

export function detailFields(config) {
  const restricted = policyOf(config).fields ?? {}
  return (config.detail?.detail_display ?? []).filter(field => {
    const capability = restricted[typeof field === 'string' ? field : field.property]
    return !capability || finance.can(capability)
  })
}

/** Initial FormAdmin model for a new record: a fresh command key when required. */
export const createModel = config => policyOf(config).commandId ? { command_id: newCommandId() } : {}

/** Declared form fields that a link may prefill (for example an opening journal). */
export function linkValues(config, query = {}) {
  const fields = new Set((config.form?.fields ?? []).map(field => typeof field === 'string' ? field : field.property))
  return Object.fromEntries(Object.entries(query).filter(([key, value]) => fields.has(key) && value !== '' && value != null))
}

/**
 * FormAdmin applies field defaults over its model and then emits `input` with
 * its form, so link values are applied on that first event. Native `input`
 * events also bubble through the same listener and are ignored.
 */
export function prefillOnce(values) {
  let done = false
  return form => {
    if (done || !form || typeof form !== 'object' || form instanceof Event) return
    done = true
    Object.assign(form, values)
  }
}
