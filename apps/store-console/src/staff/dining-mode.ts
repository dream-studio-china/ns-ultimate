export const ALL_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const

export type DiningRuleType = 'fixed_item' | 'per_guest_item' | 'choice_group'

interface DiningRuleBase {
  uuid: string
  name: string
  type: DiningRuleType
  required: boolean
  allowRemove: boolean
}
export interface FixedDiningRule extends DiningRuleBase {
  type: 'fixed_item'
  specificationUuid: string
  quantity: number
}

export interface PerGuestDiningRule extends DiningRuleBase {
  type: 'per_guest_item'
  specificationUuid: string
  quantityPerGuest: number
}

export interface ChoiceDiningRule extends DiningRuleBase {
  type: 'choice_group'
  targetType: 'category' | 'specifications'
  categoryUuid: string
  specificationUuids: string[]
  minSelections: number
  maxSelections: number
  quantityPerSelection: number
}

export type DiningRule = FixedDiningRule | PerGuestDiningRule | ChoiceDiningRule

export interface DiningCatalog {
  categoryUuids: string[]
  productUuids: string[]
}

export type CheckoutRuleType = 'confirm_participants' | 'confirm_required_items' | 'suggest_category'

export interface CheckoutRule {
  uuid: string
  name: string
  title: string
  type: CheckoutRuleType
  required: boolean
  categoryUuid: string
}

export interface DiningMode {
  uuid: string
  name: string
  enabled: boolean
  priority: number
  switchButtonLabel: string
  availability: {
    allDay: boolean
    daysOfWeek: number[]
    timeWindows: { start: string; end: string }[]
  }
  catalog: DiningCatalog
  rules: DiningRule[]
  checkoutRules: CheckoutRule[]
}

export interface DiningModesConfig {
  version: 1
  modes: DiningMode[]
}

export interface DiningLine {
  specificationUuid: string
  quantity: number
  productUuid?: string
  categoryUuid?: string | null
}

export interface DiningRuleIssue {
  ruleUuid: string
  kind: 'required' | 'minimum' | 'maximum'
  actual: number
  expected: number
}

const record = (value: unknown): Record<string, unknown> => value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}
const jsonRecord = (value: unknown): unknown => {
  if (typeof value !== 'string' || !value.trim()) return value
  try { return JSON.parse(value) } catch { return {} }
}
const expandedRecord = (value: unknown): Record<string, unknown> => {
  const source = record(jsonRecord(value))
  return { ...source, ...record(source.__metadata) }
}
const text = (value: unknown) => typeof value === 'string' ? value : ''
const integer = (value: unknown, fallback: number) => Number.isInteger(Number(value)) ? Number(value) : fallback
const uniqueText = (value: unknown) => [...new Set((Array.isArray(value) ? value : []).filter((item): item is string => typeof item === 'string' && Boolean(item.trim())).map((item) => item.trim()))]

function normalizeRule(value: unknown): DiningRule | null {
  const source = record(value)
  const type = source.type === 'per_guest_item' || source.type === 'choice_group' ? source.type : source.type === 'fixed_item' ? 'fixed_item' : null
  if (!type) return null
  const base = {
    uuid: text(source.uuid),
    name: text(source.name),
    required: source.required === true,
    allowRemove: source.allowRemove === true,
  }
  if (!base.uuid) return null
  if (type === 'choice_group') {
    return {
      ...base,
      type,
      targetType: source.targetType === 'specifications' ? 'specifications' : 'category',
      categoryUuid: text(source.categoryUuid),
      specificationUuids: uniqueText(source.specificationUuids),
      minSelections: Math.max(0, integer(source.minSelections, 1)),
      maxSelections: Math.max(1, integer(source.maxSelections, 1)),
      quantityPerSelection: Math.max(1, integer(source.quantityPerSelection, 1)),
    }
  }
  const specificationUuid = text(source.specificationUuid)
  if (!specificationUuid) return null
  return type === 'per_guest_item'
    ? { ...base, type, specificationUuid, quantityPerGuest: Math.max(1, integer(source.quantityPerGuest, 1)) }
    : { ...base, type, specificationUuid, quantity: Math.max(1, integer(source.quantity, 1)) }
}

