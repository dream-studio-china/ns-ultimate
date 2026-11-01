export interface CheckoutPaymentMethod {
  code: string
  name: string
}

const methodLabels: Record<string, string> = {
  alipay: 'Alipay',
  card: 'Card',
  cash: 'Cash',
  huifu: 'Huifu Pay',
  wechat: 'WeChat Pay',
  weixin: 'WeChat Pay',
}

const record = (value: unknown): Record<string, unknown> => value && typeof value === 'object' && !Array.isArray(value)
  ? value as Record<string, unknown>
  : {}

function text(value: unknown): string {
  return typeof value === 'string' ? value.trim() : ''
}

function normalizeMethod(value: unknown, fallbackCode = ''): CheckoutPaymentMethod | null {
  if (typeof value === 'string') {
    const code = value.trim()
    return code ? { code, name: methodLabels[code.toLocaleLowerCase()] ?? code } : null
  }
  const source = record(value)
  if (source.enabled === false || source.active === false || source.disabled === true) return null
  const code = text(source.code) || text(source.method) || text(source.type) || text(source.value) || text(source.uuid) || fallbackCode
  if (!code) return null
  const name = text(source.name) || text(source.title) || text(source.label) || text(source.displayName) || methodLabels[code.toLocaleLowerCase()] || code
  return { code, name }
}

function normalizeMethods(value: unknown): CheckoutPaymentMethod[] {
  if (typeof value === 'string') return normalizeMethods(value.split(','))
  if (Array.isArray(value)) {
    return value.flatMap((entry) => {
      const method = normalizeMethod(entry)
      return method ? [method] : []
    })
  }
  return Object.entries(record(value)).flatMap(([code, entry]) => {
    if (entry === false || entry == null) return []
    if (entry === true) {
      const method = normalizeMethod(code)
      return method ? [method] : []
    }
    const method = normalizeMethod(entry, code)
    return method ? [method] : []
  })
}

/** Read only methods already exposed by the store response; no default provider is invented. */
export function checkoutPaymentMethods(store: unknown): CheckoutPaymentMethod[] {
  const root = record(store)
  const settings = record(root.settings)
  const paymentSetting = record(root.paymentSetting ?? root.payment_setting)
  const paymentSettings = record(settings.payment)
  const values = [
    paymentSetting.channels,
    paymentSetting.methods,
    paymentSetting.paymentMethods,
    settings.paymentChannels,
    settings.paymentMethods,
    settings.enabledPaymentMethods,
    paymentSettings.channels,
    paymentSettings.methods,
    paymentSettings.paymentMethods,
  ]
  const methods = new Map<string, CheckoutPaymentMethod>()
  for (const value of values) {
    for (const method of normalizeMethods(value)) {
      if (!methods.has(method.code)) methods.set(method.code, method)
    }
  }
  return [...methods.values()]
}
