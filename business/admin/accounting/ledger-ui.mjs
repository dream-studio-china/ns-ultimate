export function minorToDecimal(value, scale) {
  const raw = String(value)
  const sign = raw.startsWith('-') ? '-' : ''
  const digits = raw.replace(/^-/, '').padStart(scale + 1, '0')
  return scale ? `${sign}${digits.slice(0, -scale)}.${digits.slice(-scale)}` : raw
}

export function decimalToMinor(value, scale) {
  const match = /^([0-9]{1,19})(?:\.([0-9]+))?$/.exec(value)
  if (!match || (match[2]?.length ?? 0) > scale) throw new Error('invalid_amount')
  const digits = `${match[1]}${(match[2] ?? '').padEnd(scale, '0')}`.replace(/^0+/, '')
  if (!digits || digits.length > 19 || (digits.length === 19 && digits > '9223372036854775807')) throw new Error('invalid_amount')
  return digits
}

export function draftLines(lines, scale) {
  return lines.map(line => ({
    account_code: line.account_code,
    side: line.side,
    amount: decimalToMinor(line.amount, scale),
    description: line.description
  }))
}

export function draftTotals(lines, scale) {
  try {
    const totals = { debit: 0n, credit: 0n }
    for (const line of draftLines(lines, scale)) totals[line.side] += BigInt(line.amount)
    return Object.fromEntries(Object.entries(totals).map(([key, value]) => [key, minorToDecimal(value, scale)]))
  } catch {
    return null
  }
}

// Keep one pending intent until a definitive response or explicit discard.
export function commandIntent(existing, action, input, newId) {
  const fingerprint = JSON.stringify([action, input])
  if (existing && existing.fingerprint !== fingerprint) throw new Error('finance_pending_intent')
  return existing ?? { action, fingerprint, body: { ...input, command_id: newId() } }
}

export function newCommandId(crypto = globalThis.crypto) {
  if (typeof crypto?.randomUUID === 'function') return crypto.randomUUID()
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6] & 15) | 64
  bytes[8] = (bytes[8] & 63) | 128
  const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