function normalizeCheckoutRule(value: unknown): CheckoutRule | null {
  const source = record(value)
  const type = source.type === 'confirm_required_items' || source.type === 'suggest_category'
    ? source.type
    : source.type === 'confirm_participants' ? 'confirm_participants' : null
  const uuid = text(source.uuid)
  if (!type || !uuid) return null
  return {
    uuid,
    name: text(source.name),
    title: text(source.title),
    type,
    required: type === 'suggest_category' ? false : source.required !== false,
    categoryUuid: type === 'suggest_category' ? text(source.categoryUuid) : '',
  }
}

export function normalizeDiningMode(value: unknown): DiningMode | null {
  const mode = expandedRecord(value)
  const uuid = text(mode.uuid)
  if (!uuid) return null
  const availability = record(mode.availability)
  const catalog = record(mode.catalog)
  const rawDays = Array.isArray(availability.daysOfWeek) ? availability.daysOfWeek : ALL_WEEKDAYS
  const days = [...new Set(rawDays.map(Number).filter((day) => ALL_WEEKDAYS.includes(day as typeof ALL_WEEKDAYS[number])))]
  const windows = (Array.isArray(availability.timeWindows) ? availability.timeWindows : []).flatMap((item) => {
    const window = record(item)
    const start = text(window.start)
    const end = text(window.end)
    return /^\d{2}:\d{2}$/.test(start) && /^\d{2}:\d{2}$/.test(end) && start !== end ? [{ start, end }] : []
  })
  return {
    uuid,
    name: text(mode.name),
    enabled: mode.enabled !== false,
    priority: integer(mode.priority, 0),
    switchButtonLabel: text(mode.switchButtonLabel),
    availability: { allDay: availability.allDay !== false, daysOfWeek: days, timeWindows: windows },
    catalog: { categoryUuids: uniqueText(catalog.categoryUuids), productUuids: uniqueText(catalog.productUuids) },
    rules: (Array.isArray(mode.rules) ? mode.rules : []).flatMap((rule) => {
      const normalized = normalizeRule(rule)
      return normalized ? [normalized] : []
    }),
    checkoutRules: (Array.isArray(mode.checkoutRules) ? mode.checkoutRules : []).flatMap((rule) => {
      const normalized = normalizeCheckoutRule(rule)
      return normalized ? [normalized] : []
    }),
  }
}

export function normalizeDiningModes(value: unknown): DiningMode[] {
  const parsed = jsonRecord(value)
  const source = Array.isArray(parsed) ? { modes: parsed } : record(parsed)
  const modes = Array.isArray(source.modes) ? source.modes : []
  return modes.flatMap((mode) => {
    const normalized = normalizeDiningMode(mode)
    return normalized ? [normalized] : []
  })
}

/**
 * Resolve the mode bound to a session. A saved snapshot is authoritative; when an
 * older endpoint returns only the bound UUID (or names the snapshot field
 * `diningModeSnapshot`), resolve that exact UUID from the store configuration.
 * No UUID and no snapshot still means a historical session with no bound mode.
 */
export function resolveBoundDiningMode(snapshot: unknown, uuid: unknown, storeModes: unknown): DiningMode | null {
  const boundUuid = text(uuid).trim()
  const saved = normalizeDiningMode(snapshot)
  if (saved && (!boundUuid || saved.uuid === boundUuid)) return saved
  return boundUuid ? normalizeDiningModes(storeModes).find((mode) => mode.uuid === boundUuid) ?? null : null
}

interface LocalClock { day: number; minute: number }

function localClock(date: Date, timeZone: string): LocalClock {
  try {
    const parts = new Intl.DateTimeFormat('en-US', { timeZone, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date)
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
    const day = ({ Sun: 7, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 } as Record<string, number>)[values.weekday ?? ''] ?? 1
    return { day, minute: Number(values.hour) * 60 + Number(values.minute) }
  } catch {
    return { day: date.getDay() === 0 ? 7 : date.getDay(), minute: date.getHours() * 60 + date.getMinutes() }
  }
}

const timeValue = (value: string) => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5))

function matchesAvailability(mode: DiningMode, now: Date, timeZone: string): boolean {
  const current = localClock(now, timeZone)
  if (!mode.availability.daysOfWeek.includes(current.day)) return false
  if (mode.availability.allDay) return true
  const previous = localClock(new Date(now.getTime() - 86_400_000), timeZone)
  return mode.availability.timeWindows.some((window) => {
    const start = timeValue(window.start)
    const end = timeValue(window.end)
    if (start < end) return current.minute >= start && current.minute < end
    // Overnight windows belong to the day on which they start. The after-midnight
    // segment therefore checks the previous local weekday.
    return (current.minute >= start && mode.availability.daysOfWeek.includes(current.day))
      || (current.minute < end && mode.availability.daysOfWeek.includes(previous.day))
  })
}

/** Highest priority first, preserving configuration order when priorities tie. */
export function availableDiningModes(modes: DiningMode[], now: Date, timeZone: string): DiningMode[] {
  return modes
    .map((mode, index) => ({ mode, index }))
    .filter(({ mode }) => mode.enabled && matchesAvailability(mode, now, timeZone))
    .sort((left, right) => right.mode.priority - left.mode.priority || left.index - right.index)
    .map(({ mode }) => mode)
}

export function diningModeLabel(mode: DiningMode): string {
  return mode.switchButtonLabel.trim() || mode.name.trim() || mode.uuid
}

export function inDiningCatalog(item: { productUuid?: string; categoryUuid?: string | null }, catalog: DiningCatalog): boolean {
  if (!catalog.categoryUuids.length && !catalog.productUuids.length) return true
  return (Boolean(item.categoryUuid) && catalog.categoryUuids.includes(item.categoryUuid!))
    || (Boolean(item.productUuid) && catalog.productUuids.includes(item.productUuid!))
}

export function ruleQuantity(rule: FixedDiningRule | PerGuestDiningRule, participantCount: number): number {
  return rule.type === 'fixed_item' ? rule.quantity : Math.max(1, Math.floor(participantCount)) * rule.quantityPerGuest
}

export function isChoiceRuleCandidate(rule: ChoiceDiningRule, line: DiningLine): boolean {
  if (rule.targetType === 'specifications') return rule.specificationUuids.includes(line.specificationUuid)
  return Boolean(rule.categoryUuid) && line.categoryUuid === rule.categoryUuid
}

export function isChoiceSelectionBlocked(mode: DiningMode | null, lines: DiningLine[], candidate: DiningLine): boolean {
  if (!mode || lines.some((line) => line.specificationUuid === candidate.specificationUuid && line.quantity > 0)) return false
  return mode.rules.some((rule) => rule.type === 'choice_group'
    && isChoiceRuleCandidate(rule, candidate)
    && lines.filter((line) => line.quantity > 0 && isChoiceRuleCandidate(rule, line)).length >= rule.maxSelections)
}

export function validateDiningRules(mode: DiningMode | null, lines: DiningLine[]): DiningRuleIssue[] {
  if (!mode) return []
  const selected = lines.filter((line) => line.quantity > 0)
  return mode.rules.flatMap((rule): DiningRuleIssue[] => {
    if (rule.type !== 'choice_group') {
      if (!rule.required) return []
      const quantity = selected.find((line) => line.specificationUuid === rule.specificationUuid)?.quantity ?? 0
      return quantity > 0 ? [] : [{ ruleUuid: rule.uuid, kind: 'required' as const, actual: 0, expected: 1 }]
    }
    const actual = selected.filter((line) => isChoiceRuleCandidate(rule, line)).length
    if (actual < rule.minSelections) return [{ ruleUuid: rule.uuid, kind: rule.minSelections > 0 ? 'minimum' as const : 'required' as const, actual, expected: rule.minSelections }]
    if (actual > rule.maxSelections) return [{ ruleUuid: rule.uuid, kind: 'maximum' as const, actual, expected: rule.maxSelections }]
    return []
  })
}
